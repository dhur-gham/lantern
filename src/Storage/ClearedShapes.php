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

    /** Hash of the SQL text with IN lists collapsed: unchanged by a longer list, changed by an edit or a hint. */
    public static function sqlHash(string $sql): string
    {
        $sql = (string) preg_replace('/\?(?:\s*,\s*\?)+/', '?', $sql);

        return md5(trim((string) preg_replace('/\s+/', ' ', $sql)));
    }

    /**
     * @param array<string, mixed> $config full config('lantern')
     * @return array<string, array{in_size: int, sql_hashes: list<string>}> issue shape (Findings::shape) => the largest IN list this trace ran for it and the SQL hashes it ran, for shapes that did not recur
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
        $sizes = [];
        $hashes = [];

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

            $sizes[$fp] = max($sizes[$fp] ?? 0, is_int($m['in_size'] ?? null) ? $m['in_size'] : 0);
            if (is_string($m['sql'] ?? null)) {
                $hashes[$fp][self::sqlHash($m['sql'])] = true;
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
        $owner = [];
        foreach (array_keys($sites) as $entry) {
            [$fp, $where] = explode("\0", $entry, 2);
            if ($nPlusOne) {
                $owner[$shapes[] = Issue::key(Issue::TYPE_N_PLUS_ONE, $fp, $where)] = $fp;
            }
            if ($slow) {
                $owner[$shapes[] = Issue::key(Issue::TYPE_SLOW, $fp, $where)] = $fp;
            }
        }
        if ($index) {
            foreach (array_slice(array_keys(array_filter($selects, fn (float $ms): bool => $ms >= $minMs)), 0, self::EXPLAINED) as $fp) {
                $owner[$shapes[] = Issue::key(Issue::TYPE_MISSING_INDEX, (string) $fp)] = (string) $fp;
                $owner[$shapes[] = Issue::key(Issue::TYPE_INDEX_NOT_CHOSEN, (string) $fp)] = (string) $fp;
            }
        }
        if ($flip) {
            foreach (array_slice(array_keys($flips), 0, self::FLIP_SHAPES) as $fp) {
                $owner[$shapes[] = Issue::key(Issue::TYPE_PLAN_FLIP, (string) $fp)] = (string) $fp;
            }
        }

        $out = [];
        foreach (array_unique($shapes) as $shape) {
            $fp = $owner[$shape];
            $out[$shape] = ['in_size' => $sizes[$fp] ?? 0, 'sql_hashes' => array_map('strval', array_keys($hashes[$fp] ?? []))];
        }

        return $out;
    }
}
