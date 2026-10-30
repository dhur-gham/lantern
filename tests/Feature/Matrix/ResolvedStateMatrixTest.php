<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Matrix;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lantern\Detection\Detectors\MissingIndexDetector;
use Lantern\Detection\Detectors\NPlusOneDetector;
use Lantern\Detection\Detectors\PlanFlipDetector;
use Lantern\Detection\Detectors\SlowQueryDetector;
use Lantern\Detection\Issue;
use Lantern\Storage\DatabaseTraceStore;
use Lantern\Storage\TraceQueries;
use Lantern\Trace\Trace;

final class ResolvedStateMatrixTest extends MatrixTestCase
{
    private const CREATE = ['2024_01_01_000001_create_lantern_traces_table', '2024_01_01_000002_create_lantern_issues_table'];

    private const RESOLVED = ['2024_01_01_000006_add_resolved_state_to_lantern_issues_table'];

    private const FP = 'fp-orders-by-merchant';

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrate('down', array_reverse([...self::CREATE, ...self::RESOLVED]));
        $this->migrate('up', [...self::CREATE, ...self::RESOLVED]);
        config([
            'lantern.explain' => true,
            'lantern.detectors' => [NPlusOneDetector::class, SlowQueryDetector::class, MissingIndexDetector::class, PlanFlipDetector::class],
        ]);
    }

    protected function tearDown(): void
    {
        if (self::driver() !== null && $this->app !== null) {
            $this->migrate('down', array_reverse([...self::CREATE, ...self::RESOLVED]));
        }
        parent::tearDown();
    }

    private function trace(float $ms = 20.0, int $line = 10, ?int $in = null): Trace
    {
        $trace = $this->queryTrace('select * from "orders" where "merchant_id" in (?, ?)', [], ['fingerprint' => self::FP, 'time_ms' => $ms] + ($in === null ? [] : ['in_size' => $in]));
        foreach ($trace->spans() as $span) {
            $span->callSite = ['file' => base_path('app/Orders.php'), 'line' => $line, 'function' => 'Orders@load'];
        }

        return $trace;
    }

    private function slow(int $line = 10): Issue
    {
        return new Issue(Issue::TYPE_SLOW, Issue::key(Issue::TYPE_SLOW, self::FP, 'app/Orders.php:'.$line), 'Slow query', Issue::SEV_CRITICAL, 1, 300.0, ['file' => base_path('app/Orders.php'), 'line' => $line, 'function' => 'Orders@load'], [2], ['fingerprint' => self::FP]);
    }

    private function plan(string $type): Issue
    {
        return new Issue($type, Issue::key($type, self::FP), $type.' finding', Issue::SEV_CRITICAL, 1, 900.0, ['file' => base_path('app/Orders.php'), 'line' => 10, 'function' => 'Orders@load'], [2], ['fingerprint' => self::FP]);
    }

    private function resolved(string $key): bool
    {
        return DB::table('lantern_issues')->where('issue_key', $key)->value('resolved_at') !== null;
    }

    public function test_migration_adds_indexed_columns_backfills_plan_shapes_and_is_repeatable(): void
    {
        foreach (['in_size', 'sql_hash'] as $column) {
            $this->assertTrue(Schema::hasColumn('lantern_issues', $column), $column);
        }
        foreach (['resolved_at', 'shape'] as $column) {
            $this->assertTrue(Schema::hasColumn('lantern_issues', $column), $column);
            $this->assertTrue(Schema::hasIndex('lantern_issues', [$column]), $column.' index');
        }

        $this->migrate('down', self::RESOLVED);
        $this->assertFalse(Schema::hasColumn('lantern_issues', 'resolved_at'));
        $this->assertFalse(Schema::hasColumn('lantern_issues', 'shape'));
        $now = date('Y-m-d H:i:s');
        foreach (['pf-key' => Issue::TYPE_PLAN_FLIP, 'inc-key' => Issue::TYPE_INDEX_NOT_CHOSEN, 'mi-key' => Issue::TYPE_MISSING_INDEX, 'slow-key' => Issue::TYPE_SLOW] as $key => $type) {
            DB::table('lantern_issues')->insert(['issue_key' => $key, 'type' => $type, 'title' => $key, 'severity' => 'warning', 'first_seen' => $now, 'last_seen' => $now]);
        }

        $this->migrate('up', self::RESOLVED);
        $this->migrate('up', self::RESOLVED);

        $shapes = DB::table('lantern_issues')->pluck('shape', 'issue_key')->all();
        $this->assertSame('pf-key', $shapes['pf-key']);
        $this->assertSame('inc-key', $shapes['inc-key']);
        $this->assertSame('mi-key', $shapes['mi-key']);
        $this->assertNull($shapes['slow-key']);
    }

    public function test_fixed_queries_resolve_and_refiring_findings_reopen(): void
    {
        $store = new DatabaseTraceStore($this->app['db']);
        $slow = $this->slow();
        $flip = $this->plan(Issue::TYPE_PLAN_FLIP);
        $notChosen = $this->plan(Issue::TYPE_INDEX_NOT_CHOSEN);

        $store->store($this->trace(300.0, 10, 41), [$slow, $flip, $notChosen]);
        foreach ([$slow, $flip, $notChosen] as $issue) {
            $this->assertFalse($this->resolved($issue->key), $issue->type);
        }

        $store->store($this->trace(15.0, 15, 41), []);
        foreach ([$slow, $flip, $notChosen] as $issue) {
            $this->assertTrue($this->resolved($issue->key), $issue->type.' resolves once the shape runs clean');
        }

        $queries = new TraceQueries($this->app['db']);
        $this->assertSame([], $queries->issues());
        $this->assertCount(3, $queries->issues(['resolved' => 'include']));
        $this->assertCount(3, $queries->issues(['resolved' => 'only']));
        $this->assertEqualsCanonicalizing([$slow->key, $flip->key, $notChosen->key], $queries->resolvedKeys([$slow->key, $flip->key, $notChosen->key, 'unknown']));

        $store->store($this->trace(400.0), [$slow]);
        $this->assertFalse($this->resolved($slow->key), 'the finding fired again');
        $this->assertTrue($this->resolved($flip->key));
        $this->assertSame([$slow->key], array_column($queries->issues(), 'issue_key'));
    }

    public function test_the_store_falls_back_when_the_columns_are_missing(): void
    {
        $this->migrate('down', self::RESOLVED);

        $issue = $this->slow();
        $first = new DatabaseTraceStore($this->app['db']);
        $first->store($this->trace(20.0), []);
        $first->store($this->trace(300.0), [$issue]);
        (new DatabaseTraceStore($this->app['db']))->store($this->trace(300.0), [$issue]);

        $this->assertSame(3, DB::table('lantern_traces')->count());
        $this->assertSame(2, (int) DB::table('lantern_issues')->where('issue_key', $issue->key)->value('occurrences'));

        $queries = new TraceQueries($this->app['db']);
        $this->assertCount(1, $queries->issues());
        $this->assertSame([], $queries->issues(['resolved' => 'only']));
        $this->assertSame([], $queries->resolvedKeys([$issue->key]));
    }

    public function test_a_smaller_input_does_not_resolve_a_finding(): void
    {
        $store = new DatabaseTraceStore($this->app['db']);
        $slow = $this->slow();
        $notChosen = $this->plan(Issue::TYPE_INDEX_NOT_CHOSEN);

        $store->store($this->trace(870.0, 10, 41), [$slow, $notChosen]);
        $this->assertSame(41, (int) DB::table('lantern_issues')->where('issue_key', $slow->key)->value('in_size'));

        $store->store($this->trace(15.0, 10, 7), []);
        $this->assertFalse($this->resolved($slow->key), 'seven merchants running clean proves nothing about forty-one');
        $this->assertFalse($this->resolved($notChosen->key));

        $store->store($this->trace(15.0, 10, 41), []);
        $this->assertTrue($this->resolved($slow->key));
        $this->assertTrue($this->resolved($notChosen->key));
    }
}
