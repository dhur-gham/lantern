<?php

declare(strict_types=1);

namespace Lantern\Instrumentation\Lifecycle;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Validation\ValidatesWhenResolved;
use Illuminate\Foundation\Events\Terminating;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Routing\Contracts\CallableDispatcher;
use Illuminate\Routing\Contracts\ControllerDispatcher;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\Factory as ValidationFactory;
use Illuminate\Container\Container;
use Lantern\Sampling\Sampler;
use Lantern\Trace\Recorder;
use Lantern\Trace\Span;
use Lantern\Trace\SpanType;
use ReflectionMethod;
use ReflectionProperty;
use Throwable;
use WeakMap;

/**
 * Request lifecycle spans: request root, bootstrap, route, controller, validation, views.
 *
 * Covered: FormRequest validation (ValidatesWhenResolved) and validators built by the validation
 * factory (first passes() run). Not covered: validators constructed by hand with `new Validator`,
 * and apps that install their own Factory::resolver() before the first validator is made.
 * Closure routes are timed through the CallableDispatcher; both dispatcher contracts are decorated.
 */
final class LifecycleInstrumentation
{
    private bool $handled = false;

    private bool $firstBegin = true;

    /** The current execution lost the head roll; request rebinds mid-flight must not roll again. */
    private bool $unsampled = false;

    private bool $terminatingHooked = false;

    private ?Span $routeSpan = null;

    /** @var WeakMap<object, Span> */
    private WeakMap $validations;

    public function __construct(private readonly Application $app)
    {
        $this->validations = new WeakMap;
    }

    public function register(): void
    {
        $app = $this->app;
        $recorder = $this->recorderResolver();

        // Request start: first bind (real HTTP, inside bootstrap) or rebind (tests, Octane).
        $app->rebinding('request', fn ($app, $request) => $this->guard(fn () => $this->beginRequest($request)));
        if (! $app->runningInConsole() && $app->bound('request')) {
            $this->guard(fn () => $this->beginRequest($app->make('request')));
        }

        $events = $app['events'];
        $events->listen(RouteMatched::class, fn (RouteMatched $e) => $this->guard(fn () => $this->routeMatched($e)));
        $events->listen(RequestHandled::class, fn (RequestHandled $e) => $this->guard(fn () => $this->requestHandled($e)));
        // Registered once: terminating callbacks are never cleared, so adding one per request grows without bound in long-lived processes.
        $events->listen(Terminating::class, fn () => $this->guard(function (): void {
            $this->unsampled = false;
            if ($this->terminatingHooked) {
                return;
            }
            $this->terminatingHooked = true;
            $this->app->terminating(fn () => $this->guard($this->finish(...)));
        }));

        // Controller + closure timing: decorate the dispatcher contracts.
        $app->extend(ControllerDispatcher::class, fn ($inner) => new TimedControllerDispatcher($inner, $recorder));
        $app->extend(CallableDispatcher::class, fn ($inner) => new TimedCallableDispatcher($inner, $recorder));

        // Validation: FormRequest (resolving -> afterResolving) and factory-made validators.
        $app->resolving(ValidatesWhenResolved::class, fn ($object) => $this->guard(fn () => $this->validationStarted($object)));
        $app->resolving('validator', fn ($factory) => $this->guard(fn () => $this->hookValidatorFactory($factory)));

        // Views and the end of bootstrap.
        $app->booted(function () use ($recorder, $app): void {
            $this->guard(function () use ($recorder): void {
                $this->decorateBladeEngine($recorder);
            });

            // Registered once booted so it runs after the framework's afterResolving hook that
            // calls validateResolved(); otherwise the span would close before validation runs.
            $app->afterResolving(ValidatesWhenResolved::class, fn ($object) => $this->guard(fn () => $this->validationFinished($object)));
        });

        // Worker safety.
        $this->listenForResets($events);
    }

