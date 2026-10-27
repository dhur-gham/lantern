<?php

declare(strict_types=1);

namespace Lantern\Instrumentation\Query;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Events\Terminating;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Lantern\Query\CallSiteResolver;
use Lantern\Query\Fingerprinter;
use Lantern\Trace\Recorder;
use Lantern\Trace\SpanType;
use Throwable;
use WeakMap;

/**
 * Query spans.
 *
 * Mechanism: Connection::beforeExecuting is attached once per connection object (ConnectionEstablished plus
 * connections already resolved). It stores a pending slot (sql, bindings, start, call site, trace id) for that
 * connection. QueryExecuted finalises the slot as a successful span.
 *
 * Failed queries never raise QueryExecuted. A slot still pending is finalised as failed=true when: the next
 * beforeExecuting fires on the same connection, a reported QueryException reaches the log (adds error class and
 * message), the request is handled, the app terminates, or a queue job ends. Limits: the error class/message is
 * only known when the exception is reported (a swallowed try/catch failure is flagged failed without them), and
 * the failed duration is the time until it was noticed, so it is approximate (an upper bound).
 * A slot belonging to an earlier trace is dropped, never attributed to the current one.
 */
final class QueryInstrumentation
{
    /** @var WeakMap<Connection, true> */
    private WeakMap $hooked;

    /** @var WeakMap<Connection, array<string, mixed>> */
    private WeakMap $pending;

    private ?CallSiteResolver $resolver = null;

    private string $resolverKey = '';

    private ?Fingerprinter $fingerprinter = null;

    public function __construct(private readonly Application $app)
    {
        $this->hooked = new WeakMap;
        $this->pending = new WeakMap;
    }

    public function register(): void
    {
        $events = $this->app['events'];

        $events->listen(ConnectionEstablished::class, fn (ConnectionEstablished $e) => $this->guard(fn () => $this->attach($e->connection)));
        $events->listen(QueryExecuted::class, fn (QueryExecuted $e) => $this->guard(fn () => $this->executed($e)));
        $events->listen(TransactionBeginning::class, fn ($e) => $this->guard(fn () => $this->transaction($e->connection, 'BEGIN')));
        $events->listen(TransactionCommitted::class, fn ($e) => $this->guard(fn () => $this->transaction($e->connection, 'COMMIT')));
        $events->listen(TransactionRolledBack::class, fn ($e) => $this->guard(fn () => $this->transaction($e->connection, 'ROLLBACK')));

        $events->listen(MessageLogged::class, fn (MessageLogged $e) => $this->guard(fn () => $this->logged($e)));
        $events->listen(RequestHandled::class, fn () => $this->guard($this->flushAll(...)));
        $events->listen(Terminating::class, fn () => $this->guard($this->flushAll(...)));
        $events->listen(JobProcessed::class, fn () => $this->guard($this->flushAll(...)));
        $events->listen(JobFailed::class, fn () => $this->guard($this->flushAll(...)));

        if ($this->app->bound('db')) {
            foreach ($this->app['db']->getConnections() as $connection) {
                $this->attach($connection);
            }
        }
    }

    private function attach(mixed $connection): void
    {
        if (! $connection instanceof Connection || isset($this->hooked[$connection])) {
            return;
        }

        $this->hooked[$connection] = true;
        $connection->beforeExecuting(function (string $query, array $bindings, Connection $connection): void {
            $this->guard(fn () => $this->before($query, $bindings, $connection));
        });
    }

    private function before(string $query, array $bindings, Connection $connection): void
    {
        // A slot still here means the previous query on this connection never reported success.
        $this->failPending($connection);

        $recorder = $this->recorder();
        if (! $recorder->isActive() || $this->ignoredConnection($connection)) {
            return;
        }

        $this->pending[$connection] = [
            'sql' => $query,
            'bindings' => $bindings,
            'start' => hrtime(true),
            'site' => $this->site($recorder),
            'trace' => $recorder->trace()?->id,
        ];
    }

