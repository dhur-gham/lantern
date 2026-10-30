<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Record;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lantern\Facades\Lantern;
use Lantern\Instrumentation\Lifecycle\MemorySink;
use Lantern\Instrumentation\Lifecycle\TraceSink;
use Lantern\Tests\TestCase;
use Lantern\Trace\Recorder;
use Lantern\Trace\SpanType;
use RuntimeException;

final class RecordTest extends TestCase
{
    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('lantern.enabled', true);
        $app['config']->set('lantern.kill_switch', false);
        $app['config']->set('lantern.app_paths', [__DIR__]);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('r_items', fn (Blueprint $t) => $t->id());
    }

    private function sink(): MemorySink
    {
        $sink = $this->app->make(TraceSink::class);
        $this->assertInstanceOf(MemorySink::class, $sink);

        return $sink;
    }

    public function test_a_record_block_is_its_own_trace_and_returns_the_closure_value(): void
    {
        $result = Lantern::record('load items', fn () => DB::select('select * from r_items where id = ?', [1]) ?: 'none', ['item_id' => 7, 'api_token' => 'abc']);

        $this->assertSame('none', $result);
        $trace = $this->sink()->last();
        $this->assertNotNull($trace);
        $this->assertSame('record', $trace->meta['kind']);
        $this->assertSame('record:load items', $trace->meta['route']);
        $this->assertSame('processed', $trace->meta['status']);
        $this->assertSame(['item_id' => 7, 'api_token' => '[redacted]'], $trace->meta['context']);
        $this->assertSame(SpanType::Record, $trace->root()->type);
        $this->assertSame('load items', $trace->root()->name);
        $this->assertTrue($trace->meta['keep']);
    }

    public function test_n_plus_one_inside_a_record_block_is_reported_with_its_call_site(): void
    {
        $line = 0;
        Lantern::record('loop', function () use (&$line) {
            foreach (range(1, 8) as $id) {
                $line = __LINE__ + 1;
                DB::select('select * from r_items where id = ?', [$id]);
            }
        });

        $trace = $this->sink()->last();
        $issue = collect($trace->meta['issues'])->firstWhere('type', 'n_plus_one');
        $this->assertNotNull($issue);
        $this->assertStringEndsWith('RecordTest.php', $issue['call_site']['file']);
        $this->assertSame($line, $issue['call_site']['line']);
    }

    public function test_a_throwing_block_is_rethrown_and_the_trace_is_failed(): void
    {
        try {
            Lantern::record('boom', function () {
                DB::select('select 1');

                throw new RuntimeException('exploded');
            });
            $this->fail('expected the exception');
        } catch (RuntimeException $e) {
            $this->assertSame('exploded', $e->getMessage());
        }

        $trace = $this->sink()->last();
        $this->assertSame('failed', $trace->meta['status']);
        $this->assertSame(RuntimeException::class, $trace->meta['exception']['class']);
    }

    public function test_inside_an_active_trace_it_is_a_child_span_and_keeps_the_trace(): void
    {
        $recorder = $this->app->make(Recorder::class);
        $trace = $recorder->begin(['kind' => 'request']);

        Lantern::record('inner', fn () => 1);

        $this->assertTrue($trace->meta['keep']);
        $names = array_map(fn ($s) => $s->name, array_filter($trace->spans(), fn ($s) => $s->type === SpanType::Record));
        $this->assertSame(['inner'], array_values($names));
    }
}
