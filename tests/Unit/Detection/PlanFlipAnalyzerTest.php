<?php

declare(strict_types=1);

namespace Lantern\Tests\Unit\Detection;

use Lantern\Detection\PlanFlip\PlanFlipAnalyzer;
use PHPUnit\Framework\TestCase;

final class PlanFlipAnalyzerTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function sample(int $size, float $ms, ?string $table = 'order_items', ?string $key = 'merchant_id', bool $scan = false, ?int $rows = 100): array
    {
        return ['in_size' => $size, 'avg_ms' => $ms, 'rows' => $rows, 'table' => $table, 'key' => $key, 'full_scan' => $scan, 'explained' => true];
    }

    public function test_buckets_are_exact_up_to_64_then_coarser(): void
    {
        $this->assertSame(1, PlanFlipAnalyzer::bucket(1));
        $this->assertSame(41, PlanFlipAnalyzer::bucket(41));
        $this->assertSame(64, PlanFlipAnalyzer::bucket(64));
        $this->assertSame(PlanFlipAnalyzer::bucket(1000), PlanFlipAnalyzer::bucket(1010));
        $this->assertEqualsWithDelta(1000, PlanFlipAnalyzer::bucket(1000), 190);
    }

    public function test_plan_change_that_costs_more_than_the_list_growth(): void
    {
        $found = PlanFlipAnalyzer::find([
            self::sample(7, 66.0),
            self::sample(41, 2900.0, 'orders', null, true, 820_000),
        ]);

        $this->assertSame('plan', $found['kind']);
        $this->assertSame(7, $found['from']['in_size']);
        $this->assertSame(41, $found['to']['in_size']);
        $this->assertGreaterThan(40, $found['time_ratio']);
    }

    public function test_plan_change_to_a_cheaper_plan_is_not_reported(): void
    {
        $this->assertNull(PlanFlipAnalyzer::find([
            self::sample(1, 400.0, 'orders', null, true, 820_000),
            self::sample(41, 20.0),
        ]));
    }

    public function test_plan_change_on_a_tiny_query_is_not_reported(): void
    {
        $this->assertNull(PlanFlipAnalyzer::find([
            self::sample(3, 0.2, 't', 'idx', false, 10),
            self::sample(30, 0.4, 't', null, true, 40),
        ]));
    }

    public function test_cost_growing_faster_than_the_list_without_a_plan_change(): void
    {
        $found = PlanFlipAnalyzer::find([self::sample(7, 10.0), self::sample(41, 400.0)]);

        $this->assertSame('growth', $found['kind']);
    }

    public function test_proportional_growth_is_not_reported(): void
    {
        $this->assertNull(PlanFlipAnalyzer::find([self::sample(7, 10.0), self::sample(41, 70.0), self::sample(100, 150.0)]));
    }

    public function test_slow_drift_over_several_steps_is_seen_against_the_smallest_size(): void
    {
        $found = PlanFlipAnalyzer::find([self::sample(10, 10.0), self::sample(20, 40.0), self::sample(40, 160.0)]);

        $this->assertSame('growth', $found['kind']);
        $this->assertSame(10, $found['from']['in_size']);
        $this->assertSame(40, $found['to']['in_size']);
    }

    public function test_a_single_size_never_flips(): void
    {
        $this->assertNull(PlanFlipAnalyzer::find([self::sample(7, 5000.0)]));
        $this->assertNull(PlanFlipAnalyzer::find([]));
    }

    public function test_changes_lists_every_plan_difference_in_size_order(): void
    {
        $changes = PlanFlipAnalyzer::changes([
            self::sample(100, 0.0, 'orders', null, true),
            self::sample(1, 0.0),
            self::sample(7, 0.0),
            self::sample(41, 0.0, 'orders', 'primary'),
        ]);

        $this->assertCount(2, $changes);
        $this->assertSame([7, 41], [$changes[0]['from']['in_size'], $changes[0]['to']['in_size']]);
        $this->assertSame([41, 100], [$changes[1]['from']['in_size'], $changes[1]['to']['in_size']]);
        $this->assertSame('full scan on orders', PlanFlipAnalyzer::describe($changes[1]['to']));
        $this->assertSame('index primary on orders', PlanFlipAnalyzer::describe($changes[0]['to']));
    }
}
