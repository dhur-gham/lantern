<?php

declare(strict_types=1);

namespace Lantern\Tests\Unit\Detection\Detectors;

use Lantern\Detection\Detectors\LazyLoadDetector;
use Lantern\Detection\Issue;
use Lantern\Trace\Recorder;
use Lantern\Trace\SpanType;
use Lantern\Trace\Trace;
use PHPUnit\Framework\TestCase;

final class LazyLoadDetectorTest extends TestCase
{
    private function trace(int $n, string $relation = 'author', int $line = 10): Trace
    {
        $r = new Recorder;
        $r->begin();
        for ($i = 0; $i < $n; $i++) {
            $r->recordClosed(SpanType::Event, "lazy_load App\\Post::$relation", 1, 1,
                ['model' => 'App\\Post', 'relation' => $relation, 'kind' => 'lazy_load'],
                ['file' => 'app/Http/C.php', 'line' => $line]);
        }

        return $r->end();
    }

    private function detector(int $min = 5): LazyLoadDetector
    {
        return new LazyLoadDetector(['thresholds' => ['n_plus_one_min_repeats' => $min]]);
    }

    public function test_flags_group_at_threshold(): void
    {
        $issues = $this->detector()->detect($this->trace(5));

        $this->assertCount(1, $issues);
        $this->assertSame(Issue::TYPE_LAZY_LOAD, $issues[0]->type);
        $this->assertSame('Lazy load: Post::author x5', $issues[0]->title);
        $this->assertSame(Issue::SEV_WARNING, $issues[0]->severity);
        $this->assertSame(5, $issues[0]->count);
        $this->assertCount(5, $issues[0]->spanIds);
        $this->assertSame(10, $issues[0]->callSite['line']);
        $this->assertStringContainsString("->with('author')", $issues[0]->suggestion);
    }

    public function test_below_threshold_not_flagged(): void
    {
        $this->assertSame([], $this->detector()->detect($this->trace(4)));
    }

    public function test_critical_at_four_times_threshold(): void
    {
        $this->assertSame(Issue::SEV_CRITICAL, $this->detector()->detect($this->trace(20))[0]->severity);
    }

    public function test_groups_by_relation_and_call_site(): void
    {
        $r = new Recorder;
        $r->begin();
        foreach ([['a', 1], ['b', 1], ['a', 2]] as [$rel, $line]) {
            for ($i = 0; $i < 3; $i++) {
                $r->recordClosed(SpanType::Event, 'x', 1, 1, ['model' => 'M', 'relation' => $rel, 'kind' => 'lazy_load'], ['file' => 'f.php', 'line' => $line]);
            }
        }
        $issues = $this->detector(3)->detect($r->end());

        $this->assertCount(3, $issues);
        $this->assertCount(3, array_unique(array_map(fn ($i) => $i->key, $issues)));
    }
}
