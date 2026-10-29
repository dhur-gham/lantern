<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Matrix;

use Illuminate\Support\Facades\DB;
use Lantern\Detection\Detectors\MissingIndexDetector;
use Lantern\Detection\Explain\DatabaseCatalog;
use Lantern\Detection\Explain\ExplainRunner;
use Lantern\Detection\Issue;

final class IndexAnalysisMatrixTest extends MatrixTestCase
{
    /** Scans mx_orders first on every engine; mx_items is reached through its order_id index. */
    private function joinSql(): string
    {
        $force = $this->isPgsql() ? '' : 'straight_join ';

        return "select {$force}mx_items.merchant_id, count(*) as aggregate from mx_orders inner join mx_items on mx_items.order_id = mx_orders.order_id "
            .'where mx_orders.payment_status = ? and mx_items.merchant_id in (?, ?, ?) group by mx_items.merchant_id';
    }

    private function forceOrdersScan(): void
    {
        if ($this->isPgsql()) {
            // plan from the FROM order, no index access: orders (the biggest table) is the scanned one
            DB::statement('set join_collapse_limit = 1');
            DB::statement('set enable_indexscan = off');
            DB::statement('set enable_bitmapscan = off');
            DB::statement('set enable_indexonlyscan = off');
        }
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        return ['explain' => true, 'thresholds' => ['slow_query_ms' => 100, 'n_plus_one_min_repeats' => 5]];
    }

    public function test_catalog_reads_indexes_and_column_facts(): void
    {
        $this->seedShop();
        $catalog = new DatabaseCatalog;
        $driver = self::driver();

        $indexes = [];
        foreach ($catalog->indexes($driver, 'mx_items') as $index) {
            $indexes[$index['name']] = $index;
        }

        $this->assertSame(['order_id'], $indexes['mx_order_id_idx']['columns']);
        $this->assertFalse($indexes['mx_order_id_idx']['unique']);
        $this->assertSame(['merchant_id', 'order_id', 'is_approved'], $indexes['mx_merchant_id_2']['columns']);
        $primary = array_values(array_filter($indexes, fn (array $i): bool => $i['unique'] && $i['columns'] === ['id']));
        $this->assertCount(1, $primary);
        $this->assertCount(3, $indexes);

        $stats = $catalog->stats($driver, 'mx_items');
        $this->assertGreaterThan(1000, $stats['rows']);
        $this->assertLessThan(8000, $stats['rows']);
        $this->assertMatchesRegularExpression($this->isPgsql() ? '/^boolean$/' : '/^tinyint\(1\)$/', (string) $stats['columns']['is_approved']['type']);
        $this->assertNotNull($stats['columns']['merchant_id']['distinct']);
        $this->assertGreaterThan(20, $stats['columns']['merchant_id']['distinct']);
        $this->assertLessThan(120, $stats['columns']['merchant_id']['distinct']);

        $this->assertSame([], $catalog->indexes($driver, 'mx_missing_table'));
        $this->assertSame([], $catalog->indexes($driver, 'mx_items; drop table mx_items'));
        $this->assertSame(['rows' => null, 'columns' => []], $catalog->stats($driver, 'mx_missing_table'));
    }

    public function test_explain_runner_lists_every_table_with_its_own_rows_and_key(): void
    {
        $this->seedShop();
        $this->forceOrdersScan();
        $runner = new ExplainRunner;

        $plan = $runner->explain(null, $this->joinSql(), ['paid', 1, 2, 3]);
        $this->assertNotNull($plan);
        $this->assertTrue($plan['full_scan']);
        $this->assertSame('mx_orders', $plan['table']);

        $byTable = [];
        foreach ($plan['tables'] as $t) {
            $byTable[$t['table']] = $t;
        }
        $this->assertEqualsCanonicalizing(['mx_orders', 'mx_items'], array_keys($byTable));
        $this->assertGreaterThan(1000, $byTable['mx_orders']['rows']);
        $this->assertNull($byTable['mx_orders']['key']);
        $this->assertIsInt($byTable['mx_items']['rows']);

        if ($this->isPgsql()) {
            DB::statement('set enable_seqscan = off');
            DB::statement('reset enable_indexscan');
        }
        $keyed = $runner->explain(null, 'select * from mx_items where order_id = ?', [5]);
        $this->assertNotNull($keyed);
        $this->assertFalse($keyed['full_scan']);
        $this->assertSame('mx_items', $keyed['tables'][0]['table']);
        $this->assertSame('mx_order_id_idx', $keyed['tables'][0]['key']);
    }

    public function test_unused_index_on_the_other_table_is_reported_as_not_chosen(): void
    {
        $this->seedShop();
        $this->forceOrdersScan();

        $issues = (new MissingIndexDetector($this->config()))->detect($this->queryTrace($this->joinSql(), ['paid', 1, 2, 3]));

        $this->assertCount(1, $issues);
        $issue = $issues[0];
        $this->assertSame(Issue::TYPE_INDEX_NOT_CHOSEN, $issue->type);
        $this->assertSame('mx_items', $issue->meta['table']);
        $this->assertSame('mx_merchant_id_2', $issue->meta['index']);
        $this->assertSame(['merchant_id', 'order_id', 'is_approved'], $issue->meta['index_columns']);
        $this->assertSame('mx_orders', $issue->meta['scanned_table']);
        $this->assertStringContainsString('mx_items.mx_merchant_id_2', $issue->title);
        $this->assertGreaterThan(1000, $issue->meta['rows_read']);
        $names = array_column($issue->meta['tables'], 'table');
        $this->assertContains('mx_orders', $names);
        $this->assertContains('mx_items', $names);
        $this->assertNull($issue->meta['rows_returned']);
    }

    public function test_plain_scan_suggests_a_selective_column_first_and_never_the_flag(): void
    {
        $this->seedShop();

        $issues = (new MissingIndexDetector($this->config()))->detect(
            $this->queryTrace('select * from mx_tickets where is_open = ? and owner_id = ?', [true, 7]),
        );

        $this->assertCount(1, $issues);
        $issue = $issues[0];
        $this->assertSame(Issue::TYPE_MISSING_INDEX, $issue->type);
        $this->assertSame('mx_tickets', $issue->meta['table']);
        $this->assertSame('owner_id', $issue->meta['columns'][0]);
        $this->assertNotSame('is_open', $issue->meta['columns'][0]);
        $this->assertStringContainsString('owner_id', (string) $issue->suggestion);
        $this->assertGreaterThan(1000, $issue->meta['rows_read']);
    }

    public function test_flag_only_filter_gets_no_column_suggestion(): void
    {
        $this->seedShop();

        $issues = (new MissingIndexDetector($this->config()))->detect(
            $this->queryTrace('select * from mx_tickets where is_open = ?', [true]),
        );

        $this->assertCount(1, $issues);
        $this->assertSame([], $issues[0]->meta['columns']);
        $this->assertStringContainsString('too few distinct values', (string) $issues[0]->suggestion);
    }

    public function test_indexed_lookup_is_not_flagged(): void
    {
        $this->seedShop();

        $this->assertSame([], (new MissingIndexDetector($this->config()))->detect(
            $this->queryTrace('select * from mx_items where order_id = ?', [5]),
        ));
    }
}
