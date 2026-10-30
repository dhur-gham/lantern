<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Explain;

use Lantern\Detection\Detectors\MissingIndexDetector;
use Lantern\Detection\Explain\AnalyzeSource;
use Lantern\Detection\Explain\Catalog;
use Lantern\Detection\Explain\ExplainRunner;
use Lantern\Detection\Explain\PlanSource;
use Lantern\Detection\Issue;
use Lantern\Schema\ProductionSnapshot;
use Lantern\Tests\Feature\Query\QueryTestCase;
use Lantern\Trace\SpanType;
use Lantern\Trace\Trace;

final class IndexAnalysisTest extends QueryTestCase
{
    private const OR_MESSAGE = 'OR across tables: no single index can serve this; check the plan (EXPLAIN ANALYZE), the join order, or split it into a UNION.';

    /** @return array<string, mixed> */
    private function config(): array
    {
        return ['explain' => true, 'thresholds' => ['slow_query_ms' => 100, 'n_plus_one_min_repeats' => 5]];
    }

    /**
     * @param  list<mixed>  $bindings
     * @param  list<string>  $more  further query shapes in the same trace
     */
    private function traceFor(string $sql, array $bindings = [], array $more = []): Trace
    {
        $recorder = $this->recorder();
        $recorder->reset();
        $trace = $recorder->begin();
        foreach ([$sql, ...$more] as $i => $statement) {
            $now = hrtime(true);
            $recorder->recordClosed(SpanType::Query, 'q'.$i, $now, $now + 60_000_000, [
                'sql' => $statement, 'bindings' => $i === 0 ? $bindings : array_fill(0, substr_count($statement, '?'), 1), 'fingerprint' => md5($statement), 'connection' => 'testing', 'time_ms' => 60.0, 'failed' => false,
            ]);
        }

        return $trace;
    }

    /**
     * @param  list<array{table: string, rows: ?int, key: ?string, type: string, alias?: ?string}>  $tables
     */
    private function plan(array $tables): PlanSource
    {
        $tables = array_map(fn (array $t): array => $t + ['alias' => null, 'possible_keys' => null], $tables);
        $scan = collect($tables)->first(fn (array $t): bool => $t['type'] === 'ALL');
        $plan = [
            'full_scan' => $scan !== null,
            'table' => $scan['table'] ?? null,
            'rows' => $scan['rows'] ?? null,
            'key' => null,
            'possible_keys' => null,
            'tables' => $tables,
            'raw' => [],
        ];

        return new class($plan) implements PlanSource
        {
            /** @param array<string, mixed> $plan */
            public function __construct(private readonly array $plan)
            {
            }

            public function explain(?string $connectionName, string $sql, array $bindings = []): ?array
            {
                return $this->plan;
            }
        };
    }

    /**
     * @param  array<string, list<array{name: string, columns: list<string>, unique: bool}>>  $indexes
     * @param  array<string, array<string, array{type: ?string, distinct: ?int}>>  $columns
     */
    private function catalog(array $indexes = [], array $columns = [], bool $throw = false): Catalog
    {
        return new class($indexes, $columns, $throw) implements Catalog
        {
            public function __construct(private readonly array $indexes, private readonly array $columns, private readonly bool $throw)
            {
            }

            public function indexes(?string $connection, string $table): array
            {
                if ($this->throw) {
                    throw new \RuntimeException('catalog down');
                }

                return $this->indexes[$table] ?? [];
            }

            public function stats(?string $connection, string $table): array
            {
                return ['rows' => null, 'columns' => $this->columns[$table] ?? []];
            }
        };
    }

    /** @return list<Issue> */
    private function detect(string $sql, PlanSource $plan, Catalog $catalog): array
    {
        return (new MissingIndexDetector($this->config(), $plan, $catalog))->detect($this->traceFor($sql));
    }

    private const INCIDENT = 'select order_items.merchant_id, count(*) as aggregate from orders inner join order_items on order_items.order_id = orders.order_id '
        .'left join shipments on shipments.id = orders.shipment_id '
        .'where (orders.payment_status = ? and order_items.merchant_id in (?, ?, ?) and orders.is_frozen = ?) '
        .'or (shipments.state = ? and order_items.merchant_id in (?, ?, ?) and orders.payment_status = ?) group by order_items.merchant_id';

