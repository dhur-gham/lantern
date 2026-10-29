<?php

declare(strict_types=1);

namespace Lantern\Console;

use Lantern\Agent\AgentCommand;
use Lantern\Agent\Findings;
use Lantern\Agent\Reader;
use Lantern\Detection\Issue;

final class DiffCommand extends AgentCommand
{
    protected $signature = 'lantern:diff
        {before : Trace id before the change (or "last")}
        {after : Trace id after the change (or "last")}
        {--format=json : table, json, ndjson or markdown}
        {--pretty : Pretty-print json}';

    protected $description = 'Compare two traces: resolved and new issues, query count and time deltas';

    protected function perform(Reader $reader): int
    {
        $a = $reader->trace((string) $this->argument('before'));
        $b = $reader->trace((string) $this->argument('after'));

        // findings are matched by shape (SQL fingerprint + call-site file and function), so moving a query to
        // another line is a change in place, not a resolved finding plus a new one
        $fa = $this->byShape($reader->traceFindings($a));
        $fb = $this->byShape($reader->traceFindings($b));
        $resolved = array_values(array_diff_key($fa, $fb));
        $new = array_values(array_diff_key($fb, $fa));

        $changed = [];
        $unchanged = [];
        foreach (array_intersect_key($fb, $fa) as $shape => $after) {
            $row = $this->compare($fa[$shape], $after);
            $changed[] = $row;
            if ($row['status'] === 'unchanged') {
                $unchanged[] = $after;
            }
        }

        // a plan-based finding on a query shape that also ran in BEFORE crossed a threshold, it is not new
        $before = $this->missingIndexIds($a);
        $threshold = array_values(array_filter($new, fn (array $f): bool => ($f['type'] ?? '') === 'missing_index' && isset($before[$f['id']])));
        $new = array_values(array_filter($new, fn (array $f): bool => ! in_array($f, $threshold, true)));

        $improved = array_values(array_filter($changed, fn (array $c): bool => $c['status'] === 'improved'));
        $regressed = array_values(array_filter($changed, fn (array $c): bool => $c['status'] === 'regressed'));

        $queryMs = $this->delta((float) ($a['query_total_ms'] ?? 0), (float) ($b['query_total_ms'] ?? 0));
        $durationMs = $this->delta((float) ($a['duration_ms'] ?? 0), (float) ($b['duration_ms'] ?? 0));
        $queries = $this->delta($this->queryCount($a), $this->queryCount($b));
        $score = $this->weight($new) + $this->weight($regressed) - $this->weight($resolved)
            - $this->weight(array_map(fn (array $c): array => ['severity' => $c['before']['severity']], $improved));
        $verdict = match (true) {
            $score > 0 || ($score === 0 && $queries['delta'] > 0) => 'regressed',
            $score < 0 || ($score === 0 && $queries['delta'] < 0) => 'improved',
            default => 'same',
        };

        $this->emit([
            'schema_version' => Findings::VERSION,
            'schema' => 'lantern.diff',
            'verdict' => $verdict,
            'before' => $this->side($a),
            'after' => $this->side($b),
            'dimensions' => [
                'queries' => $this->direction($queries['delta']),
                'query_ms' => $this->direction($queryMs['delta'], (float) $queryMs['before']),
                'duration_ms' => $this->direction($durationMs['delta'], (float) $durationMs['before']),
                'findings' => ['resolved' => count($resolved), 'new' => count($new), 'improved' => count($improved), 'regressed' => count($regressed), 'verdict' => $this->direction($score)],
            ],
            'queries' => $queries,
            'query_ms' => $queryMs,
            'duration_ms' => $durationMs,
            'resolved' => $resolved,
            'new' => $new,
            'changed' => $changed,
            'threshold' => $threshold,
            'unchanged' => $unchanged,
        ]);

        return self::SUCCESS;
    }

