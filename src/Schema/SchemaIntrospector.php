<?php

declare(strict_types=1);

namespace Lantern\Schema;

use Illuminate\Database\Connection;
use InvalidArgumentException;

/**
 * Reads index definitions from the live database. Read-only catalog queries; table names are validated first.
 */
final class SchemaIntrospector
{
    /**
     * Indexes of the requested tables (all tables when null). Tables that do not exist are left out.
     *
     * @param list<string>|null $tables
     * @return array<string, list<array{name: string, columns: list<string>, unique: bool}>>
     */
    public function capture(Connection $connection, ?array $tables = null): array
    {
        $existing = $this->tables($connection);
        $wanted = $tables === null ? $existing : array_values(array_intersect($tables, $existing));

        $out = [];
        foreach ($wanted as $table) {
            if (! self::isIdentifier($table)) {
                continue;
            }
            $out[$table] = $this->indexes($connection, $table);
        }
        ksort($out);

        return $out;
    }

    /** @return list<string> */
    public function tables(Connection $connection): array
    {
        $rows = match ($connection->getDriverName()) {
            'mysql', 'mariadb' => $connection->select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"),
            'pgsql' => $connection->select('select tablename from pg_tables where schemaname = current_schema()'),
            'sqlite' => $connection->select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%'"),
            default => throw new InvalidArgumentException('Unsupported database driver: '.$connection->getDriverName()),
        };

        return array_values(array_map(fn ($row): string => (string) array_values((array) $row)[0], $rows));
    }

    /** @return list<array{name: string, columns: list<string>, unique: bool}> */
    private function indexes(Connection $connection, string $table): array
    {
        return match ($connection->getDriverName()) {
            'mysql', 'mariadb' => $this->mysql($connection, $table),
            'pgsql' => $this->pgsql($connection, $table),
            default => $this->sqlite($connection, $table),
        };
    }

    /** @return list<array{name: string, columns: list<string>, unique: bool}> */
    private function mysql(Connection $connection, string $table): array
    {
        $rows = array_map(fn ($r): array => (array) $r, $connection->select('SHOW INDEX FROM `'.str_replace('`', '``', $table).'`'));

        // SHOW INDEX names its table in every row, so the one table asked for is the only group
        return array_values(IndexNormalizer::fromShowIndexRows($rows))[0] ?? [];
    }

    /** @return list<array{name: string, columns: list<string>, unique: bool}> */
    private function pgsql(Connection $connection, string $table): array
    {
        $rows = $connection->select(
            'select i.relname as index_name, ix.indisunique as is_unique, a.attname as column_name, k.ord as ord '
            .'from pg_class t join pg_index ix on t.oid = ix.indrelid join pg_class i on i.oid = ix.indexrelid '
            .'join pg_namespace n on n.oid = t.relnamespace '
            .'cross join lateral unnest(ix.indkey) with ordinality as k(attnum, ord) '
            .'join pg_attribute a on a.attrelid = t.oid and a.attnum = k.attnum '
            .'where t.relname = ? and n.nspname = current_schema() order by i.relname, k.ord',
            [$table],
        );

        $mapped = array_map(fn ($r): array => [
            'key_name' => ((array) $r)['index_name'],
            'column_name' => ((array) $r)['column_name'],
            'seq_in_index' => ((array) $r)['ord'],
            'non_unique' => filter_var(((array) $r)['is_unique'], FILTER_VALIDATE_BOOLEAN) ? '0' : '1',
        ], $rows);

        return IndexNormalizer::fromShowIndexRows($mapped)[''] ?? [];
    }

    /** @return list<array{name: string, columns: list<string>, unique: bool}> */
    private function sqlite(Connection $connection, string $table): array
    {
        $quoted = '"'.str_replace('"', '""', $table).'"';
        $out = [];

        $pk = [];
        foreach ($connection->select("pragma table_info($quoted)") as $col) {
            $col = (array) $col;
            if ((int) ($col['pk'] ?? 0) > 0) {
                $pk[(int) $col['pk']] = (string) $col['name'];
            }
        }
        if ($pk !== []) {
            ksort($pk);
            $out[] = ['name' => 'PRIMARY', 'columns' => array_values($pk), 'unique' => true];
        }

        foreach ($connection->select("pragma index_list($quoted)") as $index) {
            $index = (array) $index;
            if (($index['origin'] ?? '') === 'pk') {
                continue; // already reported as PRIMARY
            }
            $name = (string) $index['name'];
            $columns = [];
            foreach ($connection->select('pragma index_info("'.str_replace('"', '""', $name).'")') as $part) {
                $part = (array) $part;
                if (($part['name'] ?? null) !== null) {
                    $columns[(int) $part['seqno']] = (string) $part['name'];
                }
            }
            ksort($columns);
            $out[] = ['name' => $name, 'columns' => array_values($columns), 'unique' => (int) ($index['unique'] ?? 0) === 1];
        }

        return $out;
    }

    public static function isIdentifier(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9_$.\-]+$/', $name) === 1;
    }
}
