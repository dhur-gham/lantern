<?php

declare(strict_types=1);

namespace Lantern\Tests\Unit\Trace;

use Lantern\Trace\Span;
use Lantern\Trace\SpanType;
use PHPUnit\Framework\TestCase;

final class SpanTest extends TestCase
{
    public function test_stop_is_idempotent(): void
    {
        $s = new Span(1, null, SpanType::Query, 'q', 0);
        $this->assertFalse($s->isStopped());
        $s->stop(5_000_000);
        $s->stop(9_000_000);
        $this->assertTrue($s->isStopped());
        $this->assertSame(5_000_000, $s->endNs);
        $this->assertEqualsWithDelta(5.0, $s->durationMs(), 0.0001);
    }

    public function test_to_array(): void
    {
        $s = new Span(2, 1, SpanType::View, 'v', 0);
        $s->meta = ['a' => 1];
        $s->stop(1_000_000);
        $arr = $s->toArray();
        $this->assertSame(2, $arr['id']);
        $this->assertSame(1, $arr['parent_id']);
        $this->assertSame('view', $arr['type']);
        $this->assertSame(['a' => 1], $arr['meta']);
        $this->assertIsInt($arr['memory_delta']);
    }

    public function test_enum_values(): void
    {
        $this->assertCount(14, SpanType::cases());
        $this->assertSame('livewire', SpanType::Livewire->value);
    }
}
