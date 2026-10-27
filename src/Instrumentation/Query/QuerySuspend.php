<?php

declare(strict_types=1);

namespace Lantern\Instrumentation\Query;

use Throwable;

/** While active, query instrumentation ignores every query (used by Lantern's own storage writes). */
final class QuerySuspend
{
    private static int $depth = 0;

    public static function active(): bool
    {
        return self::$depth > 0;
    }

    public static function run(callable $fn): mixed
    {
        self::$depth++;

        try {
            return $fn();
        } finally {
            self::$depth--;
        }
    }
}
