<?php

declare(strict_types=1);

namespace Lantern\Instrumentation\Middleware;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Configuration\Middleware as MiddlewareConfig;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Router;
use Throwable;
use WeakMap;

final class MiddlewareInstrumentation
{
    /** @var WeakMap<Application, array<string, true>>|null */
    private static ?WeakMap $wrapped = null;

    /** @var WeakMap<Application, true>|null */
    private static ?WeakMap $hooked = null;

    /** @param list<string>|null $classes */
    public static function register(Application $app, ?array $classes = null): void
    {
        if ($classes !== null) {
            $aliases = self::aliases($app);
            $groups = self::groups($app);
            self::wrap($app, self::resolveAll($classes, $aliases, $groups));

            return;
        }

        $app->booted(static function () use ($app): void {
            self::wrap($app, self::discover($app));
            self::hookRoutes($app);
        });
    }

    /** @param list<string> $classes */
    private static function wrap(Application $app, array $classes): void
    {
        self::$wrapped ??= new WeakMap;
        $done = self::$wrapped[$app] ?? [];

        foreach ($classes as $class) {
            if (isset($done[$class])) {
                continue;
            }
            $done[$class] = true;

            $app->extend($class, static fn (mixed $instance, mixed $container) => $instance instanceof TimedMiddleware || ! is_object($instance)
                ? $instance
                : new TimedMiddleware($instance, $app));
        }

        self::$wrapped[$app] = $done;
    }

    private static function hookRoutes(Application $app): void
    {
        self::$hooked ??= new WeakMap;
        if (isset(self::$hooked[$app]) || ! $app->bound('events') || ! $app->bound('router')) {
            return;
        }
        self::$hooked[$app] = true;

        $seen = [];
        $app['events']->listen(RouteMatched::class, static function (RouteMatched $event) use ($app, &$seen): void {
            try {
                $router = $app->make('router');
                $names = $router->gatherRouteMiddleware($event->route);
                // Resolving names to classes uses reflection; a route's middleware list rarely changes, so do it once per list.
                $key = md5(serialize(array_map(static fn ($n) => is_string($n) ? $n : spl_object_id($n), $names)));
                if (isset($seen[$key])) {
                    return;
                }
                $seen[$key] = true;
                $aliases = self::aliases($app);
                $groups = self::groups($app);
                self::wrap($app, self::resolveAll($names, $aliases, $groups));
            } catch (Throwable) {
            }
        });
    }

    /** @return list<string> */
    private static function discover(Application $app): array
    {
        $entries = [];
        $aliases = [];
        $groups = [];

        try {
            if ($app->bound(HttpKernel::class)) {
                $kernel = $app->make(HttpKernel::class);
                if (method_exists($kernel, 'getGlobalMiddleware')) {
                    $entries = [...$entries, ...$kernel->getGlobalMiddleware()];
                }
                if (method_exists($kernel, 'getMiddlewareGroups')) {
                    $groups = [...$groups, ...$kernel->getMiddlewareGroups()];
                }
                if (method_exists($kernel, 'getRouteMiddleware')) {
                    $aliases = [...$aliases, ...$kernel->getRouteMiddleware()];
                }
                if (method_exists($kernel, 'getMiddlewareAliases')) {
                    $aliases = [...$aliases, ...$kernel->getMiddlewareAliases()];
                }
            }
        } catch (Throwable) {
        }

        $aliases = [...$aliases, ...self::aliases($app)];
        $groups = [...$groups, ...self::groups($app)];

        if (class_exists(MiddlewareConfig::class)) {
            try {
                $defaults = new MiddlewareConfig;
                $entries = [...$entries, ...$defaults->getGlobalMiddleware()];
                $groups = [...$defaults->getMiddlewareGroups(), ...$groups];
                $aliases = [...$defaults->getMiddlewareAliases(), ...$aliases];
            } catch (Throwable) {
            }
        }

        foreach ($groups as $members) {
            $entries = [...$entries, ...(array) $members];
        }
        $entries = [...$entries, ...array_values($aliases)];

        if ($app->bound('router')) {
            try {
                foreach ($app->make('router')->getRoutes() as $route) {
                    $entries = [...$entries, ...$route->middleware()];
                }
            } catch (Throwable) {
            }
        }

        return self::resolveAll($entries, $aliases, $groups);
    }

    /** @return array<string, mixed> */
    private static function aliases(Application $app): array
    {
        try {
            return $app->bound('router') ? $app->make('router')->getMiddleware() : [];
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<string, mixed> */
    private static function groups(Application $app): array
    {
        try {
            return $app->bound('router') ? $app->make('router')->getMiddlewareGroups() : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<int|string, mixed>  $entries
     * @param  array<string, mixed>  $aliases
     * @param  array<string, mixed>  $groups
     * @return list<string>
     */
    private static function resolveAll(array $entries, array $aliases, array $groups): array
    {
        $out = [];
        $seen = [];
        foreach ($entries as $entry) {
            self::resolveInto($entry, $aliases, $groups, $out, $seen);
        }

        return array_keys($out);
    }

    /**
     * @param  array<string, mixed>  $aliases
     * @param  array<string, mixed>  $groups
     * @param  array<string, true>  $out
     * @param  array<string, true>  $seen
     */
    private static function resolveInto(mixed $entry, array $aliases, array $groups, array &$out, array &$seen): void
    {
        if (! is_string($entry) || $entry === '' || $entry instanceof Closure) {
            return;
        }

        $name = trim(explode(':', $entry, 2)[0]);

        if (isset($seen[$name])) {
            return;
        }

        if (isset($groups[$name])) {
            $seen[$name] = true;
            foreach ((array) $groups[$name] as $member) {
                self::resolveInto($member, $aliases, $groups, $out, $seen);
            }

            return;
        }

        if (isset($aliases[$name])) {
            $seen[$name] = true;
            self::resolveInto($aliases[$name], $aliases, $groups, $out, $seen);

            return;
        }

        if (class_exists($name) && ! (new \ReflectionClass($name))->isAbstract() && $name !== TimedMiddleware::class) {
            $out[ltrim($name, '\\')] = true;
        }
    }
}