    /** @return list<array{table: string, rows: ?int, key: ?string, type: string}> */
    private function incidentPlan(): array
    {
        return [
            ['table' => 'orders', 'rows' => 820000, 'key' => null, 'type' => 'ALL'],
            ['table' => 'order_items', 'rows' => 3, 'key' => 'order_id_idx', 'type' => 'ref'],
            ['table' => 'shipments', 'rows' => 1, 'key' => 'PRIMARY', 'type' => 'eq_ref'],
        ];
    }

    public function test_existing_index_on_another_table_is_reported_as_not_chosen(): void
    {
        $catalog = $this->catalog([
            'orders' => [['name' => 'PRIMARY', 'columns' => ['order_id'], 'unique' => true]],
            'order_items' => [
                ['name' => 'PRIMARY', 'columns' => ['id'], 'unique' => true],
                ['name' => 'order_id_idx', 'columns' => ['order_id'], 'unique' => false],
                ['name' => 'merchant_id_2', 'columns' => ['merchant_id', 'order_id', 'is_approved'], 'unique' => false],
            ],
        ]);

        $issues = $this->detect(self::INCIDENT, $this->plan($this->incidentPlan()), $catalog);

        $this->assertCount(1, $issues);
        $this->assertSame(Issue::TYPE_INDEX_NOT_CHOSEN, $issues[0]->type);
        $this->assertSame('Index exists but was not chosen: order_items.merchant_id_2', $issues[0]->title);
        $this->assertSame('order_items', $issues[0]->meta['table']);
        $this->assertSame('merchant_id_2', $issues[0]->meta['index']);
        $this->assertSame('order_id_idx', $issues[0]->meta['used_key']);
        $this->assertStringContainsString('optimizer chose a different plan', (string) $issues[0]->suggestion);
        $this->assertStringContainsString('EXPLAIN ANALYZE', (string) $issues[0]->suggestion);
        $this->assertStringContainsString('ANALYZE TABLE order_items', (string) $issues[0]->suggestion);
    }

    public function test_plan_rows_are_stored_for_read_versus_returned(): void
    {
        $catalog = $this->catalog(['order_items' => [['name' => 'merchant_id_2', 'columns' => ['merchant_id'], 'unique' => false]]]);

        $meta = $this->detect(self::INCIDENT, $this->plan($this->incidentPlan()), $catalog)[0]->meta;

        $this->assertSame(820000, $meta['rows_read']);
        $this->assertNull($meta['rows_returned']);
        $this->assertSame(['table' => 'orders', 'rows' => 820000, 'key' => null, 'type' => 'ALL'], $meta['tables'][0]);
        $this->assertCount(3, $meta['tables']);
        $this->assertContains('merchant_id', $meta['columns_by_table']['order_items']);
        $this->assertNotContains('merchant_id', $meta['columns_by_table']['orders']);
    }

    public function test_cross_table_or_without_a_usable_index_gives_the_or_message(): void
    {
        $issues = $this->detect(self::INCIDENT, $this->plan($this->incidentPlan()), $this->catalog());

        $this->assertCount(1, $issues);
        $this->assertSame(Issue::TYPE_MISSING_INDEX, $issues[0]->type);
        $this->assertSame([], $issues[0]->meta['columns']);
        $this->assertTrue($issues[0]->meta['or_across_tables']);
        $this->assertSame(self::OR_MESSAGE, $issues[0]->suggestion);
    }

