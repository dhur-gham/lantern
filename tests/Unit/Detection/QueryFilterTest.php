<?php

declare(strict_types=1);

namespace Lantern\Tests\Unit\Detection;

use Lantern\Detection\Detectors\DuplicateQueryDetector;
use Lantern\Detection\Detectors\NPlusOneDetector;
use Lantern\Detection\Detectors\SlowQueryDetector;
use Lantern\Detection\QueryFilter;
use Lantern\Trace\Recorder;
use Lantern\Trace\SpanType;
use Lantern\Trace\Trace;
use PHPUnit\Framework\TestCase;

final class QueryFilterTest extends TestCase
{
    private function trace(string $sql, int $n = 6, float $ms = 150.0): Trace
    {
        $r = new Recorder;
        $r->begin([], 0);
        for ($i = 1; $i <= $n; $i++) {
            $r->recordClosed(SpanType::Query, $sql, 0, (int) ($ms * 1_000_000), [
                'sql' => $sql,
                'fingerprint' => md5($sql),
                'bindings_key' => md5('same'),
                'bindings' => ['same'],
                'time_ms' => $ms,
                'failed' => false,
            ], null);
        }

        return $r->end();
    }

    public function test_default_tables_are_ignored(): void
    {
        $f = new QueryFilter([]);
        foreach (['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'migrations', 'password_reset_tokens'] as $t) {
            $this->assertTrue($f->ignoresSql("select * from \"$t\" where \"id\" = ? limit 1"), $t);
        }
        $this->assertTrue($f->ignoresSql('insert into "sessions" ("id") values (?)'));
        $this->assertTrue($f->ignoresSql('update `jobs` set `attempts` = 1'));
        $this->assertFalse($f->ignoresSql('select * from "posts" where "id" = ?'));
        $this->assertFalse($f->ignoresSql('select * from "posts" where exists (select * from "sessions" where 1)'));
    }

    public function test_custom_tables_and_patterns(): void
    {
        $f = new QueryFilter(['ignore_tables' => ['audit_log'], 'ignore_sql_patterns' => ['/*skip*/', '/pg_sleep\(/i']]);
        $this->assertTrue($f->ignoresSql('select * from audit_log'));
        $this->assertFalse($f->ignoresSql('select * from sessions'));
        $this->assertTrue($f->ignoresSql('select 1 /*skip*/'));
        $this->assertTrue($f->ignoresSql('select PG_SLEEP(1)'));
    }

    public function test_detectors_skip_framework_tables(): void
    {
        $sql = 'select * from "sessions" where "id" = ? limit 1';
        $cfg = ['thresholds' => ['n_plus_one_min_repeats' => 5, 'duplicate_min_repeats' => 2, 'slow_query_ms' => 100]];

        $this->assertSame([], (new NPlusOneDetector($cfg))->detect($this->trace($sql)));
        $this->assertSame([], (new DuplicateQueryDetector($cfg))->detect($this->trace($sql)));
        $this->assertSame([], (new SlowQueryDetector($cfg))->detect($this->trace($sql)));

        // the same shape against an app table is still reported
        $app = 'select * from "posts" where "id" = ? limit 1';
        $this->assertNotSame([], (new NPlusOneDetector($cfg))->detect($this->trace($app)));
        $this->assertNotSame([], (new DuplicateQueryDetector($cfg))->detect($this->trace($app)));
        $this->assertNotSame([], (new SlowQueryDetector($cfg))->detect($this->trace($app)));
    }

    public function test_emptying_ignore_tables_reports_them_again(): void
    {
        $sql = 'select * from "sessions" where "id" = ? limit 1';
        $cfg = ['ignore_tables' => [], 'thresholds' => ['n_plus_one_min_repeats' => 5]];

        $this->assertNotSame([], (new NPlusOneDetector($cfg))->detect($this->trace($sql)));
    }
}
