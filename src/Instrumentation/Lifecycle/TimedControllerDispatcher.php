<?php

declare(strict_types=1);

namespace Lantern\Instrumentation\Lifecycle;

use Closure;
use Illuminate\Routing\Contracts\ControllerDispatcher;
use Illuminate\Routing\Route;
use Lantern\Trace\Recorder;
use Lantern\Trace\SpanType;
use ReflectionClass;
use ReflectionMethod;

final class TimedControllerDispatcher implements ControllerDispatcher
{
    /** @param Closure(): Recorder $recorder */
    public function __construct(private readonly ControllerDispatcher $inner, private readonly Closure $recorder) {}

    public function dispatch(Route $route, $controller, $method)
    {
        $recorder = ($this->recorder)();

        if (! $recorder->isActive()) {
            return $this->inner->dispatch($route, $controller, $method);
        }

        $component = $controller instanceof \Livewire\Component;
        $span = $recorder->start(SpanType::Controller, get_class($controller).($component ? '' : '@'.$method));

        try {
            // Full-page component: point at the page class, not the trait that implements the entry method.
            $reflection = $component ? new ReflectionClass($controller) : new ReflectionMethod($controller, $method);
            $file = $reflection->getFileName();
            if ($file !== false) {
                $span->callSite = ['file' => $file, 'line' => (int) $reflection->getStartLine(), 'function' => $method];
            }
        } catch (\Throwable) {
            // magic __call controllers have no reflectable method
        }

        try {
            return $this->inner->dispatch($route, $controller, $method);
        } finally {
            $recorder->stop($span);
        }
    }

    public function getMiddleware($controller, $method)
    {
        return $this->inner->getMiddleware($controller, $method);
    }
}
