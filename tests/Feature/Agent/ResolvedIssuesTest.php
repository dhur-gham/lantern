<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Agent;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lantern\Detection\Detectors\MissingIndexDetector;
use Lantern\Detection\Detectors\NPlusOneDetector;
use Lantern\Detection\Detectors\PlanFlipDetector;
use Lantern\Detection\Detectors\SlowQueryDetector;
use Lantern\Detection\Issue;
use Lantern\Storage\DatabaseTraceStore;
use Lantern\Trace\Recorder;
use Lantern\Trace\SpanType;
use Lantern\Trace\Trace;

final class ResolvedIssuesTest extends AgentTestCase
{
    private const FP = 'fp-orders-by-merchant';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['config']->set('lantern.explain', true);
        $this->app['config']->set('lantern.detectors', [NPlusOneDetector::class, SlowQueryDetector::class, MissingIndexDetector::class, PlanFlipDetector::class]);
    }

    /** @param list<array<string, mixed>> $queries */
    private function trace(array $queries): Trace
    {
        $recorder = new Recorder;
        $trace = $recorder->begin(['kind' => 'request', 'method' => 'GET', 'path' => '/orders', 'status' => 200]);
        foreach ($queries as $q) {
            $ms = (float) ($q['ms'] ?? 10.0);
            $now = hrtime(true);
            $recorder->recordClosed(SpanType::Query, 'q', $now, $now + (int) ($ms * 1_000_000), [
                'sql' => $q['sql'] ?? 'select * from "orders" where "merchant_id" in (?, ?)',
                'bindings' => [], 'fingerprint' => $q['fp'] ?? self::FP, 'connection' => 'testing', 'time_ms' => $ms, 'failed' => false,
            ] + (isset($q['in']) ? ['in_size' => $q['in']] : []), ['file' => base_path($q['file'] ?? 'app/Orders.php'), 'line' => $q['line'] ?? 10, 'function' => $q['fn'] ?? 'Orders@load']);
        }
        $recorder->end();

        return $trace;
    }

    /** @param list<Issue> $issues */
    private function persist(Trace $trace, array $issues = []): void
    {
        (new DatabaseTraceStore($this->app['db']))->store($trace, $issues);
    }

    private function slowIssue(int $line = 10, string $fp = self::FP): Issue
    {
        return new Issue(
            Issue::TYPE_SLOW,
            Issue::key(Issue::TYPE_SLOW, $fp, 'app/Orders.php:'.$line),
            'Slow query',
            Issue::SEV_CRITICAL,
            1,
            300.0,
            ['file' => base_path('app/Orders.php'), 'line' => $line, 'function' => 'Orders@load'],
            [2],
            ['fingerprint' => $fp],
        );
    }

    private function planIssue(string $type, string $fp = self::FP): Issue
    {
        return new Issue($type, Issue::key($type, $fp), $type.' finding', Issue::SEV_CRITICAL, 1, 900.0, ['file' => base_path('app/Orders.php'), 'line' => 10, 'function' => 'Orders@load'], [2], ['fingerprint' => $fp]);
    }

    private function resolvedAt(string $key): ?string
    {
        $value = DB::table('lantern_issues')->where('issue_key', $key)->value('resolved_at');

        return $value === null ? null : (string) $value;
    }

    public function test_a_query_that_runs_clean_resolves_its_slow_query_issue_even_when_the_line_moved(): void
    {
        $issue = $this->slowIssue();
        $this->persist($this->trace([['ms' => 300.0]]), [$issue]);
        $this->assertNull($this->resolvedAt($issue->key));

        $this->persist($this->trace([['ms' => 20.0, 'line' => 15]]));

        $this->assertNotNull($this->resolvedAt($issue->key));
    }

    public function test_resolved_issues_are_hidden_by_default_and_listed_on_request(): void
    {
        $issue = $this->slowIssue();
        $this->persist($this->trace([['ms' => 300.0]]), [$issue]);
        $this->persist($this->trace([['ms' => 20.0]]));

        [$code, $data] = $this->jsonOut('lantern:issues');
        $this->assertSame(0, $code);
        $this->assertSame(0, $data['total']);
        $this->assertStringContainsString('1 resolved issue is hidden', $data['hint']);
        $this->assertStringContainsString('--include-resolved', $data['hint']);

        [, $all] = $this->jsonOut('lantern:issues', ['--include-resolved' => true, '--full' => true]);
        $this->assertSame(1, $all['total']);
        $this->assertSame('resolved', $all['issues'][0]['status']);
        $this->assertNotNull($all['issues'][0]['resolved_at']);

        [, $only] = $this->jsonOut('lantern:issues', ['--resolved' => true]);
        $this->assertSame([$issue->key], array_column($only['issues'], 'id'));

        [$code] = $this->run_('lantern:issues', ['--resolved' => true, '--include-resolved' => true, '--format' => 'json']);
        $this->assertSame(2, $code);

        [$code, $out] = $this->run_('lantern:issues');
        $this->assertSame(0, $code);
        $this->assertStringContainsString('1 resolved', $out);
    }

    public function test_lantern_issue_reports_the_status(): void
    {
        $issue = $this->slowIssue();
        $this->persist($this->trace([['ms' => 300.0]]), [$issue]);

        [, $open] = $this->jsonOut('lantern:issue', ['key' => $issue->key]);
        $this->assertSame('open', $open['issue']['status']);
        $this->assertArrayHasKey('next', $open);

        $this->persist($this->trace([['ms' => 20.0]]));

        [, $resolved] = $this->jsonOut('lantern:issue', ['key' => substr($issue->key, 0, 8)]);
        $this->assertSame('resolved', $resolved['issue']['status']);
        $this->assertNotNull($resolved['issue']['resolved_at']);
        $this->assertStringContainsString('Resolved', $resolved['hint']);
        $this->assertArrayNotHasKey('next', $resolved);
    }

    public function test_a_finding_that_fires_again_reopens_the_issue(): void
    {
        $issue = $this->slowIssue();
        $this->persist($this->trace([['ms' => 300.0]]), [$issue]);
        $this->persist($this->trace([['ms' => 20.0]]));
        $this->assertNotNull($this->resolvedAt($issue->key));

        $this->persist($this->trace([['ms' => 400.0]]), [$issue]);

        $this->assertNull($this->resolvedAt($issue->key));
        [, $data] = $this->jsonOut('lantern:issues');
        $this->assertSame([$issue->key], array_column($data['issues'], 'id'));
        $this->assertSame('open', $data['issues'][0]['status']);
    }

    public function test_the_same_query_at_another_call_site_does_not_resolve_the_issue(): void
    {
        $issue = $this->slowIssue();
        $this->persist($this->trace([['ms' => 300.0]]), [$issue]);

        $this->persist($this->trace([['ms' => 5.0, 'file' => 'app/Reports.php', 'fn' => 'Reports@run']]));
        $this->persist($this->trace([['ms' => 5.0, 'fp' => 'another-shape']]));

        $this->assertNull($this->resolvedAt($issue->key));
    }

    public function test_a_shape_that_still_fires_at_a_moved_line_does_not_resolve_the_old_row(): void
    {
        $old = $this->slowIssue(10);
        $this->persist($this->trace([['ms' => 300.0]]), [$old]);

        $this->persist($this->trace([['ms' => 300.0, 'line' => 15]]), [$this->slowIssue(15)]);

        $this->assertNull($this->resolvedAt($old->key));
    }

    public function test_plan_findings_resolve_only_when_explain_looked_at_the_query(): void
    {
        $flip = $this->planIssue(Issue::TYPE_PLAN_FLIP);
        $notChosen = $this->planIssue(Issue::TYPE_INDEX_NOT_CHOSEN);
        $this->persist($this->trace([['ms' => 900.0]]), [$flip, $notChosen]);

        $this->app['config']->set('lantern.explain', false);
        $this->persist($this->trace([['in' => 41, 'ms' => 5.0]]));
        $this->assertNull($this->resolvedAt($flip->key));
        $this->assertNull($this->resolvedAt($notChosen->key));

        $this->app['config']->set('lantern.explain', true);
        $this->persist($this->trace([['ms' => 5.0, 'sql' => 'update "orders" set "a" = 1']]));
        $this->assertNull($this->resolvedAt($notChosen->key), 'only SELECTs are explained');

        $this->persist($this->trace([['ms' => 5.0]]));
        $this->assertNotNull($this->resolvedAt($notChosen->key));
        $this->assertNull($this->resolvedAt($flip->key), 'plan_flip needs an IN list that was sampled');

        $this->persist($this->trace([['in' => 41, 'ms' => 5.0]]));
        $this->assertNotNull($this->resolvedAt($flip->key));
    }

    public function test_a_trace_at_the_issue_cap_proves_nothing(): void
    {
        $issue = $this->slowIssue();
        $this->persist($this->trace([['ms' => 300.0]]), [$issue]);
        $this->app['config']->set('lantern.thresholds.max_issues_per_trace', 1);

        $other = new Issue(Issue::TYPE_DUPLICATE, 'dup-1', 'Duplicate', Issue::SEV_WARNING, 2, 4.0, null, [3]);
        $this->persist($this->trace([['ms' => 20.0]]), [$other]);

        $this->assertNull($this->resolvedAt($issue->key));
    }

    public function test_a_detector_that_is_not_running_never_resolves_its_issues(): void
    {
        $issue = $this->slowIssue();
        $this->persist($this->trace([['ms' => 300.0]]), [$issue]);
        $this->app['config']->set('lantern.detectors', [NPlusOneDetector::class]);

        $this->persist($this->trace([['ms' => 20.0]]));

        $this->assertNull($this->resolvedAt($issue->key));
    }

    public function test_the_check_gate_ignores_resolved_issues(): void
    {
        $this->seedTrace('t1', 6, ['k1']);
        $this->seedIssue('k1', traceId: 't1');

        [$code] = $this->jsonOut('lantern:check');
        $this->assertSame(1, $code);
        [$code] = $this->jsonOut('lantern:check', ['--route' => 'posts.index']);
        $this->assertSame(1, $code);

        DB::table('lantern_issues')->where('issue_key', 'k1')->update(['resolved_at' => '2026-01-03 00:00:00']);

        [$code, $data] = $this->jsonOut('lantern:check');
        $this->assertSame(0, $code);
        $this->assertSame(0, $data['scope']['issues_checked']);
        [$code] = $this->jsonOut('lantern:check', ['--route' => 'posts.index']);
        $this->assertSame(0, $code, 'an older trace that still lists the finding must not fail the gate');
    }

    public function test_issue_prefix_lookup_finds_resolved_issues(): void
    {
        $this->seedIssue('abcdef123456');
        DB::table('lantern_issues')->update(['resolved_at' => '2026-01-03 00:00:00']);

        [$code, $data] = $this->jsonOut('lantern:issue', ['key' => 'abcdef']);

        $this->assertSame(0, $code);
        $this->assertSame('resolved', $data['issue']['status']);
    }

    public function test_the_store_keeps_working_before_the_migration_has_run(): void
    {
        (require dirname(__DIR__, 3).'/database/migrations/2024_01_01_000006_add_resolved_state_to_lantern_issues_table.php')->down();
        $this->assertFalse(Schema::hasColumn('lantern_issues', 'resolved_at'));
        $this->assertFalse(Schema::hasColumn('lantern_issues', 'shape'));

        $issue = $this->slowIssue();
        $store = new DatabaseTraceStore($this->app['db']);
        $store->store($this->trace([['ms' => 300.0]]), [$issue]);
        $store->store($this->trace([['ms' => 300.0]]), [$issue]);
        $store->store($this->trace([['ms' => 20.0]]), []);

        $this->assertSame(3, DB::table('lantern_traces')->count());
        $this->assertSame(2, (int) DB::table('lantern_issues')->value('occurrences'));

        [$code, $data] = $this->jsonOut('lantern:issues');
        $this->assertSame(0, $code);
        $this->assertSame('open', $data['issues'][0]['status']);
        [, $none] = $this->jsonOut('lantern:issues', ['--resolved' => true]);
        $this->assertSame(0, $none['total']);
    }

    public function test_a_first_write_without_issues_falls_back_when_the_resolve_columns_are_missing(): void
    {
        (require dirname(__DIR__, 3).'/database/migrations/2024_01_01_000006_add_resolved_state_to_lantern_issues_table.php')->down();

        $store = new DatabaseTraceStore($this->app['db']);
        $store->store($this->trace([['ms' => 20.0]]), []);
        $store->store($this->trace([['ms' => 20.0]]), [$this->slowIssue()]);

        $this->assertSame(2, DB::table('lantern_traces')->count());
        $this->assertSame(1, DB::table('lantern_issues')->count());
    }

    public function test_migration_backfills_shapes_for_key_based_types_and_is_repeatable(): void
    {
        $migration = require dirname(__DIR__, 3).'/database/migrations/2024_01_01_000006_add_resolved_state_to_lantern_issues_table.php';
        $migration->down();
        $this->seedIssue('pf-key', Issue::TYPE_PLAN_FLIP);
        $this->seedIssue('inc-key', Issue::TYPE_INDEX_NOT_CHOSEN);
        $this->seedIssue('slow-key', Issue::TYPE_SLOW);

        $migration->up();
        $migration->up();

        $this->assertSame('pf-key', DB::table('lantern_issues')->where('issue_key', 'pf-key')->value('shape'));
        $this->assertSame('inc-key', DB::table('lantern_issues')->where('issue_key', 'inc-key')->value('shape'));
        $this->assertNull(DB::table('lantern_issues')->where('issue_key', 'slow-key')->value('shape'));
        $this->assertNull(DB::table('lantern_issues')->where('issue_key', 'pf-key')->value('resolved_at'));
        $this->assertTrue(Schema::hasIndex('lantern_issues', ['shape']));
        $this->assertTrue(Schema::hasIndex('lantern_issues', ['resolved_at']));
    }

    public function test_a_backfilled_plan_issue_resolves_on_the_next_clean_run(): void
    {
        $migration = require dirname(__DIR__, 3).'/database/migrations/2024_01_01_000006_add_resolved_state_to_lantern_issues_table.php';
        $migration->down();
        $key = Issue::key(Issue::TYPE_INDEX_NOT_CHOSEN, self::FP);
        $this->seedIssue($key, Issue::TYPE_INDEX_NOT_CHOSEN);
        $migration->up();

        $this->persist($this->trace([['ms' => 5.0]]));

        $this->assertNotNull($this->resolvedAt($key));
    }

    private function inSql(int $n, string $hint = ''): string
    {
        return 'select '.$hint.' * from "orders" where "merchant_id" in ('.implode(', ', array_fill(0, $n, '?')).')';
    }

    public function test_a_smaller_input_does_not_resolve_a_slow_query(): void
    {
        $issue = $this->slowIssue();
        $this->persist($this->trace([['ms' => 300.0, 'in' => 41, 'sql' => $this->inSql(41)]]), [$issue]);

        $this->persist($this->trace([['ms' => 20.0, 'in' => 7, 'sql' => $this->inSql(7)]]));

        $this->assertNull($this->resolvedAt($issue->key));
    }

    public function test_a_finding_that_fires_again_on_a_smaller_input_keeps_the_largest_baseline(): void
    {
        $issue = $this->slowIssue();
        $this->persist($this->trace([['ms' => 300.0, 'in' => 41, 'sql' => $this->inSql(41)]]), [$issue]);
        $this->persist($this->trace([['ms' => 150.0, 'in' => 7, 'sql' => $this->inSql(7)]]), [$issue]);
        $this->assertSame(41, (int) DB::table('lantern_issues')->where('issue_key', $issue->key)->value('in_size'));

        $this->persist($this->trace([['ms' => 20.0, 'in' => 7, 'sql' => $this->inSql(7)]]));
        $this->assertNull($this->resolvedAt($issue->key), 'seven merchants running clean still proves nothing about forty-one');

        $this->persist($this->trace([['ms' => 20.0, 'in' => 41, 'sql' => $this->inSql(41)]]));
        $this->assertNotNull($this->resolvedAt($issue->key));
    }

    public function test_a_larger_input_raises_the_baseline(): void
    {
        $issue = $this->slowIssue();
        $this->persist($this->trace([['ms' => 300.0, 'in' => 7, 'sql' => $this->inSql(7)]]), [$issue]);
        $this->persist($this->trace([['ms' => 400.0, 'in' => 41, 'sql' => $this->inSql(41)]]), [$issue]);

        $this->assertSame(41, (int) DB::table('lantern_issues')->where('issue_key', $issue->key)->value('in_size'));
    }

    public function test_a_smaller_input_does_not_resolve_a_plan_finding(): void
    {
        $issue = $this->planIssue(Issue::TYPE_INDEX_NOT_CHOSEN);
        $this->persist($this->trace([['ms' => 300.0, 'in' => 41, 'sql' => $this->inSql(41)]]), [$issue]);

        $this->persist($this->trace([['ms' => 20.0, 'in' => 7, 'sql' => $this->inSql(7)]]));

        $this->assertNull($this->resolvedAt($issue->key));
    }

    public function test_the_same_or_a_larger_input_running_clean_resolves(): void
    {
        $issue = $this->slowIssue();
        $this->persist($this->trace([['ms' => 300.0, 'in' => 41, 'sql' => $this->inSql(41)]]), [$issue]);

        $this->persist($this->trace([['ms' => 20.0, 'in' => 41, 'sql' => $this->inSql(41)]]));

        $this->assertNotNull($this->resolvedAt($issue->key));
    }

    public function test_changed_sql_text_resolves_even_with_a_smaller_input(): void
    {
        $issue = $this->slowIssue();
        $this->persist($this->trace([['ms' => 300.0, 'in' => 41, 'sql' => $this->inSql(41)]]), [$issue]);

        $this->persist($this->trace([['ms' => 20.0, 'in' => 7, 'sql' => $this->inSql(7, '/*+ JOIN_ORDER(a, b) */')]]));

        $this->assertNotNull($this->resolvedAt($issue->key));
    }

    public function test_a_finding_stored_without_input_facts_resolves_as_before(): void
    {
        $issue = $this->slowIssue();
        $this->persist($this->trace([['ms' => 300.0, 'in' => 41, 'sql' => $this->inSql(41)]]), [$issue]);
        DB::table('lantern_issues')->update(['in_size' => null, 'sql_hash' => null]);

        $this->persist($this->trace([['ms' => 20.0, 'in' => 7, 'sql' => $this->inSql(7)]]));

        $this->assertNotNull($this->resolvedAt($issue->key));
    }

    public function test_a_query_without_an_in_list_resolves_normally(): void
    {
        $issue = $this->slowIssue();
        $this->persist($this->trace([['ms' => 300.0, 'sql' => 'select * from "orders" where "id" > ?']]), [$issue]);

        $this->persist($this->trace([['ms' => 20.0, 'sql' => 'select * from "orders" where "id" > ?']]));

        $this->assertNotNull($this->resolvedAt($issue->key));
    }
}
