<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Explain;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lantern\Detection\Detectors\PlanFlipDetector;
use Lantern\Detection\Issue;
use Lantern\Storage\Pruner;
use Lantern\Tests\Feature\Query\QueryTestCase;
use Lantern\Trace\SpanType;
use Lantern\Trace\Trace;

final class PlanFlipDetectorTest extends QueryTestCase
{
    private const FP = 'fp-orders-by-merchant';

    private int $explains = 0;

    /** Lists longer than this are planned as a full scan. */
    private int $scanAbove = 20;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['000001_create_lantern_traces_table', '000002_create_lantern_issues_table', '000004_create_lantern_plan_samples_table', '000005_add_sql_hash_to_lantern_plan_samples_table'] as $file) {
            (require dirname(__DIR__, 3).'/database/migrations/2024_01_01_'.$file.'.php')->up();
        }
    }

    /** @return array<string, mixed> */
    private function config(bool $explain = true, string $driver = 'database', array $planFlip = []): array
    {
        return ['explain' => $explain, 'storage' => ['driver' => $driver], 'plan_flip' => $planFlip, 'thresholds' => ['slow_query_ms' => 100, 'n_plus_one_min_repeats' => 5]];
    }

    private function detector(array $config = []): PlanFlipDetector
    {
        return new PlanFlipDetector($config ?: $this->config(), null, function (?string $connection, string $sql): array {
            $this->explains++;
            $scan = substr_count($sql, '?') > $this->scanAbove && ! str_contains($sql, 'JOIN_ORDER');

            return [
                'full_scan' => $scan,
                'table' => $scan ? 'orders' : 'order_items',
                'rows' => $scan ? 820_000 : 120,
                'key' => $scan ? null : 'merchant_id_2',
                'possible_keys' => null,
                'raw' => [],
            ];
        });
    }

    /** @param list<array{0: int, 1: float}> $queries IN list length and execution time */
    private function trace(array $queries, int $line = 10, string $hint = ''): Trace
    {
        $recorder = $this->recorder();
        $recorder->reset();
        $trace = $recorder->begin();
        foreach ($queries as [$size, $ms]) {
            $now = hrtime(true);
            $recorder->recordClosed(SpanType::Query, 'q', $now, $now + (int) ($ms * 1_000_000), [
                'sql' => 'select '.$hint.'* from "order_items" where "merchant_id" in ('.implode(', ', array_fill(0, $size, '?')).')',
                'bindings' => [], 'fingerprint' => self::FP, 'connection' => 'testing',
                'time_ms' => $ms, 'failed' => false, 'in_size' => $size,
            ], ['file' => 'app/Orders.php', 'line' => $line]);
        }

        return $trace;
    }

    private function samples(): int
    {
        return DB::table('lantern_plan_samples')->count();
    }

    public function test_flip_is_found_across_requests_and_the_key_ignores_line_numbers(): void
    {
        $detector = $this->detector();

        $this->assertSame([], $detector->detect($this->trace([[7, 66.0]])));

        $issues = $detector->detect($this->trace([[41, 2900.0]], 121));
        $this->assertCount(1, $issues);
        $issue = $issues[0];
        $this->assertSame(Issue::TYPE_PLAN_FLIP, $issue->type);
        $this->assertSame(Issue::key(Issue::TYPE_PLAN_FLIP, self::FP), $issue->key);
        $this->assertSame(Issue::SEV_CRITICAL, $issue->severity);
        $this->assertSame('plan', $issue->meta['kind']);
        $this->assertSame(7, $issue->meta['from']['in_size']);
        $this->assertSame(41, $issue->meta['to']['in_size']);
        $this->assertSame('merchant_id_2', $issue->meta['from']['key']);
        $this->assertTrue($issue->meta['to']['full_scan']);
        $this->assertStringContainsString('index merchant_id_2 on order_items at 7 values, full scan on orders at 41', $issue->title);
        $this->assertStringContainsString('lantern:probe '.substr($issue->key, 0, 12).' --in-sizes=7,41', (string) $issue->suggestion);
        $this->assertStringContainsString('in (?', $issue->meta['sample_sql']);

        $moved = $detector->detect($this->trace([[41, 2900.0]], 126));
        $this->assertSame($issue->key, $moved[0]->key);
    }

    public function test_known_sizes_are_not_explained_again_and_averages_accumulate(): void
    {
        $detector = $this->detector();

        $detector->detect($this->trace([[7, 10.0]]));
        $detector->detect($this->trace([[7, 30.0]]));

        $this->assertSame(1, $this->explains);
        $row = DB::table('lantern_plan_samples')->where('in_size', 7)->first();
        $this->assertSame(2, (int) $row->samples);
        $this->assertEqualsWithDelta(20.0, (float) $row->avg_ms, 0.001);
        $this->assertSame('merchant_id_2', $row->plan_key);
    }

    public function test_stable_plan_with_proportional_cost_is_not_a_flip(): void
    {
        $this->scanAbove = PHP_INT_MAX;
        $detector = $this->detector();

        $detector->detect($this->trace([[7, 20.0]]));

        $this->assertSame([], $detector->detect($this->trace([[41, 120.0]])));
        $this->assertSame(2, $this->samples());
    }

    public function test_cost_growing_faster_than_the_list_is_reported_without_a_plan_change(): void
    {
        $this->scanAbove = PHP_INT_MAX;
        $detector = $this->detector();

        $detector->detect($this->trace([[7, 10.0]]));
        $issues = $detector->detect($this->trace([[41, 400.0]]));

        $this->assertCount(1, $issues);
        $this->assertSame('growth', $issues[0]->meta['kind']);
        $this->assertSame(Issue::SEV_WARNING, $issues[0]->severity);
    }

    public function test_sizes_within_one_trace_are_compared_without_persistence(): void
    {
        $issues = $this->detector($this->config(true, 'memory'))->detect($this->trace([[7, 66.0], [41, 2900.0]]));

        $this->assertCount(1, $issues);
        $this->assertSame(0, $this->samples());
    }

    public function test_missing_samples_table_degrades_to_the_current_trace(): void
    {
        Schema::drop('lantern_plan_samples');

        $issues = $this->detector()->detect($this->trace([[7, 66.0], [41, 2900.0]]));

        $this->assertCount(1, $issues);
    }

    public function test_disabled_explain_does_nothing(): void
    {
        $this->assertSame([], $this->detector($this->config(false))->detect($this->trace([[7, 66.0], [41, 2900.0]])));
        $this->assertSame(0, $this->explains);
        $this->assertSame(0, $this->samples());
    }

    public function test_queries_without_an_in_list_are_ignored(): void
    {
        $recorder = $this->recorder();
        $recorder->reset();
        $trace = $recorder->begin();
        $now = hrtime(true);
        $recorder->recordClosed(SpanType::Query, 'q', $now, $now + 5_000_000, ['sql' => 'select * from "order_items" where "id" = ?', 'fingerprint' => 'x', 'time_ms' => 5.0, 'failed' => false]);

        $this->assertSame([], $this->detector()->detect($trace));
        $this->assertSame(0, $this->explains);
    }

    public function test_shapes_per_trace_and_sizes_per_shape_are_capped(): void
    {
        $detector = $this->detector($this->config(true));
        $recorder = $this->recorder();
        $recorder->reset();
        $trace = $recorder->begin();
        for ($shape = 0; $shape < 14; $shape++) {
            $now = hrtime(true);
            $recorder->recordClosed(SpanType::Query, 'q', $now, $now + 1_000_000, [
                'sql' => 'select * from "t'.$shape.'" where "id" in (?, ?)', 'fingerprint' => 'shape-'.$shape,
                'time_ms' => 1.0, 'failed' => false, 'in_size' => 2,
            ]);
        }
        $detector->detect($trace);
        $this->assertSame(10, DB::table('lantern_plan_samples')->distinct()->count('shape'));
        $this->assertLessThanOrEqual(3, $this->explains);

        $many = $this->detector();
        for ($size = 1; $size <= 70; $size++) {
            $many->detect($this->trace([[$size, 1.0]]));
        }
        $this->assertSame(64, DB::table('lantern_plan_samples')->where('shape', self::FP)->count());
    }

    public function test_adding_an_optimizer_hint_discards_the_old_samples_and_ends_the_flip(): void
    {
        $hint = '/*+ JOIN_ORDER(order_items, orders) */ ';
        $detector = $this->detector();
        $detector->detect($this->trace([[7, 66.0]]));
        $this->assertCount(1, $detector->detect($this->trace([[41, 2900.0]])));
        $before = $this->explains;

        $this->assertSame([], $detector->detect($this->trace([[41, 150.0]], 10, $hint)), 'the old 7-value sample no longer counts');
        $this->assertSame($before + 1, $this->explains, 'the hinted text is explained afresh');
        $row = DB::table('lantern_plan_samples')->where('in_size', 41)->first();
        $this->assertSame(1, (int) $row->samples);
        $this->assertEqualsWithDelta(150.0, (float) $row->avg_ms, 0.001, 'old and new runs are not averaged together');
        $this->assertSame('merchant_id_2', $row->plan_key);
        $this->assertSame(1, $this->samples(), 'the 7-value sample from the unhinted text is gone');

        $this->assertSame([], $detector->detect($this->trace([[7, 20.0]], 10, $hint)));
        $this->assertSame(2, $this->samples());
        $this->assertSame([], $detector->detect($this->trace([[41, 140.0]], 10, $hint)));
    }

    public function test_samples_without_a_hash_are_rewritten_once(): void
    {
        $old = ['samples' => 5, 'updated_at' => now(), 'explained_at' => now()];
        DB::table('lantern_plan_samples')->insert([
            ['shape' => self::FP, 'in_size' => 7, 'avg_ms' => 66.0, 'plan_table' => 'order_items', 'plan_key' => 'merchant_id_2', 'plan_rows' => 120, 'full_scan' => false] + $old,
            ['shape' => self::FP, 'in_size' => 41, 'avg_ms' => 2900.0, 'plan_table' => 'orders', 'plan_key' => null, 'plan_rows' => 820_000, 'full_scan' => true] + $old,
        ]);

        $this->scanAbove = PHP_INT_MAX;
        $detector = $this->detector();
        $this->assertSame([], $detector->detect($this->trace([[7, 60.0]])));

        $this->assertSame(1, $this->samples());
        $row = DB::table('lantern_plan_samples')->first();
        $this->assertNotNull($row->sql_hash);
        $this->assertSame(1, (int) $row->samples);
        $this->assertEqualsWithDelta(60.0, (float) $row->avg_ms, 0.001);

        $detector->detect($this->trace([[7, 40.0]]));
        $this->assertSame(1, $this->samples());
        $this->assertSame(2, (int) DB::table('lantern_plan_samples')->value('samples'), 'a hashed sample is kept and averaged');
    }

    public function test_a_time_far_from_the_average_triggers_a_fresh_explain(): void
    {
        $this->scanAbove = PHP_INT_MAX;
        $detector = $this->detector();
        $detector->detect($this->trace([[41, 100.0]]));
        $this->assertSame(1, $this->explains);

        $detector->detect($this->trace([[41, 1000.0]]));
        $this->assertSame(1, $this->explains, 'a fresh sample is not re-explained within the cooldown');

        DB::table('lantern_plan_samples')->update(['explained_at' => now()->subMinutes(10), 'avg_ms' => 100, 'samples' => 1]);
        $detector->detect($this->trace([[41, 110.0]]));
        $this->assertSame(1, $this->explains, 'a time close to the average does not trigger');

        $detector->detect($this->trace([[41, 1500.0]]));
        $this->assertSame(2, $this->explains);

        DB::table('lantern_plan_samples')->update(['explained_at' => now()->subMinutes(10)]);
        $off = $this->detector($this->config(true, 'database', ['time_deviation' => 0]));
        $off->detect($this->trace([[41, 5000.0]]));
        $this->assertSame(2, $this->explains, 'time_deviation 0 turns the trigger off');
    }

    public function test_a_changed_plan_restarts_the_average(): void
    {
        $detector = $this->detector();
        $detector->detect($this->trace([[41, 2900.0]]));
        $detector->detect($this->trace([[41, 2900.0]]));
        $this->assertSame(2, (int) DB::table('lantern_plan_samples')->value('samples'));

        $this->scanAbove = PHP_INT_MAX;
        DB::table('lantern_plan_samples')->update(['explained_at' => now()->subMinutes(90)]);
        $detector->detect($this->trace([[41, 120.0]]));

        $row = DB::table('lantern_plan_samples')->first();
        $this->assertSame(1, (int) $row->samples);
        $this->assertEqualsWithDelta(120.0, (float) $row->avg_ms, 0.001);
        $this->assertSame('merchant_id_2', $row->plan_key);
    }

    public function test_store_keeps_working_before_the_hash_migration_has_run(): void
    {
        Schema::table('lantern_plan_samples', fn ($table) => $table->dropColumn('sql_hash'));

        $detector = $this->detector();
        $detector->detect($this->trace([[7, 66.0]]));
        $issues = $detector->detect($this->trace([[41, 2900.0]]));

        $this->assertCount(1, $issues);
        $this->assertSame(2, $this->samples());
    }

    public function test_pruner_removes_old_samples(): void
    {
        $this->detector()->detect($this->trace([[7, 10.0]]));
        DB::table('lantern_plan_samples')->insert(['shape' => 'old', 'in_size' => 3, 'samples' => 1, 'avg_ms' => 1, 'updated_at' => now()->subDays(30)]);

        (new Pruner)->prune();

        $this->assertSame([self::FP], DB::table('lantern_plan_samples')->pluck('shape')->all());
    }
}
