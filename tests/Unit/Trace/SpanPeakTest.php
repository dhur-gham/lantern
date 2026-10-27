<?php

declare(strict_types=1);

namespace Lantern\Tests\Unit\Trace;

use Lantern\Detection\Detectors\MemoryHogDetector;
use Lantern\Detection\Issue;
use Lantern\Trace\Recorder;
use Lantern\Trace\SpanType;
use PHPUnit\Framework\TestCase;

final class SpanPeakTest extends TestCase
{
    public function test_transient_growth_is_visible_through_peak_but_not_net_delta(): void
    {
        $r = new Recorder;
        $r->begin([], 0);
        $span = $r->start(SpanType::Controller, 'Hog@index');
        $blob = str_repeat('x', 12 * 1048576); // hydrated rows, say
        $r->stop($span);
        unset($blob);

        $this->assertGreaterThanOrEqual(10 * 1048576, $span->memoryPeakGrowth());

        $issues = (new MemoryHogDetector(['thresholds' => ['memory_delta_mb' => 8]]))->detect($r->end());
        $this->assertCount(1, $issues);
        $this->assertSame(Issue::TYPE_MEMORY, $issues[0]->type);
    }

    public function test_no_peak_growth_when_high_water_mark_did_not_move(): void
    {
        $big = str_repeat('x', 6 * 1048576);
        unset($big);

        $r = new Recorder;
        $r->begin([], 0);
        $span = $r->start(SpanType::Controller, 'Calm@index');
        $r->stop($span);

        $this->assertLessThan(1048576, $span->memoryPeakGrowth());
    }
}
