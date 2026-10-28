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

        $fa = $this->byId($reader->traceFindings($a));
        $fb = $this->byId($reader->traceFindings($b));
        $resolved = array_values(array_diff_key($fa, $fb));
        $new = array_values(array_diff_key($fb, $fa));
        $unchanged = array_values(array_intersect_key($fb, $fa));

        $queryMs = $this->delta((float) ($a['query_total_ms'] ?? 0), (float) ($b['query_total_ms'] ?? 0));
        $queries = $this->delta($this->queryCount($a), $this->queryCount($b));
        $score = $this->weight($new) - $this->weight($resolved);
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
                'query_ms' => $this->direction($queryMs['delta']),
                'findings' => ['resolved' => count($resolved), 'new' => count($new), 'verdict' => $this->direction($score)],
            ],
            'queries' => $queries,
            'query_ms' => $queryMs,
            'duration_ms' => $this->delta((float) ($a['duration_ms'] ?? 0), (float) ($b['duration_ms'] ?? 0)),
            'resolved' => $resolved,
            'new' => $new,
            'unchanged' => $unchanged,
        ]);

        return self::SUCCESS;
    }

    /**
     * @param list<array<string, mixed>> $findings
     * @return array<string, array<string, mixed>>
     */
    private function byId(array $findings): array
    {
        $out = [];
        foreach ($findings as $f) {
            $out[$f['id']] = $f;
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $findings severity-weighted, a critical outweighs a warning */
    private function weight(array $findings): int
    {
        return array_sum(array_map(fn (array $f): int => 1 + Issue::severityRank((string) ($f['severity'] ?? '')) * 2, $findings));
    }

    private function direction(int|float $delta): string
    {
        return $delta < 0 ? 'improved' : ($delta > 0 ? 'regressed' : 'same');
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
