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

    public function test_update_request_route_names_component_and_method(): void
    {
        $recorder = $this->app->make(Recorder::class);
        $recorder->begin(['kind' => 'request']);

        Livewire::test(ProbeComponent::class)->call('inc');

        $trace = $recorder->trace();
        $call = array_values(array_filter($trace->spans(), fn ($s) => $s->type === SpanType::Livewire && $s->meta['phase'] === 'call'))[0];
        if (! $call->meta['update_request']) {
            $this->markTestSkipped('Livewire::test does not flag an update request here');
        }

        $this->assertSame('livewire:'.ProbeComponent::class.'::inc', $trace->meta['route']);
    }

    /** @param list<array{0: string, 1: string}> $calls */
    private function traceWithCalls(array $calls): \Lantern\Trace\Trace
    {
        $recorder = new Recorder;
        $trace = $recorder->begin(['kind' => 'request']);
        $m = new \ReflectionMethod(\Lantern\Instrumentation\Livewire\LivewireInstrumentation::class, 'addCall');
        foreach ($calls as [$class, $method]) {
            $m->invoke(null, $trace, $class, $method);
        }

        return $trace;
    }

    public function test_batched_calls_are_kept_for_display_and_the_route_is_the_first_call(): void
    {
        $trace = $this->traceWithCalls([['App\Foo', 'a'], ['App\Foo', 'callSchemaComponentMethod:form.x.item_images']]);

        $this->assertSame(['App\Foo::a', 'App\Foo::callSchemaComponentMethod:form.x.item_images'], $trace->meta['livewire_calls']);
        $this->assertSame('livewire:App\Foo::a+App\Foo::callSchemaComponentMethod:form.x.item_images', $trace->meta['livewire_route']);
        $this->assertSame('livewire:App\Foo::a', $trace->meta['route']);
    }

    public function test_a_batch_is_named_after_the_main_call_not_after_filament_components_or_lazy_loads(): void
    {
        $trace = $this->traceWithCalls([
            ['Filament\Livewire\DatabaseNotifications', '__lazyLoad'],
            ['Filament\Livewire\GlobalSearch', 'updated.search'],
            ['App\Pages\EditItemSimple', '__lazyLoad'],
            ['App\Pages\EditItemSimple', 'update'],
        ]);

        $this->assertSame('livewire:App\Pages\EditItemSimple::update', $trace->meta['route']);
        $this->assertSame([
            'livewire:Filament\Livewire\DatabaseNotifications::__lazyLoad',
            'livewire:Filament\Livewire\GlobalSearch::updated.search',
            'livewire:App\Pages\EditItemSimple::__lazyLoad',
            'livewire:App\Pages\EditItemSimple::update',
        ], $trace->meta['livewire_routes']);
    }

    public function test_a_batch_of_only_filament_calls_falls_back_to_the_first(): void
    {
        $trace = $this->traceWithCalls([['Filament\Livewire\DatabaseNotifications', '__lazyLoad']]);

        $this->assertSame('livewire:Filament\Livewire\DatabaseNotifications::__lazyLoad', $trace->meta['route']);
    }

    public function test_the_same_interaction_has_the_same_route_whatever_rows_or_batching(): void
    {
        $first = $this->traceWithCalls([
            ['App\Pages\EditItemSimple', 'updated.data.variations.record-17097962094.sizes.0'],
            ['App\Pages\EditItemSimple', 'callSchemaComponentMethod:form.variations.record-17097962094.item_images'],
            ['App\Pages\EditItemSimple', 'updated.data.variations.record-17097962094.sizes.1'],
        ]);
        $second = $this->traceWithCalls([
            ['App\Pages\EditItemSimple', 'updated.data.variations.record-5550001.sizes.3'],
            ['App\Pages\EditItemSimple', 'callSchemaComponentMethod:form.variations.record-5550001.item_images'],
        ]);

        $this->assertSame('livewire:App\Pages\EditItemSimple::updated.data.variations.record-*.sizes.*', $first->meta['route']);
        $this->assertSame($first->meta['route'], $second->meta['route']);
        $this->assertNotSame($first->meta['livewire_route'], $second->meta['livewire_route']);
    }

    public function test_normalise_generalises_record_keys_uuids_and_list_positions_only(): void
    {
        $n = fn (string $c): string => \Lantern\Instrumentation\Livewire\LivewireInstrumentation::normalise($c);

        $this->assertSame('A\B::callSchemaComponentMethod:form.rows.record-*.photos', $n('A\B::callSchemaComponentMethod:form.rows.record-42.photos'));
        $this->assertSame('A\B::updated.data.rows.item-*.name', $n('A\B::updated.data.rows.item-0f8fad5b-d9cb-469f-a165-70867728950e.name'));
        $this->assertSame('A\B::updated.data.tags.*', $n('A\B::updated.data.tags.12'));
        $this->assertSame('A\B::save', $n('A\B::save'));
        $this->assertSame('A\V2::updated.data.v2.name', $n('A\V2::updated.data.v2.name'));
    }
}
