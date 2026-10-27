<?php

declare(strict_types=1);

namespace Lantern\Detection\Detectors;

use Lantern\Detection\Detector;
use Lantern\Detection\Issue;
use Lantern\Trace\Span;
use Lantern\Trace\SpanType;
use Lantern\Trace\Trace;

final class MemoryHogDetector implements Detector
{
    private const TYPES = [SpanType::Controller, SpanType::Route, SpanType::View, SpanType::Query];

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config = []) {}

    public function detect(Trace $trace): array
    {
        $threshold = (int) round((float) ($this->config['thresholds']['memory_delta_mb'] ?? 32) * 1048576);
        if ($threshold <= 0) {
            return [];
        }

        $spans = array_values($trace->spans());
        $flagged = [];
        foreach ($spans as $i => $span) {
            if (! in_array($span->type, self::TYPES, true) || ! $span->isStopped()) {
                continue;
            }
            $delta = max($span->memoryDelta(), $span->memoryPeakGrowth());
            if ($delta >= $threshold) {
                $flagged[$span->id] = $delta;
            }
        }

        // A query is only suspect when it looks unbounded; its cost shows up in the span that follows it.
        $unbounded = [];
        foreach ($spans as $i => $span) {
            if (! $this->looksUnbounded($span)) {
                continue;
            }
            $next = $this->following($spans, $i);
            if ($next !== null && $next->isStopped() && max($next->memoryDelta(), $next->memoryPeakGrowth()) >= $threshold) {
                $unbounded[$span->id] = $next;
                $flagged[$span->id] ??= max($next->memoryDelta(), $next->memoryPeakGrowth());
            }
        }

        // Keep the leaf of a chain: drop an ancestor whose growth is explained (>= 80%) by a flagged descendant.
        foreach (array_keys($flagged) as $id) {
            $parentId = $trace->spans()[$id]->parentId ?? null;
            while ($parentId !== null) {
                if (isset($flagged[$parentId]) && $flagged[$id] >= 0.8 * $flagged[$parentId] && ! isset($unbounded[$parentId])) {
                    unset($flagged[$parentId]);
                }
                $parentId = $trace->spans()[$parentId]->parentId ?? null;
            }
        }

        $peak = $trace->peakMemory();
        $issues = [];
        foreach ($flagged as $id => $delta) {
            $span = $trace->spans()[$id];
            $mb = round($delta / 1048576, 1);
            $site = $span->callSite === null ? '' : $span->callSite['file'].':'.$span->callSite['line'];
            $meta = [
                'span_type' => $span->type->value,
                'memory_delta_bytes' => $delta,
                'memory_delta_mb' => $mb,
                'peak_memory_bytes' => $peak,
                'peak_memory_mb' => round($peak / 1048576, 1),
            ];
            $suggestion = 'Reduce the data held in memory here: select fewer columns, paginate, or use chunk()/lazy()/cursor().';

            if (isset($unbounded[$id])) {
                $meta['unbounded_select'] = true;
                $meta['growth_span_id'] = $unbounded[$id]->id;
                $meta['growth_span'] = $unbounded[$id]->name;
                $suggestion = 'This query has no LIMIT and is followed by a large memory increase: add a limit/paginate() or process rows with chunkById()/cursor().';
            }

            $issues[] = new Issue(
                Issue::TYPE_MEMORY,
                Issue::key(Issue::TYPE_MEMORY, $span->type->value, $span->name, $site),
                sprintf('Memory growth: +%s MB in %s %s', $mb, $span->type->value, $span->name),
                $delta >= 4 * $threshold ? Issue::SEV_CRITICAL : Issue::SEV_WARNING,
                1,
                $span->durationMs(),
                $span->callSite,
                [$span->id],
                $meta,
                $suggestion,
            );
        }

        usort($issues, fn (Issue $a, Issue $b) => $b->meta['memory_delta_bytes'] <=> $a->meta['memory_delta_bytes']);

        return $issues;
    }

    private function looksUnbounded(Span $span): bool
    {
        if ($span->type !== SpanType::Query || ($span->meta['kind'] ?? null) === 'transaction' || ($span->meta['failed'] ?? false)) {
            return false;
        }

        $sql = strtolower(trim((string) ($span->meta['sql'] ?? '')));

        return str_starts_with($sql, 'select')
            && preg_match('/\blimit\b/', $sql) !== 1
            && preg_match('/\b(join|union|count\s*\(|sum\s*\(|avg\s*\(|max\s*\(|min\s*\(|group\s+by)\b/', $sql) !== 1
            && preg_match('/\bfrom\s+[`"\[]?\w+[`"\]]?(\s+as\s+\w+)?\s*(where|order|$)/', $sql) === 1;
    }

    /** @param list<Span> $spans */
    private function following(array $spans, int $index): ?Span
    {
        $self = $spans[$index];
        for ($j = $index + 1, $n = count($spans); $j < $n; $j++) {
            if ($spans[$j]->parentId === $self->parentId && $spans[$j]->type !== SpanType::Event) {
                return $spans[$j];
            }
        }

        return null;
    }
}
