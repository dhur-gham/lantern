<?php

declare(strict_types=1);

namespace Lantern\Agent;

use Lantern\Query\Fingerprinter;
use Lantern\Query\SqlInput;

/**
 * Finds where a SQL statement seen outside the app (an APM span, a database log) comes from in the code: the
 * stored query spans with the same shape, grouped by call site, and the issues recorded on them.
 */
final class SqlMatcher
{
    public const SIMILAR_MIN = 0.6;

    private const MAX_SIMILAR_GROUPS = 200;

    private const MAX_TRACE_IDS = 5;

    private readonly Fingerprinter $fingerprinter;

    public function __construct(private readonly Reader $reader, ?Fingerprinter $fingerprinter = null)
    {
        $this->fingerprinter = $fingerprinter ?? new Fingerprinter;
    }

    /**
     * @param array<string, mixed> $filters trace filters (scope, since)
     * @return array<string, mixed> lantern.match payload
     */
    public function match(string $sql, array $filters, int $traces, bool $includeResolved, int $limit): array
    {
        $scan = $this->scan($sql, $filters, $traces);

        $states = [];
        $matches = [];
        $hiddenResolved = 0;
        foreach ($scan['groups'] as $gk => $group) {
            $issues = [];
            foreach ($scan['issues'] as $key => $info) {
                if (! isset($info['groups'][$gk])) {
                    continue;
                }
                $state = $states[$key] ??= $this->issueState($key);
                if ($state === null) {
                    continue;
                }
                if ($state['status'] === 'resolved' && ! $includeResolved) {
                    $hiddenResolved++;

                    continue;
                }
                $issues[] = ['state' => $state, 'meta' => $info['meta']];
            }
            usort($issues, fn (array $a, array $b): int => [Findings::rank($b['state']['severity']), $b['state']['total_ms']] <=> [Findings::rank($a['state']['severity']), $a['state']['total_ms']]);
            $matches[] = $this->entry($group, $issues);
        }

        usort($matches, function (array $a, array $b): int {
            $rank = fn (array $m): array => [$m['match_type'] === 'exact' ? 1 : 0, isset($m['issue']) ? 1 : 0, $m['score'] ?? 1.0, $m['total_ms']];

            return $rank($b) <=> $rank($a);
        });

        $total = count($matches);
        if ($total === 0) {
            throw AgentException::noData(
                'No stored query matches this SQL ('.$scan['scanned'].' recent trace'.($scan['scanned'] === 1 ? '' : 's').' searched).',
                'Widen the search with --traces=500 or --since=, pass --all-apps to include other apps, or run the request that issues this query with LANTERN_ENABLED=true and LANTERN_STORAGE_DRIVER=database. Placeholders like %s, ?, $1 and :name all work.',
            );
        }

        $exact = $matches[0]['match_type'] === 'exact';
        $returned = array_slice($matches, 0, $limit);
        $payload = [
            'schema_version' => Findings::VERSION,
            'schema' => 'lantern.match',
            'generated_at' => now()->toIso8601String(),
            'shape' => $scan['shape'],
            'fingerprint' => $scan['fingerprint'],
            'match_type' => $exact ? 'exact' : 'similar',
            'traces_scanned' => $scan['scanned'],
            'total_matches' => $total,
            'returned' => count($returned),
            'truncated' => $total > count($returned),
            'matches' => $returned,
        ];
        if ($hiddenResolved > 0) {
            $payload['hidden_resolved'] = $hiddenResolved;
        }

        $first = $returned[0];
        if (isset($first['issue']['id'])) {
            $payload['next'] = 'php artisan lantern:issue '.$first['issue']['id'].' --format=json';
        } elseif (isset($first['last_trace_id'])) {
            $payload['next'] = 'php artisan lantern:trace '.$first['last_trace_id'].' --summary --format=json';
        }
        if (! $exact) {
            $payload['hint'] = 'No query with exactly this shape is stored; these are similar. The production statement may add columns or conditions, or come from another code path.';
        } elseif ($payload['truncated']) {
            $payload['hint'] = sprintf('%d of %d matches shown; raise --limit to see more.', count($returned), $total);
        }

        return $payload;
    }

