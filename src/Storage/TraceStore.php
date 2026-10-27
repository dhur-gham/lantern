<?php

declare(strict_types=1);

namespace Lantern\Storage;

use Lantern\Detection\Issue;
use Lantern\Trace\Trace;

interface TraceStore
{
    /** @param list<Issue> $issues */
    public function store(Trace $trace, array $issues): void;

    /** @return array<string, mixed>|null Row columns plus 'payload' (decoded Trace::toArray()). */
    public function find(string $id): ?array;

    /** @return list<array<string, mixed>> Newest first, without payload. */
    public function recent(int $limit = 50): array;
}
