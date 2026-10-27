<?php

declare(strict_types=1);

namespace Lantern\Instrumentation\Lifecycle;

use Closure;
use Illuminate\Contracts\View\Engine;
use Lantern\Trace\Recorder;
use Lantern\Trace\SpanType;

/**
 * Decorates the Blade engine (never replaces it) so each rendered view becomes a span.
 * The wrapped engine lives in `$engine`: Laravel's exception renderer looks for that name on decorators.
 */
final class TimedEngine implements Engine
{
    /** @param Closure(): Recorder $recorder */
    public function __construct(private readonly Engine $engine, private readonly Closure $recorder) {}

    public function get($path, array $data = [])
    {
        $recorder = ($this->recorder)();

        if (! $recorder->isActive()) {
            return $this->engine->get($path, $data);
        }

        $span = $recorder->start(SpanType::View, ViewNames::fromPath((string) $path));
        $span->callSite = ['file' => (string) $path, 'line' => 1];

        try {
            return $this->engine->get($path, $data);
        } finally {
            $recorder->stop($span);
        }
    }

    public function inner(): Engine
    {
        return $this->engine;
    }

    /** @param array<int, mixed> $arguments */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->engine->{$method}(...$arguments);
    }
}
