<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Matrix;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Lantern\Agent\Commands;
use Lantern\Detection\Detectors\MissingIndexDetector;
use Lantern\Detection\Explain\DatabaseCatalog;
use Lantern\Detection\Explain\ExplainRunner;
use Lantern\Detection\Issue;
use Lantern\Instrumentation\Lifecycle\LifecycleInstrumentation;
use Lantern\Trace\Recorder;

final class IndexAnalysisMatrixTest extends MatrixTestCase
{
    /** Lantern is on with the default redaction (no stored bindings), the setting rows_returned used to need. */
    protected array $lantern = ['storage.driver' => 'database', 'explain' => true, 'explain_analyze' => true, 'capture_bindings' => false];

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

    public function test_unindexed_flags_without_a_distinct_count_never_lead(): void
    {
        $this->seedShop();
        $stats = (new DatabaseCatalog)->stats(self::driver(), 'mx_tickets');
        if (! $this->isPgsql()) {
            // MySQL and MariaDB count distinct values only for indexed columns
            $this->assertNull($stats['columns']['state']['distinct']);
            $this->assertNull($stats['columns']['is_vip']['distinct']);
        }

        $issues = (new MissingIndexDetector($this->config()))->detect(
            $this->queryTrace('select * from mx_tickets where state = ? and is_vip = ? and owner_id = ?', [1, 0, 7]),
        );

        $this->assertCount(1, $issues);
        $this->assertSame(['owner_id', 'state', 'is_vip'], $issues[0]->meta['columns']);

        $only = (new MissingIndexDetector($this->config()))->detect(
            $this->queryTrace('select * from mx_tickets where state = ?', [1]),
        );
        $this->assertSame([], $only[0]->meta['columns']);
    }

    public function test_explain_analyze_counts_the_rows_the_query_returns(): void
    {
        $this->seedShop();
        $this->forceOrdersScan();
        $bindings = ['paid', 1, 2, 3];
        $expected = count(DB::select($this->joinSql(), $bindings));
        $this->assertGreaterThan(0, $expected);
        $config = ['explain_analyze' => true] + $this->config();

        $issues = (new MissingIndexDetector($config))->detect($this->queryTrace($this->joinSql(), $bindings));

        $this->assertCount(1, $issues);
        $this->assertSame($expected, $issues[0]->meta['rows_returned']);
        $this->assertGreaterThan(1000, $issues[0]->meta['rows_read']);

        $plain = (new MissingIndexDetector($config))->detect($this->queryTrace('select * from mx_tickets where owner_id = ?', [7]));
        $this->assertSame(count(DB::select('select * from mx_tickets where owner_id = ?', [7])), $plain[0]->meta['rows_returned']);

        $off = (new MissingIndexDetector($this->config()))->detect($this->queryTrace($this->joinSql(), $bindings));
        $this->assertNull($off[0]->meta['rows_returned']);
    }

