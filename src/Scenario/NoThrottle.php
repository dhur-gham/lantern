<?php

declare(strict_types=1);

namespace Lantern\Scenario;

use Closure;

/** Stands in for Laravel's `throttle:` middleware during a scenario that asked for no rate limiting. */
final class NoThrottle
{
    /** How many times the real middleware was skipped since reset(): 0 means the switch changed nothing. */
    public static int $skipped = 0;

    public static function reset(): void
    {
        self::$skipped = 0;
    }

    public function handle(mixed $request, Closure $next, mixed ...$parameters): mixed
    {
        self::$skipped++;

        return $next($request);
    }
}
