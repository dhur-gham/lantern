<?php

declare(strict_types=1);

namespace Lantern\Detection\Explain;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * Runs a read-only EXPLAIN for a captured SELECT and normalises the plan across drivers.
 *
 * Instrumentation bypass: the statement is executed straight on the PDO handle (prepare/execute), not through
 * Connection::select()/run(). Laravel's beforeExecuting callbacks and QueryExecuted events only fire from
 * Connection::run(), so Lantern's own query instrumentation never sees it: no span, no recursion, and no flag
 * or shared state needed.
 *
 * Safe by construction: only statements starting with SELECT are explained, never ANALYZE, and every failure
 * (unknown connection, driver, syntax, missing table) yields null instead of an exception.
 */
final class ExplainRunner implements PlanSource
{
    public function __construct(private readonly ?DatabaseManager $db = null)
    {
    }

    /**
     * `tables` lists every real table in the plan (derived tables left out) with its estimated rows, chosen key
     * and access type (`ALL` is a full scan); `table`/`rows`/`key` describe the one the detectors act on.
     *
     * @param  array<int|string, mixed>  $bindings
     * @return array{full_scan: bool, table: ?string, rows: ?int, key: ?string, possible_keys: ?string, tables: list<array{table: string, alias: ?string, rows: ?int, key: ?string, type: ?string, possible_keys: ?string}>, raw: mixed}|null
     */
    public function explain(?string $connectionName, string $sql, array $bindings = []): ?array
    {
        try {
            if (! self::isSelect($sql)) {
                return null;
            }

            $db = $this->db ?? app('db');
            $connection = $db->connection($connectionName);
            if (! $connection instanceof Connection) {
                return null;
            }

            $driver = $connection->getDriverName();
            $prefix = match ($driver) {
                'mysql', 'mariadb' => 'EXPLAIN ',
                'pgsql' => 'EXPLAIN (FORMAT JSON) ',
                'sqlite' => 'EXPLAIN QUERY PLAN ',
                default => null,
            };
            if ($prefix === null) {
                return null;
            }

            $params = $this->params($connection, $sql, $bindings);
            $statement = $connection->getReadPdo()->prepare($prefix.$sql);
            $statement->execute($params);
            $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

            return match ($driver) {
                'pgsql' => $this->pgsql($rows),
                'sqlite' => $this->sqlite($rows),
                default => $this->mysql($rows),
            };
        } catch (Throwable) {
            return null;
        }
    }

    public static function isSelect(string $sql): bool
    {
        $sql = ltrim($sql, " \t\n\r\0\x0B(");

        return stripos($sql, 'select') === 0 && ! str_contains(rtrim($sql, "; \n\r\t"), ';');
    }

