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

    /** Same for the `site_hash` column of lantern_issues (resolve by absence stays off until it exists). */
    private bool $legacyCode = false;

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
                    } elseif (! $this->legacyCode && self::missingColumn($e->getMessage(), ['site_hash'])) {
                        $this->legacyCode = true;
                    } elseif (! $this->legacyResolve && self::missingColumn($e->getMessage(), ['shape', 'resolved_at', 'in_size', 'sql_hash'])) {
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
        $withCode = $withResolve && ! $this->legacyCode;
        $app = $withApp ? $this->str($this->app, 100) : null;
        if ($withApp) {
            $row['app'] = $app;
        }

        $conn->transaction(function () use ($conn, $row, $issues, $startedAt, $trace, $now, $withApp, $withResolve, $withCode, $app): void {
            $conn->table('lantern_traces')->insert($row);

            $rows = $this->issueRows($issues, $startedAt, (string) $row['id'], $withResolve, $trace, $withCode);
            if ($rows !== []) {
                if ($withApp) {
                    $rows = array_map(fn (array $r): array => $r + ['app' => $app], $rows);
                }
                $conn->table('lantern_issues')->upsert($rows, ['issue_key'], $this->issueUpdate($conn, $withApp, $withResolve, $withCode));
            }

            if ($withResolve) {
                $this->resolveCleared($conn, $trace, $issues, $now, $withCode);
            }
        });
    }

    /**
     * Resolves open issues whose query shape ran in this trace without producing the finding. Runs in a savepoint so
     * a failure here never costs the trace; only a missing column escapes, to switch the write to the old schema.
     *
     * @param list<Issue> $issues
     */
    private function resolveCleared(Connection $conn, Trace $trace, array $issues, string $now, bool $withCode): void
    {
        try {
            $conn->transaction(function () use ($conn, $trace, $issues, $now, $withCode): void {
                $config = (array) config('lantern', []);
                $max = (int) ($config['thresholds']['max_issues_per_trace'] ?? 50);
                if ($max > 0 && count($issues) >= $max) {
                    return; // findings beyond the cap were dropped, so absence proves nothing
                }

                $fired = array_map(fn (Issue $i): string => Findings::shape($i->toArray()), $issues);
                $candidates = array_diff_key(ClearedShapes::candidates($trace, $config), array_flip($fired));
                $keys = array_map(fn (Issue $i): string => $i->key, $issues);
                foreach (array_chunk(array_keys($candidates), 200) as $chunk) {
                    $query = $conn->table('lantern_issues')->whereNull('resolved_at')->whereIn('shape', $chunk);
                    if ($keys !== []) {
                        $query->whereNotIn('issue_key', $keys);
                    }
                    $ids = [];
                    foreach ($query->get(['issue_key', 'shape', 'in_size', 'sql_hash']) as $row) {
                        $ran = $candidates[(string) $row->shape] ?? null;
                        // a smaller IN list than the one that fired is another input, not a fix, unless the SQL itself changed
                        $smaller = $ran !== null && $row->in_size !== null && $ran['in_size'] < (int) $row->in_size
                            && ($row->sql_hash === null || in_array((string) $row->sql_hash, $ran['sql_hashes'], true));
                        if (! $smaller) {
                            $ids[] = $row->issue_key;
                        }
                    }
                    if ($ids !== []) {
                        $conn->table('lantern_issues')->whereIn('issue_key', $ids)->update(['resolved_at' => $now]);
                    }
                }

                if ($withCode) {
                    $this->resolveRemoved($conn, $trace, $fired, $candidates, $now);
                }
            });
        } catch (QueryException $e) {
            if (self::missingColumn($e->getMessage(), ['shape', 'resolved_at'])) {
                throw $e;
            }
        }
    }

    /**
     * Resolves open findings whose query is gone: the same route ran again, executed the finding's call-site function
     * without the query, and the call-site file changed since the finding fired. The same code that skipped the query
     * is a data difference, not a fix. Without a route, the function in the trace or a stored hash, absence proves nothing.
     *
     * @param list<string> $fired shapes of the findings this trace produced
     * @param array<string, mixed> $ran shapes this trace ran clean (resolved by the caller)
     */
    private function resolveRemoved(Connection $conn, Trace $trace, array $fired, array $ran, string $now): void
    {
        $route = $trace->meta['route'] ?? null;
        if (! is_string($route) || $route === '') {
            return;
        }
        // a batched Livewire request carries several routes; a finding recorded under any of them can be judged here
        $routes = array_values(array_unique(array_filter([$route, ...array_map('strval', (array) ($trace->meta['livewire_routes'] ?? []))], fn (string $r): bool => $r !== '')));

        $executed = [];
        foreach ($trace->spans() as $span) {
            $site = Findings::site($span->callSite);
            if ($site !== null && isset($site['function'])) {
                $executed[Findings::whereFunction($site)] = true;
            }
        }
        if ($executed === []) {
            return;
        }

        $types = [Issue::TYPE_N_PLUS_ONE, Issue::TYPE_SLOW, Issue::TYPE_DUPLICATE, Issue::TYPE_LAZY_LOAD];
        $rows = $conn->table('lantern_issues as i')
            ->join('lantern_traces as t', 't.id', '=', 'i.last_trace_id')
            ->whereNull('i.resolved_at')
            ->whereIn('t.route', $routes)
            ->whereIn('i.type', $types)
            ->limit(500)
            ->whereNotNull('i.site_hash')
            ->get(['i.issue_key', 'i.shape', 'i.call_site', 'i.site_hash']);

        $ids = [];
        foreach ($rows as $row) {
            if (in_array((string) $row->shape, $fired, true) || isset($ran[(string) $row->shape])) {
                continue;
            }
            $site = Findings::site($row->call_site);
            if ($site !== null && isset($site['function']) && isset($executed[Findings::whereFunction($site)])) {
                $current = SiteHash::of($site);
                if ($current !== null && $current !== (string) $row->site_hash) {
                    $ids[] = $row->issue_key;
                }
            }
        }
        if ($ids !== []) {
            $conn->table('lantern_issues')->whereIn('issue_key', $ids)->update(['resolved_at' => $now]);
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
    private function issueRows(array $issues, string $now, string $traceId, bool $withResolve, ?Trace $trace = null, bool $withCode = false): array
    {
        $rows = [];
        $spans = [];
        foreach ($trace?->spans() ?? [] as $span) {
            $spans[$span->id] = $span;
        }
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
            ] + ($withResolve ? ['shape' => $this->str(Findings::shape($issue->toArray()), 64), 'resolved_at' => null] + $this->queryFacts($issue, $spans) : [])
                + ($withCode ? ['site_hash' => SiteHash::of(Findings::site($issue->callSite))] : []);
        }

        return array_values($rows);
    }

    /**
     * The IN list size and SQL text of the query a finding fired on, so a later run with a smaller list or the same
     * SQL is not mistaken for a fix. Null for findings that are not about one query.
     *
     * @param array<int, \Lantern\Trace\Span> $spans
     * @return array{in_size: ?int, sql_hash: ?string}
     */
    private function queryFacts(Issue $issue, array $spans): array
    {
        $size = null;
        $hash = null;
        foreach ($issue->spanIds as $id) {
            $m = isset($spans[$id]) ? $spans[$id]->meta : [];
            if (($m['kind'] ?? null) === 'transaction' || ! is_string($m['sql'] ?? null)) {
                continue;
            }
            $hash ??= ClearedShapes::sqlHash($m['sql']);
            $size = max($size ?? 0, is_int($m['in_size'] ?? null) ? $m['in_size'] : 0);
        }

        return ['in_size' => $size, 'sql_hash' => $hash];
    }

    /** @return array<string, Expression> */
    private function issueUpdate(Connection $conn, bool $withApp = false, bool $withResolve = false, bool $withCode = false): array
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
            // the largest input the finding fired on stays the baseline a later clean run is judged against
            $keep = 'case when '.$cur('in_size').' is not null and ('.$new('in_size').' is null or '.$new('in_size').' < '.$cur('in_size').') then ';
            $update['sql_hash'] = new Expression($keep.$cur('sql_hash').' else '.$new('sql_hash').' end');
            $update['in_size'] = new Expression($keep.$cur('in_size').' else '.$new('in_size').' end');
            $update['resolved_at'] = new Expression('NULL');
        }
        if ($withCode) {
            // the code a finding last fired on is the baseline a later absence is judged against
            $update['site_hash'] = new Expression($new('site_hash'));
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
