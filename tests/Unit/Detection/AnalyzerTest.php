<?php

declare(strict_types=1);

namespace Lantern\Tests\Unit\Detection;

use Lantern\Detection\Analyzer;
use Lantern\Detection\Detector;
use Lantern\Detection\Issue;
use Lantern\Instrumentation\Lifecycle\TraceFormatter;
use Lantern\Trace\Recorder;
use Lantern\Trace\Trace;
use PHPUnit\Framework\TestCase;

final class AnalyzerTest extends TestCase
{
    private function trace(): Trace
    {
        $r = new Recorder();
        $t = $r->begin([], 0);
        $r->end();

        return $t;
    }

    private function issue(string $key, string $sev, int $count = 1, float $ms = 1.0): Issue
    {
        return new Issue(Issue::TYPE_SLOW, $key, 't-'.$key, $sev, $count, $ms, null, [1]);
    }

    private function fake(array $issues): Detector
    {
        return new class($issues) implements Detector {
            public function __construct(private array $issues) {}

            public function detect(Trace $trace): array
            {
                return $this->issues;
            }
        };
    }

    public function test_sorts_by_severity_then_total_ms(): void
    {
        $a = new Analyzer([], [$this->fake([
            $this->issue('a', Issue::SEV_INFO, 1, 99),
            $this->issue('b', Issue::SEV_CRITICAL, 1, 1),
            $this->issue('c', Issue::SEV_WARNING, 1, 5),
            $this->issue('d', Issue::SEV_WARNING, 1, 50),
        ])]);

        $this->assertSame(['b', 'd', 'c', 'a'], array_map(fn ($i) => $i->key, $a->analyze($this->trace())));
    }

    public function test_dedupes_by_key_keeping_larger_count(): void
    {
        $a = new Analyzer([], [
            $this->fake([$this->issue('k', Issue::SEV_INFO, 3)]),
            $this->fake([$this->issue('k', Issue::SEV_INFO, 7)]),
        ]);

        $out = $a->analyze($this->trace());
        $this->assertCount(1, $out);
        $this->assertSame(7, $out[0]->count);
    }

    public function test_failing_detector_is_isolated(): void
    {
        $boom = new class implements Detector {
            public function detect(Trace $trace): array
            {
                throw new \RuntimeException('boom');
            }
        };
        $a = new Analyzer([], [$boom, $this->fake([$this->issue('ok', Issue::SEV_INFO)])]);

        $this->assertCount(1, $a->analyze($this->trace()));
    }

    public function test_instantiates_from_config_skipping_missing(): void
    {
        $a = new Analyzer(['detectors' => ['Nope\\Missing', ConfigDetector::class]]);

        $out = $a->analyze($this->trace());
        $this->assertCount(1, $out);
        $this->assertSame('cfg', $out[0]->key);
    }

    public function test_max_issues_cap(): void
    {
        $a = new Analyzer(['thresholds' => ['max_issues_per_trace' => 2]], [$this->fake([
            $this->issue('a', Issue::SEV_INFO), $this->issue('b', Issue::SEV_INFO), $this->issue('c', Issue::SEV_INFO),
        ])]);

        $this->assertCount(2, $a->analyze($this->trace()));
    }

    public function test_issue_key_is_stable_and_roundtrips(): void
    {
        $this->assertSame(Issue::key('x', 'a', 'b'), Issue::key('x', 'a', 'b'));
        $this->assertNotSame(Issue::key('x', 'a', 'b'), Issue::key('x', 'b', 'a'));
        $this->assertSame(32, strlen(Issue::key('x')));

        $i = new Issue('x', 'k', 'T', 'info', 2, 1.5, ['file' => 'f', 'line' => 1], [1, 2], ['m' => 1], 'do it');
        $this->assertEquals($i, Issue::fromArray($i->toArray()));
    }

    public function test_formatter_lists_issues(): void
    {
        $t = $this->trace();
        $t->meta['issues'] = [$this->issue('a', Issue::SEV_WARNING, 6, 12.5)->toArray()];
        $t->meta['issues'][0]['suggestion'] = 'use with()';
        $t->meta['issues'][0]['call_site'] = ['file' => '/x/y.php', 'line' => 9];

        $out = TraceFormatter::tree($t);
        $this->assertStringContainsString('Issues (1)', $out);
        $this->assertStringContainsString('[warning] slow_query: t-a  x6  12.50 ms  @ /x/y.php:9', $out);
        $this->assertStringContainsString('fix: use with()', $out);
    }
}

final class ConfigDetector implements Detector
{
    public function __construct(private array $config) {}

    public function detect(Trace $trace): array
    {
        return [new Issue(Issue::TYPE_SLOW, 'cfg', 't', Issue::SEV_INFO, 1, 1.0, null, [])];
    }
}
