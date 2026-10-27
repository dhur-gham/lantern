<?php

namespace Lantern\Tests\Feature\Lifecycle;

use Lantern\Instrumentation\Lifecycle\TraceSink;
use Lantern\Trace\Recorder;

class DisabledTest extends LifecycleTestCase
{
    protected bool $lanternEnabled = false;

    public function test_disabled_registers_nothing_and_records_nothing(): void
    {
        $this->assertFalse($this->app->bound(Recorder::class));
        $this->assertFalse($this->app->bound(TraceSink::class));

        $this->get('/things')->assertOk();

        $this->assertFalse($this->app->bound(Recorder::class));
    }

}

class KillSwitchTest extends LifecycleTestCase
{
    protected bool $killSwitch = true;

    public function test_kill_switch_wins_over_enabled(): void
    {
        $this->get('/things')->assertOk();

        $this->assertFalse($this->app->bound(Recorder::class));
    }
}
