<?php

declare(strict_types=1);

namespace Lantern\Instrumentation\Lifecycle;

use Closure;
use Illuminate\Routing\Contracts\CallableDispatcher;
use Illuminate\Routing\Route;
use Lantern\Trace\Recorder;
use Lantern\Trace\SpanType;
use ReflectionFunction;

/** Times closure routes (the controller dispatcher is not used for them). */
final class TimedCallableDispatcher implements CallableDispatcher
{
    /** @param Closure(): Recorder $recorder */
    public function __construct(private readonly CallableDispatcher $inner, private readonly Closure $recorder) {}

    public function dispatch(Route $route, $callable)
    {
        $recorder = ($this->recorder)();

        if (! $recorder->isActive()) {
            return $this->inner->dispatch($route, $callable);
        }

        $span = $recorder->start(SpanType::Controller, 'Closure');

        try {
            $reflection = new ReflectionFunction($callable);
            $file = $reflection->getFileName();
            if ($file !== false) {
                $span->callSite = ['file' => $file, 'line' => (int) $reflection->getStartLine(), 'function' => '{closure}'];
            }
        } catch (\Throwable) {
            // non-reflectable callable
        }

        try {
            return $this->inner->dispatch($route, $callable);
        } finally {
            $recorder->stop($span);
        }
    }
}
