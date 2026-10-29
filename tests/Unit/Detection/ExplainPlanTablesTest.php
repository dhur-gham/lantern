<?php

declare(strict_types=1);

namespace Lantern\Tests\Unit\Detection;

use Lantern\Detection\Explain\ExplainRunner;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ExplainPlanTablesTest extends TestCase
{
    /** @param list<array<string, mixed>> $rows */
    private function parse(string $driver, array $rows): array
    {
        return (new ReflectionMethod(ExplainRunner::class, $driver))->invoke(new ExplainRunner, $rows);
    }

    public function test_mysql_lists_every_real_table_with_its_key_and_rows(): void
    {
        $plan = $this->parse('mysql', [
            ['id' => 1, 'table' => 'o', 'type' => 'ALL', 'rows' => 820000, 'key' => null, 'possible_keys' => 'PRIMARY'],
            ['id' => 1, 'table' => 'c', 'type' => 'ref', 'rows' => 3, 'key' => 'order_id_idx', 'possible_keys' => 'order_id_idx,merchant_id_2'],
            ['id' => 1, 'table' => '<derived2>', 'type' => 'ALL', 'rows' => 5, 'key' => null, 'possible_keys' => null],
        ]);

        $this->assertCount(2, $plan['tables']);
        $this->assertSame(['table' => 'c', 'alias' => null, 'rows' => 3, 'key' => 'order_id_idx', 'type' => 'ref', 'possible_keys' => 'order_id_idx,merchant_id_2'], $plan['tables'][1]);
        $this->assertSame('o', $plan['table']);
    }

    public function test_pgsql_lists_relations_aliases_and_bitmap_index_names(): void
    {
        $json = json_encode([[
            'Plan' => [
                'Node Type' => 'Nested Loop',
                'Plans' => [
                    ['Node Type' => 'Seq Scan', 'Relation Name' => 'orders', 'Alias' => 'o', 'Plan Rows' => 820000],
                    [
                        'Node Type' => 'Bitmap Heap Scan', 'Relation Name' => 'order_items', 'Alias' => 'order_items', 'Plan Rows' => 4,
                        'Plans' => [['Node Type' => 'Bitmap Index Scan', 'Index Name' => 'merchant_id_2', 'Plan Rows' => 4]],
                    ],
                ],
            ],
        ]]);

        $plan = $this->parse('pgsql', [['QUERY PLAN' => $json]]);

        $this->assertTrue($plan['full_scan']);
        $this->assertSame('orders', $plan['table']);
        $this->assertSame(['table' => 'orders', 'alias' => 'o', 'rows' => 820000, 'key' => null, 'type' => 'ALL', 'possible_keys' => null], $plan['tables'][0]);
        $this->assertSame('order_items', $plan['tables'][1]['table']);
        $this->assertNull($plan['tables'][1]['alias']);
        $this->assertSame('merchant_id_2', $plan['tables'][1]['key']);
    }

    public function test_sqlite_lists_scans_and_searches(): void
    {
        $plan = $this->parse('sqlite', [
            ['detail' => 'SCAN o'],
            ['detail' => 'SEARCH c USING INDEX idx_c_order (order_id=?)'],
            ['detail' => 'SEARCH s AS sh USING INTEGER PRIMARY KEY (rowid=?)'],
        ]);

        $this->assertTrue($plan['full_scan']);
        $this->assertSame('o', $plan['table']);
        $this->assertSame('ALL', $plan['tables'][0]['type']);
        $this->assertSame('idx_c_order', $plan['tables'][1]['key']);
        $this->assertSame('s', $plan['tables'][2]['table']);
        $this->assertSame('sh', $plan['tables'][2]['alias']);
    }
}
