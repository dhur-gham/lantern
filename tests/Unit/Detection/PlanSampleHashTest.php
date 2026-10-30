<?php

declare(strict_types=1);

namespace Lantern\Tests\Unit\Detection;

use Lantern\Detection\PlanFlip\PlanSampleStore;
use PHPUnit\Framework\TestCase;

final class PlanSampleHashTest extends TestCase
{
    public function test_values_and_list_length_do_not_change_the_hash(): void
    {
        $base = PlanSampleStore::sqlHash('select * from t where a in (?, ?) and b = 5');

        $this->assertSame($base, PlanSampleStore::sqlHash('select * from t where a in (?, ?, ?, ?, ?) and b = 7'));
        $this->assertSame($base, PlanSampleStore::sqlHash("select  *\nfrom t where a in (1, 2, 3) and b = 99"));
        $this->assertSame(PlanSampleStore::sqlHash("select * from t where n = 'a' and a in (?)"), PlanSampleStore::sqlHash("select * from t where n = 'it''s' and a in (?, ?)"));
    }

    public function test_comments_and_hints_change_the_hash(): void
    {
        $plain = PlanSampleStore::sqlHash('select * from t where a in (?, ?)');
        $hinted = PlanSampleStore::sqlHash('select /*+ JOIN_ORDER(t, u) */ * from t where a in (?, ?)');

        $this->assertNotSame($plain, $hinted);
        $this->assertNotSame($hinted, PlanSampleStore::sqlHash('select /*+ JOIN_ORDER(u, t) */ * from t where a in (?, ?)'));
        $this->assertNotSame($plain, PlanSampleStore::sqlHash("select * from t where a in (?, ?) -- fix\n"));
        $this->assertSame($hinted, PlanSampleStore::sqlHash("select /*+  JOIN_ORDER(t, u)\n */ * from t where a in (?, ?, ?)"));
    }

    public function test_hash_fits_the_column(): void
    {
        $this->assertSame(32, strlen(PlanSampleStore::sqlHash('select 1')));
    }
}
