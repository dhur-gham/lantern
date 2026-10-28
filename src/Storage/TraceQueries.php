<?php

declare(strict_types=1);

namespace Lantern\Storage;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Lantern\Instrumentation\Query\QuerySuspend;

final class TraceQueries
{
    private const LIST_COLUMNS = ['id', 'started_at', 'duration_ms', 'status', 'method', 'path', 'route', 'query_count', 'query_total_ms', 'issue_count', 'peak_memory'];

    private const ISSUE_SORTS = ['total_ms', 'occurrences', 'last_seen'];

    public function __construct(
        private readonly ConnectionResolverInterface $db,
        private readonly ?string $connection = null,
    ) {
    }

    /**
     * @param array{status?: mixed, min_ms?: mixed, has_issues?: mixed, path?: mixed} $filters
     * @return list<array<string, mixed>>
     */
    public function traces(array $filters = [], int $limit = 50): array
    {
        return QuerySuspend::run(function () use ($filters, $limit): array {
            $q = $this->conn()->table('lantern_traces')->select(self::LIST_COLUMNS);

            if (($filters['status'] ?? '') !== '') {
                $status = (string) $filters['status'];
                preg_match('/^[1-5]xx$/i', $status) === 1
                    ? $q->where('status', 'like', $status[0].'%')
                    : $q->where('status', $status);
            }
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
     * @param array{type?: mixed, severity?: mixed, sort?: mixed} $filters
     * @return list<array<string, mixed>>
     */
    public function issues(array $filters = [], int $limit = 50): array
    {
        return QuerySuspend::run(function () use ($filters, $limit): array {
            $q = $this->conn()->table('lantern_issues');

            if (($filters['type'] ?? '') !== '') {
                $q->where('type', (string) $filters['type']);
            }
            if (($filters['severity'] ?? '') !== '') {
                $q->where('severity', (string) $filters['severity']);
            }
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
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function issueRow(array $row): array
    {
        if (is_string($row['call_site'] ?? null)) {
            $row['call_site'] = json_decode($row['call_site'], true);
        }

        return $this->withIsoDates($row, ['first_seen', 'last_seen']);
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
