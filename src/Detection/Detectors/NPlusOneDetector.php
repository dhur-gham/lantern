<?php

declare(strict_types=1);

namespace Lantern\Detection\Detectors;

use Lantern\Detection\Detector;
use Lantern\Detection\Issue;
use Lantern\Detection\QueryFilter;
use Lantern\Trace\Trace;

final class NPlusOneDetector implements Detector
{
    private const MAX_ISSUES = 20;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config = []) {}

    public function detect(Trace $trace): array
    {
        $min = max(2, (int) ($this->config['thresholds']['n_plus_one_min_repeats'] ?? 5));
        $filter = new QueryFilter($this->config);
        $spans = $trace->spans();
        $groups = [];

        foreach ($spans as $span) {
            if ($span->type !== \Lantern\Trace\SpanType::Query
                || ($span->meta['kind'] ?? null) === 'transaction'
                || ($span->meta['failed'] ?? false)
                || ! isset($span->meta['fingerprint'])
                || $filter->ignores($span)) {
                continue;
            }

            if (($span->callSite['paging'] ?? false) === true) {
                continue;
            }

            if ($this->isBulkWrite((string) ($span->meta['sql'] ?? $span->meta['normalized_sql'] ?? ''))) {
                continue;
            }

            $site = $span->callSite !== null ? $span->callSite['file'].':'.$span->callSite['line'] : 'unknown';
            $groups[$span->meta['fingerprint'].'|'.$site][] = $span;
        }

        $issues = [];
        foreach ($groups as $key => $group) {
            $count = count($group);
            if ($count < $min) {
                continue;
            }

            $total = 0.0;
            $ids = [];
            foreach ($group as $span) {
                $total += (float) ($span->meta['time_ms'] ?? $span->durationMs());
                $ids[] = $span->id;
            }

            $first = $group[0];
            $site = substr($key, strpos($key, '|') + 1);
            $sql = (string) ($first->meta['normalized_sql'] ?? $first->meta['sql'] ?? $first->name);
            $parent = $first->parentId !== null ? ($spans[$first->parentId] ?? null) : null;
            $critical = $count >= 4 * $min || $total >= 500.0;

            $issues[] = new Issue(
                Issue::TYPE_N_PLUS_ONE,
                Issue::key(Issue::TYPE_N_PLUS_ONE, (string) $first->meta['fingerprint'], $site),
                sprintf('N+1: %s x%d at %s', $this->short($sql), $count, $site),
                $critical ? Issue::SEV_CRITICAL : Issue::SEV_WARNING,
                $count,
                $total,
                $first->callSite,
                $ids,
                [
                    'fingerprint' => $first->meta['fingerprint'],
                    'sample_sql' => (string) ($first->meta['sql'] ?? $sql),
                    'parent' => $parent?->name,
                    'avg_ms' => $total / $count,
                ],
                $this->suggest($sql),
            );
        }

        usort($issues, fn (Issue $a, Issue $b) => $b->totalMs <=> $a->totalMs);

        return array_slice($issues, 0, self::MAX_ISSUES);
    }

    /** An UPDATE/DELETE over an IN list, subquery, BETWEEN or id range, or a multi-row INSERT, is deliberate batching. */
    private function isBulkWrite(string $sql): bool
    {
        $s = strtolower(trim((string) preg_replace('/\s+/', ' ', str_replace(['`', '"'], '', $sql))));

        if (preg_match('/^insert\b/', $s) === 1) {
            return preg_match('/\bvalues\s*\([^)]*\)\s*,\s*\(/', $s) === 1 || preg_match('/\)\s*select\b/', $s) === 1;
        }

        if (preg_match('/^(update|delete)\b/', $s) !== 1 || ($pos = strpos($s, ' where ')) === false) {
            return false;
        }

        $where = substr($s, $pos);

        // a row pinned by its own id is one row per statement, whatever else the WHERE filters on
        if (preg_match('/\b(?:\w+\.)?(?:id|uuid)\s*=\s*(?:\?|\d|\x27)/', $where) === 1) {
            return false;
        }

        return preg_match('/\bin\s*\(\s*(select\b|[^)]*,)/', $where) === 1
            || preg_match('/\bbetween\b/', $where) === 1
            || preg_match('/\b(?:\w+\.)?id\s*(?:>=|<=|>|<)\s*[?\d]/', $where) === 1;
    }

    private function short(string $sql): string
    {
        $sql = trim((string) preg_replace('/\s+/', ' ', $sql));

        return mb_strlen($sql) > 60 ? mb_substr($sql, 0, 57).'...' : $sql;
    }

    private function suggest(string $sql): string
    {
        $s = trim((string) preg_replace('/\s+/', ' ', str_replace(['`', '"'], '', $sql)));

        if (preg_match('/^select count\(\*\) as aggregate from (\w+) where (?:\w+\.)?(\w+_id) = \?/i', $s, $m) === 1) {
            return sprintf("Use withCount() to load the count of '%s' in one query instead of per row.", $m[1]);
        }

        if (preg_match('/^select \* from (\w+) where (?:\w+\.)?(\w+) (?:=|in) /i', $s, $m) === 1) {
            $rel = str_ends_with($m[2], '_id') ? substr($m[2], 0, -3) : $m[1];
            $rel = $m[2] === 'id' ? $m[1] : $rel;

            return sprintf("Eager load the relation with with('%s') (or load()/loadMissing()) before the loop.", $rel);
        }

        return 'Load the data in bulk before the loop (eager loading, whereIn(), or a join) instead of querying per iteration.';
    }
}