    public function test_suggestion_only_uses_columns_of_the_scanned_table(): void
    {
        $sql = 'select * from orders inner join order_items on order_items.order_id = orders.order_id '
            .'where orders.customer_id = ? and orders.payment_status = ? and orders.is_frozen = ? and order_items.merchant_id = ?';
        $catalog = $this->catalog(
            [
                'orders' => [['name' => 'PRIMARY', 'columns' => ['order_id'], 'unique' => true]],
                'order_items' => [['name' => 'order_id_idx', 'columns' => ['order_id'], 'unique' => false]],
            ],
            ['orders' => [
                'order_id' => ['type' => 'int', 'distinct' => 820000],
                'customer_id' => ['type' => 'int', 'distinct' => 50000],
                'payment_status' => ['type' => 'varchar(20)', 'distinct' => 40],
                'is_frozen' => ['type' => 'tinyint(1)', 'distinct' => 2],
            ]],
        );
        $plan = $this->plan([
            ['table' => 'orders', 'rows' => 820000, 'key' => null, 'type' => 'ALL'],
            ['table' => 'order_items', 'rows' => 3, 'key' => 'order_id_idx', 'type' => 'ref'],
        ]);

        $issues = $this->detect($sql, $plan, $catalog);

        $this->assertCount(1, $issues);
        $this->assertSame(Issue::TYPE_MISSING_INDEX, $issues[0]->type);
        $this->assertSame(['customer_id', 'payment_status', 'is_frozen'], $issues[0]->meta['columns']);
        $this->assertSame('Consider an index on (customer_id, payment_status, is_frozen)', $issues[0]->suggestion);
        $this->assertNotContains('merchant_id', $issues[0]->meta['columns']);
        $this->assertNotContains('order_id', $issues[0]->meta['columns']);
    }

    public function test_a_two_value_column_is_never_first(): void
    {
        $catalog = $this->catalog([], ['orders' => [
            'is_deleted' => ['type' => 'int', 'distinct' => 2],
            'customer_id' => ['type' => 'int', 'distinct' => 100],
        ]]);
        $plan = $this->plan([['table' => 'orders', 'rows' => 5000, 'key' => null, 'type' => 'ALL']]);

        $issues = $this->detect('select * from orders where is_deleted = ? and customer_id = ?', $plan, $catalog);

        $this->assertSame(['customer_id', 'is_deleted'], $issues[0]->meta['columns']);
    }

    public function test_equality_columns_precede_range_columns(): void
    {
        $plan = $this->plan([['table' => 'orders', 'rows' => 5000, 'key' => null, 'type' => 'ALL']]);

        $issues = $this->detect('select * from orders where created_at > ? and status in (?, ?) and customer_id = ?', $plan, $this->catalog());

        $this->assertSame(['customer_id', 'status', 'created_at'], $issues[0]->meta['columns']);
    }

    public function test_only_low_cardinality_columns_yield_no_column_suggestion(): void
    {
        $catalog = $this->catalog([], ['orders' => ['is_deleted' => ['type' => 'tinyint(1)', 'distinct' => 2]]]);
        $plan = $this->plan([['table' => 'orders', 'rows' => 5000, 'key' => null, 'type' => 'ALL']]);

        $issues = $this->detect('select * from orders where is_deleted = ?', $plan, $catalog);

        $this->assertCount(1, $issues);
        $this->assertSame([], $issues[0]->meta['columns']);
        $this->assertStringContainsString('too few distinct values', (string) $issues[0]->suggestion);
    }

    public function test_unused_index_on_the_scanned_table_is_reported(): void
    {
        $catalog = $this->catalog(['orders' => [['name' => 'customer_idx', 'columns' => ['customer_id'], 'unique' => false]]]);
        $plan = $this->plan([['table' => 'orders', 'rows' => 5000, 'key' => null, 'type' => 'ALL']]);

        $issues = $this->detect('select * from orders where customer_id = ?', $plan, $catalog);

        $this->assertSame(Issue::TYPE_INDEX_NOT_CHOSEN, $issues[0]->type);
        $this->assertSame('Index exists but was not chosen: orders.customer_idx', $issues[0]->title);
    }

    public function test_index_on_a_join_column_of_the_scanned_table_does_not_hide_a_missing_filter_index(): void
    {
        $sql = 'select * from orders o join customers c on c.id = o.customer_id where o.status = ?';
        $catalog = $this->catalog([
            'orders' => [['name' => 'customer_idx', 'columns' => ['customer_id'], 'unique' => false]],
            'customers' => [['name' => 'PRIMARY', 'columns' => ['id'], 'unique' => true]],
        ]);
        $plan = $this->plan([
            ['table' => 'o', 'rows' => 90000, 'key' => null, 'type' => 'ALL'],
            ['table' => 'c', 'rows' => 1, 'key' => 'PRIMARY', 'type' => 'eq_ref'],
        ]);

        $issues = $this->detect($sql, $plan, $catalog);

        $this->assertSame(Issue::TYPE_MISSING_INDEX, $issues[0]->type);
        $this->assertSame('Possible missing index on orders', $issues[0]->title);
        $this->assertSame(['status'], $issues[0]->meta['columns']);
    }

