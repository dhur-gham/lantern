<?php

declare(strict_types=1);

namespace Lantern\Trace;

final class Span
{
    public ?int $endNs = null;

    public readonly int $startNs;

    public readonly int $memoryStart;

    public ?int $memoryEnd = null;

    /** @var array<string, mixed> */
    public array $meta = [];

    /** @var array{file: string, line: int, function?: string}|null */
    public ?array $callSite = null;

    public function __construct(
        public readonly int $id,
        public readonly ?int $parentId,
        public readonly SpanType $type,
        public string $name,
        ?int $startNs = null,
    ) {
        $this->startNs = $startNs ?? hrtime(true);
        $this->memoryStart = memory_get_usage();
    }

    public function stop(?int $endNs = null): void
    {
        if ($this->endNs !== null) {
            return;
        }

        $this->endNs = $endNs ?? hrtime(true);
        $this->memoryEnd = memory_get_usage();
    }

    public function isStopped(): bool
    {
        return $this->endNs !== null;
    }

    public function durationMs(): float
    {
        $end = $this->endNs ?? hrtime(true);

        return max(0, $end - $this->startNs) / 1_000_000;
    }

    public function memoryDelta(): int
    {
        return ($this->memoryEnd ?? memory_get_usage()) - $this->memoryStart;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parentId,
            'type' => $this->type->value,
            'name' => $this->name,
            'duration_ms' => $this->durationMs(),
            'memory_delta' => $this->memoryDelta(),
            'call_site' => $this->callSite,
            'meta' => $this->meta,
        ];
    }
}
