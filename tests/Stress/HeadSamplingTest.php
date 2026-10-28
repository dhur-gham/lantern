<?php

declare(strict_types=1);

namespace Lantern\Tests\Stress;

use Lantern\Sampling\Sampler;
use Lantern\Trace\Recorder;
use Lantern\Trace\Trace;

final class HeadSamplingTest extends StressTestCase
{
    protected function lanternConfig(): array
    {
        return match (true) {
            str_contains($this->name(), 'web_zero') => ['sampling_rate' => 0.0],
            str_contains($this->name(), 'web_one') => ['sampling_rate' => 1.0],
            default => [],
        };
    }

    protected function resolveApplicationConfiguration($app)
    {
        if (str_contains($this->name(), 'web_')) {
            (new \ReflectionProperty($app, 'isRunningInConsole'))->setValue($app, false);
        }
        parent::resolveApplicationConfiguration($app);
    }

    private function trace(int $status, float $ms = 1.0): Trace
    {
        $r = new Recorder;
        $startNs = hrtime(true) - (int) ($ms * 1_000_000);
        $trace = $r->begin(['status' => $status], $startNs);
        $r->end();

        return $trace;
    }

    public function test_head_decision_extremes(): void
    {
        $this->assertTrue((new Sampler(['sampling_rate' => 1.0]))->headDecision());
        $this->assertFalse((new Sampler(['sampling_rate' => 0.0]))->headDecision());
        $this->assertTrue((new Sampler(['sampling_rate' => 7.5]))->headDecision(), 'rates above 1 clamp to 1');
        $this->assertFalse((new Sampler(['sampling_rate' => -3]))->headDecision(), 'negative rates clamp to 0');
        $this->assertTrue((new Sampler([]))->headDecision(), 'default is sample everything');
        $this->assertFalse((new Sampler(['sampling_rate' => 'garbage']))->headDecision());
    }

    public function test_head_decision_random_rate_is_roughly_the_configured_fraction(): void
    {
        mt_srand(42);
        $sampler = new Sampler(['sampling_rate' => 0.25]);
        $hits = 0;
        for ($i = 0; $i < 4000; $i++) {
            $hits += $sampler->headDecision() ? 1 : 0;
        }

        $this->assertEqualsWithDelta(1000, $hits, 150);
    }

    public function test_head_decision_with_key_is_deterministic(): void
    {
        $sampler = new Sampler(['sampling_rate' => 0.5]);
        $first = $sampler->headDecision('req-123');

        for ($i = 0; $i < 20; $i++) {
            $this->assertSame($first, $sampler->headDecision('req-123'));
        }
    }

    public function test_keep_trace_always_keeps_errors_slow_and_issue_bearing_traces(): void
    {
        $sampler = new Sampler(['keep_rate' => 0.0, 'keep_slow_ms' => 1000]);

        $this->assertTrue($sampler->keepTrace($this->trace(500), []));
        $this->assertTrue($sampler->keepTrace($this->trace(503), []));
        $this->assertTrue($sampler->keepTrace($this->trace(200, 1500.0), []));
        $this->assertTrue($sampler->keepTrace($this->trace(200), [['type' => 'n_plus_one']]));
        $this->assertFalse($sampler->keepTrace($this->trace(200), []));
        $this->assertFalse($sampler->keepTrace($this->trace(404), []));
        $this->assertFalse($sampler->keepTrace($this->trace(499), []));
    }

    public function test_keep_trace_slow_boundary(): void
    {
        $sampler = new Sampler(['keep_rate' => 0.0, 'keep_slow_ms' => 100]);

        $this->assertFalse($sampler->keepTrace($this->trace(200, 50.0), []));
        $this->assertTrue($sampler->keepTrace($this->trace(200, 150.0), []));
    }

    public function test_keep_rate_one_keeps_everything_and_clamps(): void
    {
        $this->assertTrue((new Sampler(['keep_rate' => 1.0]))->keepTrace($this->trace(200), []));
        $this->assertTrue((new Sampler(['keep_rate' => 9.0]))->keepTrace($this->trace(200), []));
        $this->assertFalse((new Sampler(['keep_rate' => -1.0]))->keepTrace($this->trace(200), []));
    }

    public function test_trace_without_a_status_is_not_crashed_on(): void
    {
        $r = new Recorder;
        $trace = $r->begin();
        $r->end();

        $this->assertFalse((new Sampler(['keep_rate' => 0.0]))->keepTrace($trace, []));
    }

    public function test_web_zero_sampling_registers_nothing_and_requests_still_work(): void
    {
        $this->assertFalse($this->app->bound(\Lantern\Instrumentation\Lifecycle\MemorySink::class));

        $this->get('/scenario/lazy-loop')->assertOk()->assertSee('30');
        $this->getJson('/scenario/throw')->assertStatus(500)->assertExactJson(['message' => 'Server Error']);
    }

    public function test_web_one_sampling_records(): void
    {
        $this->get('/scenario/ok')->assertOk();

        $this->assertNotNull($this->sink()->last());
    }
}
