<?php

declare(strict_types=1);

namespace Lantern\Instrumentation\Lifecycle;

use Illuminate\Contracts\Foundation\Application;
use Lantern\Detection\Issue;
use Lantern\Sampling\Sampler;
use Lantern\Storage\TraceStore;
use Lantern\Trace\Trace;
use Throwable;

/**
 * Buffers finished traces and writes them through the TraceStore. Pushes happen while the app is
 * terminating (after the response), so writes never delay the response. Storage failures fall back
 * to the MemorySink and are logged once per sink instance.
 */
final class PersistSink implements TraceSink
{
    /** @var list<array{0: Trace, 1: list<Issue>}> */
    private array $buffer = [];

    private bool $flushScheduled = false;

    private bool $failureLogged = false;

    public function __construct(
        private readonly TraceStore $store,
        private readonly Sampler $sampler,
        private readonly MemorySink $fallback,
        private readonly ?Application $app = null,
        private readonly int $batchSize = 1,
    ) {}

    public function push(Trace $trace): void
    {
        try {
            $issues = (array) ($trace->meta['issues'] ?? []);
            if (! $this->sampler->keepTrace($trace, $issues)) {
                return;
            }

            $this->buffer[] = [$trace, array_map(
                static fn ($i) => $i instanceof Issue ? $i : Issue::fromArray((array) $i),
                $issues,
            )];

            if (count($this->buffer) >= max(1, $this->batchSize)) {
                $this->flush();
            } elseif ($this->app !== null && ! $this->flushScheduled) {
                // Registered once: terminating callbacks are never cleared, so one per request would pile up.
                $this->flushScheduled = true;
                $this->app->terminating(fn () => $this->flush());
            }
        } catch (Throwable) {
            // never break the request
        }
    }

    public function flush(): void
    {
        $batch = $this->buffer;
        $this->buffer = [];

        foreach ($batch as [$trace, $issues]) {
            try {
                $this->store->store($trace, $issues);
            } catch (Throwable $e) {
                $this->fallback->push($trace);
                $this->logOnce($e);
            }
        }
    }

    private function logOnce(Throwable $e): void
    {
        if ($this->failureLogged) {
            return;
        }
        $this->failureLogged = true;

        try {
            logger()->warning('Lantern storage failed, falling back to memory: '.$e->getMessage());
        } catch (Throwable) {
        }
    }
}
