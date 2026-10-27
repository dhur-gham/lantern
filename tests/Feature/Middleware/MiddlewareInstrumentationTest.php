<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Middleware;

use Closure;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Router;
use Lantern\Instrumentation\Middleware\MiddlewareInstrumentation;
use Lantern\Instrumentation\Middleware\TimedMiddleware;
use Lantern\Tests\TestCase;
use Lantern\Trace\Recorder;
use Lantern\Trace\Span;
use Lantern\Trace\SpanType;
use RuntimeException;

final class GlobalProbe
{
    public function handle($request, Closure $next)
    {
        usleep(200);

        return $next($request);
    }
}

final class GroupProbe
{
    public function handle($request, Closure $next)
    {
        return $next($request);
    }
}

final class ParamProbe
{
    public function handle($request, Closure $next, string $a = '', string $b = '')
    {
        $response = $next($request);
        $response->headers->set('X-Params', $a.'|'.$b);

        return $response;
    }
}

final class TerminableProbe
{
    public static int $terminated = 0;

    public string $label = 'terminable';

    public function __construct(private readonly string $secret = 's3') {}

    public function secret(): string
    {
        return $this->secret;
    }

    public function handle($request, Closure $next)
    {
        return $next($request);
    }

    public function terminate($request, $response): void
    {
        self::$terminated++;
    }
}

final class ThrowingProbe
{
    public function handle($request, Closure $next)
    {
        return $next($request);
    }
}

final class MiddlewareInstrumentationTest extends TestCase
{
    private Recorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();

        TerminableProbe::$terminated = 0;

        $this->app->singleton(Recorder::class);
        $this->recorder = $this->app->make(Recorder::class);

        /** @var Router $router */
        $router = $this->app->make('router');
        $router->aliasMiddleware('probe', ParamProbe::class);
        $router->middlewareGroup('lt', [GroupProbe::class]);
        $this->app->make(Kernel::class)->pushMiddleware(GlobalProbe::class);

        $router->get('/ok', fn () => 'hello')
            ->middleware(['lt', 'throttle:60,1', 'probe:x,y', TerminableProbe::class]);
        $router->get('/boom', fn () => throw new RuntimeException('boom'))
            ->middleware([ThrowingProbe::class]);
    }

    /** @return list<Span> */
    private function middlewareSpans(): array
    {
        $spans = array_filter(
            $this->recorder->trace()->spans(),
            fn (Span $s) => $s->type === SpanType::Middleware
                && (str_contains($s->name, 'Probe') || str_contains($s->name, 'ThrottleRequests')),
        );

        return array_values($spans);
    }

    public function test_one_span_per_middleware_nested_outermost_first(): void
    {
        MiddlewareInstrumentation::register($this->app);
        $this->recorder->begin();

        $response = $this->get('/ok');

        $response->assertOk();
        $this->assertSame('hello', $response->getContent());
        $this->assertSame('x|y', $response->headers->get('X-Params'));
        $this->assertSame(1, TerminableProbe::$terminated);

        $spans = $this->middlewareSpans();
        $handles = array_values(array_filter($spans, fn (Span $s) => ! str_ends_with($s->name, '::terminate')));
        $names = array_map(fn (Span $s) => $s->name, $handles);

        $this->assertSame(
            [GlobalProbe::class, GroupProbe::class, \Illuminate\Routing\Middleware\ThrottleRequests::class, ParamProbe::class, TerminableProbe::class],
            $names,
        );

        $trace = $this->recorder->trace();
        for ($i = 1; $i < count($handles); $i++) {
            $this->assertSame($handles[$i - 1]->id, $handles[$i]->parentId);
            $this->assertGreaterThanOrEqual($handles[$i]->durationMs(), $handles[$i - 1]->durationMs());
        }

        foreach ($handles as $span) {
            $this->assertTrue($span->isStopped());
            $this->assertGreaterThanOrEqual(0.0, $trace->selfMs($span));
            $this->assertLessThanOrEqual($span->durationMs() + 1e-6, $trace->selfMs($span));
        }
        $this->assertGreaterThan(0.0, $trace->selfMs($handles[0]));
    }

    public function test_terminate_span_recorded_separately(): void
    {
        MiddlewareInstrumentation::register($this->app);
        $this->recorder->begin();

        $this->get('/ok')->assertOk();

        $terminate = array_values(array_filter(
            $this->middlewareSpans(),
            fn (Span $s) => str_ends_with($s->name, '::terminate'),
        ));

        $this->assertCount(1, $terminate);
        $this->assertSame(TerminableProbe::class.'::terminate', $terminate[0]->name);
        $this->assertTrue($terminate[0]->isStopped());
    }

    public function test_wrapper_forwards_api_and_state(): void
    {
        MiddlewareInstrumentation::register($this->app);

        $instance = $this->app->make(TerminableProbe::class);

        $this->assertInstanceOf(TimedMiddleware::class, $instance);
        $this->assertSame('s3', $instance->secret());
        $this->assertSame('terminable', $instance->label);
        $instance->label = 'changed';
        $this->assertSame('changed', $instance->inner()->label);
        $this->assertTrue(isset($instance->label));
        $this->assertTrue(method_exists($instance, 'terminate'));
    }

    public function test_repeated_register_does_not_double_wrap(): void
    {
        MiddlewareInstrumentation::register($this->app);
        MiddlewareInstrumentation::register($this->app);
        MiddlewareInstrumentation::register($this->app, [TerminableProbe::class, GlobalProbe::class]);
        $this->recorder->begin();

        $this->get('/ok')->assertOk();

        $names = array_map(fn (Span $s) => $s->name, $this->middlewareSpans());
        $this->assertSame(count($names), count(array_unique($names)));
        $this->assertCount(6, $names);

        $instance = $this->app->make(GlobalProbe::class);
        $this->assertInstanceOf(TimedMiddleware::class, $instance);
        $this->assertNotInstanceOf(TimedMiddleware::class, $instance->inner());
    }

    public function test_exceptions_propagate_and_spans_close(): void
    {
        MiddlewareInstrumentation::register($this->app);
        $this->recorder->begin();
        $this->withoutExceptionHandling();

        try {
            $this->get('/boom');
            $this->fail('exception expected');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $spans = $this->middlewareSpans();
        $this->assertNotEmpty($spans);
        foreach ($spans as $span) {
            $this->assertTrue($span->isStopped());
        }
        $names = array_map(fn (Span $s) => $s->name, $spans);
        $this->assertContains(ThrowingProbe::class, $names);
    }

    public function test_inactive_recorder_records_nothing_and_still_works(): void
    {
        MiddlewareInstrumentation::register($this->app);

        $this->get('/ok')->assertOk();

        $this->assertNull($this->recorder->trace());
        $this->assertSame(1, TerminableProbe::$terminated);
    }

    public function test_route_registered_after_register_is_covered(): void
    {
        MiddlewareInstrumentation::register($this->app);
        $this->app->make('router')->get('/late', fn () => 'late')->middleware(ThrowingProbe::class);
        $this->recorder->begin();

        $this->get('/late')->assertOk();

        $names = array_map(fn (Span $s) => $s->name, $this->middlewareSpans());
        $this->assertContains(ThrowingProbe::class, $names);
    }
}
