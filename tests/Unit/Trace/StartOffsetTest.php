<?php

declare(strict_types=1);

namespace Lantern\Tests\Unit\Trace;

use Lantern\Trace\Recorder;
use Lantern\Trace\SpanType;
use PHPUnit\Framework\TestCase;

final class StartOffsetTest extends TestCase
{
    public function test_nodes_carry_start_offset_relative_to_trace_start(): void
    {
        $recorder = new Recorder();
        $recorder->begin();
        $first = $recorder->start(SpanType::Middleware, 'first');
        $recorder->stop($first);
        // usleep() can return early on Windows, so wait on the monotonic clock
        $until = hrtime(true) + 2_000_000;
        while (hrtime(true) < $until) {
            usleep(200);
        }
        $second = $recorder->start(SpanType::Middleware, 'second');
        $recorder->stop($second);
        $tree = $recorder->end()->toArray()['root'];

        $this->assertLessThan(1.0, $tree['start_ms']);
        [$a, $b] = $tree['children'];
        $this->assertGreaterThanOrEqual($a['start_ms'] + $a['duration_ms'], $b['start_ms']);
        $this->assertGreaterThan(1.0, $b['start_ms']);
    }
}
