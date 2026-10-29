<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Matrix;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lantern\Detection\Detectors\PlanFlipDetector;
use Lantern\Detection\Explain\ExplainRunner;
use Lantern\Detection\Issue;
use Lantern\Detection\PlanFlip\InListProbe;
use Lantern\Detection\PlanFlip\PlanSampleStore;
use Lantern\Storage\Pruner;
use Lantern\Trace\Recorder;
use Lantern\Trace\SpanType;
use Lantern\Trace\Trace;

final class PlanFlipMatrixTest extends MatrixTestCase
{
    private const MIGRATIONS = ['2024_01_01_000001_create_lantern_traces_table', '2024_01_01_000002_create_lantern_issues_table', '2024_01_01_000004_create_lantern_plan_samples_table'];

    private const FP = 'fp-mx-items-by-merchant';

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrate('down', array_reverse(self::MIGRATIONS));
        $this->migrate('up', self::MIGRATIONS);
    }

    protected function tearDown(): void
    {
        if (self::driver() !== null && $this->app !== null) {
            $this->migrate('down', array_reverse(self::MIGRATIONS));
        }
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        return ['explain' => true, 'storage' => ['driver' => 'database'], 'thresholds' => ['slow_query_ms' => 100, 'n_plus_one_min_repeats' => 5]];
    }

    private function inSql(int $size): string
    {
        return 'select * from mx_items where merchant_id in ('.implode(', ', array_fill(0, $size, '?')).')';
    }

    private function trace(int $size, float $ms): Trace
    {
        $recorder = new Recorder;
        $trace = $recorder->begin(['kind' => 'request', 'method' => 'GET', 'path' => '/x', 'status' => 200]);
        $now = hrtime(true);
        $recorder->recordClosed(SpanType::Query, 'q', $now, $now + (int) ($ms * 1_000_000), [
            'sql' => $this->inSql($size), 'bindings' => range(1, $size), 'fingerprint' => self::FP, 'connection' => self::driver(),
            'time_ms' => $ms, 'failed' => false, 'in_size' => $size,
        ], ['file' => 'app/Items.php', 'line' => 12]);

        return $trace;
    }

    public function test_sample_store_accumulates_and_keeps_the_plan_across_engines(): void
    {
        $store = new PlanSampleStore;
        $plan = ['table' => 'mx_items', 'key' => 'mx_merchant_id_2', 'rows' => 120, 'full_scan' => false];

        $store->record(self::FP, 7, 10.0, $plan);
        $store->record(self::FP, 7, 30.0, null);
        $store->record(self::FP, 41, 2900.0, ['table' => 'mx_orders', 'key' => null, 'rows' => 820_000, 'full_scan' => true]);

        $samples = [];
        foreach ($store->samples(self::FP) as $s) {
            $samples[$s['in_size']] = $s;
        }
        $this->assertSame([7, 41], array_keys($samples));
        $this->assertSame(2, $samples[7]['samples']);
        $this->assertEqualsWithDelta(20.0, $samples[7]['avg_ms'], 0.001);
        $this->assertSame('mx_merchant_id_2', $samples[7]['key']);
        $this->assertFalse($samples[7]['full_scan']);
        $this->assertTrue($samples[7]['explained']);
        $this->assertTrue($samples[41]['full_scan']);
        $this->assertSame(820_000, $samples[41]['rows']);
        $this->assertNull($samples[41]['key']);
        $this->assertFalse(PlanSampleStore::needsExplain($samples[7], 60));
        $this->assertSame([], $store->samples('fp-other'));
    }

    public function test_sample_store_caps_sizes_per_shape(): void
    {
        $store = new PlanSampleStore;
        foreach (range(1, PlanSampleStore::MAX_PER_SHAPE + 6) as $size) {
            $store->record(self::FP, $size, 1.0, null);
        }

        $this->assertLessThanOrEqual(PlanSampleStore::MAX_PER_SHAPE, DB::table('lantern_plan_samples')->where('shape', self::FP)->count());
    }

    public function test_pruner_removes_old_samples(): void
    {
        $store = new PlanSampleStore;
        $store->record(self::FP, 7, 1.0, null);
        $store->record('fp-old', 7, 1.0, null);
        DB::table('lantern_plan_samples')->where('shape', 'fp-old')->update(['updated_at' => now()->subDays(60)]);

        (new Pruner)->prune();

        $this->assertSame([self::FP], DB::table('lantern_plan_samples')->pluck('shape')->all());
    }

    public function test_detector_stores_real_explain_plans_and_flip_is_found_across_requests(): void
    {
        $this->seedShop();
        $explains = 0;
        $detector = new PlanFlipDetector($this->config(), null, function (?string $connection, string $sql) use (&$explains): array {
            $explains++;
            $scan = substr_count($sql, '?') > 20;

            return ['full_scan' => $scan, 'table' => $scan ? 'mx_orders' : 'mx_items', 'rows' => $scan ? 820_000 : 120, 'key' => $scan ? null : 'mx_merchant_id_2', 'possible_keys' => null, 'raw' => []];
        });

        $this->assertSame([], $detector->detect($this->trace(7, 66.0)));
        $issues = $detector->detect($this->trace(41, 2900.0));

        $this->assertCount(1, $issues);
        $this->assertSame(Issue::TYPE_PLAN_FLIP, $issues[0]->type);
        $this->assertSame(Issue::key(Issue::TYPE_PLAN_FLIP, self::FP), $issues[0]->key);
        $this->assertSame(7, $issues[0]->meta['from']['in_size']);
        $this->assertSame(41, $issues[0]->meta['to']['in_size']);
        $this->assertSame(2, $explains);
        $this->assertSame(2, DB::table('lantern_plan_samples')->where('shape', self::FP)->count());

        $detector->detect($this->trace(41, 2800.0));
        $this->assertSame(2, $explains, 'a known size is not explained again');
        $this->assertSame(2, (int) DB::table('lantern_plan_samples')->where('in_size', 41)->value('samples'));
    }

    public function test_detector_persists_the_plan_of_a_real_explain(): void
    {
        $this->seedShop();
        $detector = new PlanFlipDetector($this->config(), new ExplainRunner);

        $detector->detect($this->trace(3, 12.0));

        $row = (array) DB::table('lantern_plan_samples')->where('shape', self::FP)->where('in_size', 3)->first();
        $this->assertSame('mx_items', $row['plan_table']);
        $this->assertNotNull($row['explained_at']);
        $this->assertNotNull($row['plan_rows']);
        $this->assertSame(1, (int) $row['samples']);
    }

    public function test_probe_explains_real_ids_at_each_size(): void
    {
        $this->seedShop();
        $probe = new InListProbe(new ExplainRunner);

        $out = $probe->run('select * from mx_items where merchant_id in (?, ?, ?) and order_id > ?', [1, 2, 3, 10], self::driver(), [1, 5, 20, 500]);

        $this->assertNotNull($out);
        $this->assertSame('mx_items', $out['table']);
        $this->assertSame('merchant_id', $out['column']);
        $this->assertSame('table', $out['ids_source']);
        $this->assertSame([1, 5, 20, 50], array_column($out['sizes'], 'in_size'), 'the 50 real merchant ids cap the largest list');
        foreach ($out['sizes'] as $row) {
            $this->assertNotNull($row['plan'], 'EXPLAIN works at size '.$row['in_size']);
            $this->assertSame('mx_items', $row['plan']['table']);
            $this->assertIsString($row['plan']['summary']);
        }
        $this->assertIsArray($out['plan_changes']);

        $this->assertNull($probe->run('select * from mx_items where id = ?', [1], self::driver(), [1, 5]));
    }

    public function test_probe_falls_back_to_synthetic_ids_when_the_table_is_unknown(): void
    {
        $this->seedShop();

        $out = (new InListProbe(new ExplainRunner))->run('select * from mx_items where nope_col in (?, ?)', [1, 2], self::driver(), [2, 4]);

        $this->assertNotNull($out);
        $this->assertSame('synthetic', $out['ids_source']);
        $this->assertSame([2, 4], array_column($out['sizes'], 'in_size'));
        $this->assertTrue(Schema::hasTable('mx_items'));
    }
}
