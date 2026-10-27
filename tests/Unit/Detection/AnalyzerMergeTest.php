<?php

declare(strict_types=1);

namespace Lantern\Tests\Unit\Detection;

use Lantern\Detection\Analyzer;
use Lantern\Detection\Issue;
use Lantern\Trace\Recorder;
use Lantern\Trace\SpanType;
use Lantern\Trace\Trace;
use PHPUnit\Framework\TestCase;

final class AnalyzerMergeTest extends TestCase
{
    private const CFG = [
        'detectors' => [
            \Lantern\Detection\Detectors\NPlusOneDetector::class,
            \Lantern\Detection\Detectors\DuplicateQueryDetector::class,
            \Lantern\Detection\Detectors\SlowQueryDetector::class,
            \Lantern\Detection\Detectors\LazyLoadDetector::class,
        ],
        'thresholds' => ['n_plus_one_min_repeats' => 5, 'duplicate_min_repeats' => 2, 'slow_query_ms' => 1000]];

    private function loop(Recorder $r, int $n, string $relation, string $table, int $line, bool $repeatId = false): void
    {
        for ($i = 1; $i <= $n; $i++) {
            $id = $repeatId ? 1 : $i;
            $r->recordClosed(SpanType::Event, "lazy_load App\\Post::$relation", 1, 1,
                ['model' => 'App\\Post', 'relation' => $relation, 'kind' => 'lazy_load'], ['file' => 'app/R.php', 'line' => $line]);
            $sql = "select * from \"$table\" where \"$table\".\"id\" = ?";
            $r->recordClosed(SpanType::Query, $sql, 0, 1_000_000, [
                'sql' => $sql, 'fingerprint' => md5($sql), 'bindings' => [$id], 'bindings_key' => md5((string) $id),
                'time_ms' => 1.0, 'failed' => false,
            ], ['file' => 'app/R.php', 'line' => $line]);
        }
    }

    /** @return list<Issue> */
    private function analyze(Trace $t): array
    {
        return (new Analyzer(self::CFG))->analyze($t);
    }

    public function test_lazy_load_and_n_plus_one_at_same_site_merge_into_one(): void
    {
        $r = new Recorder;
        $r->begin([], 0);
        $this->loop($r, 8, 'author', 'authors', 14);

        $issues = $this->analyze($r->end());

        $this->assertCount(1, $issues);
        $this->assertSame(Issue::TYPE_N_PLUS_ONE, $issues[0]->type);
        $this->assertSame(8, $issues[0]->count);
        $this->assertSame('App\\Post', $issues[0]->meta['model']);
        $this->assertSame('author', $issues[0]->meta['relation']);
        $this->assertTrue($issues[0]->meta['lazy_load']);
        $this->assertStringContainsString('Post::author', $issues[0]->title);
        $this->assertSame(14, $issues[0]->callSite['line']);
        $this->assertCount(16, $issues[0]->spanIds);
        $this->assertGreaterThan(0.0, $issues[0]->totalMs);
    }

    public function test_two_relations_on_one_line_stay_two_merged_issues(): void
    {
        $r = new Recorder;
        $r->begin([], 0);
        for ($i = 1; $i <= 6; $i++) {
            $this->loop($r, 1, 'author', 'authors', 7);
            $this->loop($r, 1, 'comments', 'comments', 7);
        }

        $issues = $this->analyze($r->end());

        $this->assertCount(2, $issues);
        $relations = array_map(fn (Issue $i) => $i->meta['relation'], $issues);
        sort($relations);
        $this->assertSame(['author', 'comments'], $relations);
    }

    public function test_lazy_load_without_matching_n_plus_one_is_kept(): void
    {
        $r = new Recorder;
        $r->begin([], 0);
        for ($i = 0; $i < 6; $i++) {
            $r->recordClosed(SpanType::Event, 'lazy_load', 1, 1, ['model' => 'App\\Post', 'relation' => 'tags', 'kind' => 'lazy_load'], ['file' => 'a.php', 'line' => 3]);
        }

        $issues = $this->analyze($r->end());

        $this->assertCount(1, $issues);
        $this->assertSame(Issue::TYPE_LAZY_LOAD, $issues[0]->type);
    }

    public function test_n_plus_one_at_another_site_is_not_merged_with_lazy_load(): void
    {
        $r = new Recorder;
        $r->begin([], 0);
        $this->loop($r, 6, 'author', 'authors', 14);
        for ($i = 1; $i <= 6; $i++) {
            $sql = 'select * from "tags" where "tags"."id" = ?';
            $r->recordClosed(SpanType::Query, $sql, 0, 1_000_000, ['sql' => $sql, 'fingerprint' => md5($sql), 'bindings' => [$i], 'bindings_key' => (string) $i, 'time_ms' => 1.0, 'failed' => false], ['file' => 'app/R.php', 'line' => 99]);
        }

        $issues = $this->analyze($r->end());

        $this->assertCount(2, $issues);
        $lines = array_map(fn (Issue $i) => $i->callSite['line'], $issues);
        sort($lines);
        $this->assertSame([14, 99], $lines);
    }

    public function test_duplicate_that_is_part_of_an_n_plus_one_is_dropped(): void
    {
        $r = new Recorder;
        $r->begin([], 0);
        $this->loop($r, 8, 'author', 'authors', 14, repeatId: true); // same binding each time: also a duplicate

        $issues = $this->analyze($r->end());

        $this->assertSame([Issue::TYPE_N_PLUS_ONE], array_map(fn (Issue $i) => $i->type, $issues));
    }

    public function test_standalone_duplicate_is_kept(): void
    {
        $r = new Recorder;
        $r->begin([], 0);
        for ($i = 0; $i < 3; $i++) {
            $sql = 'select count(*) as aggregate from "comments" where "status" = ?';
            $r->recordClosed(SpanType::Query, $sql, 0, 1_000_000, ['sql' => $sql, 'fingerprint' => md5($sql), 'bindings' => ['spam'], 'bindings_key' => 'spam', 'time_ms' => 1.0, 'failed' => false], ['file' => 'app/C.php', 'line' => 35]);
        }

        $issues = $this->analyze($r->end());

        $this->assertCount(1, $issues);
        $this->assertSame(Issue::TYPE_DUPLICATE, $issues[0]->type);
        $this->assertSame(3, $issues[0]->count);
    }
}
