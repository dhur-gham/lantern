<?php

declare(strict_types=1);

namespace Lantern\Tests\Stress;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Routing\Events\RouteMatched;
use ReflectionProperty;

final class RepeatedRequestsTest extends StressTestCase
{
    protected function setUp(): void
    {
        $this->persist = str_contains($this->name(), 'persisted');
        parent::setUp();
    }

    protected function lanternConfig(): array
    {
        return ['memory_sink_limit' => 20] + (str_contains($this->name(), 'persisted') ? ['keep_rate' => 0.0] : []);
    }

    private function terminatingCount(): int
    {
        return count((new ReflectionProperty($this->app, 'terminatingCallbacks'))->getValue($this->app));
    }

    private function growth(string $url, int $warm, int $measured): int
    {
        for ($i = 0; $i < $warm; $i++) {
            $this->get($url);
        }
        gc_collect_cycles();
        $before = memory_get_usage();
        for ($i = 0; $i < $measured; $i++) {
            $this->get($url);
        }
        gc_collect_cycles();

        return memory_get_usage() - $before;
    }

    public function test_five_hundred_sequential_requests_keep_memory_flat(): void
    {
        $growth = $this->growth('/scenario/loop?n=8', 100, 500);

        $this->assertLessThan(4 * 1048576, $growth, 'memory grew by '.round($growth / 1048576, 2).' MB over 500 requests');
    }

    public function test_evicted_traces_are_freed_after_exception_requests(): void
    {
        $this->getJson('/scenario/throw-after-query');
        $first = \WeakReference::create($this->sink()->all()[0]);

        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/scenario/throw-after-query');
        }
        gc_collect_cycles();

        $this->assertNull($first->get(), 'an evicted trace is still referenced (leak)');
    }

    public function test_evicted_traces_are_freed_after_normal_requests(): void
    {
        $this->get('/scenario/loop?n=6');
        $first = \WeakReference::create($this->sink()->all()[0]);

        for ($i = 0; $i < 60; $i++) {
            $this->get('/scenario/loop?n=6');
        }
        gc_collect_cycles();

        $this->assertNull($first->get(), 'an evicted trace is still referenced (leak)');
    }

    public function test_persisted_requests_keep_memory_flat(): void
    {
        $growth = $this->growth('/scenario/loop?n=6', 50, 200);

        $this->assertLessThan(6 * 1048576, $growth, 'memory grew by '.round($growth / 1048576, 2).' MB');
    }

    public function test_listeners_and_terminating_callbacks_do_not_accumulate(): void
    {
        $this->get('/scenario/loop?n=6');
        $listeners = [QueryExecuted::class => count($this->app['events']->getListeners(QueryExecuted::class)), RouteMatched::class => count($this->app['events']->getListeners(RouteMatched::class)), RequestHandled::class => count($this->app['events']->getListeners(RequestHandled::class))];
        $terminating = $this->terminatingCount();

        for ($i = 0; $i < 50; $i++) {
            $this->get('/scenario/loop?n=6');
        }

        foreach ($listeners as $event => $count) {
            $this->assertSame($count, count($this->app['events']->getListeners($event)), $event);
        }
        $this->assertSame($terminating, $this->terminatingCount());
    }

    public function test_identical_requests_produce_identical_shaped_traces(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->get('/scenario/loop?n=6');
        }

        $shapes = array_unique(array_map(fn ($t) => count($t->spans()).'/'.($t->meta['query_count'] ?? 0).'/'.count($t->meta['issues']), $this->sink()->all()));
        $this->assertCount(1, $shapes, implode(',', $shapes));
    }

    public function test_trace_ids_are_unique_and_span_ids_restart_each_request(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->get('/scenario/ok');
        }

        $traces = $this->sink()->all();
        $this->assertCount(20, array_unique(array_map(fn ($t) => $t->id, $traces)));
        foreach ($traces as $trace) {
            $this->assertSame(1, min(array_keys($trace->spans())));
        }
    }

    public function test_alternating_request_kinds_never_bleed_into_each_other(): void
    {
        $pattern = ['ok', 'loop?n=6', 'throw', 'catch-syntax', 'tx-nested-ok', 'blade-n1', 'abort404'];
        for ($i = 0; $i < 2; $i++) {
            foreach ($pattern as $url) {
                $this->get('/scenario/'.$url);
            }
        }

        $traces = $this->sink()->all();
        $this->assertCount(14, $traces);
        $expectedQueries = [0, 6, 0, 1, 2, 31, 0];
        foreach ($traces as $i => $trace) {
            $this->assertSame($expectedQueries[$i % 7], $trace->meta['query_count'] ?? 0, 'trace #'.$i.' ('.$trace->root()->name.')');
            $this->assertSpansClosed($trace);
        }
    }

    public function test_recorder_is_inactive_between_requests(): void
    {
        $this->get('/scenario/ok');
        $this->get('/scenario/ok');

        $this->assertFalse($this->app->make(\Lantern\Trace\Recorder::class)->isActive());
        $all = $this->sink()->all();
        $this->assertNotSame($all[0], $all[1]);
    }

    public function test_hundreds_of_distinct_sql_shapes_do_not_blow_the_fingerprint_cache(): void
    {
        $this->get('/scenario/distinct-queries?n=3000')->assertOk();

        $this->assertSame(3000, $this->lastTrace()->meta['query_count']);
    }

    public function test_sink_never_exceeds_its_limit(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->get('/scenario/ok');
        }

        $this->assertCount(20, $this->sink()->all());
    }
}
