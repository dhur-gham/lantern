<?php

declare(strict_types=1);

namespace Lantern\Detection\Detectors;

use Lantern\Detection\Detector;
use Lantern\Detection\Issue;
use Lantern\Detection\QueryFilter;
use Lantern\Trace\SpanType;
use Lantern\Trace\Trace;

final class SlowQueryDetector implements Detector
{
    private const MAX_ISSUES = 20;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config = []) {}

    public function detect(Trace $trace): array
    {
        $threshold = (float) ($this->config['thresholds']['slow_query_ms'] ?? 100);
        $filter = new QueryFilter($this->config);
        $groups = [];

        foreach ($trace->spans() as $span) {
            if ($span->type !== SpanType::Query
                || ($span->meta['kind'] ?? null) === 'transaction'
                || ($span->meta['failed'] ?? false)
                || $filter->ignores($span)) {
                continue;
            }

            $ms = (float) ($span->meta['time_ms'] ?? $span->durationMs());
            if ($ms < $threshold) {
                continue;
            }

            $site = $span->callSite !== null ? $span->callSite['file'].':'.$span->callSite['line'] : 'unknown';
            $fp = (string) ($span->meta['fingerprint'] ?? md5((string) ($span->meta['sql'] ?? $span->name)));
            $groups[$fp.'|'.$site][] = [$span, $ms];
        }

        $issues = [];
        foreach ($groups as $key => $group) {
            $total = 0.0;
            $max = 0.0;
            $ids = [];
            foreach ($group as [$span, $ms]) {
                $total += $ms;
                $max = max($max, $ms);
                $ids[] = $span->id;
            }

            $first = $group[0][0];
            $site = substr($key, strpos($key, '|') + 1);
            $sql = (string) ($first->meta['normalized_sql'] ?? $first->meta['sql'] ?? $first->name);
            $short = mb_strlen($sql) > 60 ? mb_substr($sql, 0, 57).'...' : $sql;

            $issues[] = new Issue(
                Issue::TYPE_SLOW,
                Issue::key(Issue::TYPE_SLOW, substr($key, 0, strpos($key, '|')), $site),
                sprintf('Slow query: %s (%.0f ms) at %s', $short, $max, $site),
                $max >= 5 * $threshold ? Issue::SEV_CRITICAL : Issue::SEV_WARNING,
                count($group),
                $total,
                $first->callSite,
                $ids,
                [
                    'fingerprint' => $first->meta['fingerprint'] ?? null,
                    'sample_sql' => (string) ($first->meta['sql'] ?? $sql),
                    'max_ms' => $max,
                ],
                'Check the query plan (EXPLAIN), add an index on the filtered/sorted columns, or narrow the selected rows and columns.',
            );
        }

        usort($issues, fn (Issue $a, Issue $b) => $b->totalMs <=> $a->totalMs);

        return array_slice($issues, 0, self::MAX_ISSUES);
    }
}
