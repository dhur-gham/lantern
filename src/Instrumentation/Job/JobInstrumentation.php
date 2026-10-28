<?php

declare(strict_types=1);

namespace Lantern\Instrumentation\Job;

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Str;
use Lantern\Instrumentation\Lifecycle\LifecycleInstrumentation;
use Lantern\Instrumentation\Lifecycle\PersistSink;
use Lantern\Instrumentation\Lifecycle\TraceSink;
use Lantern\Instrumentation\Query\QueryInstrumentation;
use Lantern\Sampling\Sampler;
use Lantern\Trace\Recorder;
use Lantern\Trace\Span;
use Lantern\Trace\SpanType;
use Lantern\Trace\Trace;
use ReflectionMethod;
use Throwable;
use WeakMap;

/**
 * Queue job traces. A job processed with no trace running is its own Trace (root type Job); a job that runs inside
 * another trace (sync queue inside a request or command) becomes a child Job span. A retry that is released ends
 * with status "released", an exhausted one with "failed".
 *
 * Register before LifecycleInstrumentation: its JobProcessed/JobFailed listener resets the recorder, so the job
 * trace must already be finished by then.
 */
final class JobInstrumentation
{
    /** @var WeakMap<object, array{span: Span, own: bool, exception: ?Throwable}> */
    private WeakMap $frames;

    private ?Trace $owned = null;

    public function __construct(private readonly Application $app)
    {
        $this->frames = new WeakMap;
    }

    public static function register(Application $app): void
    {
        $self = new self($app);
        $events = $app['events'];

        $events->listen(JobProcessing::class, fn (JobProcessing $e) => $self->guard(fn () => $self->begin($e->job, $e->connectionName)));
        $events->listen(JobProcessed::class, fn (JobProcessed $e) => $self->guard(fn () => $self->end($e->job, 'processed')));
        $events->listen(JobFailed::class, fn (JobFailed $e) => $self->guard(fn () => $self->end($e->job, 'failed', $e->exception)));
        $events->listen(JobExceptionOccurred::class, fn (JobExceptionOccurred $e) => $self->guard(function () use ($self, $e): void {
            if (isset($self->frames[$e->job])) {
                $frame = $self->frames[$e->job];
                $frame['exception'] = $e->exception;
                $self->frames[$e->job] = $frame;
            }
        }));
        $events->listen(JobReleasedAfterException::class, fn (JobReleasedAfterException $e) => $self->guard(fn () => $self->end($e->job, 'released')));
        $events->listen(WorkerStopping::class, fn () => $self->guard(function () use ($self): void {
            $self->finishStale('abandoned');
            $sink = $self->app->make(TraceSink::class);
            if ($sink instanceof PersistSink) {
                $sink->flush();
            }
        }));
    }

    private function begin(Job $job, ?string $connection): void
    {
        $name = $job->resolveName();
        if (! (bool) config('lantern.trace_jobs', true) || $this->ignored($name)) {
            return;
        }

        $recorder = $this->recorder();
        $this->finishStale('abandoned');

        $meta = [
            'kind' => 'job',
            'job' => $name,
            'queue' => $job->getQueue(),
            'connection' => $connection ?? $job->getConnectionName(),
            'attempts' => $job->attempts(),
            'uuid' => $job->uuid(),
            'status' => 'processing',
        ];

        if ($recorder->isActive()) {
            $span = $recorder->start(SpanType::Job, $name, $meta);
            $this->frames[$job] = ['span' => $span, 'own' => false, 'exception' => null];

            return;
        }

        if (! $this->app->make(Sampler::class)->headDecision()) {
            return;
        }

        $trace = $recorder->begin($meta, null, SpanType::Job);
        $root = $trace->root();
        if ($root === null) {
            return;
        }
        $root->name = $name;
        $root->meta = $meta;
        $this->owned = $trace;
        $this->frames[$job] = ['span' => $root, 'own' => true, 'exception' => null];
    }

    private function end(Job $job, string $status, ?Throwable $exception = null): void
    {
        if (! isset($this->frames[$job])) {
            return;
        }

        $frame = $this->frames[$job];
        unset($this->frames[$job]);
        $exception ??= $frame['exception'];

        $update = ['status' => $status, 'attempts' => $job->attempts()];
        if ($exception !== null) {
            $update['exception'] = [
                'class' => $exception::class,
                'message' => mb_substr($exception->getMessage(), 0, 300),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ];
        }

        $frame['span']->meta = array_merge($frame['span']->meta, $update);

        if (! $frame['own']) {
            $this->recorder()->stop($frame['span']);

            return;
        }

        $this->flushPendingQueries();
        $this->finishOwned($update);
    }

    /** @param array<string, mixed> $update */
    private function finishOwned(array $update): void
    {
        $trace = $this->owned;
        $this->owned = null;
        if ($trace === null) {
            return;
        }

        $trace->meta = array_merge($trace->meta, $update);
        if ($this->recorder()->trace() === $trace) {
            $this->app->make(LifecycleInstrumentation::class)->finish();
        }
    }

    /** A job trace still open when the next job starts (killed worker, missed event). */
    private function finishStale(string $status): void
    {
        if ($this->owned === null) {
            return;
        }

        $this->flushPendingQueries();
        $this->finishOwned(['status' => $status]);
        $this->recorder()->reset();
    }

    /** A failed query is finalised on JobProcessed/JobFailed, which would run after this trace has ended. */
    private function flushPendingQueries(): void
    {
        if (! $this->app->bound(QueryInstrumentation::class)) {
            return;
        }

        (new ReflectionMethod(QueryInstrumentation::class, 'flushAll'))->invoke($this->app->make(QueryInstrumentation::class));
    }

    private function ignored(string $name): bool
    {
        $patterns = (array) config('lantern.ignore_jobs', []);

        return $patterns !== [] && Str::is($patterns, $name);
    }

    private function recorder(): Recorder
    {
        return Container::getInstance()->make(Recorder::class);
    }

    private function guard(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable) {
            // instrumentation must never break the host app
        }
    }
}
