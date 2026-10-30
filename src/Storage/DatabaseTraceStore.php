<?php

declare(strict_types=1);

namespace Lantern\Storage;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\QueryException;
use Illuminate\Database\Query\Expression;
use Lantern\Agent\Findings;
use Lantern\Detection\Issue;
use Lantern\Instrumentation\Query\QuerySuspend;
use Lantern\Trace\Trace;

final class DatabaseTraceStore implements TraceStore
{
    private const JSON_FLAGS = PayloadCodec::JSON_FLAGS;

    /** Set once a write fails because the `app` column is missing (package upgraded, migration not yet run). */
    private bool $legacyApp = false;

    /** Same for the `shape` and `resolved_at` columns of lantern_issues. */
    private bool $legacyResolve = false;

    public function __construct(
        private readonly ConnectionResolverInterface $db,
        private readonly ?string $connection = null,
        private readonly bool $compress = false,
        private readonly ?string $app = null,
    ) {
    }

    public function store(Trace $trace, array $issues): void
    {
        QuerySuspend::run(function () use ($trace, $issues): void {
            $conn = $this->conn();
            $now = date('Y-m-d H:i:s');
            $startedAt = date('Y-m-d H:i:s', (int) $trace->startedAtUnix).substr(sprintf('%.3f', fmod($trace->startedAtUnix, 1)), 1);

            $row = [
                'id' => $trace->id,
                'started_at' => $startedAt,
                'duration_ms' => (int) round($trace->totalMs()),
                'status' => isset($trace->meta['status']) ? (string) $trace->meta['status'] : null,
                'method' => $this->str($trace->meta['method'] ?? null, 10),
                'path' => $this->str($trace->meta['path'] ?? null, 255),
                'route' => $this->str($trace->meta['route'] ?? null, 255),
                'query_count' => (int) ($trace->meta['query_count'] ?? 0),
                'query_total_ms' => (float) ($trace->meta['query_total_ms'] ?? 0.0),
                'issue_count' => count($issues),
                'peak_memory' => $trace->peakMemory(),
                'payload' => $this->encode($trace->toArray()),
                'created_at' => $now,
            ];

            // A column added by a migration that has not run yet is dropped from the write, once per column group.
            while (true) {
                try {
                    $this->write($conn, $row, $issues, $startedAt, $trace, $now);

                    return;
                } catch (QueryException $e) {
                    if (! $this->legacyApp && self::missingColumn($e->getMessage(), ['app'])) {
                        $this->legacyApp = true;
                    } elseif (! $this->legacyResolve && self::missingColumn($e->getMessage(), ['shape', 'resolved_at'])) {
                        $this->legacyResolve = true;
                    } else {
                        throw $e;
                    }
                }
            }
        });
    }

    /**
     * @param array<string, mixed> $row
     * @param list<Issue> $issues
     */
    private function write(Connection $conn, array $row, array $issues, string $startedAt, Trace $trace, string $now): void
    {
        $withApp = ! $this->legacyApp;
        $withResolve = ! $this->legacyResolve;
        $app = $withApp ? $this->str($this->app, 100) : null;
        if ($withApp) {
            $row['app'] = $app;
        }

        $conn->transaction(function () use ($conn, $row, $issues, $startedAt, $trace, $now, $withApp, $withResolve, $app): void {
            $conn->table('lantern_traces')->insert($row);

            $rows = $this->issueRows($issues, $startedAt, (string) $row['id'], $withResolve);
            if ($rows !== []) {
                if ($withApp) {
                    $rows = array_map(fn (array $r): array => $r + ['app' => $app], $rows);
                }
                $conn->table('lantern_issues')->upsert($rows, ['issue_key'], $this->issueUpdate($conn, $withApp, $withResolve));
            }

            if ($withResolve) {
                $this->resolveCleared($conn, $trace, $issues, $now);
            }
        });
    }

    /**
     * Resolves open issues whose query shape ran in this trace without producing the finding. Runs in a savepoint so
     * a failure here never costs the trace; only a missing column escapes, to switch the write to the old schema.
     *
     * @param list<Issue> $issues
     */
    private function resolveCleared(Connection $conn, Trace $trace, array $issues, string $now): void
    {
        try {
            $conn->transaction(function () use ($conn, $trace, $issues, $now): void {
                $config = (array) config('lantern', []);
                $max = (int) ($config['thresholds']['max_issues_per_trace'] ?? 50);
                if ($max > 0 && count($issues) >= $max) {
                    return; // findings beyond the cap were dropped, so absence proves nothing
                }

                $fired = array_map(fn (Issue $i): string => Findings::shape($i->toArray()), $issues);
                $candidates = array_values(array_diff(ClearedShapes::candidates($trace, $config), $fired));
                $keys = array_map(fn (Issue $i): string => $i->key, $issues);
                foreach (array_chunk($candidates, 200) as $chunk) {
                    $query = $conn->table('lantern_issues')->whereNull('resolved_at')->whereIn('shape', $chunk);
                    if ($keys !== []) {
                        $query->whereNotIn('issue_key', $keys);
                    }
                    $query->update(['resolved_at' => $now]);
                }
            });
        } catch (QueryException $e) {
            if (self::missingColumn($e->getMessage(), ['shape', 'resolved_at'])) {
                throw $e;
            }
        }
    }

