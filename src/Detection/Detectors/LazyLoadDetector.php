<?php

declare(strict_types=1);

namespace Lantern\Detection\Detectors;

use Lantern\Detection\Detector;
use Lantern\Detection\Issue;
use Lantern\Trace\SpanType;
use Lantern\Trace\Trace;

final class LazyLoadDetector implements Detector
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config = []) {}

    public function detect(Trace $trace): array
    {
        $min = max(2, (int) ($this->config['thresholds']['n_plus_one_min_repeats'] ?? 5));

        $groups = [];
        foreach ($trace->spans() as $span) {
            if ($span->type !== SpanType::Event || ($span->meta['kind'] ?? null) !== 'lazy_load') {
                continue;
            }

            $model = (string) ($span->meta['model'] ?? '');
            $relation = (string) ($span->meta['relation'] ?? '');
            $site = $span->callSite;
            $siteKey = $site === null ? '' : ($site['file'].':'.$site['line']);
            $groups[$model."\0".$relation."\0".$siteKey][] = [$span, $model, $relation];
        }

        $issues = [];
        foreach ($groups as $rows) {
            $count = count($rows);
            if ($count < $min) {
                continue;
            }

            [$first, $model, $relation] = $rows[0];
            $short = ($pos = strrpos($model, '\\')) === false ? $model : substr($model, $pos + 1);
            $site = $first->callSite;
            $siteKey = $site === null ? '' : ($site['file'].':'.$site['line']);

            $issues[] = new Issue(
                Issue::TYPE_LAZY_LOAD,
                Issue::key(Issue::TYPE_LAZY_LOAD, $model, $relation, $siteKey),
                "Lazy load: {$short}::{$relation} x{$count}",
                $count >= $min * 4 ? Issue::SEV_CRITICAL : Issue::SEV_WARNING,
                $count,
                0.0,
                $site,
                array_values(array_map(fn (array $r) => $r[0]->id, $rows)),
                ['model' => $model, 'relation' => $relation],
                "Eager load the relation on the parent query: ->with('{$relation}'). Use Model::preventLazyLoading() in non-production to catch these early.",
            );
        }

        return $issues;
    }
}