    public function test_a_key_that_already_serves_the_filter_is_not_reported(): void
    {
        $sql = 'select * from orders o join order_items c on c.order_id = o.order_id where c.merchant_id = ? and o.payment_status = ?';
        $catalog = $this->catalog([
            'orders' => [['name' => 'PRIMARY', 'columns' => ['order_id'], 'unique' => true]],
            'order_items' => [
                ['name' => 'merchant_id_2', 'columns' => ['merchant_id', 'order_id'], 'unique' => false],
                ['name' => 'merchant_shipment_idx', 'columns' => ['merchant_id', 'shipment_id'], 'unique' => false],
            ],
        ]);
        $plan = $this->plan([
            ['table' => 'o', 'rows' => 820000, 'key' => null, 'type' => 'ALL'],
            ['table' => 'c', 'rows' => 3, 'key' => 'merchant_id_2', 'type' => 'ref'],
        ]);

        $issues = $this->detect($sql, $plan, $catalog);

        $this->assertSame(Issue::TYPE_MISSING_INDEX, $issues[0]->type);
        $this->assertSame(['payment_status'], $issues[0]->meta['columns']);
    }

    public function test_explain_aliases_resolve_to_real_tables(): void
    {
        $sql = 'select * from orders o inner join order_items c on c.order_id = o.order_id where c.merchant_id in (?, ?)';
        $catalog = $this->catalog([
            'order_items' => [
                ['name' => 'order_id_idx', 'columns' => ['order_id'], 'unique' => false],
                ['name' => 'merchant_id_2', 'columns' => ['merchant_id', 'order_id'], 'unique' => false],
            ],
        ]);
        $plan = $this->plan([
            ['table' => 'o', 'rows' => 820000, 'key' => null, 'type' => 'ALL'],
            ['table' => 'c', 'rows' => 3, 'key' => 'order_id_idx', 'type' => 'ref'],
        ]);

        $issues = $this->detect($sql, $plan, $catalog);

        $this->assertSame('Index exists but was not chosen: order_items.merchant_id_2', $issues[0]->title);
        $this->assertSame('orders', $issues[0]->meta['tables'][0]['table']);
    }

    public function test_flags_without_a_distinct_count_are_recognised_by_name_and_type(): void
    {
        $catalog = $this->catalog([], ['orders' => [
            'customer_id' => ['type' => 'int', 'distinct' => null],
            'is_vip' => ['type' => 'int', 'distinct' => null],
            'state' => ['type' => 'tinyint(4)', 'distinct' => null],
            'kind' => ['type' => "enum('a','b')", 'distinct' => null],
        ]]);
        $plan = $this->plan([['table' => 'orders', 'rows' => 5000, 'key' => null, 'type' => 'ALL']]);

        $issues = $this->detect('select * from orders where is_vip = ? and state = ? and kind = ? and customer_id = ?', $plan, $catalog);

        $this->assertSame(['customer_id', 'is_vip', 'state', 'kind'], $issues[0]->meta['columns']);
    }

    public function test_only_flags_without_a_distinct_count_give_no_column_suggestion(): void
    {
        $catalog = $this->catalog([], ['orders' => ['state' => ['type' => 'tinyint(4)', 'distinct' => null]]]);
        $plan = $this->plan([['table' => 'orders', 'rows' => 5000, 'key' => null, 'type' => 'ALL']]);

        $issues = $this->detect('select * from orders where state = ?', $plan, $catalog);

        $this->assertSame([], $issues[0]->meta['columns']);
        $this->assertStringContainsString('too few distinct values', (string) $issues[0]->suggestion);
    }

