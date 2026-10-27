<?php

namespace Lantern\Tests\Feature\Lifecycle;

use Lantern\Instrumentation\Lifecycle\MemorySink;
use Lantern\Trace\Recorder;
use PHPUnit\Framework\TestCase;

class MemorySinkTest extends TestCase
{
    public function test_keeps_only_the_last_n_traces(): void
    {
        $sink = new MemorySink(2);
        $recorder = new Recorder;

        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $trace = $recorder->begin();
            $recorder->end();
            $ids[] = $trace->id;
            $sink->push($trace);
        }

        $this->assertCount(2, $sink->all());
        $this->assertSame([$ids[1], $ids[2]], array_map(fn ($t) => $t->id, $sink->all()));
        $this->assertSame($ids[2], $sink->last()->id);

        $sink->clear();
        $this->assertNull($sink->last());
    }
}
