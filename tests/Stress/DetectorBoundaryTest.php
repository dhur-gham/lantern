<?php

declare(strict_types=1);

namespace Lantern\Tests\Stress;

use Lantern\Detection\Analyzer;
use Lantern\Detection\Detectors\DuplicateQueryDetector;
use Lantern\Detection\Detectors\LazyLoadDetector;
use Lantern\Detection\Detectors\MemoryHogDetector;
use Lantern\Detection\Detectors\NPlusOneDetector;
use Lantern\Detection\Detectors\SlowQueryDetector;
use Lantern\Query\Fingerprinter;
use Lantern\Trace\Recorder;
use Lantern\Trace\SpanType;
use Lantern\Trace\Trace;
use PHPUnit\Framework\TestCase;

final class DetectorBoundaryTest extends TestCase
{
    private function config(array $thresholds = []): array
    {
        return ['thresholds' => $thresholds + ['n_plus_one_min_repeats' => 5, 'duplicate_min_repeats' => 2, 'slow_query_ms' => 100, 'memory_delta_mb' => 32, 'max_issues_per_trace' => 50]];
    }

    /** @param list<array{sql?: string, ms?: float, bindings?: array, site?: int}> $queries */
    private function trace(array $queries): Trace
    {
        $f = new Fingerprinter;
        $r = new Recorder;
        $trace = $r->begin();
        foreach ($queries as $q) {
            $sql = $q['sql'] ?? 'select * from users where id = ?';
            $bindings = $q['bindings'] ?? [1];
            $r->recordClosed(SpanType::Query, 'q', 1, 2, [
                'sql' => $sql, 'normalized_sql' => $f->normalize($sql), 'fingerprint' => $f->fingerprint($sql),
                'bindings_key' => $f->bindingsKey($sql, $bindings), 'bindings' => $bindings,
                'time_ms' => $q['ms'] ?? 1.0, 'failed' => false,
            ], ['file' => '/app/A.php', 'line' => $q['site'] ?? 10]);
        }
        $r->end();

        return $trace;
    }

