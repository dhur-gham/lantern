<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Explain;

use Lantern\Detection\Detectors\MissingIndexDetector;
use Lantern\Detection\Explain\Catalog;
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

    private function traceFor(string $sql): Trace
    {
        $recorder = $this->recorder();
        $recorder->reset();
        $trace = $recorder->begin();
        $now = hrtime(true);
        $recorder->recordClosed(SpanType::Query, 'q', $now, $now + 60_000_000, [
            'sql' => $sql, 'bindings' => [], 'fingerprint' => md5($sql), 'connection' => 'testing', 'time_ms' => 60.0, 'failed' => false,
        ]);

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
}
