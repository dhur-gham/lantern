<?php

declare(strict_types=1);

namespace Lantern\Tests\Unit\Trace;

use Lantern\Trace\Recorder;
use Lantern\Trace\Span;
use Lantern\Trace\SpanType;
use PHPUnit\Framework\TestCase;

final class RecorderTest extends TestCase
{
    private const MS = 1_000_000;

    public function test_begin_creates_root_request_span(): void
    {
        $r = new Recorder();
        $trace = $r->begin(['a' => 1], 0);

        $this->assertTrue($r->isActive());
        $this->assertSame(SpanType::Request, $trace->root()->type);
        $this->assertSame('request', $trace->root()->name);
        $this->assertNull($trace->root()->parentId);
        $this->assertSame(['a' => 1], $trace->meta);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $trace->id);
        $this->assertSame($trace->root(), $r->current());
    }

    public function test_nesting_and_parent_ids(): void
    {
        $r = new Recorder();
        $t = $r->begin();
        $a = $r->start(SpanType::Middleware, 'a');
        $b = $r->start(SpanType::Query, 'b');
        $this->assertSame($t->root()->id, $a->parentId);
        $this->assertSame($a->id, $b->parentId);
        $r->stop($b);
        $this->assertSame($a, $r->current());
        $c = $r->start(SpanType::Query, 'c');
        $this->assertSame($a->id, $c->parentId);
        $this->assertCount(2, $t->children($a->id));
        $this->assertCount(4, $t->spans());
    }

    public function test_self_time(): void
    {
        $r = new Recorder();
        $t = $r->begin(['x' => 1], 0);
        $a = $r->start(SpanType::Controller, 'a', [], 10 * self::MS);
        $r->recordClosed(SpanType::Query, 'q', 20 * self::MS, 30 * self::MS);
        $r->stop($a, 50 * self::MS);
        $r->end();

        $this->assertEqualsWithDelta(40.0, $a->durationMs(), 0.001);
        $this->assertEqualsWithDelta(30.0, $t->selfMs($a), 0.001);
    }

    public function test_self_time_floors_at_zero(): void
    {
        $r = new Recorder();
        $t = $r->begin([], 0);
        $r->start(SpanType::Controller, 'a', [], 0);
        $r->recordClosed(SpanType::Query, 'q', 0, 100 * self::MS);
        $r->recordClosed(SpanType::Query, 'q2', 0, 100 * self::MS);
        $a = $r->current();
        $r->stop($a, 100 * self::MS);
        $this->assertSame(0.0, $t->selfMs($a));
    }

    public function test_stop_closes_open_descendants(): void
    {
        $r = new Recorder();
        $r->begin();
        $a = $r->start(SpanType::Middleware, 'a');
        $b = $r->start(SpanType::Query, 'b');
        $c = $r->start(SpanType::Query, 'c');
        $r->stop($a);

        $this->assertTrue($a->isStopped());
        $this->assertTrue($b->isStopped());
        $this->assertTrue($c->isStopped());
        $this->assertSame($r->trace()->root(), $r->current());
    }

    public function test_inactive_is_noop(): void
    {
        $r = new Recorder();
        $s = $r->start(SpanType::Query, 'x', ['k' => 1]);
        $this->assertTrue($s->isStopped());
        $this->assertNull($r->current());
        $this->assertNull($r->trace());
        $this->assertNull($r->end());
        $r->stop($s);
        $closed = $r->recordClosed(SpanType::Query, 'y', 1, 2);
        $this->assertTrue($closed->isStopped());
        $this->assertSame('ok', $r->measure(SpanType::Query, 'm', fn () => 'ok'));
        $this->assertNull($r->trace());
    }

    public function test_record_closed_ordering_and_call_site(): void
    {
        $r = new Recorder();
        $t = $r->begin([], 0);
        $a = $r->start(SpanType::Controller, 'a', [], 0);
        $q1 = $r->recordClosed(SpanType::Query, 'q1', 1, 2, ['sql' => 'x'], ['file' => 'f.php', 'line' => 3]);
        $q2 = $r->recordClosed(SpanType::Query, 'q2', 3, 4);

        $this->assertTrue($q1->isStopped());
        $this->assertSame($a->id, $q1->parentId);
        $this->assertSame(['file' => 'f.php', 'line' => 3], $q1->callSite);
        $this->assertSame($a, $r->current());
        $this->assertSame([$q1, $q2], $t->children($a->id));
        $this->assertLessThan($q2->id, $q1->id);
    }

    public function test_measure_stops_on_exception(): void
    {
        $r = new Recorder();
        $t = $r->begin();
        try {
            $r->measure(SpanType::Cache, 'c', function () {
                throw new \RuntimeException('boom');
            });
            $this->fail('expected exception');
        } catch (\RuntimeException) {
        }

        $span = $t->children($t->root()->id)[0];
        $this->assertTrue($span->isStopped());
        $this->assertSame($t->root(), $r->current());
    }

    public function test_end_stops_everything(): void
    {
        $r = new Recorder();
        $r->begin();
        $a = $r->start(SpanType::Job, 'a');
        $t = $r->end();

        $this->assertFalse($r->isActive());
        $this->assertTrue($a->isStopped());
        $this->assertTrue($t->root()->isStopped());
        $this->assertNull($r->current());
        $this->assertSame($t, $r->trace());
    }

    public function test_reset_drops_everything_and_begin_resets(): void
    {
        $r = new Recorder();
        $first = $r->begin();
        $r->start(SpanType::Job, 'a');
        $r->reset();
        $this->assertNull($r->trace());
        $this->assertFalse($r->isActive());
        $this->assertNull($r->current());

        $second = $r->begin();
        $this->assertNotSame($first, $second);
        $this->assertNotSame($first->id, $second->id);
        $this->assertCount(1, $second->spans());
        $this->assertSame(1, $second->root()->id);
    }

    public function test_to_array_tree_shape(): void
    {
        $r = new Recorder();
        $t = $r->begin([], 0);
        $a = $r->start(SpanType::Route, 'a', ['m' => 1], 0);
        $r->recordClosed(SpanType::Query, 'q', 0, 5 * self::MS, [], ['file' => 'f', 'line' => 1]);
        $r->stop($a, 10 * self::MS);
        $r->end();

        $arr = $t->toArray();
        $root = $arr['root'];
        foreach (['id', 'type', 'name', 'duration_ms', 'self_ms', 'memory_delta', 'call_site', 'meta', 'children'] as $k) {
            $this->assertArrayHasKey($k, $root);
        }
        $this->assertSame('request', $root['type']);
        $this->assertCount(1, $root['children']);
        $node = $root['children'][0];
        $this->assertSame('route', $node['type']);
        $this->assertSame(['m' => 1], $node['meta']);
        $this->assertEqualsWithDelta(5.0, $node['self_ms'], 0.001);
        $this->assertSame('query', $node['children'][0]['type']);
        $this->assertSame(['file' => 'f', 'line' => 1], $node['children'][0]['call_site']);
        $this->assertSame([], $node['children'][0]['children']);
    }

    public function test_peak_memory_and_total(): void
    {
        $r = new Recorder();
        $t = $r->begin();
        $r->end();
        $this->assertGreaterThan(0, $t->peakMemory());
        $this->assertGreaterThanOrEqual(0.0, $t->totalMs());
    }
}