    private function executed(QueryExecuted $event): void
    {
        $connection = $event->connection;
        $recorder = $this->recorder();
        if (! $recorder->isActive() || $this->ignoredConnection($connection)) {
            unset($this->pending[$connection]);

            return;
        }

        $slot = $this->pending[$connection] ?? null;
        unset($this->pending[$connection]);

        if ($slot !== null && ($slot['sql'] !== $event->sql || $slot['trace'] !== $recorder->trace()?->id)) {
            if ($slot['trace'] === $recorder->trace()?->id) {
                $this->finishFailed($recorder, $connection, $slot, null);
            }
            $slot = null;
        }

        $now = hrtime(true);
        $duration = (int) round($event->time * 1_000_000);
        $slot ??= [
            'sql' => $event->sql,
            'bindings' => $event->bindings,
            'start' => $now - $duration,
            'site' => $this->site($recorder),
        ];

        $start = (int) $slot['start'];
        $this->record($recorder, $connection, $slot, $start, $start + $duration, [
            'time_ms' => $event->time,
            'rows' => null,
            'failed' => false,
        ]);

        $trace = $recorder->trace();
        if ($trace !== null) {
            $trace->meta['query_count'] = ($trace->meta['query_count'] ?? 0) + 1;
            $trace->meta['query_total_ms'] = ($trace->meta['query_total_ms'] ?? 0.0) + $event->time;
        }
    }

    private function transaction(mixed $connection, string $name): void
    {
        $recorder = $this->recorder();
        if (! $recorder->isActive() || ! $connection instanceof Connection || $this->ignoredConnection($connection)) {
            return;
        }

        $now = hrtime(true);
        $recorder->recordClosed(SpanType::Query, $name, $now, $now, [
            'kind' => 'transaction',
            'connection' => $connection->getName(),
            'transaction_level' => $connection->transactionLevel(),
        ]);
    }

    private function logged(MessageLogged $event): void
    {
        $exception = $event->context['exception'] ?? null;
        if (! $exception instanceof QueryException) {
            return;
        }

        $connection = null;
        foreach ($this->app['db']->getConnections() as $candidate) {
            if (isset($this->pending[$candidate]) && $this->pending[$candidate]['sql'] === $exception->getSql()) {
                $connection = $candidate;
                break;
            }
        }

        if ($connection !== null) {
            $this->failPending($connection, $exception);
        }
    }

    private function flushAll(): void
    {
        if (! $this->app->bound('db')) {
            return;
        }

        foreach ($this->app['db']->getConnections() as $connection) {
            $this->failPending($connection);
        }
    }

    private function failPending(Connection $connection, ?Throwable $error = null): void
    {
        $slot = $this->pending[$connection] ?? null;
        if ($slot === null) {
            return;
        }
        unset($this->pending[$connection]);

        $recorder = $this->recorder();
        if (! $recorder->isActive() || $slot['trace'] !== $recorder->trace()?->id) {
            return;
        }

        $this->finishFailed($recorder, $connection, $slot, $error);
    }

    /** @param array<string, mixed> $slot */
    private function finishFailed(Recorder $recorder, Connection $connection, array $slot, ?Throwable $error): void
    {
        $end = hrtime(true);
        $start = (int) $slot['start'];
        $cause = $error instanceof QueryException ? ($error->getPrevious() ?? $error) : $error;

        $this->record($recorder, $connection, $slot, $start, $end, [
            'time_ms' => ($end - $start) / 1_000_000,
            'rows' => null,
            'failed' => true,
            'error_class' => $cause === null ? null : $cause::class,
            'error_message' => $cause === null ? null : mb_substr($cause->getMessage(), 0, 500),
        ]);

        $trace = $recorder->trace();
        if ($trace !== null) {
            $trace->meta['query_count'] = ($trace->meta['query_count'] ?? 0) + 1;
            $trace->meta['failed_query_count'] = ($trace->meta['failed_query_count'] ?? 0) + 1;
            $trace->meta['query_total_ms'] = ($trace->meta['query_total_ms'] ?? 0.0) + ($end - $start) / 1_000_000;
        }
    }

