<?php

declare(strict_types=1);

namespace Lantern\Detection\Detectors;

use Closure;
use Lantern\Detection\Detector;
use Lantern\Detection\Explain\ExplainRunner;
use Lantern\Detection\Issue;
use Lantern\Detection\PlanFlip\PlanFlipAnalyzer;
use Lantern\Detection\PlanFlip\PlanSampleStore;
use Lantern\Detection\QueryFilter;
use Lantern\Trace\Span;
use Lantern\Trace\SpanType;
use Lantern\Trace\Trace;

/**
 * Flags a query shape whose plan changes, or whose cost outgrows its list, as the length of an `IN (...)` list grows.
 *
 * Each trace contributes one sample per shape and list size (average time, plus an EXPLAIN plan refreshed at most every
 * `plan_flip.refresh_minutes`, or sooner when the time moves `plan_flip.time_deviation` times away from the average).
 * Samples persist in `lantern_plan_samples` with the database storage driver, so sizes seen in different requests are
 * compared; without it only sizes within the current trace are. A sample belongs to the SQL text it was taken from:
 * when the text changes (an optimizer hint, a rewritten condition) the old samples are discarded. Opt-in with `explain`.
 */
final class PlanFlipDetector implements Detector
{
    private const MAX_SHAPES = 10;

    private const MAX_EXPLAINS = 3;

    private ?PlanSampleStore $store = null;

    private bool $storeResolved = false;

    /**
     * @param array<string, mixed> $config full config('lantern')
     * @param (Closure(?string, string, array<int|string, mixed>): ?array<string, mixed>)|null $explain replaces EXPLAIN (tests)
     */
    public function __construct(
        private readonly array $config,
        private readonly ?ExplainRunner $runner = null,
        private readonly ?Closure $explain = null,
        ?PlanSampleStore $store = null,
    ) {
        if ($store !== null) {
            $this->store = $store;
            $this->storeResolved = true;
        }
    }

    public function detect(Trace $trace): array
    {
        if (! ($this->config['explain'] ?? false)) {
            return [];
        }

        $groups = $this->groups($trace);
        if ($groups === []) {
            return [];
        }

        $options = (array) ($this->config['plan_flip'] ?? []);
        $factor = (float) ($options['factor'] ?? 3);
        $minMs = (float) ($options['min_ms'] ?? 10);
        $minRows = (int) ($options['min_rows'] ?? 1000);
        $refresh = (int) ($options['refresh_minutes'] ?? 60);
        $deviation = (float) ($options['time_deviation'] ?? 4);
        $slowMs = (float) ($this->config['thresholds']['slow_query_ms'] ?? 100);

        $issues = [];
        $explains = 0;
        foreach ($groups as $fp => $buckets) {
            $largestBy = [];
            $hashes = [];
            foreach ($buckets as $bucket => $bucketSpans) {
                $largestBy[$bucket] = array_reduce($bucketSpans, fn (?Span $c, Span $s) => $c === null || $s->meta['in_size'] > $c->meta['in_size'] ? $s : $c);
                $hashes[$bucket] = PlanSampleStore::sqlHash((string) $largestBy[$bucket]->meta['sql']);
            }

            $this->store()?->discardStale((string) $fp, array_values(array_unique($hashes)));

            $known = [];
            foreach ($this->store()?->samples((string) $fp) ?? [] as $sample) {
                $known[$sample['in_size']] = $sample;
            }

            $spans = [];
            foreach ($buckets as $bucket => $bucketSpans) {
                $largest = $largestBy[$bucket];
                $ms = array_sum(array_map(fn (Span $s) => (float) ($s->meta['time_ms'] ?? 0), $bucketSpans)) / count($bucketSpans);
                $existing = $known[$bucket] ?? null;

                $plan = null;
                if ($explains < self::MAX_EXPLAINS && PlanSampleStore::needsExplain($existing, $refresh, $ms, $deviation, $minMs)) {
                    $explains++;
                    $plan = $this->plan($largest);
                }

                $this->store()?->record((string) $fp, (int) $bucket, $ms, $plan, $hashes[$bucket]);
                $known[$bucket] = $this->merge($existing, (int) $bucket, $ms, $plan);
                array_push($spans, ...$bucketSpans);
            }

            $found = PlanFlipAnalyzer::find(array_values($known), $factor, $minMs, $minRows);
            if ($found === null) {
                continue;
            }

            $issues[] = $this->issue((string) $fp, $found, $spans, array_values($known), $slowMs);
        }

        return $issues;
    }

    /** @return array<string, array<int, list<Span>>> fingerprint => size bucket => spans */
    private function groups(Trace $trace): array
    {
        $filter = new QueryFilter($this->config);
        $groups = [];
        foreach ($trace->spans() as $span) {
            $m = $span->meta;
            if ($span->type !== SpanType::Query || ($m['kind'] ?? null) === 'transaction' || ($m['failed'] ?? false)
                || ! is_string($m['sql'] ?? null) || ! is_int($m['in_size'] ?? null) || $m['in_size'] < 1
                || ! ExplainRunner::isSelect($m['sql']) || $filter->ignores($span)) {
                continue;
            }
            $fp = (string) ($m['fingerprint'] ?? md5($m['sql']));
            if (! isset($groups[$fp]) && count($groups) >= self::MAX_SHAPES) {
                continue;
            }
            $groups[$fp][PlanFlipAnalyzer::bucket($m['in_size'])][] = $span;
        }

        return $groups;
    }