    public function test_a_real_distinct_count_overrides_the_name_and_type_guess(): void
    {
        $catalog = $this->catalog([], ['orders' => [
            'is_new' => ['type' => 'int', 'distinct' => 5000],
            'customer_id' => ['type' => 'int', 'distinct' => 60],
            'level' => ['type' => 'tinyint(4)', 'distinct' => 40],
        ]]);
        $plan = $this->plan([['table' => 'orders', 'rows' => 5000, 'key' => null, 'type' => 'ALL']]);

        $issues = $this->detect('select * from orders where level = ? and customer_id = ? and is_new = ?', $plan, $catalog);

        $this->assertSame(['is_new', 'customer_id', 'level'], $issues[0]->meta['columns']);
    }

    /** @return AnalyzeSource&object{calls: list<array<string, mixed>>} */
    private function analyzer(int|\Throwable|null $answer): AnalyzeSource
    {
        return new class($answer) implements AnalyzeSource
        {
            /** @var list<array<string, mixed>> */
            public array $calls = [];

            public function __construct(private readonly int|\Throwable|null $answer)
            {
            }

            public function rowsReturned(?string $connectionName, string $sql, array $bindings = [], int $timeoutMs = 2000): ?int
            {
                $this->calls[] = ['sql' => $sql, 'bindings' => $bindings, 'timeout' => $timeoutMs];
                if ($this->answer instanceof \Throwable) {
                    throw $this->answer;
                }

                return $this->answer;
            }
        };
    }

    /**
     * @param  array<string, mixed>  $extra
     * @param  list<mixed>  $bindings
     * @param  list<string>  $more
     * @return list<Issue>
     */
    private function detectAnalyzed(AnalyzeSource $analyzer, array $extra = [], array $bindings = [1], array $more = []): array
    {
        $plan = $this->plan([['table' => 'orders', 'rows' => 820000, 'key' => null, 'type' => 'ALL']]);
        $config = array_replace_recursive($this->config(), ['explain_analyze' => true], $extra);

        return (new MissingIndexDetector($config, $plan, $this->catalog(), $analyzer))
            ->detect($this->traceFor('select * from orders where customer_id = ?', $bindings, $more));
    }

    public function test_rows_returned_is_measured_when_explain_analyze_is_on(): void
    {
        $analyzer = $this->analyzer(41);

        $issues = $this->detectAnalyzed($analyzer, ['explain_analyze_timeout_ms' => 1500]);

        $this->assertSame(41, $issues[0]->meta['rows_returned']);
        $this->assertSame(820000, $issues[0]->meta['rows_read']);
        $this->assertCount(1, $analyzer->calls);
        $this->assertSame([1], $analyzer->calls[0]['bindings']);
        $this->assertSame(1500, $analyzer->calls[0]['timeout']);
    }

    public function test_nothing_is_replayed_unless_explain_analyze_is_on(): void
    {
        $analyzer = $this->analyzer(41);
        $plan = $this->plan([['table' => 'orders', 'rows' => 820000, 'key' => null, 'type' => 'ALL']]);

        $issues = (new MissingIndexDetector($this->config(), $plan, $this->catalog(), $analyzer))
            ->detect($this->traceFor('select * from orders where customer_id = ?', [1]));

        $this->assertNull($issues[0]->meta['rows_returned']);
        $this->assertSame([], $analyzer->calls);
    }

    public function test_replays_are_capped_per_trace(): void
    {
        $analyzer = $this->analyzer(7);

        $issues = $this->detectAnalyzed(
            $analyzer,
            ['explain_analyze_max' => 1],
            [1],
            ['select * from orders where region_id = ?', 'select * from orders where owner_id = ?'],
        );

        $this->assertCount(3, $issues);
        $this->assertCount(1, $analyzer->calls);
        $this->assertSame([7, null, null], array_map(fn (Issue $i) => $i->meta['rows_returned'], $issues));
    }

    public function test_incomplete_bindings_are_not_replayed(): void
    {
        foreach ([[], ['[redacted]'], ['[binary 12 bytes]'], [str_repeat('a', 200).'...']] as $bindings) {
            $analyzer = $this->analyzer(41);

            $issues = $this->detectAnalyzed($analyzer, [], $bindings);

            $this->assertNull($issues[0]->meta['rows_returned']);
            $this->assertSame([], $analyzer->calls);
        }
    }

