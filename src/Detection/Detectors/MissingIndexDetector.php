<?php

declare(strict_types=1);

namespace Lantern\Detection\Detectors;

use Lantern\Detection\Detector;
use Lantern\Detection\Explain\ExplainRunner;
use Lantern\Detection\Issue;
use Lantern\Detection\QueryFilter;
use Lantern\Trace\Span;
use Lantern\Trace\SpanType;
use Lantern\Trace\Trace;

/**
 * Flags SELECTs that EXPLAIN reports as full table scans. Opt-in (config explain=true) because it runs extra
 * queries. It runs while the analyzer finalises the trace, i.e. after the request, so it adds no latency to the
 * response but does cost a few EXPLAINs (at most 5 distinct fingerprints per trace, one EXPLAIN each).
 */
final class MissingIndexDetector implements Detector
{
    private const MAX_FINGERPRINTS = 5;

    /** @param array<string, mixed> $config full config('lantern') */
    public function __construct(private readonly array $config, private readonly ?ExplainRunner $runner = null)
    {
    }

    public function detect(Trace $trace): array
    {
        if (! ($this->config['explain'] ?? false)) {
            return [];
        }

        $slowMs = (float) ($this->config['thresholds']['slow_query_ms'] ?? 100);
        $explainMs = (float) ($this->config['thresholds']['explain_min_ms'] ?? 2);
        $filter = new QueryFilter($this->config);
        $minRows = (int) ($this->config['explain_min_rows'] ?? 100);

        $groups = [];
        foreach ($trace->spans() as $span) {
            $m = $span->meta;
            if ($span->type !== SpanType::Query || ($m['kind'] ?? null) === 'transaction' || ($m['failed'] ?? false)
                || ! is_string($m['sql'] ?? null) || ! ExplainRunner::isSelect($m['sql']) || $filter->ignores($span)) {
                continue;
            }
            $groups[(string) ($m['fingerprint'] ?? md5($m['sql']))][] = $span;
        }

        $candidates = [];
        foreach ($groups as $fp => $spans) {
            $max = max(array_map(fn (Span $s) => (float) ($s->meta['time_ms'] ?? 0), $spans));
            if ($max >= min($explainMs, $slowMs)) {
                $candidates[$fp] = [$max, $spans];
            }
        }
        uasort($candidates, fn (array $a, array $b) => $b[0] <=> $a[0]);
        $candidates = array_map(fn (array $c) => $c[1], $candidates);

        $runner = $this->runner ?? new ExplainRunner;
        $issues = [];
        foreach (array_slice($candidates, 0, self::MAX_FINGERPRINTS, true) as $fp => $spans) {
            $first = $spans[0];
            $sql = (string) $first->meta['sql'];
            $columns = $this->columns($sql);
            if ($columns === [] || $columns === ['id']) {
                continue; // primary-key lookup, or no predicate an index could serve
            }

            $plan = $runner->explain($first->meta['connection'] ?? null, $sql, (array) ($first->meta['bindings'] ?? []));
            if ($plan === null || ! $plan['full_scan'] || $plan['table'] === null) {
                continue;
            }
            // sqlite has no row estimate: a scan is only suspect when a WHERE column predicate exists.
            if ($plan['rows'] !== null && $plan['rows'] <= $minRows) {
                continue;
            }

            $summary = sprintf(
                'full scan on %s%s%s',
                $plan['table'],
                $plan['rows'] !== null ? ", ~{$plan['rows']} rows" : '',
                $plan['possible_keys'] ? ", possible keys: {$plan['possible_keys']}" : '',
            );
            $issues[] = new Issue(
                Issue::TYPE_MISSING_INDEX,
                Issue::key(Issue::TYPE_MISSING_INDEX, (string) $fp),
                'Possible missing index on '.$plan['table'],
                Issue::SEV_WARNING,
                count($spans),
                array_sum(array_map(fn (Span $s) => (float) ($s->meta['time_ms'] ?? 0), $spans)),
                $first->callSite,
                array_values(array_map(fn (Span $s) => $s->id, $spans)),
                ['table' => $plan['table'], 'columns' => $columns, 'plan' => $summary, 'rows' => $plan['rows']],
                $columns === [] ? 'Consider an index on the columns used in WHERE' : 'Consider an index on ('.implode(', ', $columns).')',
            );
        }

        return $issues;
    }

    /** @return list<string> columns used in WHERE predicates, best effort */
    private function columns(string $sql): array
    {
        if (preg_match('/\bwhere\b(.*?)(?:\bgroup\s+by\b|\border\s+by\b|\blimit\b|\bhaving\b|$)/is', $sql, $m) !== 1) {
            return [];
        }
        preg_match_all('/(?:[`"\[]?\w+[`"\]]?\.)?[`"\[]?(\w+)[`"\]]?\s*(?:=|<>|!=|<=|>=|<|>|\blike\b|\bin\b|\bis\b|\bbetween\b)/i', $m[1], $all);

        return array_values(array_unique($all[1]));
    }
}
