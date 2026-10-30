<?php

declare(strict_types=1);

namespace Lantern\Storage;

use Lantern\Agent\Findings;
use Lantern\Detection\Detectors\MissingIndexDetector;
use Lantern\Detection\Detectors\NPlusOneDetector;
use Lantern\Detection\Detectors\PlanFlipDetector;
use Lantern\Detection\Detectors\SlowQueryDetector;
use Lantern\Detection\Explain\ExplainRunner;
use Lantern\Detection\Issue;
use Lantern\Detection\QueryFilter;
use Lantern\Trace\SpanType;
use Lantern\Trace\Trace;

/**
 * The finding shapes a trace proves are gone: query shapes that ran here, were examined by an active detector and
 * produced no finding. The caller subtracts what did fire; whatever remains and is still open gets resolved.
 *
 * A shape only counts when the detector that owns it actually looked: plan based findings need `explain` on, and the
 * per-trace EXPLAIN caps are respected with a margin so a shape the detector skipped is never taken for clean.
 */
final class ClearedShapes
{
    private const MAX_FINGERPRINTS = 300;

    /** Under MissingIndexDetector::MAX_FINGERPRINTS (25). */
    private const EXPLAINED = 20;

    /** Under PlanFlipDetector::MAX_SHAPES (10). */
    private const FLIP_SHAPES = 8;

    /**
     * @param array<string, mixed> $config full config('lantern')
     * @return list<string> issue shapes (Findings::shape) that did not recur in this trace
     */
    public static function candidates(Trace $trace, array $config): array
    {
        $active = array_flip(array_filter((array) ($config['detectors'] ?? []), 'is_string'));
        $explain = (bool) ($config['explain'] ?? false);
        $nPlusOne = isset($active[NPlusOneDetector::class]);
        $slow = isset($active[SlowQueryDetector::class]);
        $index = $explain && isset($active[MissingIndexDetector::class]);
        $flip = $explain && isset($active[PlanFlipDetector::class]);
        if (! $nPlusOne && ! $slow && ! $index && ! $flip) {
            return [];
        }

        $minMs = min((float) ($config['thresholds']['explain_min_ms'] ?? 0), (float) ($config['thresholds']['slow_query_ms'] ?? 100));
        $filter = new QueryFilter($config);
        $seen = [];
        $sites = [];
        $selects = [];
        $flips = [];

        foreach ($trace->spans() as $span) {
            $m = $span->meta;
            $fp = $m['fingerprint'] ?? null;
            if ($span->type !== SpanType::Query || ($m['kind'] ?? null) === 'transaction' || ($m['failed'] ?? false)
                || ! is_string($fp) || $fp === '' || $filter->ignores($span)) {
                continue;
            }
            if (! isset($seen[$fp])) {
                if (count($seen) >= self::MAX_FINGERPRINTS) {
                    continue;
                }
                $seen[$fp] = true;
            }

            $site = Findings::site($span->callSite);
            $sites[$fp."\0".($site === null ? '-' : $site['file'].'#'.($site['function'] ?? ''))] = true;

            if (($index || $flip) && is_string($m['sql'] ?? null) && ExplainRunner::isSelect($m['sql'])) {
                $selects[$fp] = max($selects[$fp] ?? 0.0, (float) ($m['time_ms'] ?? 0));
                if (is_int($m['in_size'] ?? null) && $m['in_size'] >= 1) {
                    $flips[$fp] = true;
                }
            }
        }

        $shapes = [];
        foreach (array_keys($sites) as $entry) {
            [$fp, $where] = explode("\0", $entry, 2);
            if ($nPlusOne) {
                $shapes[] = Issue::key(Issue::TYPE_N_PLUS_ONE, $fp, $where);
            }
            if ($slow) {
                $shapes[] = Issue::key(Issue::TYPE_SLOW, $fp, $where);
            }
        }
        if ($index) {
            foreach (array_slice(array_keys(array_filter($selects, fn (float $ms): bool => $ms >= $minMs)), 0, self::EXPLAINED) as $fp) {
                $shapes[] = Issue::key(Issue::TYPE_MISSING_INDEX, (string) $fp);
                $shapes[] = Issue::key(Issue::TYPE_INDEX_NOT_CHOSEN, (string) $fp);
            }
        }
        if ($flip) {
            foreach (array_slice(array_keys($flips), 0, self::FLIP_SHAPES) as $fp) {
                $shapes[] = Issue::key(Issue::TYPE_PLAN_FLIP, (string) $fp);
            }
        }

        return array_values(array_unique($shapes));
    }
}
