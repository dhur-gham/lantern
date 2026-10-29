<?php

declare(strict_types=1);

namespace Lantern\Detection\Explain;

interface PlanSource
{
    /**
     * @param  array<int|string, mixed>  $bindings
     * @return array{full_scan: bool, table: ?string, rows: ?int, key: ?string, possible_keys: ?string, tables?: list<array{table: string, alias: ?string, rows: ?int, key: ?string, type: ?string, possible_keys: ?string}>, raw: mixed}|null
     */
    public function explain(?string $connectionName, string $sql, array $bindings = []): ?array;
}
