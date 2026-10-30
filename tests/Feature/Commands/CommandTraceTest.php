<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Commands;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\DB;
use Lantern\Instrumentation\Lifecycle\MemorySink;
use Lantern\Instrumentation\Lifecycle\TraceSink;
use Lantern\Tests\TestCase;
use Lantern\Trace\SpanType;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

final class CommandTraceTest extends TestCase
{
    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('lantern.enabled', true);
        $app['config']->set('lantern.kill_switch', false);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    /** Laravel skips the Symfony->Laravel event bridge in unit tests, so raise the events by hand. */
    private function runCommand(string $name, int $exit = 0, array $input = [], ?callable $body = null): void
    {
        $in = new ArrayInput($input);
        $out = new NullOutput;
        $events = $this->app['events'];

        $events->dispatch(new CommandStarting($name, $in, $out));
        if ($body !== null) {
            $body();
        }
        $events->dispatch(new CommandFinished($name, $in, $out, $exit));
    }

    private function sink(): MemorySink
    {
        return $this->app->make(TraceSink::class);
    }

    public function test_command_trace_with_exit_code_and_queries(): void
    {
        $this->runCommand('app:report', 2, [], fn () => DB::select('select 1'));

        $trace = $this->sink()->last();
        $this->assertNotNull($trace);
        $this->assertSame(SpanType::Command, $trace->root()->type);
        $this->assertSame('app:report', $trace->root()->name);
        $this->assertSame('command', $trace->meta['kind']);
        $this->assertSame('command:app:report', $trace->meta['route']);
        $this->assertSame(2, $trace->meta['exit_code']);
        $this->assertSame('failed', $trace->meta['status']);
        $this->assertSame(2, $trace->root()->meta['exit_code']);
        $this->assertCount(1, array_filter($trace->spans(), fn ($s) => $s->type === SpanType::Query));
    }

    public function test_successful_command_status(): void
    {
        $this->runCommand('app:ok');

        $this->assertSame(0, $this->sink()->last()->meta['exit_code']);
        $this->assertSame('ok', $this->sink()->last()->meta['status']);
    }

    public function test_default_and_loop_commands_are_ignored(): void
    {
        foreach (['lantern:prune', 'queue:work', 'queue:listen', 'schedule:work', 'migrate', 'migrate:fresh', ''] as $name) {
            $this->runCommand($name);
        }

        $this->assertSame([], $this->sink()->all());
    }

    public function test_ignore_commands_is_configurable(): void
    {
        config(['lantern.ignore_commands' => ['app:noisy*']]);

        $this->runCommand('app:noisy-one');
        $this->runCommand('migrate');

        $this->assertCount(1, $this->sink()->all());
        $this->assertSame('migrate', $this->sink()->last()->root()->name);
    }

    public function test_queue_work_loop_is_ignored_even_when_not_configured(): void
    {
        config(['lantern.ignore_commands' => []]);

        $this->runCommand('queue:work');

        $this->assertSame([], $this->sink()->all());
    }
}
