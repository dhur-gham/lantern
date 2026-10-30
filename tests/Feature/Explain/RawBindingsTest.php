<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Explain;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lantern\Detection\Analyzer;
use Lantern\Instrumentation\Lifecycle\LifecycleInstrumentation;
use Lantern\Tests\Feature\Agent\AgentTestCase;
use Lantern\Trace\Recorder;
use Lantern\Trace\SpanType;
use Lantern\Trace\Trace;

final class RawBindingsTest extends AgentTestCase
{
    private const SECRET = 'sentinel-binding-4f9c1e';

    /** @var array<string, mixed> */
    protected array $lantern = ['explain' => true, 'explain_analyze' => true, 'capture_bindings' => false];

    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('lantern.enabled', true);
        $app['config']->set('lantern.kill_switch', false);
        $app['config']->set('lantern.keep_rate', 1.0);
        $app['config']->set('lantern.app_paths', [dirname(__DIR__, 3)]);
        foreach ($this->lantern as $key => $value) {
            $app['config']->set('lantern.'.$key, $value);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('r_orders', function (Blueprint $t) {
            $t->id();
            $t->string('note')->nullable();
            $t->integer('customer_id')->default(0);
        });
        DB::table('r_orders')->insert([['note' => 'a', 'customer_id' => 1], ['note' => 'b', 'customer_id' => 2]]);
    }

    private function recorder(): Recorder
    {
        return $this->app->make(Recorder::class);
    }

    private function begin(): Trace
    {
        $this->recorder()->reset();

        return $this->recorder()->begin(['kind' => 'request', 'method' => 'GET', 'path' => '/orders', 'status' => 200]);
    }

    /** @return list<\Lantern\Trace\Span> */
    private function queries(Trace $trace): array
    {
        return array_values(array_filter($trace->spans(), fn ($s) => $s->type === SpanType::Query));
    }

    public function test_raw_bindings_ride_on_the_span_and_are_not_part_of_its_meta(): void
    {
        $trace = $this->begin();

        DB::select('select * from r_orders where note = ?', [self::SECRET]);

        [$span] = $this->queries($trace);
        $this->assertSame([self::SECRET], $span->rawBindings());
        $this->assertArrayNotHasKey('bindings', $span->meta);
        $this->assertStringNotContainsString(self::SECRET, json_encode($span->toArray()));
        $this->assertStringNotContainsString(self::SECRET, json_encode($trace->toArray()));
    }

    public function test_they_are_cleared_once_the_detectors_have_run(): void
    {
        $trace = $this->begin();
        DB::select('select * from r_orders where note = ?', [self::SECRET]);
        $this->assertNotNull($this->queries($trace)[0]->rawBindings());

        $this->app->make(Analyzer::class)->analyze($trace);

        foreach ($trace->spans() as $span) {
            $this->assertNull($span->rawBindings());
        }
    }

    public function test_they_are_cleared_when_the_trace_finishes_even_if_analysis_fails(): void
    {
        $this->app->forgetInstance(Analyzer::class);
        $this->app->bind(Analyzer::class, fn () => throw new \RuntimeException('analysis unavailable'));
        $trace = $this->begin();
        DB::select('select * from r_orders where note = ?', [self::SECRET]);

        $this->app->make(LifecycleInstrumentation::class)->finish();

        foreach ($trace->spans() as $span) {
            $this->assertNull($span->rawBindings());
        }
    }

