<?php

declare(strict_types=1);

namespace Lantern\Detection\Detectors;

use Lantern\Detection\Detector;
use Lantern\Detection\Issue;
use Lantern\Detection\QueryFilter;
use Lantern\Trace\SpanType;
use Lantern\Trace\Trace;

final class DuplicateQueryDetector implements Detector
{
    private const MAX_ISSUES = 20;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config = []) {}

    public function detect(Trace $trace): array
    {
        $min = max(2, (int) ($this->config['thresholds']['duplicate_min_repeats'] ?? 2));
        $filter = new QueryFilter($this->config);
        $groups = [];

        foreach ($trace->spans() as $span) {
            if ($span->type !== SpanType::Query
                || ($span->meta['kind'] ?? null) === 'transaction'
                || ($span->meta['failed'] ?? false)
                || ! isset($span->meta['sql'])
                || $filter->ignores($span)) {
                continue;
            }

            if (isset($span->meta['bindings_key'])) {
                $bk = (string) $span->meta['bindings_key'];
            } elseif (isset($span->meta['bindings'])) {
                $bk = md5(serialize($span->meta['bindings']));
            } else {
                continue; // bindings unknown: cannot tell duplicates from different parameters
            }

            $groups[md5((string) $span->meta['sql']).'|'.$bk][] = $span;
        }

        $issues = [];
        foreach ($groups as $key => $group) {
            $count = count($group);
            if ($count < $min) {
                continue;
            }

            $total = 0.0;
            $ids = [];
            $sites = [];
            foreach ($group as $span) {
                $total += (float) ($span->meta['time_ms'] ?? $span->durationMs());
                $ids[] = $span->id;
                $sites[] = $span->callSite !== null ? $span->callSite['file'].':'.$span->callSite['line'] : 'unknown';
            }

            $first = $group[0];
            $sql = (string) ($first->meta['normalized_sql'] ?? $first->meta['sql']);
            $short = mb_strlen($sql) > 60 ? mb_substr($sql, 0, 57).'...' : $sql;

            $issues[] = new Issue(
                Issue::TYPE_DUPLICATE,
                Issue::key(Issue::TYPE_DUPLICATE, $key),
                sprintf('Duplicate query x%d: %s', $count, $short),
                $count >= 3 ? Issue::SEV_WARNING : Issue::SEV_INFO,
                $count,
                $total,
                $first->callSite,
                $ids,
                [
                    'fingerprint' => $first->meta['fingerprint'] ?? null,
                    'sample_sql' => (string) $first->meta['sql'],
                    'call_sites' => array_values(array_unique($sites)),
                ],
                'Run this query once and reuse the result: hoist it out of the loop, memoize it, or cache it.',
            );
        }

        usort($issues, fn (Issue $a, Issue $b) => $b->totalMs <=> $a->totalMs);

        return array_slice($issues, 0, self::MAX_ISSUES);
    }
}
