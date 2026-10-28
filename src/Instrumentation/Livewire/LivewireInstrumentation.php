<?php

declare(strict_types=1);

namespace Lantern\Instrumentation\Livewire;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Lantern\Trace\Recorder;
use Lantern\Trace\SpanType;
use Livewire\Livewire;
use Throwable;
use WeakMap;

final class LivewireInstrumentation
{
    /** @var WeakMap<Application, true>|null */
    private static ?WeakMap $registered = null;

    public static function register(Application $app): void
    {
        if (! class_exists(Livewire::class)) {
            return;
        }

        self::$registered ??= new WeakMap;
        if (isset(self::$registered[$app])) {
            return;
        }
        self::$registered[$app] = true;

        // Listeners must exist before Livewire wires its hooks, so attach during booting.
        $attach = static fn () => self::attach($app);
        $app->isBooted() ? $attach() : $app->booting($attach);
    }

    private static function attach(Application $app): void
    {
        try {
            Livewire::listen('request', static function (...$args) use ($app): void {
                self::guard(static function () use ($app): void {
                    $recorder = self::recorder($app);
                    $root = $recorder?->trace()?->root();
                    if ($root !== null && Livewire::isLivewireRequest()) {
                        $root->name = 'livewire update';
                    }
                });
            });

            Livewire::listen('update', static function (...$args) use ($app): void {
                self::guard(static function () use ($app, $args): void {
                    $recorder = self::recorder($app);
                    $trace = $recorder?->isActive() ? $recorder->trace() : null;
                    if ($trace !== null && ($args[0] ?? null) instanceof \Livewire\Component && is_string($args[1] ?? null) && self::isUpdate()) {
                        $trace->meta['route'] = 'livewire:'.$args[0]::class.' updated '.$args[1];
                    }
                });
            });

            Livewire::listen('mount', static fn (...$args) => self::phaseWithFinish($app, 'mount', $args[0] ?? null, null));
            Livewire::listen('render', static fn (...$args) => self::phaseWithFinish($app, 'render', $args[0] ?? null, null));
            Livewire::listen('call', static fn (...$args) => self::phaseWithFinish($app, 'call', $args[0] ?? null, is_string($args[1] ?? null) ? $args[1] : null));
            Livewire::listen('hydrate', static function (...$args) use ($app): void {
                self::instant($app, 'hydrate', $args[0] ?? null);
            });
            Livewire::listen('dehydrate', static function (...$args) use ($app): void {
                self::instant($app, 'dehydrate', $args[0] ?? null);
            });
        } catch (Throwable) {
        }
    }

    private static function phaseWithFinish(Application $app, string $phase, mixed $component, ?string $method): ?Closure
    {
        try {
            $recorder = self::recorder($app);
            if ($recorder === null || ! $recorder->isActive()) {
                return null;
            }

            $meta = self::meta($component, $phase, $method);
            $name = $component instanceof \Livewire\Component ? class_basename($component).' '.($method !== null ? 'call '.$method : $phase) : $phase;
            $span = $recorder->start(SpanType::Livewire, $name, $meta);
            self::touch($recorder, $meta['component'] ?? null);
            self::label($recorder, $meta['component'] ?? null, $method);

            return static function (...$ret) use ($recorder, $span): void {
                try {
                    $recorder->stop($span);
                } catch (Throwable) {
                }
            };
        } catch (Throwable) {
            return null;
        }
    }

    private static function instant(Application $app, string $phase, mixed $component): void
    {
        self::guard(static function () use ($app, $phase, $component): void {
            $recorder = self::recorder($app);
            if ($recorder === null || ! $recorder->isActive()) {
                return;
            }

            $meta = self::meta($component, $phase, null);
            $now = hrtime(true);
            $name = $component instanceof \Livewire\Component ? class_basename($component).' '.$phase : $phase;
            $recorder->recordClosed(SpanType::Livewire, $name, $now, $now, $meta);
            self::touch($recorder, $meta['component'] ?? null);
            self::label($recorder, $meta['component'] ?? null, null);
        });
    }

    /** @return array<string, mixed> */
    private static function meta(mixed $component, string $phase, ?string $method): array
    {
        $isComponent = $component instanceof \Livewire\Component;

        return [
            'component' => $isComponent ? $component::class : null,
            'component_id' => $isComponent ? $component->getId() : null,
            'method' => $method,
            'phase' => $phase,
            'update_request' => self::isUpdate(),
        ];
    }

    private static function isUpdate(): bool
    {
        try {
            return (bool) Livewire::isLivewireRequest();
        } catch (Throwable) {
            return false;
        }
    }

    private static function label(Recorder $recorder, mixed $class, ?string $method): void
    {
        $trace = $recorder->trace();
        if ($trace === null || ! is_string($class) || ! self::isUpdate()) {
            return;
        }

        $current = (string) ($trace->meta['route'] ?? '');
        $named = str_starts_with($current, 'livewire:');
        if ($method === null ? $named : str_contains($current, '@')) {
            return;
        }

        if ($method === '$commit') {
            return;
        }
        if ($method !== null && str_starts_with($method, '$set')) {
            $method = null;
        }

        $trace->meta['route'] = 'livewire:'.$class.($method !== null ? '@'.$method : '');
    }

    private static function touch(Recorder $recorder, mixed $class): void
    {
        $trace = $recorder->trace();
        if ($trace === null || ! is_string($class)) {
            return;
        }

        $list = (array) ($trace->meta['livewire'] ?? []);
        if (! in_array($class, $list, true)) {
            $list[] = $class;
            $trace->meta['livewire'] = $list;
        }
    }

    private static function recorder(Application $app): ?Recorder
    {
        return $app->bound(Recorder::class) ? $app->make(Recorder::class) : null;
    }

    private static function guard(Closure $fn): void
    {
        try {
            $fn();
        } catch (Throwable) {
        }
    }
}
