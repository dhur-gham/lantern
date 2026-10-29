<?php

declare(strict_types=1);

namespace Lantern\Detection\Explain;

interface Catalog
{
    /**
     * Indexes of a table, columns in index order. Empty when unknown or unsupported.
     *
     * @return list<array{name: string, columns: list<string>, unique: bool}>
     */
    public function indexes(?string $connection, string $table): array;

    /**
     * Row estimate and per-column facts (keys are lower-case column names).
     *
     * @return array{rows: ?int, columns: array<string, array{type: ?string, distinct: ?int}>}
     */
    public function stats(?string $connection, string $table): array;
}
