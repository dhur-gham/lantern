<?php

declare(strict_types=1);

namespace Lantern\Detection\Explain;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use PDO;
use Throwable;

/**
 * Reads index and column facts from the database catalog. Like ExplainRunner it talks to the PDO handle directly,
 * so Lantern's query instrumentation never sees these statements. Every failure yields an empty answer.
 */
final class DatabaseCatalog implements Catalog
{
    /** @var array<string, array{indexes: list<array{name: string, columns: list<string>, unique: bool}>, stats: array{rows: ?int, columns: array<string, array{type: ?string, distinct: ?int}>}}> */
    private array $cache = [];

    public function __construct(private readonly ?DatabaseManager $db = null)
    {
    }

    public function indexes(?string $connection, string $table): array
    {
        return $this->load($connection, $table)['indexes'];
    }

    public function stats(?string $connection, string $table): array
    {
        return $this->load($connection, $table)['stats'];
    }

    /** @return array{indexes: list<array{name: string, columns: list<string>, unique: bool}>, stats: array{rows: ?int, columns: array<string, array{type: ?string, distinct: ?int}>}} */
    private function load(?string $connection, string $table): array
    {
        return $this->cache[($connection ?? '').'|'.$table] ??= $this->fetch($connection, $table);
    }

    /** @return array{indexes: list<array{name: string, columns: list<string>, unique: bool}>, stats: array{rows: ?int, columns: array<string, array{type: ?string, distinct: ?int}>}} */
    private function fetch(?string $connectionName, string $table): array
    {
        $empty = ['indexes' => [], 'stats' => ['rows' => null, 'columns' => []]];

        try {
            if (preg_match('/^[A-Za-z_][\w$]*$/', $table) !== 1) {
                return $empty;
            }
            $connection = ($this->db ?? app('db'))->connection($connectionName);
            if (! $connection instanceof Connection) {
                return $empty;
            }
            $pdo = $connection->getReadPdo();

            return match ($connection->getDriverName()) {
                'mysql', 'mariadb' => $this->mysql($pdo, $table),
                'pgsql' => $this->pgsql($pdo, $table),
                default => $empty,
            };
        } catch (Throwable) {
            return $empty;
        }
    }

    /** @return array{indexes: list<array{name: string, columns: list<string>, unique: bool}>, stats: array{rows: ?int, columns: array<string, array{type: ?string, distinct: ?int}>}} */
    private function mysql(PDO $pdo, string $table): array
    {
        $indexes = [];
        $distinct = [];
        foreach ($this->all($pdo, 'SHOW INDEX FROM `'.$table.'`') as $row) {
            $row = array_change_key_case($row);
            $column = $row['column_name'] ?? null;
            if ($column === null) {
                continue; // functional key part
            }
            $name = (string) $row['key_name'];
            $seq = (int) ($row['seq_in_index'] ?? 1);
            $indexes[$name]['name'] = $name;
            $indexes[$name]['unique'] = (int) ($row['non_unique'] ?? 1) === 0;
            $indexes[$name]['cols'][$seq] = (string) $column;
            if ($seq === 1 && isset($row['cardinality'])) {
                $key = strtolower((string) $column);
                $distinct[$key] = max($distinct[$key] ?? 0, (int) $row['cardinality']);
            }
        }

        $rows = null;
        foreach ($this->all($pdo, 'select table_rows from information_schema.tables where table_schema = database() and table_name = ?', [$table]) as $row) {
            $row = array_change_key_case($row);
            $rows = isset($row['table_rows']) ? (int) $row['table_rows'] : null;
        }

        $columns = [];
        foreach ($this->all($pdo, 'select column_name, column_type from information_schema.columns where table_schema = database() and table_name = ?', [$table]) as $row) {
            $row = array_change_key_case($row);
            $key = strtolower((string) $row['column_name']);
            $columns[$key] = ['type' => (string) $row['column_type'], 'distinct' => $distinct[$key] ?? null];
        }

        return ['indexes' => $this->normalize($indexes), 'stats' => ['rows' => $rows, 'columns' => $columns]];
    }

    /** @return array{indexes: list<array{name: string, columns: list<string>, unique: bool}>, stats: array{rows: ?int, columns: array<string, array{type: ?string, distinct: ?int}>}} */
    private function pgsql(PDO $pdo, string $table): array
    {
        $indexes = [];
        $sql = 'select i.relname as key_name, ix.indisunique as is_unique, a.attname as column_name, k.ord as seq '
            .'from pg_class t join pg_index ix on ix.indrelid = t.oid join pg_class i on i.oid = ix.indexrelid '
            .'cross join lateral unnest(ix.indkey::int2[]) with ordinality as k(attnum, ord) '
            .'left join pg_attribute a on a.attrelid = t.oid and a.attnum = k.attnum '
            ."where t.relname = ? and t.relkind = 'r' and pg_table_is_visible(t.oid) order by i.relname, k.ord";
        foreach ($this->all($pdo, $sql, [$table]) as $row) {
            if ($row['column_name'] === null) {
                continue; // expression key part
            }
            $name = (string) $row['key_name'];
            $indexes[$name]['name'] = $name;
            $indexes[$name]['unique'] = filter_var($row['is_unique'], FILTER_VALIDATE_BOOLEAN);
            $indexes[$name]['cols'][(int) $row['seq']] = (string) $row['column_name'];
        }

        $rows = null;
        foreach ($this->all($pdo, "select reltuples::bigint as n from pg_class where relname = ? and relkind = 'r' and pg_table_is_visible(oid)", [$table]) as $row) {
            $rows = max(0, (int) $row['n']);
        }

        $columns = [];
        foreach ($this->all($pdo, 'select column_name, data_type from information_schema.columns where table_name = ? and table_schema = any(current_schemas(false))', [$table]) as $row) {
            $columns[strtolower((string) $row['column_name'])] = ['type' => (string) $row['data_type'], 'distinct' => null];
        }
        foreach ($this->all($pdo, 'select attname, n_distinct from pg_stats where tablename = ? and schemaname = any(current_schemas(false))', [$table]) as $row) {
            $key = strtolower((string) $row['attname']);
            if (! isset($columns[$key])) {
                continue;
            }
            $n = (float) $row['n_distinct'];
            $columns[$key]['distinct'] = $n >= 0 ? (int) $n : ($rows !== null ? (int) round(-$n * $rows) : null);
        }

        return ['indexes' => $this->normalize($indexes), 'stats' => ['rows' => $rows, 'columns' => $columns]];
    }

    /**
     * @param  array<string, array{name?: string, unique?: bool, cols?: array<int, string>}>  $indexes
     * @return list<array{name: string, columns: list<string>, unique: bool}>
     */
    private function normalize(array $indexes): array
    {
        $out = [];
        foreach ($indexes as $index) {
            $cols = $index['cols'] ?? [];
            ksort($cols);
            $out[] = ['name' => (string) $index['name'], 'columns' => array_values($cols), 'unique' => (bool) ($index['unique'] ?? false)];
        }

        return $out;
    }

    /**
     * @param  list<mixed>  $params
     * @return list<array<string, mixed>>
     */
    private function all(PDO $pdo, string $sql, array $params = []): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
