<?php

declare(strict_types=1);

namespace Lantern\Instrumentation\Lifecycle;

use Lantern\Trace\Trace;

/** Passes on only traces made by Lantern::record(), so setup code around the blocks of a script stores nothing. */
final class RecordOnlySink implements TraceSink
{
    public function __construct(private readonly PersistSink $inner) {}

    public function push(Trace $trace): void
    {
        if (($trace->meta['kind'] ?? null) === 'record') {
            $this->inner->push($trace);
        }
    }

    public function flush(): void
    {
        $this->inner->flush();
    }
}
