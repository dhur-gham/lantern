<?php

declare(strict_types=1);

namespace Lantern\Storage;

use Illuminate\Support\Facades\DB;
use Throwable;

final class MigrationGuard
{
    private static ?string $lastSkip = null;

    /**
     * True when a Lantern migration must do nothing: the host app is running its test suite and Lantern is
     * configured for a connection other than the app's own default, which would be the real database file.
     * Never applies to --sql output (pretend mode).
     */
    public static function skip(?string $connection): bool
    {
        $reason = self::check($connection);
        if ($reason !== null) {
            self::$lastSkip = $reason;
        }

        return $reason !== null;
    }

    /** Why the last migration was skipped: 'tests-other-connection' or 'connection-unverified'; null if none was. */
    public static function lastSkip(): ?string
    {
        return self::$lastSkip;
    }

    public static function reset(): void
    {
        self::$lastSkip = null;
    }

    /** The reason a migration on this connection would be skipped right now, without recording it. */
    public static function check(?string $connection): ?string
    {
        if ($connection === null || ! app()->runningUnitTests()) {
            return null;
        }
        if ($connection === config('database.default')) {
            return null;
        }

        try {
            return DB::connection($connection)->pretending() ? null : 'tests-other-connection';
        } catch (Throwable) {
            return 'connection-unverified';
        }
    }
}
