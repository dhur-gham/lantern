<?php

declare(strict_types=1);

namespace Lantern\Tests\Unit\Detection;

use Lantern\Detection\Explain\ExplainRunner;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ExplainMysqlPickTest extends TestCase
{
    /** @param list<array<string, mixed>> $rows */
    private function parse(array $rows): array
    {
        return (new ReflectionMethod(ExplainRunner::class, 'mysql'))->invoke(new ExplainRunner, $rows);
    }

    public function test_picks_biggest_full_scan_and_skips_derived_tables(): void
    {
        $plan = $this->parse([
            ['id' => 1, 'table' => 'posts', 'type' => 'ALL', 'rows' => 19742, 'key' => null, 'possible_keys' => 'PRIMARY'],
            ['id' => 1, 'table' => '<subquery2>', 'type' => 'eq_ref', 'rows' => 1, 'key' => '<auto_distinct_key>', 'possible_keys' => null],
            ['id' => 2, 'table' => 'comments', 'type' => 'ALL', 'rows' => 198976, 'key' => null, 'possible_keys' => 'comments_post_id_index'],
        ]);

        $this->assertTrue($plan['full_scan']);
        $this->assertSame('comments', $plan['table']);
        $this->assertSame(198976, $plan['rows']);
    }

    public function test_indexed_plan_is_not_a_full_scan(): void
    {
        $plan = $this->parse([['table' => 'posts', 'type' => 'ref', 'rows' => 3, 'key' => 'idx', 'possible_keys' => 'idx']]);

        $this->assertFalse($plan['full_scan']);
        $this->assertSame('idx', $plan['key']);
    }
}
