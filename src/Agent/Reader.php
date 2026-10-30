<?php

declare(strict_types=1);

namespace Lantern\Agent;

use Illuminate\Support\Carbon;
use Lantern\Storage\TraceQueries;
use Throwable;

final class Reader
{
    public const MAX_ROWS = 500;

    public function __construct(private readonly TraceQueries $queries)
    {
    }

    public static function since(?string $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (preg_match('/^(\d+)\s*([smhdw])$/i', $value, $m) === 1) {
            $unit = ['s' => 'seconds', 'm' => 'minutes', 'h' => 'hours', 'd' => 'days', 'w' => 'weeks'][strtolower($m[2])];

            return Carbon::now()->sub($unit, (int) $m[1]);
        }
        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            throw AgentException::usage('Invalid --since value: '.$value, 'Use a duration like 30m, 24h, 7d or a date like 2026-01-31.');
        }
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: list<array<string, mixed>>, 1: int, 2: bool} page of findings, total matching, whether total is exact
     */
    public function issuesPage(array $filters, int $limit, int $offset, ?Carbon $since): array
    {
        $rows = $this->queries->issues($filters, self::MAX_ROWS);
        $exact = count($rows) < self::MAX_ROWS;
        if ($since !== null) {
            $rows = array_values(array_filter($rows, fn (array $r): bool => isset($r['last_seen']) && Carbon::parse($r['last_seen'])->greaterThanOrEqualTo($since)));
        }

        return [array_map(Findings::fromRow(...), array_slice($rows, $offset, $limit)), count($rows), $exact];
    }

    /** @return array<string, mixed> */
    public function issueRow(string $key): array
    {
        $row = $this->queries->issue($key);
        if ($row === null && strlen($key) >= 6) {
            $matches = array_values(array_filter(
                $this->queries->issues(['resolved' => 'include'], self::MAX_ROWS),
                fn (array $r): bool => str_starts_with((string) $r['issue_key'], $key),
            ));
            if (count($matches) > 1) {
                throw AgentException::usage('Issue key prefix is ambiguous: '.$key, 'Pass more characters of the id.');
            }
            $row = $matches[0] ?? null;
        }
        if ($row === null) {
            throw AgentException::notFound('Issue not found: '.$key, strlen($key) < 6
                ? 'An id prefix needs at least 6 characters. List ids with: php artisan lantern:issues --format=json'
                : 'List ids with: php artisan lantern:issues --format=json');
        }

        return $row;
    }

    /**
     * Full finding with redacted evidence taken from the trace that last recorded it.
     *
     * @return array<string, mixed>
     */
    public function finding(string $key, bool $withBindings = false): array
    {
        $row = $this->issueRow($key);
        $finding = Findings::fromRow($row);
        $finding['evidence'] = $this->evidence($finding, $withBindings);

        return $finding;
    }

    /**
     * @param array<string, mixed> $finding
     * @return array<string, mixed>
     */
    private function evidence(array $finding, bool $withBindings): array
    {
        $evidence = ['trace_id' => $finding['related_trace_ids'][0] ?? null, 'sample_sql' => null, 'sql_shape' => null, 'bindings' => null, 'parent' => null, 'avg_ms' => null];
        if ($evidence['trace_id'] === null) {
            return $evidence;
        }
        $trace = $this->queries->trace((string) $evidence['trace_id']);
        if ($trace === null) {
            return $evidence;
        }

        foreach ($trace['issues'] ?? [] as $i) {
            if (Findings::keyOf((array) $i) !== $finding['id']) {
                continue;
            }
            $meta = (array) ($i['meta'] ?? []);
            $evidence['avg_ms'] = isset($meta['avg_ms']) ? round((float) $meta['avg_ms'], 2) : null;
            $evidence['parent'] = $meta['parent'] ?? null;
            $evidence += self::planEvidence($meta);
            $sample = $meta['sample_sql'] ?? null;
            $ids = array_map('intval', (array) ($i['span_ids'] ?? []));
            $node = $ids === [] ? null : $this->findNode($trace['tree'] ?? null, $ids);
            $nodeMeta = (array) ($node['meta'] ?? []);
            $sample ??= $nodeMeta['sql'] ?? null;
            if (is_string($sample) && $sample !== '') {
                $evidence['sample_sql'] = Redactor::sql($sample);
                $evidence['sql_shape'] = Redactor::sql((string) ($nodeMeta['normalized_sql'] ?? $sample));
                if ($evidence['sample_sql'] === $evidence['sql_shape']) {
                    $evidence['sample_sql'] = null;
                }
            }
            if (isset($nodeMeta['bindings']) && is_array($nodeMeta['bindings'])) {
                $evidence['bindings'] = Redactor::bindings($nodeMeta['bindings'], $withBindings);
            }
            break;
        }

        return $evidence;
    }

    /**
     * Plan-derived fields of an issue's meta; each is present only when known. `read_ratio` is rows read per row
     * returned and needs an actual returned count.
     *
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private static function planEvidence(array $meta): array
    {
        $out = [];
        if (isset($meta['rows_read']) && is_numeric($meta['rows_read'])) {
            $out['rows_read'] = (int) $meta['rows_read'];
            if (isset($meta['rows_returned']) && is_numeric($meta['rows_returned']) && (int) $meta['rows_returned'] > 0) {
                $out['rows_returned'] = (int) $meta['rows_returned'];
                $out['read_ratio'] = round($out['rows_read'] / $out['rows_returned'], 1);
            } elseif (is_string($meta['rows_returned_why'] ?? null) && in_array($meta['rows_returned_why'], ['unsupported', 'budget', 'no_bindings', 'timeout', 'parse_failed', 'failed'], true)) {
                $out['rows_returned_why'] = $meta['rows_returned_why'];
            }
        }
        foreach (['tables', 'projected'] as $key) {
            if (is_array($meta[$key] ?? null) && $meta[$key] !== []) {
                $out[$key] = $meta[$key];
            }
        }
        if (is_string($meta['schema_note'] ?? null) && $meta['schema_note'] !== '') {
            $out['schema_note'] = $meta['schema_note'];
        }

        return $out;
    }

    /**
     * @param array<string, mixed>|null $node
     * @param list<int> $ids
     * @return array<string, mixed>|null
     */
    private function findNode(?array $node, array $ids): ?array
    {
        if ($node === null) {
            return null;
        }
        if (isset($node['id']) && in_array((int) $node['id'], $ids, true)) {
            return $node;
        }
        foreach ((array) ($node['children'] ?? []) as $child) {
            if (($found = $this->findNode((array) $child, $ids)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Resolves an id, 'last', or the slowest of the recent traces. `$filters` narrow the latest/slowest lookup.
     *
     * @param array<string, mixed> $filters
     */
    public function trace(?string $id, bool $slow = false, array $filters = []): array
    {
        if ($id === null || $id === '' || $id === 'last' || $id === 'latest') {
            $rows = $this->queries->traces($filters, $slow ? 100 : 1);
            if ($rows === []) {
                $narrowed = array_diff_key($filters, ['app' => 1]) !== [];
                throw AgentException::noData(
                    $narrowed ? 'No traces match these filters.' : 'No traces recorded yet.',
                    $narrowed
                        ? 'Loosen --path, --route, --status or --since, or pass --all-apps to include other apps.'
                        : (isset($filters['app'])
                            ? 'No traces for app "'.$filters['app'].'". Pass --all-apps to include other apps, or make a request with LANTERN_ENABLED=true and LANTERN_STORAGE_DRIVER=database.'
                            : 'Make a request with LANTERN_ENABLED=true and LANTERN_STORAGE_DRIVER=database.'),
                );
            }
            if ($slow) {
                usort($rows, fn (array $a, array $b): int => ($b['duration_ms'] ?? 0) <=> ($a['duration_ms'] ?? 0));
            }
            $id = (string) $rows[0]['id'];
        }
        $trace = $this->queries->trace($id);
        if ($trace === null) {
            throw AgentException::notFound('Trace not found: '.$id, 'Use a trace id from lantern:issue (evidence.trace_id) or lantern:issues (related_trace_ids), or the keyword last.');
        }

        return $trace;
    }

    /** @return list<array<string, mixed>> */
    public function traces(array $filters, int $limit): array
    {
        return $this->queries->traces($filters, $limit);
    }

    /** @return list<array<string, mixed>> */
    public function issueRows(int $limit = self::MAX_ROWS): array
    {
        return $this->queries->issues([], $limit);
    }

    /**
     * @param list<string> $ids issue ids
     * @return list<string> those that are resolved
     */
    public function resolvedIds(array $ids): array
    {
        return $this->queries->resolvedKeys($ids);
    }

    /** @return array<string, mixed> */
    public function traceRow(string $id): ?array
    {
        return $this->queries->trace($id);
    }

    /**
     * @param array<string, mixed> $trace
     * @return list<array<string, mixed>> issues embedded in the trace, as compact findings keyed by id
     */
    public function traceFindings(array $trace): array
    {
        $out = [];
        foreach ((array) ($trace['issues'] ?? []) as $i) {
            $b = Findings::brief((array) $i);
            $out[$b['id']] = $b;
        }

        return array_values($out);
    }

    /**
     * @param array<string, mixed> $trace
     * @return array<string, mixed>
     */
    public function summary(array $trace, int $top = 10): array
    {
        $nodes = $this->flatNodes($trace['tree'] ?? null);
        $queryNodes = array_filter($nodes, fn (array $n): bool => ($n['type'] ?? '') === 'query' && (($n['meta']['kind'] ?? null) !== 'transaction'));
        $issueBySpan = [];
        $planByIssue = [];
        foreach ((array) ($trace['issues'] ?? []) as $i) {
            $key = Findings::keyOf((array) $i);
            $plan = array_intersect_key(self::planEvidence((array) ($i['meta'] ?? [])), ['rows_read' => 1, 'read_ratio' => 1]);
            if ($plan !== []) {
                $planByIssue[$key] = $plan;
            }
            foreach ((array) ($i['span_ids'] ?? []) as $sid) {
                $issueBySpan[(int) $sid] = $key;
            }
        }

        $groups = [];
        foreach ($queryNodes as $n) {
            $site = Findings::site($n['call_site'] ?? null);
            $shape = Redactor::sql((string) ($n['meta']['normalized_sql'] ?? $n['name'] ?? ''));
            $k = ($n['meta']['fingerprint'] ?? md5($shape)).'|'.Findings::siteString($site);
            $groups[$k] ??= ['sql_shape' => $shape, 'count' => 0, 'total_ms' => 0.0, 'call_site' => $site, 'issue_id' => null];
            $groups[$k]['count']++;
            $groups[$k]['total_ms'] += (float) ($n['duration_ms'] ?? 0);
            if (isset($n['id'], $issueBySpan[(int) $n['id']])) {
                $groups[$k]['issue_id'] = $issueBySpan[(int) $n['id']];
            }
        }
        $groups = array_values($groups);
        foreach ($groups as $n => $g) {
            $groups[$n] += $planByIssue[$g['issue_id'] ?? ''] ?? [];
        }
        usort($groups, fn (array $a, array $b): int => [$b['total_ms'], $b['rows_read'] ?? 0] <=> [$a['total_ms'], $a['rows_read'] ?? 0]);
        $groupCount = count($groups);
        $groups = array_map(function (array $g): array {
            $g['total_ms'] = round($g['total_ms'], 2);

            return array_filter($g, fn ($v) => $v !== null);
        }, array_slice($groups, 0, $top));

        $queryCount = isset($trace['query_count']) ? (int) $trace['query_count'] : count($queryNodes);
        $findings = array_map(fn (array $f): array => $f + ($planByIssue[$f['id']] ?? []), $this->traceFindings($trace));
        $summary = [
            'schema_version' => Findings::VERSION,
            'schema' => 'lantern.trace.summary',
            'trace_id' => (string) $trace['id'],
            'started_at' => $trace['started_at'] ?? null,
            'method' => $trace['method'] ?? null,
            'path' => $trace['path'] ?? null,
            'route' => $trace['route'] ?? null,
            'status' => $trace['status'] ?? null,
            'duration_ms' => round((float) ($trace['duration_ms'] ?? 0), 2),
            'query_count' => $queryCount,
            'query_ms' => round((float) ($trace['query_total_ms'] ?? array_sum(array_column($queryNodes, 'duration_ms'))), 2),
            'peak_memory' => (int) ($trace['peak_memory'] ?? 0),
            'issue_count' => count($findings),
            'issues' => $findings,
            'query_groups' => $groups,
            'truncated' => $groupCount > $top,
        ];
        if ($findings !== []) {
            $summary['next'] = 'php artisan lantern:issue '.$findings[0]['id'].' --format=json';
        }
        if ($groupCount > $top) {
            $summary['hint'] = sprintf('%d of %d query groups shown; add --limit=%d to see more.', $top, $groupCount, min(self::MAX_ROWS, $groupCount));
        }

        return $summary;
    }

    /**
     * Flat span list: the slowest `limit` spans above `minMs`, in recorded order.
     *
     * @param array<string, mixed> $trace
     * @return array{0: list<array<string, mixed>>, 1: int} spans and matching total
     */
    public function spans(array $trace, float $minMs, int $limit): array
    {
        $rows = [];
        foreach ($this->flatNodes($trace['tree'] ?? null, true) as $n) {
            if ((float) ($n['duration_ms'] ?? 0) < $minMs) {
                continue;
            }
            $name = (string) ($n['name'] ?? '');
            $rows[] = array_filter([
                'id' => $n['id'] ?? null,
                'parent_id' => $n['_parent'] ?? null,
                'type' => $n['type'] ?? null,
                'name' => ($n['type'] ?? '') === 'query' ? Redactor::sql($name) : $name,
                'start_ms' => $n['start_ms'] ?? null,
                'duration_ms' => round((float) ($n['duration_ms'] ?? 0), 2),
                'self_ms' => round((float) ($n['self_ms'] ?? 0), 2),
                'call_site' => Findings::site($n['call_site'] ?? null),
            ], fn ($v) => $v !== null);
        }
        $total = count($rows);
        if ($total > $limit) {
            $keep = $rows;
            usort($keep, fn (array $a, array $b): int => $b['duration_ms'] <=> $a['duration_ms']);
            $keep = array_slice($keep, 0, $limit);
            $rows = array_values(array_filter($rows, fn (array $r): bool => in_array($r, $keep, true)));
        }

        return [$rows, $total];
    }

    /**
     * Nested tree with repeated sibling queries collapsed into one node.
     *
     * @param array<string, mixed>|null $node
     * @return array<string, mixed>|null
     */
    public function tree(?array $node, int $depth, int $level = 0): ?array
    {
        if ($node === null) {
            return null;
        }
        $out = array_filter([
            'type' => $node['type'] ?? null,
            'name' => ($node['type'] ?? '') === 'query' ? Redactor::sql((string) ($node['name'] ?? '')) : ($node['name'] ?? null),
            'duration_ms' => round((float) ($node['duration_ms'] ?? 0), 2),
            'self_ms' => round((float) ($node['self_ms'] ?? 0), 2),
            'call_site' => Findings::site($node['call_site'] ?? null),
        ], fn ($v) => $v !== null);

        $children = (array) ($node['children'] ?? []);
        if ($children === []) {
            return $out;
        }
        if ($level >= $depth) {
            $out['children_omitted'] = count($children);

            return $out;
        }

        $kids = [];
        $groups = [];
        foreach ($children as $child) {
            $child = (array) $child;
            if (($child['type'] ?? '') === 'query' && (($child['meta']['kind'] ?? null) !== 'transaction')) {
                $shape = Redactor::sql((string) ($child['meta']['normalized_sql'] ?? $child['name'] ?? ''));
                $k = ($child['meta']['fingerprint'] ?? md5($shape)).'|'.Findings::siteString(Findings::site($child['call_site'] ?? null));
                if (! isset($groups[$k])) {
                    $groups[$k] = count($kids);
                    $kids[] = ['type' => 'query_group', 'sql_shape' => $shape, 'count' => 0, 'total_ms' => 0.0, 'call_site' => Findings::site($child['call_site'] ?? null)];
                }
                $g = &$kids[$groups[$k]];
                $g['count']++;
                $g['total_ms'] = round($g['total_ms'] + (float) ($child['duration_ms'] ?? 0), 2);
                unset($g);

                continue;
            }
            $kids[] = $this->tree($child, $depth, $level + 1);
        }
        foreach ($kids as &$k) {
            if (($k['type'] ?? '') === 'query_group') {
                $k = array_filter($k, fn ($v) => $v !== null);
                if ($k['count'] === 1) {
                    $k['type'] = 'query';
                    $k['name'] = $k['sql_shape'];
                    unset($k['sql_shape'], $k['count']);
                }
            }
        }
        unset($k);
        $out['children'] = $kids;

        return $out;
    }

    /**
     * @param array<string, mixed>|null $node
     * @return list<array<string, mixed>>
     */
    private function flatNodes(?array $node, bool $withParent = false, ?int $parent = null): array
    {
        if ($node === null) {
            return [];
        }
        $children = (array) ($node['children'] ?? []);
        $self = $node;
        unset($self['children']);
        if ($withParent) {
            $self['_parent'] = $parent;
        }
        $out = [$self];
        foreach ($children as $child) {
            array_push($out, ...$this->flatNodes((array) $child, $withParent, isset($node['id']) ? (int) $node['id'] : null));
        }

        return $out;
    }
}