    /**
     * @param  array<string, mixed>  $slot
     * @param  array<string, mixed>  $extra
     */
    private function record(Recorder $recorder, Connection $connection, array $slot, int $startNs, int $endNs, array $extra): void
    {
        $sql = (string) $slot['sql'];
        $bindings = (array) $slot['bindings'];
        $fingerprinter = $this->fingerprinter();

        $normalized = $fingerprinter?->normalize($sql) ?? trim((string) preg_replace('/\s+/', ' ', $sql));

        $meta = [
            'sql' => $sql,
            'fingerprint' => $fingerprinter?->fingerprint($sql) ?? md5($normalized),
        ];

        if ($this->config('capture_normalized_sql', true)) {
            $meta['normalized_sql'] = $normalized;
        }
        if ($fingerprinter !== null) {
            $meta['bindings_key'] = $fingerprinter->bindingsKey($sql, $bindings);
        }
        if ($this->captureBindings()) {
            $meta['bindings'] = $this->sanitizeBindings($bindings);
        }

        $meta['connection'] = $connection->getName();
        $meta['transaction_level'] = $connection->transactionLevel();
        $meta = [...$meta, ...$extra];

        $recorder->recordClosed(SpanType::Query, mb_substr($normalized, 0, 80), $startNs, $endNs, $meta, $slot['site'] ?? null);
    }

    /**
     * The first application frame, or, when the stack holds only framework code (e.g. validation rules run
     * by a FormRequest), the call site of the span the query runs inside (marked inherited).
     *
     * @return array<string, mixed>|null
     */
    private function site(Recorder $recorder): ?array
    {
        $site = $this->resolver()->resolve();
        if ($site !== null) {
            return $site;
        }

        $spans = $recorder->trace()?->spans() ?? [];
        $span = $recorder->current();
        for ($i = 0; $span !== null && $i < 8; $i++) {
            if ($span->callSite !== null) {
                $site = $span->callSite;
                $base = rtrim(str_replace('\\', '/', $this->app->basePath()), '/').'/';
                $file = str_replace('\\', '/', (string) $site['file']);
                $site['file'] = str_starts_with($file, $base) ? substr($file, strlen($base)) : $site['file'];

                return [...$site, 'inherited' => true];
            }
            $span = $span->parentId === null ? null : ($spans[$span->parentId] ?? null);
        }

        return null;
    }

    private function captureBindings(): bool
    {
        $value = $this->config('capture_bindings', null);

        return $value === null ? $this->config('mode', 'dev') === 'dev' : (bool) $value;
    }

    /**
     * @param  array<int|string, mixed>  $bindings
     * @return array<int|string, mixed>
     */
    private function sanitizeBindings(array $bindings): array
    {
        $max = max(1, (int) $this->config('binding_max_length', 200));
        $out = [];

        foreach ($bindings as $key => $value) {
            if (is_string($key) && preg_match('/password|token|secret/i', $key) === 1) {
                $out[$key] = '[redacted]';

                continue;
            }

            $out[$key] = match (true) {
                $value === null, is_bool($value), is_int($value), is_float($value) => $value,
                is_string($value) => $this->truncate($value, $max),
                $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
                $value instanceof \Stringable => $this->truncate((string) $value, $max),
                is_object($value) => '['.$value::class.']',
                default => '['.get_debug_type($value).']',
            };
        }

        return $out;
    }

    private function truncate(string $value, int $max): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            return '[binary '.strlen($value).' bytes]';
        }

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max).'...' : $value;
    }

    private function ignoredConnection(Connection $connection): bool
    {
        if (QuerySuspend::active()) {
            return true;
        }

        $storage = $this->config('storage.connection', null);

        return is_string($storage) && $storage !== '' && $connection->getName() === $storage;
    }

    private function resolver(): CallSiteResolver
    {
        $paths = array_values(array_filter((array) $this->config('app_paths', []), 'is_string'));
        if ($paths === []) {
            $paths = [$this->app->path()];
        }
        $limit = max(1, (int) $this->config('backtrace_limit', 50));
        $base = $this->app->basePath();

        $key = md5(json_encode([$paths, $limit, $base]));
        if ($this->resolver === null || $this->resolverKey !== $key) {
            $this->resolver = new CallSiteResolver($paths, $base, $limit);
            $this->resolverKey = $key;
        }

        return $this->resolver;
    }

    private function fingerprinter(): ?Fingerprinter
    {
        if ($this->fingerprinter === null && class_exists(Fingerprinter::class)) {
            $this->fingerprinter = new Fingerprinter;
        }

        return $this->fingerprinter;
    }

    private function config(string $key, mixed $default): mixed
    {
        return $this->app['config']->get('lantern.'.$key, $default);
    }

    private function recorder(): Recorder
    {
        return Container::getInstance()->make(Recorder::class);
    }

    /** Instrumentation must never break the host app. */
    private function guard(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable) {
            // swallowed by design
        }
    }
}
