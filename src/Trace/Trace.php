<?php

declare(strict_types=1);

namespace Lantern\Trace;

final class Trace
{
    public readonly string $id;

    public readonly int $startedAtNs;

    public readonly float $startedAtUnix;

    /** @var array<string, mixed> */
    public array $meta = [];

    /** @var array<int, Span> */
    private array $spans = [];

    /** @var array<int, list<int>> */
    private array $children = [];

    private ?int $rootId = null;

    public function __construct(?int $startedAtNs = null)
    {
        $this->id = self::uuid();
        $this->startedAtNs = $startedAtNs ?? hrtime(true);
        $this->startedAtUnix = microtime(true);
    }

    /** @return array<int, Span> */
    public function spans(): array
    {
        return $this->spans;
    }

    public function root(): ?Span
    {
        return $this->rootId === null ? null : ($this->spans[$this->rootId] ?? null);
    }

    /** @return list<Span> */
    public function children(int $spanId): array
    {
        $out = [];
        foreach ($this->children[$spanId] ?? [] as $id) {
            $out[] = $this->spans[$id];
        }

        return $out;
    }

    public function add(Span $span): void
    {
        $this->spans[$span->id] = $span;

        if ($span->parentId === null) {
            $this->rootId ??= $span->id;
        } else {
            $this->children[$span->parentId][] = $span->id;
        }
    }

    public function selfMs(Span $span): float
    {
        $self = $span->durationMs();
        foreach ($this->children($span->id) as $child) {
            $self -= $child->durationMs();
        }

        return max(0.0, $self);
    }

    public function totalMs(): float
    {
        return $this->root()?->durationMs() ?? 0.0;
    }

    public function peakMemory(): int
    {
        $peak = 0;
        foreach ($this->spans as $span) {
            $peak = max($peak, $span->memoryStart, $span->memoryEnd ?? 0, $span->peakEnd ?? 0);
        }

        return $peak;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $root = $this->root();

        return [
            'id' => $this->id,
            'started_at' => $this->startedAtUnix,
            'total_ms' => $this->totalMs(),
            'peak_memory' => $this->peakMemory(),
            'meta' => $this->meta,
            'root' => $root === null ? null : $this->node($root, 0, $this->maxDepth()),
        ];
    }

    /** @return array<string, mixed> */
    private function node(Span $span, int $depth, int $maxDepth): array
    {
        $children = [];
        $omitted = 0;
        if ($depth + 1 >= $maxDepth) {
            $omitted = count($this->children[$span->id] ?? []);
        } else {
            foreach ($this->children($span->id) as $child) {
                $children[] = $this->node($child, $depth + 1, $maxDepth);
            }
        }

        return ($omitted > 0 ? ['children_omitted' => $omitted] : []) + [
            'id' => $span->id,
            'type' => $span->type->value,
            'name' => $span->name,
            'start_ms' => round(max(0, $span->startNs - $this->startedAtNs) / 1_000_000, 3),
            'duration_ms' => $span->durationMs(),
            'self_ms' => $this->selfMs($span),
            'memory_delta' => $span->memoryDelta(),
            'call_site' => $span->callSite,
            'meta' => $span->meta,
            'children' => $children,
        ];
    }

    private function maxDepth(): int
    {
        try {
            $value = (int) config('lantern.max_tree_depth', 100);
        } catch (\Throwable) {
            $value = 100;
        }

        return $value > 0 ? $value : 100;
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);

        return sprintf('%s-%s-%s-%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4), substr($h, 16, 4), substr($h, 20, 12));
    }
}