    /** @return array{table: ?string, key: ?string, rows: ?int, full_scan: bool}|null */
    private function plan(Span $span): ?array
    {
        $args = [$span->meta['connection'] ?? null, (string) $span->meta['sql'], (array) ($span->meta['bindings'] ?? [])];
        $plan = $this->explain !== null ? ($this->explain)(...$args) : ($this->runner ?? new ExplainRunner)->explain(...$args);
        if ($plan === null) {
            return null;
        }

        return ['table' => $plan['table'] ?? null, 'key' => $plan['key'] ?? null, 'rows' => $plan['rows'] ?? null, 'full_scan' => (bool) ($plan['full_scan'] ?? false)];
    }

    /**
     * @param array<string, mixed>|null $existing
     * @param array{table: ?string, key: ?string, rows: ?int, full_scan: bool}|null $plan
     * @return array<string, mixed>
     */
    private function merge(?array $existing, int $size, float $ms, ?array $plan): array
    {
        $sample = $existing ?? ['table' => null, 'key' => null, 'rows' => null, 'full_scan' => false, 'explained_at' => null];

        return [
            'in_size' => $size,
            ...PlanSampleStore::fold($existing, $ms, $plan),
            'explained_at' => $plan !== null ? 'now' : $sample['explained_at'],
            'explained' => $plan !== null || ($existing['explained'] ?? false),
        ] + ($plan ?? $sample);
    }

    /**
     * @param array<string, mixed> $found
     * @param list<Span> $spans
     * @param list<array<string, mixed>> $samples
     */
    private function issue(string $fp, array $found, array $spans, array $samples, float $slowMs): Issue
    {
        $key = Issue::key(Issue::TYPE_PLAN_FLIP, $fp);
        $from = $found['from'];
        $to = $found['to'];
        $largest = array_reduce($spans, fn (?Span $c, Span $s) => $c === null || $s->meta['in_size'] > $c->meta['in_size'] ? $s : $c);

        $title = $found['kind'] === 'plan'
            ? sprintf('Plan changes as the IN list grows: %s at %d values, %s at %d', PlanFlipAnalyzer::describe($from), $from['in_size'], PlanFlipAnalyzer::describe($to), $to['in_size'])
            : sprintf('Query cost outgrows its IN list: %s at %d values, %s at %d', self::cost($from), $from['in_size'], self::cost($to), $to['in_size']);

        return new Issue(
            Issue::TYPE_PLAN_FLIP,
            $key,
            $title,
            $to['avg_ms'] >= $slowMs * 5 ? Issue::SEV_CRITICAL : Issue::SEV_WARNING,
            count($spans),
            array_sum(array_map(fn (Span $s) => (float) ($s->meta['time_ms'] ?? 0), $spans)),
            $spans[0]->callSite,
            array_values(array_map(fn (Span $s) => $s->id, $spans)),
            [
                'fingerprint' => $fp,
                'kind' => $found['kind'],
                'sample_sql' => (string) $largest->meta['sql'],
                'connection' => $largest->meta['connection'] ?? null,
                'from' => self::out($from),
                'to' => self::out($to),
                'size_ratio' => round($found['size_ratio'], 2),
                'time_ratio' => $found['time_ratio'] === null ? null : round($found['time_ratio'], 2),
                'rows_ratio' => $found['rows_ratio'] === null ? null : round($found['rows_ratio'], 2),
                'samples' => array_map(self::out(...), $samples),
            ],
            sprintf(
                'The optimizer treats %d and %d values differently. Compare plans with: php artisan lantern:probe %s --in-sizes=%d,%d. Then try EXPLAIN ANALYZE, a join-order or index hint, ANALYZE TABLE, or batching the list below %d values.',
                $from['in_size'],
                $to['in_size'],
                substr($key, 0, 12),
                $from['in_size'],
                $to['in_size'],
                $to['in_size'],
            ),
        );
    }

    /** @param array<string, mixed> $sample */
    private static function cost(array $sample): string
    {
        return round($sample['avg_ms'], 1).' ms'.($sample['rows'] !== null ? ', ~'.$sample['rows'].' rows' : '');
    }

    /**
     * @param array<string, mixed> $s
     * @return array<string, mixed>
     */
    private static function out(array $s): array
    {
        return [
            'in_size' => $s['in_size'],
            'avg_ms' => round((float) $s['avg_ms'], 2),
            'rows' => $s['rows'],
            'table' => $s['table'],
            'key' => $s['key'],
            'full_scan' => $s['full_scan'],
        ];
    }

    private function store(): ?PlanSampleStore
    {
        if (! $this->storeResolved) {
            $this->store = PlanSampleStore::fromConfig($this->config);
            $this->storeResolved = true;
        }

        return $this->store;
    }
}
