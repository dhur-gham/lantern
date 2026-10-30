<?php

declare(strict_types=1);

namespace Lantern;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Lantern\Instrumentation\Lifecycle\LifecycleInstrumentation;
use Lantern\Trace\Recorder;
use Lantern\Trace\SpanType;
use Throwable;

/**
 * Records any code as a trace: the closure's queries, lazy loads and spans are analysed and stored like a request's.
 * A plain call of the closure while Lantern is off, so probes can stay in place.
 */
final class Recording
{
    /** @var list<array<string, mixed>> */
    private array $finished = [];

    public function __construct(private readonly Application $app) {}

    /**
     * @param array<string, mixed> $context ids and small values describing the run; redacted and truncated like bindings
     */
    public function record(string $name, Closure $fn, array $context = []): mixed
    {
        if (! $this->app->bound(Recorder::class) || ! $this->app->bound(LifecycleInstrumentation::class)) {
            return $fn();
        }

        $recorder = $this->app->make(Recorder::class);
        $meta = ['kind' => 'record', 'name' => $name, 'route' => 'record:'.$name, 'status' => 'processing'];
        if ($context !== []) {
            $meta['context'] = $this->context($context);
        }

        if ($recorder->isActive()) {
            return $this->nested($recorder, $name, $meta, $fn);
        }

        $root = null;
        try {
            $trace = $recorder->begin($meta + ['keep' => true], null, SpanType::Record);
            $root = $trace->root();
            if ($root !== null) {
                $root->name = $name;
                $root->meta = $meta;
            }
        } catch (Throwable) {
            return $fn();
        }

        $status = 'processed';
        try {
            return $fn();
        } catch (Throwable $e) {
            $status = 'failed';
            $trace->meta['exception'] = ['class' => $e::class, 'message' => mb_substr($e->getMessage(), 0, 300)];

            throw $e;
        } finally {
            $trace->meta['status'] = $status;
            if ($root !== null) {
                $root->meta['status'] = $status;
            }
            $this->finish($trace);
        }
    }

    /** Summaries of the traces recorded in this process, oldest first. @return list<array<string, mixed>> */
    public function finished(): array
    {
        return $this->finished;
    }

    public function forget(): void
    {
        $this->finished = [];
    }

    /** @param array<string, mixed> $meta */
    private function nested(Recorder $recorder, string $name, array $meta, Closure $fn): mixed
    {
        // inside a request, job or command: a child span, and the enclosing trace is kept whatever sampling says
        $enclosing = $recorder->trace();
        if ($enclosing !== null) {
            $enclosing->meta['keep'] = true;
        }
        $span = $recorder->start(SpanType::Record, $name, $meta);

        try {
            return $fn();
        } finally {
            $recorder->stop($span);
        }
    }

    private function finish(\Lantern\Trace\Trace $trace): void
    {
        try {
            $this->app->make(LifecycleInstrumentation::class)->finish(true);
            $queries = array_filter($trace->spans(), fn ($s) => $s->type === SpanType::Query && ($s->meta['kind'] ?? null) !== 'transaction');
            $issues = (array) ($trace->meta['issues'] ?? []);
            $this->finished[] = [
                'trace_id' => $trace->id,
                'name' => (string) ($trace->meta['name'] ?? ''),
                'status' => (string) ($trace->meta['status'] ?? ''),
                'duration_ms' => round($trace->totalMs(), 2),
                'query_count' => count($queries),
                'query_ms' => round(array_sum(array_map(fn ($s) => (float) ($s->meta['time_ms'] ?? $s->durationMs()), $queries)), 2),
                'issue_count' => count($issues),
                'issues' => array_map(fn ($i) => array_intersect_key((array) $i, array_flip(['id', 'key', 'type', 'severity', 'title', 'call_site', 'count'])), $issues),
            ];
        } catch (Throwable) {
            // recording must never break the code it wraps
        }
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function context(array $context): array
    {
        $max = max(8, (int) config('lantern.binding_max_length', 200));
        $out = [];
        foreach (array_slice($context, 0, 30, true) as $key => $value) {
            if (is_string($key) && preg_match('/pass(word)?|secret|token|api[_-]?key|authorization|credential/i', $key) === 1) {
                $out[$key] = '[redacted]';

                continue;
            }
            $out[$key] = match (true) {
                is_string($value) => mb_strlen($value) > $max ? mb_substr($value, 0, $max).'...' : $value,
                is_scalar($value) || $value === null => $value,
                default => '['.get_debug_type($value).']',
            };
        }

        return $out;
    }
}
