<?php

declare(strict_types=1);

namespace Lantern\Tests\Stress;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Events\Terminating;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Routing\Contracts\CallableDispatcher;
use Illuminate\Routing\Contracts\ControllerDispatcher;
use Illuminate\Routing\Events\RouteMatched;
use Lantern\Instrumentation\Lifecycle\TimedCallableDispatcher;
use Lantern\Instrumentation\Lifecycle\TimedControllerDispatcher;
use Lantern\Instrumentation\Lifecycle\TimedEngine;
use Lantern\Instrumentation\Lifecycle\TraceSink;
use Lantern\Trace\Recorder;
use ReflectionProperty;

/** enabled = false: zero listeners, no decorators, no behaviour change. */
final class DisabledOverheadTest extends StressTestCase
{
    protected bool $lanternEnabled = false;

    public function test_no_lantern_services_are_bound(): void
    {
        $this->assertFalse($this->app->bound(Recorder::class));
        $this->assertFalse($this->app->bound(TraceSink::class));
        $this->assertFalse($this->app->bound(\Lantern\Detection\Analyzer::class));
    }

    public function test_no_lantern_listeners_are_attached(): void
    {
        foreach ([QueryExecuted::class, RouteMatched::class, RequestHandled::class, Terminating::class, TransactionBeginning::class, MessageLogged::class, ConnectionEstablished::class, JobProcessed::class] as $event) {
            $this->assertSame(0, $this->lanternListeners($event), $event);
        }
    }

    public function test_dispatchers_and_engine_are_not_decorated(): void
    {
        $this->assertNotInstanceOf(TimedControllerDispatcher::class, $this->app->make(ControllerDispatcher::class));
        $this->assertNotInstanceOf(TimedCallableDispatcher::class, $this->app->make(CallableDispatcher::class));
        $this->assertNotInstanceOf(TimedEngine::class, $this->app->make('view.engine.resolver')->resolve('blade'));
    }

    public function test_eloquent_lazy_loading_state_is_untouched(): void
    {
        $this->assertFalse(Model::preventsLazyLoading());
        $this->assertNull((new ReflectionProperty(Model::class, 'lazyLoadingViolationCallback'))->getValue());
    }

    public function test_validator_factory_resolver_is_not_replaced(): void
    {
        $this->assertNull((new ReflectionProperty(\Illuminate\Validation\Factory::class, 'resolver'))->getValue($this->app['validator']));
    }

    public function test_requests_work_and_lazy_loads_never_throw(): void
    {
        foreach (['ok', 'lazy-loop', 'blade-n1', 'catch-syntax', 'tx-nested-ok'] as $name) {
            $this->get('/scenario/'.$name)->assertOk();
        }
        $this->getJson('/scenario/throw')->assertStatus(500)->assertExactJson(['message' => 'Server Error']);
        $this->assertFalse($this->app->bound(Recorder::class));
    }

    public function test_dashboard_routes_are_not_registered(): void
    {
        $this->get('/lantern')->assertNotFound();
        $this->getJson('/lantern/api/traces')->assertNotFound();
    }

    public function test_terminating_callbacks_are_not_added(): void
    {
        $before = count((new ReflectionProperty($this->app, 'terminatingCallbacks'))->getValue($this->app));
        for ($i = 0; $i < 5; $i++) {
            $this->get('/scenario/ok');
        }

        $this->assertSame($before, count((new ReflectionProperty($this->app, 'terminatingCallbacks'))->getValue($this->app)));
    }
}
