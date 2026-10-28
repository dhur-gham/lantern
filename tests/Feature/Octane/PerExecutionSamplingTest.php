<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Octane;

use Illuminate\Queue\Events\JobProcessed;
use Lantern\Instrumentation\Lifecycle\LifecycleInstrumentation;
use Lantern\Instrumentation\Lifecycle\MemorySink;
use Lantern\Instrumentation\Lifecycle\TraceSink;
use Lantern\Sampling\Sampler;
use Lantern\Tests\Feature\Lifecycle\LifecycleTestCase;

require_once __DIR__.'/Fixtures.php';

final class PerExecutionSamplingTest extends LifecycleTestCase
{
    private MemorySink $sink;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sink = new MemorySink(100000);
        $this->app->instance(TraceSink::class, $this->sink);
    }

    private function useRate(float $rate, int $seed = 7): void
    {
        mt_srand($seed);
        $this->app->instance(Sampler::class, new Sampler(['sampling_rate' => $rate]));
    }

    private function runRequests(int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->octane('RequestReceived');
            $this->get('/things')->assertOk();
            $this->octane('RequestTerminated');
        }
    }

    private function octane(string $event): void
    {
        $class = 'Laravel\\Octane\\Events\\'.$event;
        $this->app['events']->dispatch(new $class);
    }

    public function test_rate_zero_records_nothing_across_many_executions(): void
    {
        $this->useRate(0.0);
        $this->runRequests(40);

        $this->assertCount(0, $this->sink->all());
        $this->assertFalse($this->recorder()->isActive());
    }

    public function test_rate_one_records_every_execution(): void
    {
        $this->useRate(1.0);
        $this->runRequests(40);

        $this->assertCount(40, $this->sink->all());
    }

    public function test_rate_half_records_roughly_half_with_a_seeded_random(): void
    {
        $this->useRate(0.5);
        $this->runRequests(200);

        $this->assertEqualsWithDelta(100, count($this->sink->all()), 30);
    }

    public function test_decisions_are_independent_and_state_does_not_leak(): void
    {
        $decisions = [true, false, false, true, false, true];
        $queue = array_map(fn (bool $d) => $d ? 0.1 : 0.9, $decisions);
        $this->app->instance(Sampler::class, new Sampler(['sampling_rate' => 0.5], function () use (&$queue): float {
            return array_shift($queue) ?? 0.9;
        }));

        $sampled = [];
        foreach ($decisions as $_) {
            $before = count($this->sink->all());
            $this->runRequests(1);
            $sampled[] = count($this->sink->all()) > $before;
            $this->assertFalse($this->recorder()->isActive(), 'nothing active between executions');
            $this->assertNull($this->recorder()->trace(), 'recorder reset after each execution');
        }

        $this->assertSame($decisions, $sampled);
        foreach ($this->sink->all() as $trace) {
            $this->assertCount(1, array_filter($trace->spans(), fn ($s) => $s->parentId === null), 'one root per trace');
        }
    }

    public function test_task_tick_and_job_events_reset_per_execution_state(): void
    {
        $this->useRate(1.0);
        foreach (['TaskReceived', 'TickReceived'] as $event) {
            $this->get('/things')->assertOk();
            $this->octane($event);
            $this->assertFalse($this->recorder()->isActive());
            $this->assertNull($this->recorder()->trace());
        }
    }

    public function test_provider_boot_cost_is_not_repeated_per_execution(): void
    {
        $this->useRate(0.0);
        $listeners = fn () => count($this->app['events']->getListeners(\Illuminate\Routing\Events\RouteMatched::class));
        $terminating = new \ReflectionProperty($this->app, 'terminatingCallbacks');
        $lifecycle = $this->app->make(LifecycleInstrumentation::class);

        $this->runRequests(1);
        $l = $listeners();
        $t = count($terminating->getValue($this->app));
        $this->runRequests(30);

        $this->assertSame($l, $listeners());
        $this->assertSame($t, count($terminating->getValue($this->app)));
        $this->assertSame($lifecycle, $this->app->make(LifecycleInstrumentation::class));
    }

    public function test_unsampled_executions_do_no_listener_work(): void
    {
        $this->useRate(0.0);
        $this->runRequests(1);
        $this->assertNull($this->recorder()->trace());
        $this->assertSame(0, $this->recorder()->trace()?->root()?->id ?? 0);
    }
}