    public function beginRequest(Request $request): void
    {
        if ($this->unsampled) {
            return;
        }

        $recorder = $this->recorder();

        if ($recorder->isActive()) {
            if (! $this->handled) {
                return; // a sub-request rebinding mid-flight
            }
            $this->finish();
        }

        $recorder->reset();
        $this->routeSpan = null;
        $this->validations = new WeakMap;

        if ($this->ignored($request)) {
            return;
        }

        // Per-execution head decision: an unsampled request leaves the recorder inactive, so every hook is a no-op.
        if (! $this->app->make(Sampler::class)->headDecision()) {
            $this->unsampled = true;

            return;
        }

        $startNs = $this->firstBegin ? $this->laravelStartNs() : null;
        $this->firstBegin = false;
        $startNs ??= hrtime(true);

        $path = '/'.ltrim($request->path(), '/');
        $trace = $recorder->begin(['kind' => 'request', 'method' => $request->method(), 'path' => $path], $startNs);
        $trace->root()->name = $request->method().' '.$path;
        $this->handled = false;

        $bootstrap = $recorder->start(SpanType::Bootstrap, 'bootstrap', [], $startNs);
        $this->app->booted(fn () => $this->guard(fn () => $this->recorder()->stop($bootstrap)));
    }

    private function routeMatched(RouteMatched $event): void
    {
        $recorder = $this->recorder();
        if (! $recorder->isActive()) {
            return;
        }

        $route = $event->route;
        $uri = '/'.ltrim($route->uri(), '/');

        $root = $recorder->trace()?->root();
        if ($root !== null) {
            $root->name = $event->request->method().' '.$uri;
        }

        $this->routeSpan = $recorder->start(SpanType::Route, $uri, [
            'name' => $route->getName(),
            'uri' => $route->uri(),
            'action' => $route->getActionName(),
            'methods' => $route->methods(),
        ]);
    }

    private function requestHandled(RequestHandled $event): void
    {
        $recorder = $this->recorder();
        if (! $recorder->isActive()) {
            return;
        }

        $this->handled = true;
        $trace = $recorder->trace();
        if ($trace !== null) {
            $trace->meta['status'] = $event->response->getStatusCode();
            $trace->meta['handled_ms'] = (hrtime(true) - $trace->startedAtNs) / 1_000_000;
            $root = $trace->root();
            if ($root !== null) {
                $root->meta['status'] = $event->response->getStatusCode();
            }
        }

        if ($this->routeSpan !== null) {
            $recorder->stop($this->routeSpan);
            $this->routeSpan = null;
        }
    }

    /** End the trace and hand it to the sink. Safe to call repeatedly. */
    public function finish(): void
    {
        $recorder = $this->recorder();
        if (! $recorder->isActive()) {
            return;
        }

        $trace = $recorder->end();
        $this->routeSpan = null;
        if ($trace === null) {
            return;
        }

        if (class_exists(\Lantern\Instrumentation\Filament\FilamentInstrumentation::class)) {
            \Lantern\Instrumentation\Filament\FilamentInstrumentation::annotateTrace($this->app, $trace);
        }

        try {
            $issues = array_map(fn (\Lantern\Detection\Issue $i) => $i->toArray(), $this->app->make(\Lantern\Detection\Analyzer::class)->analyze($trace));
            $trace->meta['issues'] = $this->withFilamentMeta($trace, $issues);
            $trace->meta['issue_count'] = count($issues);
        } catch (Throwable) {
            // analysis must never break the request
        }

        $this->app->make(TraceSink::class)->push($trace);

        if (config('lantern.debug_write_last_trace')) {
            $this->writeLastTrace($trace);
        }
    }

    /**
     * @param list<array<string, mixed>> $issues
     * @return list<array<string, mixed>>
     */
    private function withFilamentMeta(\Lantern\Trace\Trace $trace, array $issues): array
    {
        $spans = $trace->spans();
        $summary = [];
        foreach ($issues as &$issue) {
            foreach ((array) ($issue['span_ids'] ?? []) as $id) {
                $info = isset($spans[$id]) ? ($spans[$id]->meta['filament'] ?? null) : null;
                if ($info !== null) {
                    $issue['meta']['filament'] = $info;
                    $summary[json_encode($info)] = $info;
                    break;
                }
            }
        }
        unset($issue);

        if ($summary !== []) {
            $trace->meta['filament'] = array_values($summary);
        }

        return $issues;
    }