    public function test_rows_returned_and_the_read_ratio_appear_with_bindings_redacted(): void
    {
        $files = ['2024_01_01_000001_create_lantern_traces_table', '2024_01_01_000002_create_lantern_issues_table'];
        $this->migrate('down', array_reverse($files));
        $this->migrate('up', $files);
        $kernel = $this->app->make(Kernel::class);
        foreach (Commands::all() as $class) {
            $kernel->registerCommand($this->app->make($class));
        }

        try {
            $this->seedShop();
            $this->forceOrdersScan();
            $bindings = ['paid', 1, 2, 3];
            $expected = count(DB::select($this->joinSql(), $bindings));
            $this->assertGreaterThan(0, $expected);

            $recorder = $this->app->make(Recorder::class);
            $recorder->reset();
            $trace = $recorder->begin(['kind' => 'request', 'method' => 'GET', 'path' => '/shop', 'status' => 200]);
            DB::select($this->joinSql(), $bindings);

            $span = array_values(array_filter($trace->spans(), fn ($s) => str_contains((string) ($s->meta['sql'] ?? ''), 'mx_orders')))[0];
            $this->assertArrayNotHasKey('bindings', $span->meta, 'bindings are not captured in this configuration');
            $this->assertSame($bindings, $span->rawBindings());

            $this->app->make(LifecycleInstrumentation::class)->finish();

            foreach ($trace->spans() as $s) {
                $this->assertNull($s->rawBindings(), 'raw bindings are dropped once the trace is analysed');
            }
            $this->assertStringNotContainsString('"paid"', (string) DB::table('lantern_traces')->value('payload'));

            $listed = $this->cli('lantern:issues', ['--full' => true]);
            $plan = array_values(array_filter($listed['issues'] ?? [], fn (array $i): bool => in_array($i['type'], ['missing_index', 'index_not_chosen'], true)));
            $this->assertCount(1, $plan);

            $evidence = $this->cli('lantern:issue', ['key' => $plan[0]['id']])['issue']['evidence'];
            $this->assertSame($expected, $evidence['rows_returned']);
            $this->assertGreaterThan(1000, $evidence['rows_read']);
            $this->assertEqualsWithDelta($evidence['rows_read'] / $expected, $evidence['read_ratio'], 0.1);
        } finally {
            $this->migrate('down', array_reverse($files));
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function cli(string $command, array $params = []): array
    {
        Artisan::call($command, $params + ['--format' => 'json']);
        $data = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($data);

        return $data;
    }

    public function test_rows_returned_honours_limit_and_aggregates(): void
    {
        $this->seedShop();
        $runner = new ExplainRunner;

        $this->assertSame(5, $runner->rowsReturned(self::driver(), 'select * from mx_tickets where owner_id > ? limit 5', [0]));
        $this->assertSame(1, $runner->rowsReturned(self::driver(), 'select count(*) from mx_tickets where owner_id > ?', [0]));
        $this->assertSame(0, $runner->rowsReturned(self::driver(), 'select * from mx_tickets where owner_id > ?', [100000]));
    }

    public function test_a_query_with_its_own_hint_block_is_still_measured(): void
    {
        $this->seedShop();
        $expected = count(DB::select('select * from mx_tickets where owner_id = ?', [7]));
        $this->assertGreaterThan(0, $expected);

        $rows = (new ExplainRunner)->rowsReturned(self::driver(), 'select /*+ NO_BNL(mx_tickets) */ * from mx_tickets where owner_id = ?', [7], 1500);

        $this->assertSame($expected, $rows);
    }

    public function test_a_slow_query_is_cut_off_and_leaves_the_connection_usable(): void
    {
        $this->seedShop();
        $started = microtime(true);

        $rows = (new ExplainRunner)->rowsReturned(
            self::driver(),
            'select count(*) from mx_orders a cross join mx_orders b cross join mx_orders c where a.order_id <> b.order_id',
            [],
            150,
        );

        $this->assertNull($rows);
        $this->assertLessThan(5.0, microtime(true) - $started);
        $this->assertFalse(DB::connection()->getReadPdo()->inTransaction());
        $this->assertSame(6000, (int) DB::table('mx_orders')->count());
    }

    public function test_statements_with_side_effects_are_never_replayed(): void
    {
        $this->seedShop();
        $runner = new ExplainRunner;

        $this->assertNull($runner->rowsReturned(self::driver(), 'select * from mx_tickets where id = ? for update', [1]));
        $this->assertNull($runner->rowsReturned(self::driver(), $this->isPgsql() ? 'select pg_sleep(0)' : 'select sleep(0)'));
    }

    public function test_indexed_lookup_is_not_flagged(): void
    {
        $this->seedShop();

        $this->assertSame([], (new MissingIndexDetector($this->config()))->detect(
            $this->queryTrace('select * from mx_items where order_id = ?', [5]),
        ));
    }
}
