<?php

declare(strict_types=1);

namespace Lantern\Tests\Unit\Query;

use Lantern\Query\InListSize;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InListSizeTest extends TestCase
{
    /** @return array<string, array{string, ?int}> */
    public static function cases(): array
    {
        return [
            'placeholders' => ['select * from t where id in (?, ?, ?)', 3],
            'inlined integers' => ['select * from t where id in (1,2, 3,4)', 4],
            'single value' => ['select * from t where id in (?)', 1],
            'not in' => ['select * from t where id not in (?, ?)', 2],
            'largest list wins' => ['select * from t where a in (?, ?) and b in (?, ?, ?, ?)', 4],
            'commas inside quotes and calls' => ["select * from t where n in ('a,b', 'c', lower('D,E'))", 3],
            'subquery is not a list' => ['select * from t where id in (select id from u where x in (?, ?))', 2],
            'no list' => ['select * from t where id = ?', null],
            'function named min is not IN' => ['select min(id) from t', null],
            'write statements are ignored' => ['delete from t where id in (?, ?)', null],
            'empty list' => ['select * from t where id in ()', null],
        ];
    }

    #[DataProvider('cases')]
    public function test_counts_members(string $sql, ?int $expected): void
    {
        $this->assertSame($expected, InListSize::of($sql));
    }

    public function test_lists_report_offsets_of_the_inner_text(): void
    {
        $sql = 'select * from t where id in (?, ?) limit 5';
        [$list] = InListSize::lists($sql);

        $this->assertSame('?, ?', substr($sql, $list['start'], $list['end'] - $list['start']));
        $this->assertSame('in (', substr($sql, $list['at'], 4));
    }
}