    private function writeLastTrace(\Lantern\Trace\Trace $trace): void
    {
        $dir = storage_path('lantern');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        file_put_contents(
            $dir.DIRECTORY_SEPARATOR.'last-trace.json',
            json_encode($trace->toArray(), JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES),
        );
    }

    private function validationStarted(object $object): void
    {
        $recorder = $this->recorder();
        if (! $recorder->isActive()) {
            return;
        }

        $span = $recorder->start(SpanType::Validation, $object::class, ['kind' => 'form_request']);

        try {
            if (method_exists($object, 'rules')) {
                $reflection = new ReflectionMethod($object, 'rules');
                if ($reflection->getFileName() !== false) {
                    $span->callSite = ['file' => $reflection->getFileName(), 'line' => (int) $reflection->getStartLine(), 'function' => 'rules'];
                }
            }
        } catch (Throwable) {
            // no call site
        }

        $this->validations[$object] = $span;
    }

    private function validationFinished(object $object): void
    {
        if (! isset($this->validations[$object])) {
            return;
        }

        // If validateResolved() threw, this never runs; the controller span's stop closes the span.
        $span = $this->validations[$object];
        unset($this->validations[$object]);
        $this->recorder()->stop($span);
    }

    private function hookValidatorFactory(mixed $factory): void
    {
        if (! $factory instanceof ValidationFactory) {
            return;
        }

        $resolver = new ReflectionProperty(ValidationFactory::class, 'resolver');
        if ($resolver->getValue($factory) !== null) {
            return; // the app owns the resolver; do not override it
        }

        $recorder = $this->recorderResolver();
        $factory->resolver(fn ($translator, $data, $rules, $messages, $attributes) => new TimedValidator($translator, $data, $rules, $messages, $attributes, $recorder));
    }

    private function decorateBladeEngine(Closure $recorder): void
    {
        if (! $this->app->bound('view.engine.resolver')) {
            return;
        }

        $resolver = $this->app->make('view.engine.resolver');
        $inner = $resolver->resolve('blade');

        if ($inner instanceof TimedEngine) {
            return;
        }

        $resolver->register('blade', fn () => new TimedEngine($inner, $recorder));
    }

    private function listenForResets($events): void
    {
        $reset = function (): void {
            $this->guard(function (): void {
                $this->unsampled = false;
                $this->finish();
                $this->recorder()->reset();
                $this->routeSpan = null;
            });
        };

        foreach (['RequestReceived', 'RequestTerminated', 'TaskReceived', 'TaskTerminated', 'TickReceived', 'TickTerminated'] as $name) {
            $class = 'Laravel\\Octane\\Events\\'.$name;
            if (class_exists($class)) {
                $events->listen($class, $reset);
            }
        }

        $afterJob = function (): void {
            $this->guard(function (): void {
                $trace = $this->recorder()->trace();
                // A sync job inside a request must not wipe the request trace.
                if ($trace === null || ($trace->meta['kind'] ?? null) !== 'request' || ! $this->recorder()->isActive()) {
                    $this->recorder()->reset();
                }
            });
        };
        $events->listen(JobProcessed::class, $afterJob);
        $events->listen(JobFailed::class, $afterJob);
    }

    private function ignored(Request $request): bool
    {
        $patterns = (array) config('lantern.ignore_paths', []);

        return $patterns !== [] && $request->is(...$patterns);
    }

    /** LARAVEL_START (microtime float) as an hrtime-comparable timestamp, or null if unusable. */
    private function laravelStartNs(): ?int
    {
        if (! defined('LARAVEL_START') || ! is_float(LARAVEL_START)) {
            return null;
        }

        $elapsed = microtime(true) - LARAVEL_START;
        if ($elapsed < 0 || $elapsed > 30) {
            return null;
        }

        return hrtime(true) - (int) ($elapsed * 1_000_000_000);
    }

    private function recorder(): Recorder
    {
        return Container::getInstance()->make(Recorder::class);
    }

    /** @return Closure(): Recorder */
    private function recorderResolver(): Closure
    {
        return fn (): Recorder => $this->recorder();
    }

    /** Instrumentation must never break the host app. */
    private function guard(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable) {
            // swallowed by design
        }
    }
}
