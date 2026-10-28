<?php

declare(strict_types=1);

namespace Lantern\Storage;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Expression;
use Lantern\Detection\Issue;
use Lantern\Instrumentation\Query\QuerySuspend;
use Lantern\Trace\Trace;

final class DatabaseTraceStore implements TraceStore
{
    private const JSON_FLAGS = PayloadCodec::JSON_FLAGS;

    public function __construct(
        private readonly ConnectionResolverInterface $db,
        private readonly ?string $connection = null,
        private readonly bool $compress = false,
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

            $conn->transaction(function () use ($conn, $row, $issues, $startedAt): void {
                $conn->table('lantern_traces')->insert($row);

                $rows = $this->issueRows($issues, $startedAt, (string) $row['id']);
                if ($rows !== []) {
                    $conn->table('lantern_issues')->upsert($rows, ['issue_key'], $this->issueUpdate($conn));
                }
            });
        });
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
    private function issueRows(array $issues, string $now, string $traceId): array
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
            ];
        }

        return array_values($rows);
    }

    /** @return array<string, Expression> */
    private function issueUpdate(Connection $conn): array
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

        return [
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
