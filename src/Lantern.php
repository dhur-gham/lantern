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

    public static function hasAuth(): bool
    {
        return self::$auth !== null;
    }

    public static function check(Request $request): bool
    {
        if (self::$auth !== null) {
            return (bool) (self::$auth)($request);
        }

        return app()->environment('local') && self::isLoopback($request);
    }

    /** The TCP peer address, never trusting forwarded headers; any forwarding header means a proxy or tunnel, so not local. */
    private static function isLoopback(Request $request): bool
    {
        foreach (['X-Forwarded-For', 'Forwarded', 'X-Forwarded-Host', 'X-Real-IP'] as $header) {
            if ($request->headers->has($header)) {
                return false;
            }
        }

        $ip = (string) $request->server('REMOTE_ADDR', '');

        return $ip === '::1' || str_starts_with($ip, '127.') || $ip === '::ffff:127.0.0.1';
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
