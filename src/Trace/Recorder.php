<?php

declare(strict_types=1);

namespace Lantern\Trace;

final class Recorder
{
    private ?Trace $trace = null;

    /** @var list<Span> */
    private array $stack = [];

    private int $nextId = 0;

    private bool $active = false;

    public function __construct() {}

    /** @param array<string, mixed> $meta */
    public function begin(array $meta = [], ?int $startNs = null): Trace
    {
        $this->reset();

        $this->trace = new Trace($startNs);
        $this->trace->meta = $meta;
        $this->active = true;

        $root = new Span(++$this->nextId, null, SpanType::Request, 'request', $startNs);
        $this->trace->add($root);
        $this->stack[] = $root;

        return $this->trace;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /** @param array<string, mixed> $meta */
    public function start(SpanType $type, string $name, array $meta = [], ?int $startNs = null): Span
    {
        if (! $this->active || $this->trace === null) {
            return $this->detached($type, $name, $meta);
        }

        $parent = end($this->stack);
        $span = new Span(++$this->nextId, $parent === false ? null : $parent->id, $type, $name, $startNs);
        $span->meta = $meta;
        $this->trace->add($span);
        $this->stack[] = $span;

        return $span;
    }

    public function stop(Span $span, ?int $endNs = null): void
    {
        $index = null;
        foreach ($this->stack as $i => $open) {
            if ($open === $span) {
                $index = $i;
                break;
            }
        }

        if ($index === null) {
            $span->stop($endNs);

            return;
        }

        for ($i = count($this->stack) - 1; $i > $index; $i--) {
            $this->stack[$i]->stop($endNs);
        }

        $span->stop($endNs);
        $this->stack = array_slice($this->stack, 0, $index);
    }

    public function current(): ?Span
    {
        if (! $this->active || $this->stack === []) {
            return null;
        }

        return $this->stack[count($this->stack) - 1];
    }

    /** @param array<string, mixed> $meta */
    public function measure(SpanType $type, string $name, callable $fn, array $meta = []): mixed
    {
        $span = $this->start($type, $name, $meta);

        try {
            return $fn();
        } finally {
            $this->stop($span);
        }
    }

    /**
     * @param array<string, mixed> $meta
     * @param array{file: string, line: int, function?: string}|null $callSite
     */
    public function recordClosed(SpanType $type, string $name, int $startNs, int $endNs, array $meta = [], ?array $callSite = null): Span
    {
        if (! $this->active || $this->trace === null) {
            $span = $this->detached($type, $name, $meta);
            $span->callSite = $callSite;

            return $span;
        }

        $parent = end($this->stack);
        $span = new Span(++$this->nextId, $parent === false ? null : $parent->id, $type, $name, $startNs);
        $span->meta = $meta;
        $span->callSite = $callSite;
        $span->stop($endNs);
        $this->trace->add($span);

        return $span;
    }

    public function end(): ?Trace
    {
        if ($this->trace === null) {
            return null;
        }

        for ($i = count($this->stack) - 1; $i >= 0; $i--) {
            $this->stack[$i]->stop();
        }

        $this->stack = [];
        $this->active = false;

        return $this->trace;
    }

    public function trace(): ?Trace
    {
        return $this->trace;
    }

    public function reset(): void
    {
        $this->trace = null;
        $this->stack = [];
        $this->nextId = 0;
        $this->active = false;
    }

    /** @param array<string, mixed> $meta */
    private function detached(SpanType $type, string $name, array $meta): Span
    {
        $span = new Span(0, null, $type, $name);
        $span->meta = $meta;
        $span->stop();

        return $span;
    }
}
