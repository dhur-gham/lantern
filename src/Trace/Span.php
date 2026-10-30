<?php

declare(strict_types=1);

namespace Lantern\Trace;

final class Span
{
    public ?int $endNs = null;

    public readonly int $startNs;

    public readonly int $memoryStart;

    public ?int $memoryEnd = null;

    public readonly int $peakStart;

    public ?int $peakEnd = null;

    /** @var array<string, mixed> */
    public array $meta = [];

    /** @var array{file: string, line: int, function?: string}|null */
    public ?array $callSite = null;

    /**
     * Raw query bindings kept in memory only so explain_analyze can replay the query. Never part of meta or
     * toArray(), so they cannot be persisted or emitted; released as soon as the detectors have run.
     *
     * @var array<int|string, mixed>|null
     */
    private ?array $rawBindings = null;

    public function __construct(
        public readonly int $id,
        public readonly ?int $parentId,
        public readonly SpanType $type,
        public string $name,
        ?int $startNs = null,
    ) {
        $this->startNs = $startNs ?? hrtime(true);
        $this->memoryStart = memory_get_usage();
        $this->peakStart = memory_get_peak_usage();
    }

    public function stop(?int $endNs = null): void
    {
        if ($this->endNs !== null) {
            return;
        }

        $this->endNs = $endNs ?? hrtime(true);
        $this->memoryEnd = memory_get_usage();
        $this->peakEnd = memory_get_peak_usage();
    }

    public function isStopped(): bool
    {
        return $this->endNs !== null;
    }

    /** @param array<int|string, mixed> $bindings */
    public function keepRawBindings(array $bindings): void
    {
        $this->rawBindings = $bindings;
    }

    /** @return array<int|string, mixed>|null */
    public function rawBindings(): ?array
    {
        return $this->rawBindings;
    }

    public function releaseRawBindings(): void
    {
        $this->rawBindings = null;
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

    /**
     * Bytes the process high-water mark rose above this span's starting usage while it ran. Catches transient growth
     * (rows hydrated and freed inside the span) that memoryDelta() misses. 0 when the peak did not move.
     */
    public function memoryPeakGrowth(): int
    {
        $end = $this->peakEnd ?? memory_get_peak_usage();

        return $end > $this->peakStart ? max(0, $end - $this->memoryStart) : 0;
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