    /**
     * Ids of the issues recorded on queries with exactly this shape.
     *
     * @param array<string, mixed> $filters
     * @return list<string>
     */
    public function issueKeys(string $sql, array $filters, int $traces): array
    {
        try {
            return array_keys($this->scan($sql, $filters, $traces)['issues']);
        } catch (AgentException $e) {
            if ($e->errorCode === AgentException::NO_DATA) {
                return [];
            }

            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{shape: string, fingerprint: string, scanned: int, groups: array<string, array<string, mixed>>, issues: array<string, array{meta: array<string, mixed>, groups: array<string, true>}>}
     */
    private function scan(string $sql, array $filters, int $traces): array
    {
        $prepared = SqlInput::prepare($sql);
        $shape = $this->fingerprinter->normalize($prepared);
        $fingerprint = $this->fingerprinter->fingerprint($prepared);
        $loose = SqlInput::loose($shape);

        $rows = $this->reader->traces($filters, $traces);
        if ($rows === []) {
            throw AgentException::noData(
                'No traces recorded yet.',
                isset($filters['app'])
                    ? 'No traces for app "'.$filters['app'].'". Pass --all-apps to include other apps, or make a request with LANTERN_ENABLED=true and LANTERN_STORAGE_DRIVER=database.'
                    : 'Make a request with LANTERN_ENABLED=true and LANTERN_STORAGE_DRIVER=database.',
            );
        }

        $groups = [];
        $issues = [];
        $classified = [];
        $similarGroups = 0;
        $scanned = 0;
        foreach ($rows as $row) {
            $trace = $this->reader->traceRow((string) $row['id']);
            if ($trace === null) {
                continue;
            }
            $scanned++;
            $spanGroup = [];
            foreach ($this->queryNodes($trace['tree'] ?? null) as $node) {
                $meta = (array) ($node['meta'] ?? []);
                $nodeShape = is_string($meta['normalized_sql'] ?? null) && $meta['normalized_sql'] !== ''
                    ? $meta['normalized_sql']
                    : $this->fingerprinter->normalize(SqlInput::prepare((string) ($node['name'] ?? '')));
                $fpMatch = is_string($meta['fingerprint'] ?? null) && $meta['fingerprint'] === $fingerprint;

                $key = $fpMatch ? 'fp' : $nodeShape;
                $classified[$key] ??= $fpMatch ? ['exact', 1.0] : $this->classify($nodeShape, $shape, $loose);
                [$type, $score] = $classified[$key];
                if ($type === null) {
                    continue;
                }

                $site = Findings::site($node['call_site'] ?? null);
                // by file and function, not line: traces recorded before the code moved describe the same call site (rows are newest first, so the first one seen carries the current line)
                $gk = $type.'|'.md5($nodeShape).'|'.($site['file'] ?? '-').'|'.($site['function'] ?? '');
                if (! isset($groups[$gk])) {
                    if ($type === 'similar' && ++$similarGroups > self::MAX_SIMILAR_GROUPS) {
                        continue;
                    }
                    $groups[$gk] = [
                        'match_type' => $type,
                        'score' => $score,
                        'sql_shape' => Redactor::sql($nodeShape),
                        'call_site' => $site,
                        'occurrences' => 0,
                        'total_ms' => 0.0,
                        'max_ms' => 0.0,
                        'trace_ids' => [],
                        'last_trace_id' => (string) $trace['id'],
                    ];
                }
                $ms = (float) ($node['duration_ms'] ?? 0);
                $groups[$gk]['occurrences']++;
                $groups[$gk]['total_ms'] += $ms;
                $groups[$gk]['max_ms'] = max($groups[$gk]['max_ms'], $ms);
                if (count($groups[$gk]['trace_ids']) < self::MAX_TRACE_IDS && ! in_array((string) $trace['id'], $groups[$gk]['trace_ids'], true)) {
                    $groups[$gk]['trace_ids'][] = (string) $trace['id'];
                }
                if ($type === 'exact' && isset($node['id'])) {
                    $spanGroup[(int) $node['id']] = $gk;
                }
            }

            if ($spanGroup === []) {
                continue;
            }
            foreach ((array) ($trace['issues'] ?? []) as $issue) {
                $issue = (array) $issue;
                foreach ((array) ($issue['span_ids'] ?? []) as $sid) {
                    if (! isset($spanGroup[(int) $sid])) {
                        continue;
                    }
                    $key = Findings::keyOf($issue);
                    $issues[$key] ??= ['meta' => (array) ($issue['meta'] ?? []), 'groups' => []];
                    $issues[$key]['groups'][$spanGroup[(int) $sid]] = true;
                }
            }
        }

        return ['shape' => Redactor::sql($shape), 'fingerprint' => $fingerprint, 'scanned' => $scanned, 'groups' => $groups, 'issues' => $issues];
    }

    /** @return array{0: ?string, 1: float} */
    private function classify(string $nodeShape, string $shape, string $loose): array
    {
        if ($nodeShape === $shape) {
            return ['exact', 1.0];
        }
        $nodeLoose = SqlInput::loose($nodeShape);
        if ($nodeLoose === $loose) {
            return ['exact', 1.0];
        }
        if (strtok($nodeLoose, ' ') !== strtok($loose, ' ')) {
            return [null, 0.0];
        }
        $score = SqlInput::similarity($loose, $nodeLoose);

        return $score >= self::SIMILAR_MIN ? ['similar', $score] : [null, 0.0];
    }

    /**
     * @param array<string, mixed>|null $node
     * @return \Generator<int, array<string, mixed>>
     */
    private function queryNodes(?array $node): \Generator
    {
        if ($node === null) {
            return;
        }
        if (($node['type'] ?? '') === 'query' && (($node['meta']['kind'] ?? null) !== 'transaction')) {
            yield $node;
        }
        foreach ((array) ($node['children'] ?? []) as $child) {
            yield from $this->queryNodes((array) $child);
        }
    }

    /** @return array{id: string, type: string, severity: string, status: string, title: string, suggestion: ?string, total_ms: float}|null */
    private function issueState(string $key): ?array
    {
        try {
            $f = Findings::fromRow($this->reader->issueRow($key));
        } catch (AgentException) {
            return null;
        }

        return [
            'id' => $f['id'],
            'type' => $f['type'],
            'severity' => $f['severity'],
            'status' => $f['status'],
            'title' => $f['title'],
            'suggestion' => $f['suggestion'],
            'total_ms' => (float) $f['total_ms'],
        ];
    }

    /**
     * @param array<string, mixed> $group
     * @param list<array{state: array<string, mixed>, meta: array<string, mixed>}> $issues best first
     * @return array<string, mixed>
     */
    private function entry(array $group, array $issues): array
    {
        $out = [
            'match_type' => $group['match_type'],
            'sql_shape' => $group['sql_shape'],
            'call_site' => $group['call_site'],
            'occurrences' => $group['occurrences'],
            'total_ms' => round($group['total_ms'], 2),
            'max_ms' => round($group['max_ms'], 2),
            'avg_ms' => round($group['total_ms'] / max(1, $group['occurrences']), 2),
            'last_trace_id' => $group['last_trace_id'],
            'trace_ids' => $group['trace_ids'],
        ];
        if ($group['match_type'] === 'similar') {
            $out['score'] = $group['score'];
        }
        if ($issues !== []) {
            $best = $issues[0];
            $out['issue'] = array_filter([
                'id' => $best['state']['id'],
                'type' => $best['state']['type'],
                'severity' => $best['state']['severity'],
                'status' => $best['state']['status'],
                'title' => $best['state']['title'],
                'suggestion' => $best['state']['suggestion'],
            ], fn ($v) => $v !== null && $v !== '');
            $out['issue_ids'] = array_map(fn (array $i): string => $i['state']['id'], $issues);
            $plan = Reader::planEvidence($best['meta']);
            if (is_string($best['meta']['plan'] ?? null) && $best['meta']['plan'] !== '') {
                $plan = ['summary' => $best['meta']['plan']] + $plan;
            }
            if ($plan !== []) {
                $out['plan'] = $plan;
            }
        }

        return array_filter($out, fn ($v) => $v !== null);
    }
}
