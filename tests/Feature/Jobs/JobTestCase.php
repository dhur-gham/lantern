<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lantern\Instrumentation\Lifecycle\MemorySink;
use Lantern\Instrumentation\Lifecycle\TraceSink;
use Lantern\Tests\TestCase;
use Lantern\Trace\Recorder;
use Lantern\Trace\Span;
use Lantern\Trace\SpanType;
use Lantern\Trace\Trace;
use RuntimeException;

final class NPlusOneJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public static int $line = 0;

    public function handle(): void
    {
        foreach (range(1, 8) as $id) {
            self::$line = __LINE__ + 1;
            DB::select('select * from j_items where id = ?', [$id]);
        }
    }
}

final class FailingJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        DB::select('select 1');

        throw new RuntimeException('job exploded');
    }
}

final class SingleQueryJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        DB::select('select * from j_items where id = ?', [1]);
    }
}

abstract class JobTestCase extends TestCase
{
    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('lantern.enabled', true);
        $app['config']->set('lantern.kill_switch', false);
        $app['config']->set('lantern.app_paths', [__DIR__]);
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('j_items', fn (Blueprint $t) => $t->id());
    }

    protected function sink(): MemorySink
    {
        $sink = $this->app->make(TraceSink::class);
        $this->assertInstanceOf(MemorySink::class, $sink);

        return $sink;
    }

    protected function recorder(): Recorder
    {
        return $this->app->make(Recorder::class);
    }

    /** @return list<Span> */
    protected function queries(Trace $trace): array
    {
        return array_values(array_filter($trace->spans(), fn (Span $s) => $s->type === SpanType::Query && ($s->meta['kind'] ?? null) !== 'transaction'));
    }
}
