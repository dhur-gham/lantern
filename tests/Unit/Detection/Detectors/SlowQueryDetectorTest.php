<?php

declare(strict_types=1);

namespace Lantern\Tests\Unit\Detection\Detectors;

use Lantern\Detection\Detectors\SlowQueryDetector;
use Lantern\Detection\Issue;
use Lantern\Trace\Recorder;
use Lantern\Trace\SpanType;
use Lantern\Trace\Trace;
use PHPUnit\Framework\TestCase;

final class SlowQueryDetectorTest extends TestCase
{
    private const MS = 1_000_000;

    private Recorder $r;

    protected function setUp(): void
    {
        $this->r = new Recorder();
        $this->r->begin([], 0);
    }

    private function q(string $sql, array $b = [], float $ms = 1.0, string $file = 'app/A.php', int $line = 10, array $extra = []): void
    {
        $this->r->recordClosed(SpanType::Query, $sql, 0, (int) ($ms * self::MS), [
            'sql' => $sql,
            'fingerprint' => md5(preg_replace('/\d+/', '?', $sql)),
            'bindings_key' => md5(serialize($b)),
            'bindings' => $b,
            'time_ms' => $ms,
            'failed' => false,
            ...$extra,
        ], ['file' => $file, 'line' => $line]);
    }

    private function trace(): Trace
    {
        return $this->r->end();
    }

    private function det(array $t = []): SlowQueryDetector
    {
        return new SlowQueryDetector(['thresholds' => $t]);
    }

    public function test_below_threshold_no_issue(): void
    {
        $this->q('select * from big', [], 99.0);
        $this->assertSame([], $this->det(['slow_query_ms' => 100])->detect($this->trace()));
    }

    public function test_at_threshold_warning(): void
    {
        $this->q('select * from big', [], 100.0);
        $issues = $this->det(['slow_query_ms' => 100])->detect($this->trace());
        $this->assertCount(1, $issues);
        $this->assertSame(Issue::TYPE_SLOW, $issues[0]->type);
        $this->assertSame(Issue::SEV_WARNING, $issues[0]->severity);
        $this->assertSame(100.0, $issues[0]->meta['max_ms']);
    }

    public function test_critical_at_five_times(): void
    {
        $this->q('select * from big', [], 500.0);
        $this->assertSame(Issue::SEV_CRITICAL, $this->det(['slow_query_ms' => 100])->detect($this->trace())[0]->severity);
    }

    public function test_sums_same_fingerprint_and_site(): void
    {
        $this->q('select * from big where id = 1', [], 150.0);
        $this->q('select * from big where id = 2', [], 250.0);
        $issues = $this->det(['slow_query_ms' => 100])->detect($this->trace());
        $this->assertCount(1, $issues);
        $this->assertSame(2, $issues[0]->count);
        $this->assertSame(400.0, $issues[0]->totalMs);
        $this->assertSame(250.0, $issues[0]->meta['max_ms']);
    }

    public function test_distinct_call_sites_not_merged(): void
    {
        $this->q('select * from big', [], 150.0, 'app/A.php', 1);
        $this->q('select * from big', [], 150.0, 'app/B.php', 2);
        $this->assertCount(2, $this->det(['slow_query_ms' => 100])->detect($this->trace()));
    }

    public function test_failed_ignored(): void
    {
        $this->q('select * from big', [], 900.0, 'app/A.php', 1, ['failed' => true]);
        $this->assertSame([], $this->det(['slow_query_ms' => 100])->detect($this->trace()));
    }
}