    public function test_raw_bindings_held_on_the_span_are_replayed_when_stored_bindings_are_redacted(): void
    {
        $analyzer = $this->analyzer(41);
        $plan = $this->plan([['table' => 'orders', 'rows' => 820000, 'key' => null, 'type' => 'ALL']]);
        $trace = $this->traceFor('select * from orders where customer_id = ?', ['[redacted]']);
        array_values($trace->spans())[1]->keepRawBindings([4711]);
        $config = array_replace_recursive($this->config(), ['explain_analyze' => true]);

        $issues = (new MissingIndexDetector($config, $plan, $this->catalog(), $analyzer))->detect($trace);

        $this->assertSame(41, $issues[0]->meta['rows_returned']);
        $this->assertSame([4711], $analyzer->calls[0]['bindings']);
        $this->assertArrayNotHasKey('bindings', $issues[0]->meta);
        $this->assertStringNotContainsString('4711', json_encode($issues[0]->toArray()));
    }

    public function test_raw_bindings_are_replayed_with_the_sql_they_belong_to(): void
    {
        $analyzer = $this->analyzer(5);
        $plan = $this->plan([['table' => 'orders', 'rows' => 820000, 'key' => null, 'type' => 'ALL']]);
        $recorder = $this->recorder();
        $recorder->reset();
        $trace = $recorder->begin();
        foreach (['select * from orders where customer_id in (?)' => [1], 'select * from orders where customer_id in (?, ?, ?)' => [1, 2, 3]] as $sql => $values) {
            $now = hrtime(true);
            $span = $recorder->recordClosed(SpanType::Query, 'q', $now, $now + 60_000_000, [
                'sql' => $sql, 'bindings' => ['[redacted]'], 'fingerprint' => 'same-shape', 'connection' => 'testing', 'time_ms' => 60.0, 'failed' => false,
            ]);
            if (count($values) === 3) {
                $span->keepRawBindings($values);
            }
        }
        $config = array_replace_recursive($this->config(), ['explain_analyze' => true]);

        (new MissingIndexDetector($config, $plan, $this->catalog(), $analyzer))->detect($trace);

        $this->assertSame('select * from orders where customer_id in (?, ?, ?)', $analyzer->calls[0]['sql']);
        $this->assertSame([1, 2, 3], $analyzer->calls[0]['bindings']);
    }

    public function test_raw_bindings_that_do_not_fit_the_sql_are_not_replayed(): void
    {
        $analyzer = $this->analyzer(41);
        $plan = $this->plan([['table' => 'orders', 'rows' => 820000, 'key' => null, 'type' => 'ALL']]);
        $trace = $this->traceFor('select * from orders where customer_id = ?', ['[redacted]']);
        array_values($trace->spans())[1]->keepRawBindings([1, 2]);
        $config = array_replace_recursive($this->config(), ['explain_analyze' => true]);

        $issues = (new MissingIndexDetector($config, $plan, $this->catalog(), $analyzer))->detect($trace);

        $this->assertNull($issues[0]->meta['rows_returned']);
        $this->assertSame([], $analyzer->calls);
    }

    public function test_the_execution_limit_is_merged_into_an_existing_hint_block(): void
    {
        $this->assertSame(
            ['select /*+ MAX_EXECUTION_TIME(1500) */ * from orders', 1500],
            ExplainRunner::withExecutionLimit('select * from orders', 1500),
        );
        $this->assertSame(
            ['select /*+ MAX_EXECUTION_TIME(1500) JOIN_ORDER(orders, items) */ * from orders', 1500],
            ExplainRunner::withExecutionLimit('select /*+ JOIN_ORDER(orders, items) */ * from orders', 1500),
        );
        $this->assertSame(
            ['  SELECT /*+ MAX_EXECUTION_TIME(1500) NO_BNL(a)  INDEX(a idx) */ a.* from a', 1500],
            ExplainRunner::withExecutionLimit("  SELECT\n/*+ NO_BNL(a)  INDEX(a idx) */ a.* from a", 1500),
        );
        // the tighter of the two limits wins, and no second hint comment is added
        $this->assertSame(
            ['select /*+ MAX_EXECUTION_TIME(300) JOIN_ORDER(a, b) */ * from a', 300],
            ExplainRunner::withExecutionLimit('select /*+ MAX_EXECUTION_TIME(300) JOIN_ORDER(a, b) */ * from a', 1500),
        );
        $this->assertSame(
            ['select /*+ MAX_EXECUTION_TIME(1500) */ * from a', 1500],
            ExplainRunner::withExecutionLimit('select /*+ MAX_EXECUTION_TIME(9000) */ * from a', 1500),
        );
        $this->assertNull(ExplainRunner::withExecutionLimit('(select 1) union (select 2)', 1500));
    }

