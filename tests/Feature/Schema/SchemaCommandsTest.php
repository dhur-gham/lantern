<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Schema;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Lantern\Agent\Commands;
use Lantern\Schema\ProductionSnapshot;
use Lantern\Tests\TestCase;

final class SchemaCommandsTest extends TestCase
{
    private const CSV = <<<'CSV'
Table,Non_unique,Key_name,Seq_in_index,Column_name,Collation,Cardinality,Index_type
order_items,0,PRIMARY,1,id,A,2686282,BTREE
order_items,1,order_id,1,order_id,A,100,BTREE
order_items,1,order_id,2,merchant_id,A,100,BTREE
order_items,1,order_id,3,item_is_received,A,100,BTREE
order_items,1,merchant_id_2,1,merchant_id,A,100,BTREE
order_items,1,merchant_id_2,2,order_id,A,100,BTREE
order_items,1,merchant_id_2,3,is_approved,A,100,BTREE
order_items,1,merchant_shipment_idx,1,merchant_id,A,100,BTREE
order_items,1,merchant_shipment_idx,2,shipment_id,A,100,BTREE
CSV;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        $kernel = $this->app->make(\Illuminate\Contracts\Console\Kernel::class);
        foreach (Commands::all() as $class) {
            $kernel->registerCommand($this->app->make($class));
        }

