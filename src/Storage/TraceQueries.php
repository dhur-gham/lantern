<?php

declare(strict_types=1);

namespace Lantern\Storage;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Lantern\Instrumentation\Query\QuerySuspend;

final class TraceQueries
{
    private const LIST_COLUMNS = ['id', 'started_at', 'duration_ms', 'status', 'method', 'path', 'route', 'query_count', 'query_total_ms', 'issue_count', 'peak_memory'];

    private const ISSUE_SORTS = ['total_ms', 'occurrences', 'last_seen'];

    private bool $resolvedColumn = false;

    public function __construct(
        private readonly ConnectionResolverInterface $db,
        private readonly ?string $connection = null,
    ) {
    }

    /**
     * `path` (dashboard) is a substring match; `path_is` and `route_is` take Str::is style patterns where `*` is a
     * wildcard. `app` keeps rows tagged with that app plus untagged (older) rows; `since` is a Carbon instance.
     *
     * @param array{status?: mixed, min_ms?: mixed, has_issues?: mixed, path?: mixed, path_is?: mixed, route_is?: mixed, since?: mixed, app?: mixed} $filters
     * @return list<array<string, mixed>>
     */
    public function traces(array $filters = [], int $limit = 50): array
    {
        return QuerySuspend::run(function () use ($filters, $limit): array {
            $q = $this->conn()->table('lantern_traces')->select(self::LIST_COLUMNS);

            $this->traceFilters($q, $filters);
            if (($filters['min_ms'] ?? '') !== '') {
                $q->where('duration_ms', '>=', (int) $filters['min_ms']);
            }
            if (filter_var($filters['has_issues'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $q->where('issue_count', '>', 0);
            }
            if (($filters['path'] ?? '') !== '') {
                $q->where('path', 'like', '%'.addcslashes((string) $filters['path'], '%_\\').'%');
            }

            return $q->orderByDesc('started_at')->limit($this->limit($limit))->get()
                ->map(fn ($r): array => $this->withIsoDates((array) $r))->all();
        });
    }

    /** @param array<string, mixed> $filters */
    private function traceFilters(Builder $q, array $filters): void
    {
        if (($filters['status'] ?? '') !== '') {
            $status = (string) $filters['status'];
            preg_match('/^[1-5]xx$/i', $status) === 1
                ? $q->where('status', 'like', $status[0].'%')
                : $q->where('status', $status);
        }
        if (($filters['path_is'] ?? '') !== '') {
            $pattern = (string) $filters['path_is'];
            $this->wildcard($q, 'path', $pattern[0] === '*' ? $pattern : '/'.ltrim($pattern, '/'));
        }
        if (($filters['route_is'] ?? '') !== '') {
            $this->wildcard($q, 'route', (string) $filters['route_is']);
        }
        if (($filters['since'] ?? null) instanceof \DateTimeInterface) {
            $q->where('started_at', '>=', $filters['since']->format('Y-m-d H:i:s'));
        }
        $this->appFilter($q, $filters);
    }

    /** @param array<string, mixed> $filters */
    private function appFilter(Builder $q, array $filters): void
    {
        if (($filters['app'] ?? '') !== '') {
            $app = (string) $filters['app'];
            $q->where(fn (Builder $w) => $w->where('app', $app)->orWhereNull('app'));
        }
    }

    /** Str::is semantics: `*` matches anything, everything else is literal. */
    private function wildcard(Builder $q, string $column, string $pattern): void
    {
        $like = str_replace(['!', '%', '_', '*'], ['!!', '!%', '!_', '%'], $pattern);
        $q->whereRaw($q->getGrammar()->wrap($column)." like ? escape '!'", [$like]);
    }

    /** @return array<string, mixed>|null */
    public function trace(string $id): ?array
    {
        return QuerySuspend::run(function () use ($id): ?array {
            $row = $this->conn()->table('lantern_traces')->where('id', $id)->first();
            if ($row === null) {
                return null;
            }

            $row = $this->withIsoDates((array) $row);
            $decoded = $this->decode((string) $row['payload']);
            if ($decoded === null && trim((string) $row['payload']) !== '') {
                $row['error'] = 'payload_unreadable';
            }
            $payload = $decoded ?? [];
            $row['payload'] = $decoded;
            $row['tree'] = $payload['root'] ?? null;
            $row['issues'] = array_values((array) ($payload['meta']['issues'] ?? []));

            return $row;
        });
    }

    /**
     * `path_is`, `route_is` and `status` narrow to issues whose latest trace matches; `app` as for traces. Resolved
     * issues are hidden unless `resolved` is `include` (both) or `only`.
     *
     * @param array{type?: mixed, severity?: mixed, sort?: mixed, path_is?: mixed, route_is?: mixed, status?: mixed, app?: mixed, resolved?: mixed} $filters
     * @return list<array<string, mixed>>
     */
    public function issues(array $filters = [], int $limit = 50): array
    {
        return QuerySuspend::run(function () use ($filters, $limit): array {
            $q = $this->conn()->table('lantern_issues');

            if ($this->hasResolvedColumn()) {
                match ((string) ($filters['resolved'] ?? '')) {
                    'include' => null,
                    'only' => $q->whereNotNull('resolved_at'),
                    default => $q->whereNull('resolved_at'),
                };
            } elseif (($filters['resolved'] ?? '') === 'only') {
                return [];
            }

            if (($filters['type'] ?? '') !== '') {
                $q->where('type', (string) $filters['type']);
            }
            if (($filters['severity'] ?? '') !== '') {
                $q->where('severity', (string) $filters['severity']);
            }
            $viaTrace = array_intersect_key($filters, array_flip(['path_is', 'route_is', 'status']));
            if (array_filter($viaTrace, fn ($v) => $v !== null && $v !== '') !== []) {
                $q->whereIn('last_trace_id', function (Builder $sub) use ($viaTrace): void {
                    $sub->select('id')->from('lantern_traces');
                    $this->traceFilters($sub, $viaTrace);
                });
            }
            $this->appFilter($q, $filters);
            $sort = in_array($filters['sort'] ?? null, self::ISSUE_SORTS, true) ? $filters['sort'] : 'total_ms';

            return $q->orderByDesc($sort)->limit($this->limit($limit))->get()
                ->map(fn ($r): array => $this->issueRow((array) $r))->all();
        });
    }

    /** @return array<string, mixed>|null */
    public function issue(string $key): ?array
    {
        return QuerySuspend::run(function () use ($key): ?array {
            $row = $this->conn()->table('lantern_issues')->where('issue_key', $key)->first();

            return $row === null ? null : $this->issueRow((array) $row);
        });
    }

    /**
     * Which of these issue keys are resolved.
     *
     * @param list<string> $keys
     * @return list<string>
     */
    public function resolvedKeys(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        return QuerySuspend::run(function () use ($keys): array {
            if (! $this->hasResolvedColumn()) {
                return [];
            }
            $found = [];
            foreach (array_chunk(array_values(array_unique($keys)), 200) as $chunk) {
                array_push($found, ...$this->conn()->table('lantern_issues')->whereIn('issue_key', $chunk)->whereNotNull('resolved_at')->pluck('issue_key')->map(fn ($k): string => (string) $k)->all());
            }

            return $found;
        });
    }

    /** False until the resolved-state migration has run; only a positive answer is remembered. */
    private function hasResolvedColumn(): bool
    {
        return $this->resolvedColumn = $this->resolvedColumn || $this->conn()->getSchemaBuilder()->hasColumn('lantern_issues', 'resolved_at');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function issueRow(array $row): array
    {
        if (is_string($row['call_site'] ?? null)) {
            $row['call_site'] = json_decode($row['call_site'], true);
        }

        return $this->withIsoDates($row, ['first_seen', 'last_seen', 'resolved_at']);
    }

    /**
     * Stored timestamps carry no zone (they are written in the app timezone); emit ISO-8601 with an offset
     * so browsers don't read them as local time.
     *
     * @param array<string, mixed> $row
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    private function withIsoDates(array $row, array $keys = ['started_at', 'created_at']): array
    {
        $zone = (string) config('app.timezone', 'UTC');
        foreach ($keys as $key) {
            if (is_string($row[$key] ?? null) && $row[$key] !== '') {
                try {
                    $row[$key] = \Illuminate\Support\Carbon::parse($row[$key], $zone)->toIso8601String();
                } catch (\Throwable) {
                }
            }
        }

        return $row;
    }

    private function limit(int $limit): int
    {
        return min(max(1, $limit), 500);
    }

    private function conn(): Connection
    {
        /** @var Connection */
        return $this->db->connection($this->connection ?: null);
    }

    /** @return array<string, mixed>|null */
    private function decode(string $payload): ?array
    {
        return PayloadCodec::decode($payload);
    }
}