    public function test_a_failing_measurement_never_breaks_the_finding(): void
    {
        $issues = $this->detectAnalyzed($this->analyzer(new \RuntimeException('server gone')));

        $this->assertCount(1, $issues);
        $this->assertNull($issues[0]->meta['rows_returned']);
    }

    public function test_only_side_effect_free_selects_are_replayable(): void
    {
        $this->assertTrue(ExplainRunner::isReplayable('select * from orders where customer_id = ?'));
        $this->assertTrue(ExplainRunner::isReplayable('(select id from a) union (select id from b)'));
        foreach ([
            'select * from orders where id = ? for update',
            'select * from orders lock in share mode',
            'select * from orders for share',
            'select * into outfile "/tmp/x" from orders',
            'select get_lock("a", 1)',
            'select sleep(5)',
            "select nextval('s')",
            'select pg_advisory_lock(1)',
            'update orders set a = 1',
        ] as $sql) {
            $this->assertFalse(ExplainRunner::isReplayable($sql), $sql);
        }
        $this->assertNull((new ExplainRunner)->rowsReturned('testing', 'select * from orders for update'));
    }

    public function test_catalog_failure_never_breaks_detection(): void
    {
        $plan = $this->plan([['table' => 'orders', 'rows' => 5000, 'key' => null, 'type' => 'ALL']]);

        $this->assertSame([], $this->detect('select * from orders where customer_id = ?', $plan, $this->catalog(throw: true)));
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return list<Issue>
     */
    private function detectProjected(array $extra, int $localRows = 75000): array
    {
        $plan = $this->plan([
            ['table' => 'orders', 'rows' => $localRows, 'key' => null, 'type' => 'ALL'],
            ['table' => 'order_items', 'rows' => 3, 'key' => 'order_id_idx', 'type' => 'ref'],
            ['table' => 'shipments', 'rows' => 1, 'key' => 'PRIMARY', 'type' => 'eq_ref'],
        ]);
        $catalog = $this->catalog(['order_items' => [['name' => 'merchant_id_2', 'columns' => ['merchant_id'], 'unique' => false]]]);
        $config = array_replace_recursive($this->config(), $extra);

        return (new MissingIndexDetector($config, $plan, $catalog))->detect($this->traceFor(self::INCIDENT));
    }

    public function test_scan_is_projected_onto_production_row_counts_and_escalates(): void
    {
        $issue = $this->detectProjected(['production_row_counts' => ['orders' => 838776]])[0];

        $this->assertSame([['table' => 'orders', 'local_rows' => 75000, 'production_rows' => 838776, 'factor' => 11.2]], $issue->meta['projected']);
        $this->assertStringContainsString('reads 75k rows here, about 839k (11x) in production', (string) $issue->suggestion);
        $this->assertStringContainsString('about 839k (11x) in production', $issue->meta['plan']);
        $this->assertSame(Issue::SEV_CRITICAL, $issue->severity);
    }

    public function test_projection_below_the_threshold_or_with_escalation_off_stays_a_warning(): void
    {
        $small = $this->detectProjected(['production_row_counts' => ['orders' => 200000]])[0];
        $this->assertSame(Issue::SEV_WARNING, $small->severity);
        $this->assertSame(200000, $small->meta['projected'][0]['production_rows']);

        $off = $this->detectProjected(['production_row_counts' => ['orders' => 838776], 'thresholds' => ['production_scan_rows' => 0]])[0];
        $this->assertSame(Issue::SEV_WARNING, $off->severity);
        $this->assertArrayHasKey('projected', $off->meta);
    }

    public function test_nothing_changes_without_production_data(): void
    {
        $issue = $this->detectProjected([])[0];

        $this->assertArrayNotHasKey('projected', $issue->meta);
        $this->assertArrayNotHasKey('schema_note', $issue->meta);
        $this->assertSame(Issue::SEV_WARNING, $issue->severity);
        $this->assertStringNotContainsString('in production', (string) $issue->suggestion);
    }

    public function test_snapshot_row_counts_and_index_differences_annotate_the_finding(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'lsn');
        file_put_contents($path, json_encode([
            'schema' => 'lantern.schema-snapshot',
            'tables' => ['order_items' => [
                ['name' => 'merchant_id_2', 'columns' => ['merchant_id'], 'unique' => false],
                ['name' => 'merchant_shipment_idx', 'columns' => ['merchant_id', 'shipment_id'], 'unique' => false],
            ]],
            'row_counts' => ['orders' => 839000],
        ]));
        config()->set('lantern.schema_snapshot', $path);
        ProductionSnapshot::flush();

        try {
            $issue = $this->detectProjected([])[0];
        } finally {
            ProductionSnapshot::flush();
            @unlink($path);
        }

        $this->assertSame('order_items: local schema differs from production: missing merchant_shipment_idx', $issue->meta['schema_note']);
        $this->assertSame(839000, $issue->meta['projected'][0]['production_rows']);
        $this->assertSame(Issue::SEV_CRITICAL, $issue->severity);
    }

