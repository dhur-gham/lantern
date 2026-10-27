<?php

declare(strict_types=1);

namespace Lantern\Instrumentation\Middleware;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Lantern\Trace\Recorder;
use Lantern\Trace\SpanType;

final class TimedMiddleware
{
    public function __construct(
        private readonly object $inner,
        private readonly Application $app,
    ) {}

    public function inner(): object
    {
        return $this->inner;
    }

    public function handle(mixed $request, Closure $next, mixed ...$params): mixed
    {
        $recorder = $this->recorder();

        if (! $recorder->isActive()) {
            return $this->inner->handle($request, $next, ...$params);
        }

        $span = $recorder->start(SpanType::Middleware, $this->inner::class);

        try {
            return $this->inner->handle($request, $next, ...$params);
        } finally {
            $recorder->stop($span);
        }
    }

    public function terminate(mixed ...$args): mixed
    {
        if (! method_exists($this->inner, 'terminate')) {
            return null;
        }

        $recorder = $this->recorder();

        if (! $recorder->isActive()) {
            return $this->inner->terminate(...$args);
        }

        $span = $recorder->start(SpanType::Middleware, $this->inner::class.'::terminate');

        try {
            return $this->inner->terminate(...$args);
        } finally {
            $recorder->stop($span);
        }
    }

    /** @param array<int, mixed> $arguments */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->inner->{$method}(...$arguments);
    }

    public function __get(string $name): mixed
    {
        return $this->inner->{$name};
    }

    public function __set(string $name, mixed $value): void
    {
        $this->inner->{$name} = $value;
    }

    public function __isset(string $name): bool
    {
        return isset($this->inner->{$name});
    }

    public function __unset(string $name): void
    {
        unset($this->inner->{$name});
    }

    private function recorder(): Recorder
    {
        return $this->app->make(Recorder::class);
    }
}
