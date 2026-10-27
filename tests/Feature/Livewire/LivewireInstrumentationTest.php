<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Livewire;

use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use Lantern\LanternServiceProvider;
use Lantern\Trace\Recorder;
use Lantern\Trace\SpanType;
use Orchestra\Testbench\TestCase;

class ProbeComponent extends Component
{
    public int $n = 0;

    public function inc(): void
    {
        DB::select('select 1');
        $this->n++;
    }

    public function render(): string
    {
        return '<div>{{ $n }}</div>';
    }
}

final class LivewireInstrumentationTest extends TestCase
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

    public function test_initial_render_records_mount(): void
    {
        $recorder = $this->app->make(Recorder::class);
        $recorder->begin(['kind' => 'request']);

        Livewire::test(ProbeComponent::class);

        $phases = array_map(
            fn ($s) => $s->meta['phase'],
            array_filter($recorder->trace()->spans(), fn ($s) => $s->type === SpanType::Livewire),
        );
        $this->assertContains('mount', $phases);
    }

    public function test_call_span_nests_query_and_records_meta(): void
    {
        $recorder = $this->app->make(Recorder::class);
        $recorder->begin(['kind' => 'request']);

        Livewire::test(ProbeComponent::class)->call('inc');

        $trace = $recorder->trace();
        $spans = array_values(array_filter($trace->spans(), fn ($s) => $s->type === SpanType::Livewire));
        $phases = array_map(fn ($s) => $s->meta['phase'], $spans);

        foreach (['hydrate', 'call', 'render', 'dehydrate'] as $phase) {
            $this->assertContains($phase, $phases);
        }

        $call = array_values(array_filter($spans, fn ($s) => $s->meta['phase'] === 'call'))[0];
        $this->assertSame('inc', $call->meta['method']);
        $this->assertSame(ProbeComponent::class, $call->meta['component']);
        $this->assertArrayHasKey('update_request', $call->meta);

        $queries = array_filter($trace->spans(), fn ($s) => $s->type === SpanType::Query && $s->parentId === $call->id);
        $this->assertNotEmpty($queries);
        $this->assertContains(ProbeComponent::class, $trace->meta['livewire']);
    }
}
