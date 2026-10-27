<?php

declare(strict_types=1);

namespace Lantern\Instrumentation\Filament;

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Events\Terminating;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Lantern\Trace\Recorder;
use Lantern\Trace\SpanType;
use Lantern\Trace\Trace;
use Throwable;

/**
 * Filament attribution: panel id in trace meta, and a `filament` meta block on Query spans whose call site
 * lies in app/Filament/**. Spans are annotated when the request is handled and on terminating (idempotent),
 * so no listener ordering against QueryInstrumentation is needed.
 */
final class FilamentInstrumentation
{
    public function __construct(private readonly Application $app) {}

    public static function register(Application $app): void
    {
        $self = new self($app);
        $events = $app['events'];

        $events->listen('Filament\Events\ServingFilament', fn () => $self->guard($self->servingFilament(...)));
        $events->listen(RequestHandled::class, fn () => $self->guard($self->annotateCurrent(...)));
        $events->listen(Terminating::class, fn () => $self->guard($self->annotateCurrent(...)));
    }

    public function servingFilament(): void
    {
        $trace = $this->recorder()->trace();
        if ($trace === null || ! $this->recorder()->isActive()) {
            return;
        }

        $facade = 'Filament\Facades\Filament';
        if (! class_exists($facade)) {
            return;
        }

        $panel = $facade::getCurrentPanel();
        if ($panel !== null) {
            $trace->meta['filament_panel'] = $panel->getId();
        }
    }

    public function annotateCurrent(): void
    {
        $trace = $this->recorder()->trace();
        if ($trace !== null) {
            $this->annotate($trace);
        }
    }

    public function annotate(Trace $trace): void
    {
        $locator = new ColumnLocator;
        $base = rtrim(str_replace(chr(92), '/', $this->app->basePath()), '/');
        $resources = $this->contextResources($trace);

        foreach ($trace->spans() as $span) {
            if ($span->type !== SpanType::Query || isset($span->meta['filament']) || $span->callSite === null) {
                continue;
            }

            try {
                $file = str_replace(chr(92), '/', (string) $span->callSite['file']);
                $info = self::describe($file);
                if ($info === null) {
                    $accessor = $this->accessorInfo($span->callSite, $resources, $base, $locator);
                    if ($accessor !== null) {
                        $span->meta['filament'] = $accessor;
                    }

                    continue;
                }

                $absolute = preg_match('~^(?:[a-z]:)?/~i', $file) === 1 ? $file : $base.'/'.$file;
                $column = $locator->locate($absolute, (int) $span->callSite['line']);
                if ($column !== null) {
                    $info['column'] = $column;
                }

                $span->meta['filament'] = $info;
            } catch (Throwable) {
                // never break the host
            }
        }
    }

    /** Same as annotate(), for callers that hold only a trace (the lifecycle finisher). */
    public static function annotateTrace(Application $app, Trace $trace): void
    {
        try {
            (new self($app))->annotate($trace);
        } catch (Throwable) {
            // never break the host
        }
    }

    /**
     * Resource names (e.g. PostResource) of the Filament pages/components that ran in this trace.
     *
     * @return list<string>
     */
    private function contextResources(Trace $trace): array
    {
        $names = (array) ($trace->meta['livewire'] ?? []);
        foreach ($trace->spans() as $span) {
            if ($span->type === SpanType::Controller) {
                $names[] = $span->name;
            }
        }

        $found = [];
        foreach ($names as $name) {
            if (is_string($name) && preg_match('~^App\\\\Filament\\\\Resources\\\\(\w+)\\\\~', $name, $m) === 1) {
                $found[$m[1]] = true;
            }
        }

        return array_keys($found);
    }

    /**
     * A query fired from a model accessor (e.g. Post::latestCommentExcerpt) is matched to the table column of
     * the same snake_case name in the resources that rendered.
     *
     * @param array{file: string, line: int, function?: string} $callSite
     * @param list<string> $resources
     * @return array<string, mixed>|null
     */
    private function accessorInfo(array $callSite, array $resources, string $base, ColumnLocator $locator): ?array
    {
        $function = (string) ($callSite['function'] ?? '');
        if ($resources === [] || $function === '') {
            return null;
        }

        $name = preg_match_all('~::(\w+)\(\)~', $function, $m) > 0 ? end($m[1]) : $function;
        $name = preg_replace('~^get(.+)Attribute$~', '$1', $name) ?? $name;
        $column = strtolower((string) preg_replace('~(?<=[a-z0-9])([A-Z])~', '_$1', $name));

        foreach ($resources as $resource) {
            $relative = 'app/Filament/Resources/'.$resource.'.php';
            $line = $locator->find($base.'/'.$relative, $column);
            if ($line !== null) {
                return ['resource' => $resource, 'column' => $column, 'via' => 'accessor', 'source' => $relative.':'.$line];
            }
        }

        return null;
    }

    /**
     * Derives resource and component class from an app/Filament/** path.
     *
     * @return array<string, string>|null
     */
    public static function describe(string $file): ?array
    {
        $file = str_replace(chr(92), '/', $file);
        if (preg_match('~(?:^|/)app/(Filament/.+)\.php$~i', $file, $m) !== 1) {
            return null;
        }

        $parts = explode('/', $m[1]);
        $class = 'App'.chr(92).implode(chr(92), $parts);
        $info = [];

        if (($parts[1] ?? null) === 'Resources' && count($parts) >= 3) {
            $info['resource'] = $parts[2];
        }

        $kind = match (true) {
            in_array('RelationManagers', $parts, true) => 'relation_manager',
            in_array('Widgets', $parts, true) => 'widget',
            in_array('Pages', $parts, true) => 'page',
            default => null,
        };

        if ($kind !== null) {
            $info[$kind] = $class;
        } else {
            $info['class'] = $class;
        }

        return $info;
    }

    private function recorder(): Recorder
    {
        return Container::getInstance()->make(Recorder::class);
    }

    private function guard(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable) {
            // swallowed by design
        }
    }
}
