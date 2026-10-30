<?php

declare(strict_types=1);

namespace Lantern\Access;

use Lantern\Storage\TraceQueries;
use Throwable;

/** The host the browser used on the last request Lantern recorded, to compare with APP_URL. */
final class HostCheck
{
    public const HINT = 'No recorded request has a host yet, so APP_URL could not be checked against the host the browser uses. Open the app in the browser once with Lantern on (or set APP_URL to that address), then run this again.';

    public function __construct(private readonly TraceQueries $queries) {}

    public function lastSeen(): ?string
    {
        try {
            if (config('lantern.storage.driver', 'memory') !== 'database') {
                return null;
            }
            foreach ($this->queries->traces([], 15) as $row) {
                if (($row['method'] ?? null) === null) {
                    continue;
                }
                $trace = $this->queries->trace((string) $row['id']);
                $host = $trace['payload']['meta']['host'] ?? null;
                if (is_string($host) && $host !== '') {
                    return $host;
                }
            }
        } catch (Throwable) {
        }

        return null;
    }
}
