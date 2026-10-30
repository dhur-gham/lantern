<?php

declare(strict_types=1);

namespace Lantern\Detection\Explain;

interface AnalyzeSource
{
    /**
     * Rows the query really returns, measured by running it once more under a time limit. Null when it cannot be
     * measured (unsupported driver, timeout, error, a query that must not be replayed).
     *
     * @param  array<int|string, mixed>  $bindings
     */
    public function rowsReturned(?string $connectionName, string $sql, array $bindings = [], int $timeoutMs = 2000): ?int;
}
