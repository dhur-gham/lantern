<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Record;

use Illuminate\Support\Facades\DB;
use Lantern\Facades\Lantern;
use Lantern\Instrumentation\Lifecycle\MemorySink;
use Lantern\Instrumentation\Lifecycle\TraceSink;
use Lantern\LanternServiceProvider;
use Lantern\Trace\SpanType;
use Livewire\Component;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase;

class RecordProbeComponent extends Component
{
    public int $n = 0;

    public function inc(): void
    {
        DB::select('select 1');
        DB::select('select 2');
        $this->n++;
    }

    public function render(): string
    {
        return '<div>{{ $n }}</div>';
    }
}

final class RecordLivewireTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LivewireServiceProvider::class, LanternServiceProvider::class];
    }

    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('lantern.enabled', true);
        $app['config']->set('lantern.kill_switch', false);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    public function test_a_livewire_call_inside_a_record_block_stays_in_the_record_trace(): void
    {
        Lantern::record('inc', function () {
            Livewire::test(RecordProbeComponent::class)->call('inc');
        });

        $sink = $this->app->make(TraceSink::class);
        $this->assertInstanceOf(MemorySink::class, $sink);
        $this->assertCount(1, $sink->all(), 'the internal requests must not become traces of their own');

        $trace = $sink->last();
        $this->assertSame('record', $trace->meta['kind']);
        $this->assertSame('record:inc', $trace->meta['route']);
        $queries = array_filter($trace->spans(), fn ($s) => $s->type === SpanType::Query && ($s->meta['kind'] ?? null) !== 'transaction');
        $this->assertGreaterThanOrEqual(2, count($queries));
    }
}