        Schema::create('order_items', function ($t): void {
            $t->id();
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('merchant_id');
            $t->boolean('item_is_received')->default(false);
            $t->boolean('is_approved')->default(false);
            $t->unsignedBigInteger('shipment_id')->nullable();
            $t->index(['order_id', 'merchant_id', 'item_is_received'], 'cart_order_idx');
            $t->index('shipment_id', 'cart_shipment_idx');
        });
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        ProductionSnapshot::flush();
        parent::tearDown();
    }

    private function file(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lantern');
        file_put_contents($path, $contents);

        return $this->files[] = $path;
    }

    /**
     * @param array<string, mixed> $params
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function runJson(string $command, array $params = []): array
    {
        $code = Artisan::call($command, $params + ['--format' => 'json']);
        $data = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($data, 'stdout must be one JSON document');

        return [$code, $data];
    }

    public function test_snapshot_from_show_index_csv_groups_columns_per_index(): void
    {
        [$code, $out] = $this->runJson('lantern:schema-snapshot', ['--from-csv' => $this->file(self::CSV), '--row-counts' => 'order_items:2686282,orders:838776']);

        $this->assertSame(0, $code);
        $this->assertSame(1, $out['schema_version']);
        $this->assertSame('lantern.schema-snapshot', $out['schema']);
        $byName = array_column($out['tables']['order_items'], null, 'name');
        $this->assertSame(['id'], $byName['PRIMARY']['columns']);
        $this->assertTrue($byName['PRIMARY']['unique']);
        $this->assertSame(['merchant_id', 'order_id', 'is_approved'], $byName['merchant_id_2']['columns']);
        $this->assertFalse($byName['merchant_id_2']['unique']);
        $this->assertSame(['order_items' => 2686282, 'orders' => 838776], $out['row_counts']);
    }

    public function test_diff_lists_production_indexes_missing_locally(): void
    {
        $snapshot = $this->file((string) json_encode($this->runJson('lantern:schema-snapshot', ['--from-csv' => $this->file(self::CSV)])[1]));

        [$code, $out] = $this->runJson('lantern:schema-diff', ['snapshot' => $snapshot]);

        $this->assertSame(0, $code);
        $this->assertSame('lantern.schema-diff', $out['schema']);
        $this->assertFalse($out['in_sync']);
        $missing = array_column($out['missing_locally'], 'name');
        sort($missing);
        $this->assertSame(['merchant_id_2', 'merchant_shipment_idx'], $missing);
        $this->assertSame(['cart_shipment_idx'], array_column($out['extra_locally'], 'name'));
        $this->assertSame([], $out['tables_missing_locally']);
    }

    public function test_diff_matches_indexes_by_columns_not_names(): void
    {
        $snapshot = $this->file((string) json_encode($this->runJson('lantern:schema-snapshot', ['--from-csv' => $this->file(self::CSV)])[1]));

        [, $out] = $this->runJson('lantern:schema-diff', ['snapshot' => $snapshot]);

        $this->assertNotContains('order_id', array_column($out['missing_locally'], 'name'));
        $this->assertNotContains('PRIMARY', array_column($out['missing_locally'], 'name'));
    }

    public function test_snapshot_of_the_live_connection_diffs_clean_against_itself(): void
    {
        [$code, $snap] = $this->runJson('lantern:schema-snapshot', ['--tables' => 'order_items']);
        $this->assertSame(0, $code);
        $this->assertSame('sqlite', $snap['driver']);

        [, $out] = $this->runJson('lantern:schema-diff', ['snapshot' => $this->file((string) json_encode($snap))]);

        $this->assertTrue($out['in_sync']);
    }

    public function test_diff_reports_tables_absent_locally(): void
    {
        $csv = self::CSV."\norders,0,PRIMARY,1,id,A,1,BTREE";
        $snapshot = $this->file((string) json_encode($this->runJson('lantern:schema-snapshot', ['--from-csv' => $this->file($csv)])[1]));

        [, $out] = $this->runJson('lantern:schema-diff', ['snapshot' => $snapshot]);

        $this->assertSame(['orders'], $out['tables_missing_locally']);
    }

    public function test_commands_work_without_lantern_storage(): void
    {
        $this->assertSame('memory', config('lantern.storage.driver'));
        [$code] = $this->runJson('lantern:schema-snapshot', ['--tables' => 'order_items']);

        $this->assertSame(0, $code);
    }

    public function test_errors_use_documented_exit_codes_and_json(): void
    {
        [$code, $out] = $this->runJson('lantern:schema-diff', ['snapshot' => '/no/such/file.json']);
        $this->assertSame(2, $code);
        $this->assertSame('not_found', $out['error']['code']);

        [$code] = $this->runJson('lantern:schema-snapshot', ['--tables' => 'order_items;drop']);
        $this->assertSame(2, $code);

        [$code] = $this->runJson('lantern:schema-snapshot', ['--row-counts' => 'order_items:lots']);
        $this->assertSame(2, $code);

        [$code, $out] = $this->runJson('lantern:schema-snapshot', ['--from-csv' => $this->file("Table,Key_name\n")]);
        $this->assertSame(3, $code);
        $this->assertSame('no_data', $out['error']['code']);

        [$code] = $this->runJson('lantern:schema-diff', ['snapshot' => $this->file('{"nope":1}')]);
        $this->assertSame(2, $code);
    }

    public function test_production_snapshot_annotates_row_counts_and_index_differences(): void
    {
        $this->assertNull(ProductionSnapshot::load());

        $snapshot = $this->file((string) json_encode($this->runJson('lantern:schema-snapshot', ['--from-csv' => $this->file(self::CSV), '--row-counts' => 'order_items:100'])[1]));
        config()->set('lantern.schema_snapshot', $snapshot);
        config()->set('lantern.production_row_counts', ['orders' => 838776]);

        $production = ProductionSnapshot::load();
        $this->assertNotNull($production);
        $this->assertSame(838776, $production->rowCount('orders'));
        $this->assertSame(100, $production->rowCount('order_items'));
        $this->assertNull($production->rowCount('users'));
        $this->assertCount(4, $production->indexesFor('order_items'));
        $this->assertSame(
            'local schema differs from production: missing merchant_id_2, merchant_shipment_idx',
            $production->differsFor('order_items', ['PRIMARY', 'order_id']),
        );
        $this->assertNull($production->differsFor('order_items', ['PRIMARY', 'order_id', 'merchant_id_2', 'merchant_shipment_idx']));
    }

    public function test_production_snapshot_never_throws_on_a_broken_file(): void
    {
        config()->set('lantern.schema_snapshot', $this->file('not json'));
        $this->assertNull(ProductionSnapshot::load());

        config()->set('lantern.schema_snapshot', '/no/such/snapshot.json');
        $this->assertNull(ProductionSnapshot::load());
    }
}
