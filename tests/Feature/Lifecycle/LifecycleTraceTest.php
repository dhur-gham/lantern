<?php

namespace Lantern\Tests\Feature\Lifecycle;

use Lantern\Instrumentation\Lifecycle\MemorySink;
use Lantern\Instrumentation\Lifecycle\TraceFormatter;
use Lantern\Instrumentation\Lifecycle\TraceSink;
use Lantern\Trace\SpanType;

class LifecycleTraceTest extends LifecycleTestCase
{
    public function test_request_produces_ordered_lifecycle_tree(): void
    {
        $this->get('/things')->assertOk();

        $trace = $this->recorder()->trace();
        $this->assertNotNull($trace);
        $this->assertSame(SpanType::Request, $trace->root()->type);
        $this->assertSame('GET /things', $trace->root()->name);
        $this->assertSame(200, $trace->meta['status']);
        $this->assertFalse($this->recorder()->isActive());

        $types = array_map(fn ($s) => $s->type, $trace->spans());
        foreach ([SpanType::Bootstrap, SpanType::Route, SpanType::Controller, SpanType::View] as $type) {
            $this->assertContains($type, $types, $type->value.' span missing');
        }

        $bootstrap = $this->spansOfType($trace, SpanType::Bootstrap)[0];
        $route = $this->spansOfType($trace, SpanType::Route)[0];
        $controller = $this->spansOfType($trace, SpanType::Controller)[0];
        $view = $this->spansOfType($trace, SpanType::View)[0];

        $this->assertSame($trace->root()->id, $bootstrap->parentId);
        $this->assertLessThan($route->id, $bootstrap->id);
        $this->assertLessThan($controller->id, $route->id);
        $this->assertLessThan($view->id, $controller->id);
        // the view renders when the response is prepared, after the controller returned
        $this->assertSame($route->id, $view->parentId);
        $this->assertSame($route->id, $controller->parentId);

        $this->assertSame('hello', $view->name);
        $this->assertStringEndsWith('hello.blade.php', $view->callSite['file']);
        $this->assertSame(ThingController::class.'@index', $controller->name);
        $this->assertSame((new \ReflectionMethod(ThingController::class, 'index'))->getStartLine(), $controller->callSite['line']);
        $this->assertSame('things', $route->meta['uri']);
        $this->assertSame(['GET', 'HEAD'], $route->meta['methods']);

        $this->assertStringContainsString('[controller]', TraceFormatter::tree($trace));
    }

    public function test_route_meta_is_name_else_uri(): void
    {
        $this->get('/named')->assertOk();
        $this->assertSame('things.named', $this->recorder()->trace()->meta['route']);

        $this->get('/things')->assertOk();
        $this->assertSame('/things', $this->recorder()->trace()->meta['route']);
    }

    public function test_invokable_and_closure_controllers_are_timed(): void
    {
        $this->get('/invokable')->assertOk();
        $controller = $this->spansOfType($this->recorder()->trace(), SpanType::Controller)[0];
        $this->assertSame(InvokableController::class.'@__invoke', $controller->name);
        $this->assertNotNull($controller->callSite);

        $this->get('/closure')->assertOk();
        $controller = $this->spansOfType($this->recorder()->trace(), SpanType::Controller)[0];
        $this->assertSame('Closure', $controller->name);
        $this->assertSame(__DIR__.DIRECTORY_SEPARATOR.'LifecycleTestCase.php', $controller->callSite['file']);
    }

    public function test_form_request_validation_is_a_span(): void
    {
        $this->postJson('/things', ['title' => 'x'])->assertCreated();

        $trace = $this->recorder()->trace();
        $validation = $this->spansOfType($trace, SpanType::Validation);
        $this->assertNotEmpty($validation);
        $this->assertSame(StoreThingRequest::class, $validation[0]->name);
        $this->assertSame('rules', $validation[0]->callSite['function']);
        $this->assertTrue($validation[0]->isStopped());

        // the validator built by validateResolved() runs inside the FormRequest span
        $inner = $trace->children($validation[0]->id);
        $this->assertCount(1, $inner);
        $this->assertSame('validator', $inner[0]->name);

        $controller = $this->spansOfType($trace, SpanType::Controller)[0];
        $this->assertLessThan($validation[0]->id, $controller->id);
    }

    public function test_factory_made_validator_is_a_span(): void
    {
        $this->get('/validator')->assertOk();

        $validation = $this->spansOfType($this->recorder()->trace(), SpanType::Validation);
        $this->assertCount(1, $validation);
        $this->assertSame('validator', $validation[0]->name);
        $this->assertTrue($validation[0]->isStopped());
    }

    public function test_failed_validation_leaves_no_open_spans(): void
    {
        $this->postJson('/things', [])->assertStatus(422);

        $trace = $this->recorder()->trace();
        foreach ($trace->spans() as $span) {
            $this->assertTrue($span->isStopped(), $span->name.' left open');
        }
        $this->assertSame(422, $trace->meta['status']);
    }

    public function test_repeated_requests_reset_the_recorder_and_fill_the_sink(): void
    {
        $this->get('/things');
        $first = $this->recorder()->trace();
        $this->get('/closure');
        $second = $this->recorder()->trace();

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('GET /closure', $second->root()->name);
        $this->assertCount(1, $this->spansOfType($second, SpanType::Route));

        /** @var MemorySink $sink */
        $sink = $this->app->make(TraceSink::class);
        $this->assertCount(2, $sink->all());
        $this->assertSame($second, $sink->last());
    }

    public function test_ignored_paths_are_not_traced(): void
    {
        $this->get('/lantern/internal')->assertOk();

        $this->assertNull($this->recorder()->trace());
        $this->assertFalse($this->recorder()->isActive());
    }

    public function test_last_trace_json_is_written_when_enabled(): void
    {
        $this->app['config']->set('lantern.debug_write_last_trace', true);
        $file = storage_path('lantern/last-trace.json');
        @unlink($file);

        $this->get('/things')->assertOk();

        $this->assertFileExists($file);
        $data = json_decode(file_get_contents($file), true);
        $this->assertSame('request', $data['root']['type']);
        $this->assertStringContainsString('[view] hello', TraceFormatter::fromArray($data));

        $this->artisan('lantern:trace:dump', ['--last' => true])->assertSuccessful();
        @unlink($file);
    }
}
