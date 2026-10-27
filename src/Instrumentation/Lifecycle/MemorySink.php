<?php

declare(strict_types=1);

namespace Lantern\Instrumentation\Lifecycle;

use Lantern\Trace\Trace;

final class MemorySink implements TraceSink
{
    /** @var list<Trace> */
    private array $traces = [];

    public function __construct(private readonly int $limit = 20) {}

    public function push(Trace $trace): void
    {
        $this->traces[] = $trace;

        $limit = max(1, $this->limit);
        if (count($this->traces) > $limit) {
            $this->traces = array_slice($this->traces, -$limit);
        }
    }

    /** @return list<Trace> */
    public function all(): array
    {
        return $this->traces;
    }

    public function last(): ?Trace
    {
        return $this->traces === [] ? null : $this->traces[count($this->traces) - 1];
    }

    public function clear(): void
    {
        $this->traces = [];
    }
}
