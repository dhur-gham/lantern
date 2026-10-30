<?php

declare(strict_types=1);

namespace Lantern\Instrumentation\Command;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Str;
use Lantern\Instrumentation\Lifecycle\LifecycleInstrumentation;
use Lantern\Sampling\Sampler;
use Lantern\Trace\Recorder;
use Lantern\Trace\Span;
use Lantern\Trace\SpanType;
use Lantern\Trace\Trace;
use Symfony\Component\Console\Input\InputInterface;
use Throwable;

/**
 * Artisan command traces (CommandStarting / CommandFinished). Each command is its own Trace (root type Command), or a
 * child span when another trace is already running. Loop wrappers never finish and are always skipped (their jobs
 * are traced individually); everything else is filtered by config lantern.ignore_commands.
 *
 * Laravel does not raise these events when running unit tests (rerouteSymfonyCommandEvents is skipped).
 */
final class CommandInstrumentation
{
    public const LOOPS = ['queue:work', 'queue:listen', 'schedule:work', 'horizon', 'horizon:work', 'octane:start', 'octane:reload', 'serve'];

    public const DEFAULT_IGNORE = [
        'lantern:*', 'migrate*', 'db:*', 'make:*', 'package:discover', 'vendor:publish', 'config:*', 'route:*', 'view:*',
        'event:*', 'optimize*', 'list', 'help', 'completion', '_complete', 'about', 'env', 'key:generate', 'storage:link',
        'tinker', 'test', 'cache:*',
    ];

    /** @var list<array{name: string, span: Span, own: bool}> */
    private array $stack = [];

    private ?Trace $owned = null;

    public function __construct(private readonly Application $app) {}

    public static function register(Application $app): void
    {
        $self = new self($app);
        $events = $app['events'];

        $events->listen(CommandStarting::class, fn (CommandStarting $e) => $self->guard(fn () => $self->begin($e->command, $e->input)));
        $events->listen(CommandFinished::class, fn (CommandFinished $e) => $self->guard(fn () => $self->end($e->command, $e->exitCode)));
    }

    private function begin(string $name, InputInterface $input): void
    {
        if (! (bool) config('lantern.trace_commands', true) || $this->ignored($name)) {
            return;
        }

        $recorder = $this->recorder();
        $meta = ['kind' => 'command', 'command' => $name, 'args' => $this->args($input), 'status' => 'running'];

        if ($recorder->isActive()) {
            $this->stack[] = ['name' => $name, 'span' => $recorder->start(SpanType::Command, $name, $meta), 'own' => false];

            return;
        }

        if (! $this->app->make(Sampler::class)->headDecision()) {
            return;
        }

        $trace = $recorder->begin($meta + ['route' => 'command:'.$name], null, SpanType::Command);
        $root = $trace->root();
        if ($root === null) {
            return;
        }
        $root->name = $name;
        $root->meta = $meta;
        $this->owned = $trace;
        $this->stack[] = ['name' => $name, 'span' => $root, 'own' => true];
    }

    private function end(string $name, int $exitCode): void
    {
        $frame = end($this->stack);
        if ($frame === false || $frame['name'] !== $name) {
            return;
        }
        array_pop($this->stack);

        $update = ['exit_code' => $exitCode, 'status' => $exitCode === 0 ? 'ok' : 'failed'];
        $frame['span']->meta = array_merge($frame['span']->meta, $update);

        if (! $frame['own']) {
            $this->recorder()->stop($frame['span']);

            return;
        }

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

    private function ignored(string $name): bool
    {
        if ($name === '' || in_array($name, self::LOOPS, true)) {
            return true;
        }

        return Str::is((array) config('lantern.ignore_commands', self::DEFAULT_IGNORE), $name);
    }

    /** @return array<string, mixed> */
    private function args(InputInterface $input): array
    {
        $out = [];
        foreach (['arguments' => $input->getArguments(), 'options' => $input->getOptions()] as $group => $values) {
            foreach ($values as $key => $value) {
                if ($key === 'command' || $value === null || $value === false || $value === [] || $value === '') {
                    continue;
                }
                $out[$group][$key] = preg_match('/pass|token|secret|key|auth/i', (string) $key)
                    ? '[redacted]'
                    : $this->scalar($value);
            }
        }

        return $out;
    }

    private function scalar(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($v) => $this->scalar($v), array_slice($value, 0, 20));
        }

        return is_string($value) ? mb_substr($value, 0, 200) : (is_scalar($value) ? $value : get_debug_type($value));
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
