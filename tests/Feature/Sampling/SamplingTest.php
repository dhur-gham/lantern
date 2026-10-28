<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Sampling;

use Lantern\Detection\Issue;
use Lantern\Instrumentation\Lifecycle\MemorySink;
use Lantern\Instrumentation\Lifecycle\PersistSink;
use Lantern\Sampling\Sampler;
use Lantern\Storage\TraceStore;
use Lantern\Trace\Recorder;
use Lantern\Trace\Trace;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SamplingTest extends TestCase
{
    private function trace(array $meta = []): Trace
    {
        $recorder = new Recorder;
        $trace = $recorder->begin();
        $recorder->end();
        $trace->meta = $meta;

        return $trace;
    }

    private function sampler(array $config, float $rand = 0.5): Sampler
    {
        return new Sampler($config, fn () => $rand);
    }

    public function test_head_decision_follows_rate(): void
    {
        $this->assertTrue($this->sampler(['sampling_rate' => 1.0])->headDecision());
        $this->assertFalse($this->sampler(['sampling_rate' => 0.0])->headDecision());
        $this->assertTrue($this->sampler(['sampling_rate' => 0.6], 0.5)->headDecision());
        $this->assertFalse($this->sampler(['sampling_rate' => 0.4], 0.5)->headDecision());
    }

    public function test_head_decision_is_deterministic_for_a_key(): void
    {
        $s = $this->sampler(['sampling_rate' => 0.5]);
        $this->assertSame($s->headDecision('abc'), $s->headDecision('abc'));
    }

    public function test_tail_rules(): void
    {
        $cfg = ['keep_slow_ms' => 1000, 'keep_rate' => 0.0];
        $s = $this->sampler($cfg);

        $this->assertFalse($s->keepTrace($this->trace(), []));
        $this->assertTrue($s->keepTrace($this->trace(), [['type' => 'n_plus_one']]));
        $this->assertTrue($s->keepTrace($this->trace(['status' => 503]), []));
        $this->assertFalse($s->keepTrace($this->trace(['status' => 404]), []));
        $this->assertTrue($this->sampler(['keep_slow_ms' => 0, 'keep_rate' => 0.0])->keepTrace($this->trace(), []));
        $this->assertTrue($this->sampler(['keep_rate' => 0.1], 0.05)->keepTrace($this->trace(), []));
        $this->assertFalse($this->sampler(['keep_rate' => 0.1, 'keep_slow_ms' => 99999], 0.5)->keepTrace($this->trace(), []));
    }

    public function test_persist_sink_writes_kept_traces_with_issues(): void
    {
        $store = new FakeStore;
        $sink = new PersistSink($store, $this->sampler(['keep_rate' => 1.0]), new MemorySink);
        $trace = $this->trace(['issues' => [(new Issue('slow_query', 'k', 'slow', 'warning', 1, 5.0, null, [], [], null))->toArray()]]);

        $sink->push($trace);

        $this->assertCount(1, $store->stored);
        $this->assertSame($trace, $store->stored[0][0]);
        $this->assertCount(1, $store->stored[0][1]);
        $this->assertSame('slow_query', $store->stored[0][1][0]->type);
    }

    public function test_persist_sink_drops_unsampled_traces(): void
    {
        $store = new FakeStore;
        $sink = new PersistSink($store, $this->sampler(['keep_rate' => 0.0, 'keep_slow_ms' => 99999]), new MemorySink);
        $sink->push($this->trace());

        $this->assertSame([], $store->stored);
    }

    public function test_persist_sink_batches(): void
    {
        $store = new FakeStore;
        $sink = new PersistSink($store, $this->sampler(['keep_rate' => 1.0]), new MemorySink, null, 3);

        $sink->push($this->trace());
        $sink->push($this->trace());
        $this->assertCount(0, $store->stored);
        $sink->push($this->trace());
        $this->assertCount(3, $store->stored);
    }

    public function test_storage_failure_falls_back_to_memory_and_never_throws(): void
    {
        $store = new FakeStore;
        $store->fail = true;
        $fallback = new MemorySink;
        $sink = new PersistSink($store, $this->sampler(['keep_rate' => 1.0]), $fallback);

        $sink->push($this->trace());
        $sink->push($this->trace());

        $this->assertCount(2, $fallback->all());
    }
}

final class FakeStore implements TraceStore
{
    /** @var list<array{0: Trace, 1: array}> */
    public array $stored = [];

    public bool $fail = false;

    public function find(string $id): ?array
    {
        return null;
    }

    public function recent(int $limit = 50): array
    {
        return [];
    }

    public function store(Trace $trace, array $issues): void
    {
        if ($this->fail) {
            throw new RuntimeException('down');
        }
        $this->stored[] = [$trace, $issues];
    }

    public function test_unset_keep_rate_defaults_by_environment(): void
    {
        $this->app['env'] = 'local';
        $this->assertSame(1.0, \Lantern\Sampling\Sampler::defaultKeepRate());
        $this->assertTrue($this->sampler(['keep_rate' => null], 0.99)->keepTrace($this->trace(), []));

        $this->app['env'] = 'production';
        $this->assertSame(0.1, \Lantern\Sampling\Sampler::defaultKeepRate());
        $this->assertFalse($this->sampler(['keep_rate' => null, 'keep_slow_ms' => 99999], 0.99)->keepTrace($this->trace(), []));
    }
}
