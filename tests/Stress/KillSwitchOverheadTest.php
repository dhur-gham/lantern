<?php

declare(strict_types=1);

namespace Lantern\Tests\Stress;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Routing\Contracts\ControllerDispatcher;
use Illuminate\Routing\Events\RouteMatched;
use Lantern\Instrumentation\Lifecycle\TimedControllerDispatcher;
use Lantern\Trace\Recorder;

final class KillSwitchOverheadTest extends StressTestCase
{
    protected function lanternConfig(): array
    {
        return ['kill_switch' => true];
    }

    public function test_kill_switch_wins_over_enabled_and_installs_nothing(): void
    {
        $this->assertFalse($this->app->bound(Recorder::class));
        $this->assertSame(0, $this->lanternListeners(QueryExecuted::class));
        $this->assertSame(0, $this->lanternListeners(RouteMatched::class));
        $this->assertNotInstanceOf(TimedControllerDispatcher::class, $this->app->make(ControllerDispatcher::class));
        $this->assertFalse(Model::preventsLazyLoading());
    }

    public function test_requests_are_unchanged_and_untraced(): void
    {
        $this->get('/scenario/lazy-loop')->assertOk()->assertSee('30');
        $this->getJson('/scenario/throw')->assertStatus(500);
        $this->assertFalse($this->app->bound(Recorder::class));
    }

    public function test_dashboard_is_not_exposed(): void
    {
        $this->getJson('/lantern/api/traces')->assertNotFound();
    }
}