    private function distinct(int $n, array $extra = []): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = ['bindings' => [$i]] + $extra;
        }

        return $out;
    }

    public function test_n_plus_one_boundary_default_threshold(): void
    {
        $d = new NPlusOneDetector($this->config());

        $this->assertCount(0, $d->detect($this->trace($this->distinct(4))));
        $this->assertCount(1, $d->detect($this->trace($this->distinct(5))));
        $this->assertCount(1, $d->detect($this->trace($this->distinct(6))));
    }

    public function test_n_plus_one_boundary_custom_threshold(): void
    {
        $d = new NPlusOneDetector($this->config(['n_plus_one_min_repeats' => 9]));

        $this->assertCount(0, $d->detect($this->trace($this->distinct(8))));
        $this->assertCount(1, $d->detect($this->trace($this->distinct(9))));
        $this->assertCount(1, $d->detect($this->trace($this->distinct(10))));
    }

    public function test_n_plus_one_threshold_below_two_is_clamped_to_two(): void
    {
        foreach ([0, 1, -5] as $min) {
            $d = new NPlusOneDetector($this->config(['n_plus_one_min_repeats' => $min]));
            $this->assertCount(0, $d->detect($this->trace($this->distinct(1))), "min=$min");
            $this->assertCount(1, $d->detect($this->trace($this->distinct(2))), "min=$min");
        }
    }

    public function test_n_plus_one_severity_boundary(): void
    {
        $d = new NPlusOneDetector($this->config());

        $this->assertSame('warning', $d->detect($this->trace($this->distinct(19)))[0]->severity);
        $this->assertSame('critical', $d->detect($this->trace($this->distinct(20)))[0]->severity);
    }

    public function test_n_plus_one_requires_the_same_call_site(): void
    {
        $d = new NPlusOneDetector($this->config());
        $queries = [];
        for ($i = 0; $i < 8; $i++) {
            $queries[] = ['bindings' => [$i], 'site' => $i % 2 === 0 ? 10 : 20];
        }

        $this->assertCount(0, $d->detect($this->trace($queries)));
    }

    public function test_n_plus_one_ignores_failed_and_transaction_spans(): void
    {
        $r = new Recorder;
        $trace = $r->begin();
        for ($i = 0; $i < 10; $i++) {
            $r->recordClosed(SpanType::Query, 'q', 1, 2, ['sql' => 's', 'fingerprint' => 'fp', 'failed' => true, 'time_ms' => 1], ['file' => 'a', 'line' => 1]);
            $r->recordClosed(SpanType::Query, 'BEGIN', 1, 2, ['kind' => 'transaction', 'fingerprint' => 'fp'], ['file' => 'a', 'line' => 1]);
        }
        $r->end();

        $this->assertCount(0, (new NPlusOneDetector($this->config()))->detect($trace));
    }

    public function test_duplicate_boundary(): void
    {
        $d = new DuplicateQueryDetector($this->config());

        $this->assertCount(0, $d->detect($this->trace([['bindings' => [1]]])));
        $this->assertCount(1, $d->detect($this->trace([['bindings' => [1]], ['bindings' => [1]]])));
        $this->assertCount(0, $d->detect($this->trace([['bindings' => [1]], ['bindings' => [2]]])));
    }

    public function test_duplicate_threshold_three(): void
    {
        $d = new DuplicateQueryDetector($this->config(['duplicate_min_repeats' => 3]));
        $same = fn (int $n) => array_fill(0, $n, ['bindings' => [7]]);

        $this->assertCount(0, $d->detect($this->trace($same(2))));
        $this->assertCount(1, $d->detect($this->trace($same(3))));
        $this->assertCount(1, $d->detect($this->trace($same(4))));
    }

    public function test_duplicate_severity_boundary(): void
    {
        $d = new DuplicateQueryDetector($this->config());

        $this->assertSame('info', $d->detect($this->trace(array_fill(0, 2, ['bindings' => [1]])))[0]->severity);
        $this->assertSame('warning', $d->detect($this->trace(array_fill(0, 3, ['bindings' => [1]])))[0]->severity);
    }

    public function test_slow_query_boundary_is_inclusive(): void
    {
        $d = new SlowQueryDetector($this->config());

        $this->assertCount(0, $d->detect($this->trace([['ms' => 99.999]])));
        $this->assertCount(1, $d->detect($this->trace([['ms' => 100.0]])));
        $this->assertCount(1, $d->detect($this->trace([['ms' => 100.001]])));
    }

    public function test_slow_query_severity_boundary(): void
    {
        $d = new SlowQueryDetector($this->config());

        $this->assertSame('warning', $d->detect($this->trace([['ms' => 499.9]]))[0]->severity);
        $this->assertSame('critical', $d->detect($this->trace([['ms' => 500.0]]))[0]->severity);
    }

    public function test_slow_query_threshold_zero_flags_everything(): void
    {
        $d = new SlowQueryDetector($this->config(['slow_query_ms' => 0]));

        $this->assertCount(1, $d->detect($this->trace([['ms' => 0.0]])));
    }

    public function test_lazy_load_boundary(): void
    {
        $d = new LazyLoadDetector($this->config());
        $make = function (int $n): Trace {
            $r = new Recorder;
            $trace = $r->begin();
            for ($i = 0; $i < $n; $i++) {
                $r->recordClosed(SpanType::Event, 'lazy', 1, 1, ['kind' => 'lazy_load', 'model' => 'App\\Post', 'relation' => 'author'], ['file' => 'a.php', 'line' => 3]);
            }
            $r->end();

            return $trace;
        };

        $this->assertCount(0, $d->detect($make(4)));
        $this->assertCount(1, $d->detect($make(5)));
        $this->assertCount(1, $d->detect($make(6)));
        $this->assertSame('critical', $d->detect($make(20))[0]->severity);
        $this->assertSame('warning', $d->detect($make(19))[0]->severity);
    }

    public function test_memory_hog_boundary_with_a_stopped_span(): void
    {
        $d = new MemoryHogDetector($this->config(['memory_delta_mb' => 1]));
        $r = new Recorder;
        $trace = $r->begin();
        $span = $r->start(SpanType::Controller, 'C@x');
        $hold = str_repeat('m', 3 * 1048576);
        $r->stop($span);
        $r->end();

        $this->assertCount(1, $d->detect($trace));
        unset($hold);
    }

    public function test_memory_hog_threshold_zero_or_negative_disables_it(): void
    {
        $r = new Recorder;
        $trace = $r->begin();
        $r->end();

        $this->assertSame([], (new MemoryHogDetector($this->config(['memory_delta_mb' => 0])))->detect($trace));
        $this->assertSame([], (new MemoryHogDetector($this->config(['memory_delta_mb' => -4])))->detect($trace));
    }

    public function test_analyzer_caps_issues_and_orders_by_severity(): void
    {
        $queries = [];
        foreach (range(1, 8) as $t) {
            for ($i = 0; $i < 6; $i++) {
                $queries[] = ['sql' => "select * from t$t where id = ?", 'bindings' => [$i], 'ms' => 1.0];
            }
        }
        $analyzer = new Analyzer(['detectors' => [NPlusOneDetector::class]] + $this->config(['max_issues_per_trace' => 3]));

        $this->assertCount(3, $analyzer->analyze($this->trace($queries)));
    }

    public function test_analyzer_with_max_zero_is_unbounded(): void
    {
        $queries = [];
        foreach (range(1, 8) as $t) {
            for ($i = 0; $i < 6; $i++) {
                $queries[] = ['sql' => "select * from t$t where id = ?", 'bindings' => [$i]];
            }
        }
        $analyzer = new Analyzer(['detectors' => [NPlusOneDetector::class]] + $this->config(['max_issues_per_trace' => 0]));

        $this->assertCount(8, $analyzer->analyze($this->trace($queries)));
    }

    public function test_n_plus_one_hides_framework_tables_at_the_boundary(): void
    {
        $d = new NPlusOneDetector($this->config() + ['ignore_tables' => ['sessions']]);
        $q = fn (string $t) => array_map(fn ($i) => ['sql' => "select * from $t where id = ?", 'bindings' => [$i]], range(1, 6));

        $this->assertCount(0, $d->detect($this->trace($q('sessions'))));
        $this->assertCount(1, $d->detect($this->trace($q('users'))));
    }

    public function test_analyzer_on_an_empty_trace(): void
    {
        $r = new Recorder;
        $trace = $r->begin();
        $r->end();

        $this->assertSame([], (new Analyzer($this->config() + ['detectors' => [NPlusOneDetector::class, DuplicateQueryDetector::class, SlowQueryDetector::class, MemoryHogDetector::class, LazyLoadDetector::class]]))->analyze($trace));
    }
}
