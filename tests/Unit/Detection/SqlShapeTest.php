<?php

declare(strict_types=1);

namespace Lantern\Tests\Unit\Detection;

use Lantern\Detection\Explain\SqlShape;
use PHPUnit\Framework\TestCase;

final class SqlShapeTest extends TestCase
{
    /** @return list<string> */
    private function columns(SqlShape $shape, ?string $table = null): array
    {
        $cols = [];
        foreach ($shape->predicates as $p) {
            if ($table === null || $p['table'] === $table) {
                $cols[] = ($table === null ? $p['table'].'.' : '').$p['column'];
            }
        }

        return $cols;
    }

    public function test_resolves_aliases_and_qualified_names(): void
    {
        $shape = SqlShape::parse('select * from `orders` as o inner join order_items c on c.order_id = o.order_id where c.merchant_id in (?, ?) and o.status = ?');

        $this->assertSame('orders', $shape->table('o'));
        $this->assertSame('order_items', $shape->table('C'));
        $this->assertSame(['orders', 'order_items'], $shape->tables());
        $this->assertContains('order_items.merchant_id', $this->columns($shape));
        $this->assertContains('orders.status', $this->columns($shape));
        $this->assertFalse($shape->orAcrossTables);
    }

    public function test_join_conditions_mark_both_sides_as_join(): void
    {
        $shape = SqlShape::parse('select * from a join b on b.a_id = a.id where a.flag = ?');

        $joins = array_values(array_filter($shape->predicates, fn (array $p): bool => $p['join']));
        $this->assertEqualsCanonicalizing(['a.id', 'b.a_id'], array_map(fn (array $p): string => $p['table'].'.'.$p['column'], $joins));
        $filters = array_values(array_filter($shape->predicates, fn (array $p): bool => ! $p['join']));
        $this->assertSame('flag', $filters[0]['column']);
    }

    public function test_unqualified_columns_belong_to_the_only_table(): void
    {
        $shape = SqlShape::parse('select * from posts where user_id = ? and created_at > ? and title like ?');

        $this->assertSame(['user_id', 'created_at', 'title'], $this->columns($shape, 'posts'));
        $this->assertSame(['eq', 'range', 'range'], array_column($shape->predicates, 'kind'));
    }

    public function test_unqualified_columns_stay_unresolved_with_several_tables(): void
    {
        $shape = SqlShape::parse('select * from a join b on a.id = b.a_id where status = ?');

        $status = array_values(array_filter($shape->predicates, fn (array $p): bool => $p['column'] === 'status'));
        $this->assertNull($status[0]['table']);
    }

    public function test_or_across_tables_is_detected_and_shared_predicates_kept(): void
    {
        $shape = SqlShape::parse('select * from orders o join order_items c on c.order_id = o.order_id left join shipments s on s.id = o.shipment_id '
            .'where (o.paid = ? and c.merchant_id in (?, ?)) or (s.state = ? and c.merchant_id in (?, ?) and o.paid = ?)');

        $this->assertTrue($shape->orAcrossTables);
        $usable = array_map(fn (array $p): string => $p['table'].'.'.$p['column'], $shape->usable());
        $this->assertContains('order_items.merchant_id', $usable);
        $this->assertContains('orders.paid', $usable);
        $this->assertNotContains('shipments.state', $usable);
    }

    public function test_or_within_one_table_is_not_cross_table(): void
    {
        $shape = SqlShape::parse('select * from posts where status = ? or featured = ?');

        $this->assertFalse($shape->orAcrossTables);
        $this->assertSame(['status', 'featured'], $this->columns($shape, 'posts'));
    }

    public function test_or_inside_parentheses_or_a_string_is_not_a_top_level_or(): void
    {
        $shape = SqlShape::parse("select * from a join b on b.a_id = a.id where a.x = 'this or that' and (a.y = ? or b.z = ?)");

        $this->assertFalse($shape->orAcrossTables);
    }

    public function test_negated_predicates_do_not_serve_an_index(): void
    {
        $shape = SqlShape::parse('select * from t where a <> ? and b not in (?, ?) and c is not null and d = ?');

        $usable = array_column($shape->usable(), 'column');
        $this->assertSame(['d'], $usable);
    }
}
