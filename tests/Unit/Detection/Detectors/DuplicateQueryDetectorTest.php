<?php

declare(strict_types=1);

namespace Lantern\Tests\Unit\Detection\Detectors;

use Lantern\Detection\Detectors\DuplicateQueryDetector;
use Lantern\Detection\Issue;
use Lantern\Trace\Recorder;
use Lantern\Trace\SpanType;
use Lantern\Trace\Trace;
use PHPUnit\Framework\TestCase;

final class DuplicateQueryDetectorTest extends TestCase
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

    private function det(array $t = []): DuplicateQueryDetector
    {
        return new DuplicateQueryDetector(['thresholds' => $t]);
    }

    public function test_identical_sql_and_bindings_flagged(): void
    {
        $this->q('select * from users where id = ?', [1]);
        $this->q('select * from users where id = ?', [1]);
        $issues = $this->det(['duplicate_min_repeats' => 2])->detect($this->trace());
        $this->assertCount(1, $issues);
        $this->assertSame(Issue::TYPE_DUPLICATE, $issues[0]->type);
        $this->assertSame(Issue::SEV_INFO, $issues[0]->severity);
        $this->assertSame(2, $issues[0]->count);
    }

    public function test_warning_at_three(): void
    {
        for ($i = 0; $i < 3; $i++) { $this->q('select * from users where id = ?', [1]); }
        $issues = $this->det(['duplicate_min_repeats' => 2])->detect($this->trace());
        $this->assertSame(Issue::SEV_WARNING, $issues[0]->severity);
    }

    public function test_different_bindings_not_duplicates(): void
    {
        for ($i = 1; $i <= 6; $i++) { $this->q('select * from users where id = ?', [$i]); }
        $this->assertSame([], $this->det(['duplicate_min_repeats' => 2])->detect($this->trace()));
    }

    public function test_below_threshold(): void
    {
        $this->q('select * from users where id = ?', [1]);
        $this->q('select * from users where id = ?', [1]);
        $this->assertSame([], $this->det(['duplicate_min_repeats' => 3])->detect($this->trace()));
    }

    public function test_failed_queries_ignored(): void
    {
        $this->q('select 1', [], 1.0, 'app/A.php', 1, ['failed' => true]);
        $this->q('select 1', [], 1.0, 'app/A.php', 1, ['failed' => true]);
        $this->assertSame([], $this->det()->detect($this->trace()));
    }

    public function test_duplicates_across_call_sites_merge(): void
    {
        $this->q('select 1', [], 1.0, 'app/A.php', 1);
        $this->q('select 1', [], 1.0, 'app/B.php', 2);
        $issues = $this->det()->detect($this->trace());
        $this->assertCount(1, $issues);
        $this->assertCount(2, $issues[0]->meta['call_sites']);
    }
}