    /** @param list<string> $columns */
    private static function missingColumn(string $message, array $columns): bool
    {
        $names = implode('|', array_map(fn (string $c): string => preg_quote($c, '/'), $columns));

        return preg_match('/(?:no column named |no such column: |unknown column \'|column ")(?:\w+\.)?(?:'.$names.')\b/i', $message) === 1;
    }

    public function find(string $id): ?array
    {
        return QuerySuspend::run(function () use ($id): ?array {
            $row = $this->conn()->table('lantern_traces')->where('id', $id)->first();
            if ($row === null) {
                return null;
            }

            $row = (array) $row;
            $row['payload'] = $this->decode((string) $row['payload']);

            return $row;
        });
    }

    public function recent(int $limit = 50): array
    {
        return QuerySuspend::run(fn (): array => $this->conn()->table('lantern_traces')
            ->select(['id', 'started_at', 'duration_ms', 'status', 'method', 'path', 'route', 'query_count', 'query_total_ms', 'issue_count', 'peak_memory', 'created_at'])
            ->orderByDesc('started_at')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn ($r): array => (array) $r)
            ->all());
    }

    /**
     * One row per issue key (merged within the trace); occurrences starts at 1 so the upsert can add the row's values.
     *
     * @param list<Issue> $issues
     * @return list<array<string, mixed>>
     */
    private function issueRows(array $issues, string $now, string $traceId, bool $withResolve): array
    {
        $rows = [];
        foreach ($issues as $issue) {
            $max = (float) ($issue->meta['max_ms'] ?? ($issue->count > 0 ? $issue->totalMs / $issue->count : $issue->totalMs));

            if (isset($rows[$issue->key])) {
                $rows[$issue->key]['count_total'] += $issue->count;
                $rows[$issue->key]['total_ms'] += $issue->totalMs;
                $rows[$issue->key]['max_ms'] = max($rows[$issue->key]['max_ms'], $max);

                continue;
            }

            $rows[$issue->key] = [
                'issue_key' => $issue->key,
                'type' => $this->str($issue->type, 32),
                'title' => $this->str($issue->title, 500),
                'severity' => $this->str($issue->severity, 16),
                'occurrences' => 1,
                'count_total' => $issue->count,
                'total_ms' => $issue->totalMs,
                'max_ms' => $max,
                'call_site' => $issue->callSite === null ? null : json_encode($issue->callSite, self::JSON_FLAGS),
                'suggestion' => $issue->suggestion,
                'first_seen' => $now,
                'last_seen' => $now,
                'last_trace_id' => $traceId,
            ] + ($withResolve ? ['shape' => $this->str(Findings::shape($issue->toArray()), 64), 'resolved_at' => null] : []);
        }

        return array_values($rows);
    }

    /** @return array<string, Expression> */
    private function issueUpdate(Connection $conn, bool $withApp = false, bool $withResolve = false): array
    {
        $g = $conn->getQueryGrammar();
        $table = $g->wrapTable('lantern_issues');
        $driver = $conn->getDriverName();
        $mysql = $driver === 'mysql' || $driver === 'mariadb';
        $alias = $mysql ? $conn->getConfig('use_upsert_alias') : null;

        $cur = fn (string $c): string => $table.'.'.$g->wrap($c);
        $new = fn (string $c): string => match (true) {
            $mysql && $alias => $g->wrap('laravel_upsert_alias').'.'.$g->wrap($c),
            $mysql => 'values('.$g->wrap($c).')',
            default => 'excluded.'.$g->wrap($c),
        };
        $greatest = $driver === 'sqlite' ? 'max' : 'greatest';

        $update = [
            'severity' => new Expression($new('severity')),
            'title' => new Expression($new('title')),
            'call_site' => new Expression($new('call_site')),
            'suggestion' => new Expression($new('suggestion')),
            'occurrences' => new Expression($cur('occurrences').' + '.$new('occurrences')),
            'count_total' => new Expression($cur('count_total').' + '.$new('count_total')),
            'total_ms' => new Expression($cur('total_ms').' + '.$new('total_ms')),
            'max_ms' => new Expression($greatest.'('.$cur('max_ms').', '.$new('max_ms').')'),
            'last_seen' => new Expression($new('last_seen')),
            'last_trace_id' => new Expression($new('last_trace_id')),
        ];
        if ($withApp) {
            $update['app'] = new Expression('coalesce('.$new('app').', '.$cur('app').')');
        }
        if ($withResolve) {
            // a finding that fires again reopens the issue
            $update['shape'] = new Expression($new('shape'));
            $update['resolved_at'] = new Expression('NULL');
        }

        return $update;
    }

    private function conn(): Connection
    {
        /** @var Connection */
        return $this->db->connection($this->connection ?: null);
    }

    /** @param array<string, mixed> $data */
    private function encode(array $data): string
    {
        return PayloadCodec::encode($data, $this->compress);
    }

    /** @return array<string, mixed> */
    private function decode(string $payload): array
    {
        return PayloadCodec::decode($payload) ?? [];
    }

    private function str(mixed $value, int $max): ?string
    {
        return $value === null ? null : mb_substr((string) $value, 0, $max);
    }
}
