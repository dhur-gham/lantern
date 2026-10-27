<?php

declare(strict_types=1);

namespace Lantern\Tests\Unit\Detection\Detectors;

use Lantern\Detection\Detectors\MemoryHogDetector;
use Lantern\Detection\Issue;
use Lantern\Trace\Recorder;
use Lantern\Trace\Span;
use Lantern\Trace\SpanType;
use PHPUnit\Framework\TestCase;

final class MemoryHogDetectorTest extends TestCase
{
    private const MB = 1048576;

    private function grow(Span $span, int $bytes): Span
    {
        $span->memoryEnd = $span->memoryStart + $bytes;

        return $span;
    }

    private function detector(): MemoryHogDetector
    {
        return new MemoryHogDetector(['thresholds' => ['memory_delta_mb' => 32]]);
    }

    public function test_flags_span_over_threshold_with_peak(): void
    {
        $r = new Recorder;
        $r->begin();
        $c = $r->start(SpanType::Controller, 'PostController@index');
        $c->callSite = ['file' => 'app/C.php', 'line' => 7];
        $r->stop($c);
        $this->grow($c, 40 * self::MB);

        $issues = $this->detector()->detect($r->end());

        $this->assertCount(1, $issues);
        $this->assertSame(Issue::TYPE_MEMORY, $issues[0]->type);
        $this->assertSame(Issue::SEV_WARNING, $issues[0]->severity);
        $this->assertSame(7, $issues[0]->callSite['line']);
        $this->assertStringContainsString('PostController@index', $issues[0]->title);
        $this->assertGreaterThan(0, $issues[0]->meta['peak_memory_bytes']);
        $this->assertSame(40.0, $issues[0]->meta['memory_delta_mb']);
    }

    public function test_critical_at_four_times(): void
    {
        $r = new Recorder;
        $r->begin();
        $v = $r->start(SpanType::View, 'big');
        $r->stop($v);
        $this->grow($v, 130 * self::MB);

        $this->assertSame(Issue::SEV_CRITICAL, $this->detector()->detect($r->end())[0]->severity);
    }

    public function test_below_threshold_and_other_types_ignored(): void
    {
        $r = new Recorder;
        $r->begin();
        $a = $r->start(SpanType::Controller, 'small');
        $r->stop($a);
        $this->grow($a, 10 * self::MB);
        $b = $r->start(SpanType::Cache, 'cache');
        $r->stop($b);
        $this->grow($b, 100 * self::MB);

        $this->assertSame([], $this->detector()->detect($r->end()));
    }

    public function test_chain_reports_leaf_not_explained_ancestor(): void
    {
        $r = new Recorder;
        $r->begin();
        $c = $r->start(SpanType::Controller, 'outer');
        $v = $r->start(SpanType::View, 'inner');
        $r->stop($v);
        $r->stop($c);
        $this->grow($v, 50 * self::MB);
        $this->grow($c, 52 * self::MB);

        $issues = $this->detector()->detect($r->end());

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('inner', $issues[0]->title);
    }

    public function test_unbounded_select_followed_by_growth(): void
    {
        $r = new Recorder;
        $r->begin();
        $q = $r->recordClosed(SpanType::Query, 'select * from users', 1, 2, ['sql' => 'select * from users'], ['file' => 'app/C.php', 'line' => 3]);
        $v = $r->start(SpanType::View, 'users.index');
        $r->stop($v);
        $this->grow($v, 45 * self::MB);

        $issues = $this->detector()->detect($r->end());
        $byId = [];
        foreach ($issues as $i) {
            $byId[$i->spanIds[0]] = $i;
        }

        $this->assertTrue($byId[$q->id]->meta['unbounded_select']);
        $this->assertSame($v->id, $byId[$q->id]->meta['growth_span_id']);
        $this->assertSame(3, $byId[$q->id]->callSite['line']);
    }

    public function test_limited_select_not_flagged_as_unbounded(): void
    {
        $r = new Recorder;
        $r->begin();
        $r->recordClosed(SpanType::Query, 'q', 1, 2, ['sql' => 'select * from users limit 10']);
        $v = $r->start(SpanType::View, 'v');
        $r->stop($v);
        $this->grow($v, 45 * self::MB);

        $issues = $this->detector()->detect($r->end());

        $this->assertCount(1, $issues);
        $this->assertArrayNotHasKey('unbounded_select', $issues[0]->meta);
    }
}
