<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Record;

use Lantern\Facades\Lantern;
use Lantern\Tests\TestCase;
use Lantern\Trace\Recorder;

final class RecordDisabledTest extends TestCase
{
    public function test_disabled_it_only_calls_the_closure(): void
    {
        $this->assertFalse($this->app->bound(Recorder::class));
        $this->assertSame(42, Lantern::record('anything', fn () => 42));
    }
}
