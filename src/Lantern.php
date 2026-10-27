<?php

declare(strict_types=1);

namespace Lantern;

use Closure;
use Illuminate\Http\Request;

final class Lantern
{
    private static ?Closure $auth = null;

    public static function auth(Closure $callback): void
    {
        self::$auth = $callback;
    }

    public static function check(Request $request): bool
    {
        if (self::$auth !== null) {
            return (bool) (self::$auth)($request);
        }

        return app()->environment('local');
    }

    /** Contents of a bundled dashboard asset (views are compiled, so `__DIR__` in a view is the cache dir). */
    public static function asset(string $name): string
    {
        $path = dirname(__DIR__).'/resources/assets/'.basename($name);

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    public static function flush(): void
    {
        self::$auth = null;
    }
}
