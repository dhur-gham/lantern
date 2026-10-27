<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Query;

use Illuminate\Support\Facades\DB;

require_once __DIR__.'/QueryTestCase.php';

final class LazyCallSiteTest extends QueryTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['lantern.call_site_mode' => 'lazy', 'lantern.thresholds.n_plus_one_min_repeats' => 3, 'lantern.thresholds.slow_query_ms' => 100000]);
    }

    public function test_fast_one_off_queries_carry_no_call_site(): void
    {
        $this->seedTables();
        $this->recorder()->begin();

        DB::select('select * from q_authors where id = ?', [1]);
        DB::select('select * from q_posts where id = ?', [1]);

        foreach ($this->queries($this->recorder()->trace()) as $span) {
            $this->assertNull($span->callSite);
        }
    }

    public function test_repeats_resolve_and_backfill_earlier_spans(): void
    {
        $this->seedTables();
        $this->recorder()->begin();

        $lines = [];
        for ($i = 1; $i <= 4; $i++) {
            $lines[] = __LINE__ + 1;
            DB::select('select * from q_authors where id = ?', [$i]);
        }

        $spans = $this->queries($this->recorder()->trace());
        $this->assertCount(4, $spans);
        foreach ($spans as $span) {
            $this->assertSame($lines[0], $span->callSite['line']);
            $this->assertStringEndsWith('LazyCallSiteTest.php', $span->callSite['file']);
        }
    }

    public function test_slow_query_resolves_call_site(): void
    {
        $this->seedTables();
        config(['lantern.thresholds.slow_query_ms' => 0.0]);
        $this->recorder()->begin();

        $line = __LINE__ + 1;
        DB::select('select * from q_authors where id = ?', [1]);

        $span = $this->queries($this->recorder()->trace())[0];
        $this->assertSame($line, $span->callSite['line']);
    }

    public function test_all_mode_always_resolves(): void
    {
        $this->seedTables();
        config(['lantern.call_site_mode' => 'all']);
        $this->recorder()->begin();

        DB::select('select 1');

        $this->assertNotNull($this->queries($this->recorder()->trace())[0]->callSite);
    }
}