    /**
     * @param  array<string, float>  $queries  sql => time in ms, in execution order
     * @param  array<string, mixed>  $extra
     * @return list<Issue>
     */
    private function detectTimed(AnalyzeSource $analyzer, array $queries, array $extra = []): array
    {
        $recorder = $this->recorder();
        $recorder->reset();
        $trace = $recorder->begin();
        foreach (array_keys($queries) as $i => $sql) {
            $now = hrtime(true);
            $ms = $queries[$sql];
            $recorder->recordClosed(SpanType::Query, 'q'.$i, $now, $now + (int) ($ms * 1_000_000), [
                'sql' => $sql, 'bindings' => [1], 'fingerprint' => md5($sql), 'connection' => 'testing', 'time_ms' => $ms, 'failed' => false,
            ]);
        }
        $plan = $this->plan([['table' => 'orders', 'rows' => 820000, 'key' => null, 'type' => 'ALL']]);
        $config = array_replace_recursive($this->config(), ['explain_analyze' => true], $extra);

        return (new MissingIndexDetector($config, $plan, $this->catalog(), $analyzer))->detect($trace);
    }

    public function test_the_replay_budget_goes_to_the_slowest_query_not_the_first_one(): void
    {
        $analyzer = $this->analyzer(41);

        $issues = $this->detectTimed($analyzer, [
            'select * from orders where customer_id = ? /* a */' => 120.0,
            'select * from orders where level = ? /* b */' => 130.0,
            'select * from orders where is_new = ? /* slowest */' => 870.0,
        ], ['explain_analyze_max' => 1]);

        $this->assertCount(1, $analyzer->calls);
        $this->assertStringContainsString('slowest', $analyzer->calls[0]['sql']);
        $byQuery = [];
        foreach ($issues as $issue) {
            $byQuery[$issue->meta['rows_returned'] === 41 ? 'measured' : 'skipped'][] = $issue;
        }
        $this->assertCount(1, $byQuery['measured']);
        $this->assertSame(['budget', 'budget'], array_column(array_column($byQuery['skipped'], 'meta'), 'rows_returned_why'));
    }

    public function test_an_empty_rows_returned_says_why(): void
    {
        $failed = $this->detectAnalyzed($this->analyzer(null));
        $this->assertNull($failed[0]->meta['rows_returned']);
        $this->assertSame('failed', $failed[0]->meta['rows_returned_why']);

        $redacted = $this->detectAnalyzed($this->analyzer(41), [], ['[redacted]']);
        $this->assertSame('no_bindings', $redacted[0]->meta['rows_returned_why']);

        $measured = $this->detectAnalyzed($this->analyzer(41));
        $this->assertArrayNotHasKey('rows_returned_why', $measured[0]->meta);
    }
}