    public function test_nothing_reaches_persisted_traces_issues_or_command_output(): void
    {
        $trace = $this->begin();
        DB::select('select * from r_orders where note = ?', [self::SECRET]);
        DB::select('select * from r_orders where note = ?', [self::SECRET.'-again']);

        $this->app->make(LifecycleInstrumentation::class)->finish();

        $this->assertSame(1, DB::table('lantern_traces')->count());
        $stored = json_encode(DB::table('lantern_traces')->get()).json_encode(DB::table('lantern_issues')->get());
        $this->assertStringNotContainsString(self::SECRET, $stored);

        foreach ([
            ['lantern:trace', ['--summary' => true]],
            ['lantern:trace', ['--spans' => true]],
            ['lantern:trace', ['--tree' => true]],
            ['lantern:issues', ['--full' => true]],
        ] as [$command, $params]) {
            [, $out] = $this->run_($command, $params + ['--format' => 'json']);
            $this->assertStringNotContainsString(self::SECRET, $out, $command);
        }
        $this->assertStringNotContainsString(self::SECRET, json_encode($trace->toArray()));
    }

    public function test_oversized_or_unsupported_bindings_are_not_held(): void
    {
        $trace = $this->begin();

        DB::select('select * from r_orders where note = ?', [str_repeat('x', 9000)]);
        DB::select('select * from r_orders where note in (?, ?, ?, ?, ?, ?, ?, ?, ?)', array_fill(0, 9, str_repeat('y', 8000)));

        foreach ($this->queries($trace) as $span) {
            $this->assertNull($span->rawBindings());
        }
    }

    public function test_only_the_first_select_of_each_shape_is_held_and_shapes_are_capped(): void
    {
        $trace = $this->begin();

        DB::select('select * from r_orders where note = ?', ['first']);
        DB::select('select * from r_orders where note = ?', ['second']);
        for ($i = 0; $i < 30; $i++) {
            DB::select("select id as shape_{$i} from r_orders where customer_id = ? and note = ?", [$i, 'n']);
        }

        $held = array_values(array_filter($this->queries($trace), fn ($s) => $s->rawBindings() !== null));
        $this->assertCount(25, $held);
        $this->assertSame(['first'], $held[0]->rawBindings());
        $this->assertNull($this->queries($trace)[1]->rawBindings());
    }

    public function test_writes_are_never_held(): void
    {
        $trace = $this->begin();

        DB::update('update r_orders set note = ? where id = ?', [self::SECRET, 1]);

        $this->assertNull($this->queries($trace)[0]->rawBindings());
    }

    public function test_nothing_is_held_unless_explain_and_explain_analyze_are_on(): void
    {
        foreach ([['explain' => true, 'explain_analyze' => false], ['explain' => false, 'explain_analyze' => true]] as $flags) {
            config(['lantern.explain' => $flags['explain'], 'lantern.explain_analyze' => $flags['explain_analyze']]);
            $trace = $this->begin();

            DB::select('select * from r_orders where note = ?', ['x']);

            foreach ($trace->spans() as $span) {
                $this->assertNull($span->rawBindings());
            }
        }
    }

    public function test_a_slow_query_takes_the_place_of_the_fastest_held_shape_when_the_slots_are_used_up(): void
    {
        $trace = $this->begin();
        $fire = fn (string $sql, array $bindings, float $ms) => event(new \Illuminate\Database\Events\QueryExecuted($sql, $bindings, $ms, DB::connection()));

        for ($i = 0; $i < 25; $i++) {
            $fire("select id as shape_{$i} from r_orders where customer_id = ?", [$i], 1.0 + $i / 100);
        }
        $fire('select * from r_orders where note = ?', ['fast'], 5.0);
        $fire('select * from r_orders where note = ? and customer_id = ?', ['slow', 7], 850.0);

        $held = array_values(array_filter($this->queries($trace), fn ($s) => $s->rawBindings() !== null));
        $this->assertCount(25, $held);
        $slow = array_values(array_filter($this->queries($trace), fn ($s) => str_contains((string) $s->meta['sql'], 'customer_id = ?') && str_contains((string) $s->meta['sql'], 'note')));
        $this->assertSame(['slow', 7], $slow[0]->rawBindings());
        $this->assertNull($this->queries($trace)[0]->rawBindings(), 'the fastest shape made room');
        $this->assertNull($this->queries($trace)[25]->rawBindings(), 'a fast query never displaces a held one');
    }
}