    /**
     * One entry per shape; findings sharing a shape (the same query from two lines of one function) are merged.
     *
     * @param list<array<string, mixed>> $findings
     * @return array<string, array<string, mixed>>
     */
    private function byShape(array $findings): array
    {
        $out = [];
        foreach ($findings as $f) {
            $shape = (string) ($f['shape'] ?? $f['id']);
            if (! isset($out[$shape])) {
                $out[$shape] = $f;

                continue;
            }
            $out[$shape]['count'] = (int) $out[$shape]['count'] + (int) ($f['count'] ?? 0);
            $out[$shape]['total_ms'] = round((float) $out[$shape]['total_ms'] + (float) ($f['total_ms'] ?? 0), 2);
            if (Issue::severityRank((string) ($f['severity'] ?? '')) > Issue::severityRank((string) $out[$shape]['severity'])) {
                $out[$shape]['severity'] = $f['severity'];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array<string, mixed>
     */
    private function compare(array $before, array $after): array
    {
        $beforeMs = (float) ($before['total_ms'] ?? 0);
        $afterMs = (float) ($after['total_ms'] ?? 0);
        $countDelta = (int) ($after['count'] ?? 0) - (int) ($before['count'] ?? 0);
        $rank = Issue::severityRank((string) ($after['severity'] ?? '')) - Issue::severityRank((string) ($before['severity'] ?? ''));

        $status = $this->direction($afterMs - $beforeMs, $beforeMs);
        if ($status === 'same') {
            $status = $this->direction($countDelta);
        }
        if ($status === 'same') {
            $status = $this->direction($rank);
        }

        return [
            'id' => $after['id'],
            'before_id' => $before['id'],
            'type' => $after['type'],
            'severity' => $after['severity'],
            'title' => $after['title'],
            'call_site' => $after['call_site'],
            'status' => $status === 'same' ? 'unchanged' : $status,
            'before' => ['severity' => $before['severity'], 'count' => (int) ($before['count'] ?? 0), 'total_ms' => $beforeMs, 'call_site' => $before['call_site']],
            'after' => ['severity' => $after['severity'], 'count' => (int) ($after['count'] ?? 0), 'total_ms' => $afterMs, 'call_site' => $after['call_site']],
        ];
    }

    /** @param list<array<string, mixed>> $findings severity-weighted, a critical outweighs a warning */
    private function weight(array $findings): int
    {
        return array_sum(array_map(fn (array $f): int => 1 + Issue::severityRank((string) ($f['severity'] ?? '')) * 2, $findings));
    }

    /** Milliseconds only count as better or worse beyond max(tolerance_pct of before, tolerance_ms). */
    private function direction(int|float $delta, ?float $before = null): string
    {
        if ($before !== null) {
            $pct = (float) config('lantern.diff.tolerance_pct', 20);
            $min = (float) config('lantern.diff.tolerance_ms', 5);
            if (abs($delta) <= max($before * $pct / 100, $min)) {
                return 'same';
            }
        }

        return $delta < 0 ? 'improved' : ($delta > 0 ? 'regressed' : 'same');
    }

    /**
     * @param array<string, mixed> $trace
     * @return array<string, true> missing_index keys for every query shape in the trace
     */
    private function missingIndexIds(array $trace): array
    {
        $ids = [];
        $walk = function (?array $node) use (&$walk, &$ids): void {
            if ($node === null) {
                return;
            }
            $fp = $node['meta']['fingerprint'] ?? null;
            if (($node['type'] ?? '') === 'query' && is_string($fp)) {
                $ids[Issue::key(Issue::TYPE_MISSING_INDEX, $fp)] = true;
            }
            foreach ((array) ($node['children'] ?? []) as $child) {
                $walk((array) $child);
            }
        };
        $walk(is_array($trace['tree'] ?? null) ? $trace['tree'] : null);

        return $ids;
    }

    /** @param array<string, mixed> $trace */
    private function queryCount(array $trace): int
    {
        return (int) ($trace['query_count'] ?? 0);
    }

    /**
     * @param array<string, mixed> $trace
     * @return array<string, mixed>
     */
    private function side(array $trace): array
    {
        return ['trace_id' => (string) $trace['id'], 'route' => $trace['route'] ?? null, 'path' => $trace['path'] ?? null, 'issue_count' => count((array) ($trace['issues'] ?? []))];
    }

    /** @return array{before: int|float, after: int|float, delta: int|float} */
    private function delta(int|float $before, int|float $after): array
    {
        $d = $after - $before;

        return ['before' => is_float($before) ? round($before, 2) : $before, 'after' => is_float($after) ? round($after, 2) : $after, 'delta' => is_float($d) ? round($d, 2) : $d];
    }
}
