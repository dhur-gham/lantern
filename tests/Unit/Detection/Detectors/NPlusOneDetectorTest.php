<?php

declare(strict_types=1);

namespace Lantern\Tests\Unit\Detection\Detectors;

use Lantern\Detection\Detectors\NPlusOneDetector;
use Lantern\Detection\Explain\Catalog;
use Lantern\Detection\Issue;
use Lantern\Trace\Recorder;
use Lantern\Trace\SpanType;
use Lantern\Trace\Trace;
use PHPUnit\Framework\TestCase;

final class NPlusOneDetectorTest extends TestCase
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

    private function det(array $t = []): NPlusOneDetector
    {
        return new NPlusOneDetector(['thresholds' => $t]);
    }

    public function test_below_threshold_no_issue(): void
    {
        for ($i = 1; $i <= 4; $i++) { $this->q('select * from posts where posts.user_id = '.$i, [$i]); }
        $this->assertSame([], $this->det(['n_plus_one_min_repeats' => 5])->detect($this->trace()));
    }

    public function test_at_threshold_warning_with_suggestion(): void
    {
        for ($i = 1; $i <= 5; $i++) { $this->q('select * from posts where posts.user_id = ?', [$i]); }
        $issues = $this->det(['n_plus_one_min_repeats' => 5])->detect($this->trace());
        $this->assertCount(1, $issues);
        $this->assertSame(Issue::TYPE_N_PLUS_ONE, $issues[0]->type);
        $this->assertSame(Issue::SEV_WARNING, $issues[0]->severity);
        $this->assertSame(5, $issues[0]->count);
        $this->assertStringContainsString('x5 at app/A.php:10', $issues[0]->title);
        $this->assertStringContainsString("with('user')", $issues[0]->suggestion);
        $this->assertSame(1.0, $issues[0]->meta['avg_ms']);
    }

    public function test_critical_at_four_times_threshold(): void
    {
        for ($i = 1; $i <= 20; $i++) { $this->q('select * from posts where posts.user_id = ?', [$i]); }
        $issues = $this->det(['n_plus_one_min_repeats' => 5])->detect($this->trace());
        $this->assertSame(Issue::SEV_CRITICAL, $issues[0]->severity);
    }

    public function test_critical_on_total_time(): void
    {
        for ($i = 1; $i <= 5; $i++) { $this->q('select * from posts where posts.user_id = ?', [$i], 100.0); }
        $issues = $this->det(['n_plus_one_min_repeats' => 5])->detect($this->trace());
        $this->assertSame(Issue::SEV_CRITICAL, $issues[0]->severity);
    }

    public function test_distinct_call_sites_not_merged(): void
    {
        for ($i = 1; $i <= 3; $i++) { $this->q('select * from posts where user_id = ?', [$i], 1.0, 'app/A.php', 1); }
        for ($i = 1; $i <= 3; $i++) { $this->q('select * from posts where user_id = ?', [$i], 1.0, 'app/B.php', 2); }
        $this->assertSame([], $this->det(['n_plus_one_min_repeats' => 5])->detect($this->trace()));
    }

    public function test_failed_and_transaction_ignored(): void
    {
        for ($i = 1; $i <= 6; $i++) { $this->q('select * from posts where user_id = ?', [$i], 1.0, 'app/A.php', 10, ['failed' => true]); }
        for ($i = 1; $i <= 6; $i++) { $this->q('BEGIN', [], 0.0, 'app/A.php', 10, ['kind' => 'transaction']); }
        $this->assertSame([], $this->det(['n_plus_one_min_repeats' => 5])->detect($this->trace()));
    }

    public function test_count_aggregate_suggests_with_count(): void
    {
        for ($i = 1; $i <= 5; $i++) { $this->q('select count(*) as aggregate from `comments` where `comments`.`post_id` = ?', [$i]); }
        $issues = $this->det()->detect($this->trace());
        $this->assertStringContainsString('withCount', $issues[0]->suggestion);
    }

    public function test_capped_at_twenty(): void
    {
        for ($t = 0; $t < 25; $t++) {
            for ($i = 1; $i <= 5; $i++) { $this->q('select * from t'.$t.' where x = ?', [$i], 1.0, 'app/A.php', 100 + $t); }
        }
        $this->assertCount(20, $this->det()->detect($this->trace()));
    }

    public function test_chunked_bulk_writes_are_not_flagged(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->q('update `posts` set `x` = ? where `id` in (?, ?, ?)', [1, 2, 3, 4]);
            $this->q('delete from posts where id between ? and ?', [1, 2], 1.0, 'app/B.php', 2);
            $this->q('update posts set x = ? where id >= ? and id <= ?', [1, 2, 3], 1.0, 'app/C.php', 3);
            $this->q('insert into posts (a) values (?), (?)', [1, 2], 1.0, 'app/D.php', 4);
        }
        $this->assertSame([], $this->det()->detect($this->trace()));
    }

    public function test_per_row_update_loop_is_still_flagged(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->q('update `posts` set `x` = ? where `id` = ?', [1, $i]);
        }
        $this->assertCount(1, $this->det()->detect($this->trace()));
    }

    public function test_an_in_list_next_to_an_id_equality_is_still_a_per_row_loop(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->q('update `posts` set `x` = ? where `state` in (?, ?) and `id` = ?', [1, 'a', 'b', $i]);
        }
        $this->assertCount(1, $this->det()->detect($this->trace()));
    }

    public function test_a_scoping_foreign_key_equality_does_not_make_a_bulk_write_per_row(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->q('update `posts` set `x` = ? where `merchant_id` = ? and `id` in (?, ?, ?)', [1, 2, 3, 4, 5]);
        }
        $this->assertSame([], $this->det()->detect($this->trace()));
    }

    /** @param array<string, list<array{name: string, columns: list<string>, unique: bool}>> $indexes */
    private function catalog(array $indexes): Catalog
    {
        return new class($indexes) implements Catalog
        {
            public function __construct(private readonly array $indexes) {}

            public function indexes(?string $connection, string $table): array
            {
                return $this->indexes[$table] ?? [];
            }

            public function stats(?string $connection, string $table): array
            {
                return ['rows' => null, 'columns' => []];
            }
        };
    }

    public function test_equality_on_the_primary_key_column_makes_a_bulk_looking_write_per_row(): void
    {
        $catalog = $this->catalog(['items' => [['name' => 'PRIMARY', 'columns' => ['item_id'], 'unique' => true]]]);
        for ($i = 1; $i <= 6; $i++) {
            $this->q('update `items` set `x` = ? where `state` in (?, ?) and `item_id` = ?', [1, 'a', 'b', $i]);
        }

        $this->assertCount(1, (new NPlusOneDetector(['thresholds' => []], $catalog))->detect($this->trace()));
    }

    public function test_without_catalog_data_only_id_and_uuid_count_as_a_primary_key(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->q('update `items` set `x` = ? where `state` in (?, ?) and `item_id` = ?', [1, 'a', 'b', $i]);
        }

        $this->assertSame([], $this->det()->detect($this->trace()));
    }

    public function test_a_composite_primary_key_needs_every_column_pinned(): void
    {
        $catalog = $this->catalog(['order_lines' => [['name' => 'PRIMARY', 'columns' => ['order_id', 'line_no'], 'unique' => true]]]);
        $detector = new NPlusOneDetector(['thresholds' => []], $catalog);

        for ($i = 1; $i <= 6; $i++) {
            $this->q('update `order_lines` set `x` = ? where `order_id` = ? and `status` in (?, ?)', [1, $i, 'a', 'b'], 1.0, 'app/Whole.php', 5);
        }
        $this->assertSame([], $detector->detect($this->trace()));

        $this->setUp();
        for ($i = 1; $i <= 6; $i++) {
            $this->q('update `order_lines` set `x` = ? where `order_id` = ? and `line_no` = ? and `status` in (?, ?)', [1, 7, $i, 'a', 'b'], 1.0, 'app/Line.php', 6);
        }
        $this->assertCount(1, $detector->detect($this->trace()));
    }

    public function test_paging_call_site_is_not_flagged(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->q('select * from posts where id > '.$i, [$i]);
        }
        $this->assertNotEmpty($this->det()->detect($this->r->end()));

        $r = new Recorder();
        $r->begin([], 0);
        for ($i = 0; $i < 8; $i++) {
            $r->recordClosed(SpanType::Query, 'q', 0, 1, ['sql' => 'select 1', 'fingerprint' => 'f', 'failed' => false, 'time_ms' => 1.0], ['file' => 'app/A.php', 'line' => 1, 'paging' => true]);
        }
        $this->assertSame([], $this->det()->detect($r->end()));
    }
}