    /**
     * Captured bindings may be redacted/truncated or absent; missing values become NULL so the plan can
     * still be produced (the plan shape does not depend on the values).
     *
     * @param  array<int|string, mixed>  $bindings
     * @return list<mixed>
     */
    private function params(Connection $connection, string $sql, array $bindings): array
    {
        $needed = substr_count($sql, '?');
        $values = array_values($connection->prepareBindings($bindings));
        $values = array_slice($values, 0, $needed);
        while (count($values) < $needed) {
            $values[] = null;
        }

        return $values;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{full_scan: bool, table: ?string, rows: ?int, key: ?string, possible_keys: ?string, raw: mixed}
     */
    private function mysql(array $rows): array
    {
        $rows = array_map(fn ($r) => array_change_key_case($r), $rows);
        // Prefer the biggest full scan (a join or materialized subquery can list several tables); derived tables like
        // <subquery2> are not real tables. Otherwise fall back to the first row.
        $pick = null;
        foreach ($rows as $row) {
            if (str_starts_with((string) ($row['table'] ?? ''), '<')) {
                continue;
            }
            $scan = strtoupper((string) ($row['type'] ?? '')) === 'ALL';
            $pickScan = $pick !== null && strtoupper((string) ($pick['type'] ?? '')) === 'ALL';
            if ($pick === null || ($scan && ! $pickScan) || ($scan && $pickScan && (int) ($row['rows'] ?? 0) > (int) ($pick['rows'] ?? 0))) {
                $pick = $row;
            }
        }
        $pick ??= [];

        $tables = [];
        foreach ($rows as $row) {
            if (! isset($row['table']) || str_starts_with((string) $row['table'], '<')) {
                continue;
            }
            $tables[] = [
                'table' => (string) $row['table'],
                'alias' => null,
                'rows' => isset($row['rows']) ? (int) $row['rows'] : null,
                'key' => isset($row['key']) ? (string) $row['key'] : null,
                'type' => isset($row['type']) ? (string) $row['type'] : null,
                'possible_keys' => isset($row['possible_keys']) ? (string) $row['possible_keys'] : null,
            ];
        }

        return [
            'full_scan' => strtoupper((string) ($pick['type'] ?? '')) === 'ALL',
            'table' => isset($pick['table']) ? (string) $pick['table'] : null,
            'rows' => isset($pick['rows']) ? (int) $pick['rows'] : null,
            'key' => isset($pick['key']) ? (string) $pick['key'] : null,
            'possible_keys' => isset($pick['possible_keys']) ? (string) $pick['possible_keys'] : null,
            'tables' => $tables,
            'raw' => $rows,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{full_scan: bool, table: ?string, rows: ?int, key: ?string, possible_keys: ?string, raw: mixed}
     */
    private function pgsql(array $rows): array
    {
        $json = array_values($rows[0] ?? [])[0] ?? '[]';
        $plan = json_decode((string) $json, true) ?: [];
        $seq = null;
        $tables = [];
        $walk = function (array $node, ?int $heap) use (&$walk, &$seq, &$tables): void {
            if (($node['Node Type'] ?? null) === 'Seq Scan' && ($seq === null || ($node['Plan Rows'] ?? 0) > ($seq['Plan Rows'] ?? 0))) {
                $seq = $node;
            }
            if (isset($node['Relation Name'])) {
                $heap = count($tables);
                $tables[] = [
                    'table' => (string) $node['Relation Name'],
                    'alias' => isset($node['Alias']) && $node['Alias'] !== $node['Relation Name'] ? (string) $node['Alias'] : null,
                    'rows' => isset($node['Plan Rows']) ? (int) $node['Plan Rows'] : null,
                    'key' => isset($node['Index Name']) ? (string) $node['Index Name'] : null,
                    'type' => ($node['Node Type'] ?? null) === 'Seq Scan' ? 'ALL' : 'ref',
                    'possible_keys' => null,
                ];
            } elseif ($heap !== null && isset($node['Index Name']) && $tables[$heap]['key'] === null) {
                $tables[$heap]['key'] = (string) $node['Index Name'];
            }
            foreach ($node['Plans'] ?? [] as $child) {
                $walk($child, $heap);
            }
        };
        if (isset($plan[0]['Plan'])) {
            $walk($plan[0]['Plan'], null);
        }

        // like MySQL, name the driving table (and its key) even when nothing is scanned
        $first = $seq === null ? ($tables[0] ?? null) : null;

        return [
            'full_scan' => $seq !== null,
            'table' => $seq['Relation Name'] ?? $first['table'] ?? null,
            'rows' => isset($seq['Plan Rows']) ? (int) $seq['Plan Rows'] : ($first['rows'] ?? null),
            'key' => $first['key'] ?? null,
            'possible_keys' => null,
            'tables' => $tables,
            'raw' => $plan,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{full_scan: bool, table: ?string, rows: ?int, key: ?string, possible_keys: ?string, raw: mixed}
     */
    private function sqlite(array $rows): array
    {
        $table = null;
        $key = null;
        $full = false;
        $tables = [];
        foreach ($rows as $row) {
            $detail = (string) ($row['detail'] ?? '');
            if (preg_match('/^SCAN (?:TABLE )?(\S+)(.*)$/i', $detail, $m) === 1
                && strcasecmp($m[1], 'CONSTANT') !== 0
                && stripos($m[2], 'INDEX') === false) {
                $full = true;
                $table ??= trim($m[1], '`"[]');
            } elseif (preg_match('/USING (?:COVERING )?INDEX (\S+)/i', $detail, $m) === 1) {
                $key ??= $m[1];
            }
            if (preg_match('/^(SCAN|SEARCH) (?:TABLE )?(\S+)(?: AS (\S+))?(.*)$/i', $detail, $m) === 1 && strcasecmp($m[2], 'CONSTANT') !== 0) {
                $indexed = preg_match('/USING (?:COVERING )?INDEX (\S+)/i', $m[4], $k) === 1;
                $tables[] = [
                    'table' => trim($m[2], '`"[]'),
                    'alias' => ($m[3] ?? '') !== '' ? trim($m[3], '`"[]') : null,
                    'rows' => null,
                    'key' => $indexed ? $k[1] : null,
                    'type' => strtoupper($m[1]) === 'SCAN' && ! $indexed ? 'ALL' : 'ref',
                    'possible_keys' => null,
                ];
            }
        }

        return [
            'full_scan' => $full,
            'table' => $table,
            'rows' => null,
            'key' => $key,
            'possible_keys' => null,
            'tables' => $tables,
            'raw' => $rows,
        ];
    }
}
