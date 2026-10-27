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
final class ExplainRunner
{
    public function __construct(private readonly ?DatabaseManager $db = null)
    {
    }

    /**
     * @param  array<int|string, mixed>  $bindings
     * @return array{full_scan: bool, table: ?string, rows: ?int, key: ?string, possible_keys: ?string, raw: mixed}|null
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
        $pick = null;
        foreach ($rows as $row) {
            if ($pick === null || (strtoupper((string) ($row['type'] ?? '')) === 'ALL' && strtoupper((string) ($pick['type'] ?? '')) !== 'ALL')) {
                $pick = $row;
            }
        }
        $pick ??= [];

        return [
            'full_scan' => strtoupper((string) ($pick['type'] ?? '')) === 'ALL',
            'table' => isset($pick['table']) ? (string) $pick['table'] : null,
            'rows' => isset($pick['rows']) ? (int) $pick['rows'] : null,
            'key' => isset($pick['key']) ? (string) $pick['key'] : null,
            'possible_keys' => isset($pick['possible_keys']) ? (string) $pick['possible_keys'] : null,
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
        $walk = function (array $node) use (&$walk, &$seq): void {
            if (($node['Node Type'] ?? null) === 'Seq Scan' && ($seq === null || ($node['Plan Rows'] ?? 0) > ($seq['Plan Rows'] ?? 0))) {
                $seq = $node;
            }
            foreach ($node['Plans'] ?? [] as $child) {
                $walk($child);
            }
        };
        if (isset($plan[0]['Plan'])) {
            $walk($plan[0]['Plan']);
        }

        return [
            'full_scan' => $seq !== null,
            'table' => $seq['Relation Name'] ?? null,
            'rows' => isset($seq['Plan Rows']) ? (int) $seq['Plan Rows'] : null,
            'key' => null,
            'possible_keys' => null,
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
        }

        return [
            'full_scan' => $full,
            'table' => $table,
            'rows' => null,
            'key' => $key,
            'possible_keys' => null,
            'raw' => $rows,
        ];
    }
}
