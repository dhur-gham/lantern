<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Matrix;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lantern\Schema\SchemaDiff;
use Lantern\Schema\SchemaIntrospector;
use Lantern\Schema\SchemaSnapshot;

final class SchemaMatrixTest extends MatrixTestCase
{
    /** @return array<string, list<array{name: string, columns: list<string>, unique: bool}>> */
    private function capture(array $tables = ['mx_items', 'mx_orders']): array
    {
        return (new SchemaIntrospector)->capture(DB::connection(), $tables);
    }

    /** @param list<array{name: string, columns: list<string>, unique: bool}> $indexes */
    private function byColumns(array $indexes): array
    {
        $out = [];
        foreach ($indexes as $index) {
            $out[implode(',', $index['columns'])] = $index;
        }

        return $out;
    }

    public function test_capture_reports_primary_plain_composite_and_unique_indexes(): void
    {
        $this->seedShop();
        Schema::table('mx_items', function (Blueprint $t) {
            $t->unique(['order_id', 'merchant_id'], 'mx_uniq_order_merchant');
        });

        $captured = $this->capture();
        $this->assertSame(['mx_items', 'mx_orders'], array_keys($captured));

        $items = $this->byColumns($captured['mx_items']);
        $this->assertSame(['id', 'merchant_id,order_id,is_approved', 'order_id', 'order_id,merchant_id'], $this->sorted(array_keys($items)));
        $this->assertTrue($items['id']['unique']);
        $this->assertFalse($items['order_id']['unique']);
        $this->assertSame('mx_order_id_idx', $items['order_id']['name']);
        $this->assertSame('mx_merchant_id_2', $items['merchant_id,order_id,is_approved']['name']);
        $this->assertTrue($items['order_id,merchant_id']['unique']);
        $this->assertSame(['order_id', 'merchant_id'], $items['order_id,merchant_id']['columns']);

        $orders = $this->byColumns($captured['mx_orders']);
        $this->assertSame(['order_id'], array_keys($orders));
        $this->assertTrue($orders['order_id']['unique']);
    }

    /** @param list<string> $values @return list<string> */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    public function test_unknown_and_unsafe_table_names_are_left_out(): void
    {
        $this->seedShop();

        $this->assertSame(['mx_orders'], array_keys($this->capture(['mx_orders', 'mx_nope', 'mx_orders; drop table mx_orders'])));
        $this->assertTrue(Schema::hasTable('mx_orders'));
    }

    public function test_expression_indexes_do_not_break_capture(): void
    {
        $this->seedShop();
        if ($this->isPgsql()) {
            DB::statement('create index mx_expr_idx on mx_orders (lower(payment_status))');
        } else {
            Schema::table('mx_orders', fn (Blueprint $t) => $t->index('payment_status', 'mx_status_idx'));
        }

        $orders = $this->byColumns($this->capture(['mx_orders'])['mx_orders']);

        $this->assertArrayHasKey('order_id', $orders);
        $this->assertSame(['order_id'], $orders['order_id']['columns']);
        $this->assertTrue($orders['order_id']['unique']);
        if ($this->isPgsql()) {
            $this->assertCount(1, $orders, 'an expression key part has no column to report');
        } else {
            $this->assertArrayHasKey('payment_status', $orders);
        }
    }

    public function test_snapshot_round_trips_and_diff_finds_missing_and_extra_indexes(): void
    {
        $this->seedShop();
        $production = new SchemaSnapshot(self::driver(), 'matrix', '2026-01-01T00:00:00+00:00', $this->capture(), ['mx_orders' => 838_776]);

        $decoded = SchemaSnapshot::fromArray(json_decode((string) json_encode($production->toArray()), true));
        $this->assertEquals($production->tables, $decoded->tables);
        $this->assertSame(['mx_orders' => 838_776], $decoded->rowCounts);
        $this->assertSame(['tables_missing_locally' => [], 'missing_locally' => [], 'extra_locally' => []], SchemaDiff::compare($decoded->tables, $this->capture()));

        Schema::table('mx_items', function (Blueprint $t) {
            $t->dropIndex('mx_merchant_id_2');
            $t->index(['is_approved', 'merchant_id'], 'mx_local_only_idx');
        });

        $diff = SchemaDiff::compare($decoded->tables, $this->capture());
        $this->assertCount(1, $diff['missing_locally']);
        $this->assertSame('mx_items', $diff['missing_locally'][0]['table']);
        $this->assertSame(['merchant_id', 'order_id', 'is_approved'], $diff['missing_locally'][0]['columns']);
        $this->assertSame('mx_merchant_id_2', $diff['missing_locally'][0]['name']);
        $this->assertCount(1, $diff['extra_locally']);
        $this->assertSame('mx_local_only_idx', $diff['extra_locally'][0]['name']);

        Schema::drop('mx_tickets');
        $this->assertSame(['mx_tickets'], SchemaDiff::compare($this->withTickets($decoded->tables), $this->capture())['tables_missing_locally']);
    }

    public function test_snapshot_and_diff_commands_work_on_the_live_database(): void
    {
        $this->seedShop();

        $this->assertSame(0, Artisan::call('lantern:schema-snapshot', ['--tables' => 'mx_items,mx_orders', '--row-counts' => 'mx_orders:838776']));
        $snapshot = json_decode(Artisan::output(), true);
        $this->assertSame('lantern.schema-snapshot', $snapshot['schema']);
        $this->assertSame(self::driver(), $snapshot['driver']);
        $this->assertSame(['mx_items', 'mx_orders'], array_keys($snapshot['tables']));
        $this->assertSame(838776, $snapshot['row_counts']['mx_orders']);

        $file = tempnam(sys_get_temp_dir(), 'lsnap');
        file_put_contents($file, json_encode($snapshot));
        try {
            $this->assertSame(0, Artisan::call('lantern:schema-diff', ['snapshot' => $file]));
            $diff = json_decode(Artisan::output(), true);
            $this->assertSame([], $diff['missing_locally']);
            $this->assertSame([], $diff['extra_locally']);

            Schema::table('mx_items', fn (Blueprint $t) => $t->dropIndex('mx_merchant_id_2'));
            Artisan::call('lantern:schema-diff', ['snapshot' => $file]);
            $diff = json_decode(Artisan::output(), true);
            $this->assertCount(1, $diff['missing_locally']);
            $this->assertSame('mx_merchant_id_2', $diff['missing_locally'][0]['name']);
        } finally {
            @unlink($file);
        }
    }

    /**
     * @param array<string, list<array<string, mixed>>> $tables
     * @return array<string, list<array<string, mixed>>>
     */
    private function withTickets(array $tables): array
    {
        return $tables + ['mx_tickets' => [['name' => 'PRIMARY', 'columns' => ['id'], 'unique' => true]]];
    }
}
