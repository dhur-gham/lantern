<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Agent;

use Lantern\Agent\Redactor;

final class TraceDiffCheckTest extends AgentTestCase
{
    public function test_trace_summary_schema_and_grouping(): void
    {
        $this->seedTrace('t1', 51, ['k1']);

        [$code, $data] = $this->jsonOut('lantern:trace', ['id' => 't1', '--summary' => true]);

        $this->assertSame(0, $code);
        $this->assertSame(
            ['schema_version', 'schema', 'trace_id', 'started_at', 'method', 'path', 'route', 'status', 'duration_ms', 'query_count', 'query_ms', 'peak_memory', 'issue_count', 'issues', 'query_groups', 'truncated', 'next'],
            array_keys($data),
        );
        $this->assertSame(51, $data['query_count']);
        $this->assertCount(1, $data['query_groups']);
        $this->assertSame(51, $data['query_groups'][0]['count']);
        $this->assertSame('k1', $data['query_groups'][0]['issue_id']);
        $this->assertSame('k1', $data['issues'][0]['id']);
        $this->assertStringNotContainsString('jane@example.com', (string) json_encode($data));
    }

    public function test_default_format_json_is_summary_and_last_resolves(): void
    {
        $this->seedTrace('old', 1, [], 10, '2026-01-01 10:00:00');
        $this->seedTrace('new', 2, [], 10, '2026-01-02 10:00:00');

        [, $data] = $this->jsonOut('lantern:trace', ['--last' => true]);

        $this->assertSame('lantern.trace.summary', $data['schema']);
        $this->assertSame('new', $data['trace_id']);
    }

    public function test_tree_collapses_repeated_queries(): void
    {
        $this->seedTrace('t1', 51);

        [, $data] = $this->jsonOut('lantern:trace', ['id' => 't1', '--tree' => true]);

        $children = $data['tree']['children'];
        $this->assertCount(1, $children);
        $this->assertSame('query_group', $children[0]['type']);
        $this->assertSame(51, $children[0]['count']);
        $this->assertSame(76.5, $children[0]['total_ms']);

        [, $text] = $this->run_('lantern:trace', ['id' => 't1', '--tree' => true, '--format' => 'markdown']);
        $this->assertStringContainsString('query x51', $text);

        [, $data] = $this->jsonOut('lantern:trace', ['id' => 't1', '--tree' => true, '--depth' => 0]);
        $this->assertSame(51, $data['tree']['children_omitted']);
    }

    public function test_spans_limit_and_truncation(): void
    {
        $this->seedTrace('t1', 30);

        [, $data] = $this->jsonOut('lantern:trace', ['id' => 't1', '--spans' => true, '--limit' => 5]);

        $this->assertSame(31, $data['total']);
        $this->assertSame(5, $data['returned']);
        $this->assertTrue($data['truncated']);
        $this->assertArrayHasKey('hint', $data);

        [, $data] = $this->jsonOut('lantern:trace', ['id' => 't1', '--spans' => true, '--min-ms' => 10, '--fields' => 'id,name']);
        $this->assertSame([['id' => 1, 'name' => 'GET /posts']], $data['spans']);
    }

    public function test_trace_errors(): void
    {
        [$code, $data] = $this->jsonOut('lantern:trace', ['--last' => true]);
        $this->assertSame(3, $code);
        $this->assertSame('no_data', $data['error']['code']);

        $this->seedTrace('t1', 1);
        [$code, $data] = $this->jsonOut('lantern:trace', ['id' => 'missing']);
        $this->assertSame(2, $code);
        $this->assertSame('not_found', $data['error']['code']);

        [$code] = $this->jsonOut('lantern:trace', ['id' => 't1', '--tree' => true, '--spans' => true]);
        $this->assertSame(2, $code);
    }

    public function test_diff_improved_and_regressed(): void
    {
        $this->seedTrace('before', 51, ['k1', 'k2'], 100, '2026-01-01 10:00:00');
        $this->seedTrace('after', 3, ['k2', 'k3'], 20, '2026-01-01 11:00:00');

        [$code, $data] = $this->jsonOut('lantern:diff', ['before' => 'before', 'after' => 'after']);

        $this->assertSame(0, $code);
        $this->assertSame(['schema_version', 'schema', 'verdict', 'before', 'after', 'dimensions', 'queries', 'query_ms', 'duration_ms', 'resolved', 'new', 'unchanged'], array_keys($data));
        $this->assertSame(['before' => 51, 'after' => 3, 'delta' => -48], $data['queries']);
        $this->assertSame(['before' => 100, 'after' => 20, 'delta' => -80], $data['duration_ms']);
        $this->assertSame(['k1'], array_column($data['resolved'], 'id'));
        $this->assertSame(['k3'], array_column($data['new'], 'id'));
        $this->assertSame(['k2'], array_column($data['unchanged'], 'id'));
        $this->assertSame('improved', $data['verdict']);
        $this->assertSame(['queries' => 'improved', 'query_ms' => 'improved', 'findings' => ['resolved' => 1, 'new' => 1, 'verdict' => 'same']], $data['dimensions']);

        $this->seedTrace('worse', 3, ['k9'], 20, '2026-01-01 13:00:00');
        $this->seedTrace('base', 3, [], 20, '2026-01-01 14:00:00');
        [, $data] = $this->jsonOut('lantern:diff', ['before' => 'base', 'after' => 'worse']);
        $this->assertSame('regressed', $data['verdict']);

        [, $data] = $this->jsonOut('lantern:diff', ['before' => 'after', 'after' => 'after']);
        $this->assertSame('same', $data['verdict']);

        $this->seedTrace('clean', 3, [], 20, '2026-01-01 12:00:00');
        [, $data] = $this->jsonOut('lantern:diff', ['before' => 'before', 'after' => 'clean']);
        $this->assertSame('improved', $data['verdict']);
        $this->assertSame(['k1', 'k2'], array_column($data['resolved'], 'id'));
    }

    public function test_diff_errors(): void
    {
        [$code, $data] = $this->jsonOut('lantern:diff', ['before' => 'a', 'after' => 'b']);
        $this->assertSame(2, $code);
        $this->assertSame('not_found', $data['error']['code']);
    }

    public function test_check_no_data_exit_3(): void
    {
        [$code, $data] = $this->jsonOut('lantern:check');

        $this->assertSame(3, $code);
        $this->assertSame('no_data', $data['error']['code']);
    }

    public function test_check_pass_and_fail_on_severity(): void
    {
        $this->seedTrace('t1', 5);
        $this->seedIssue('w', 'slow_query', 'warning');

        [$code, $data] = $this->jsonOut('lantern:check');
        $this->assertSame(0, $code);
        $this->assertTrue($data['pass']);
        $this->assertSame(
            ['schema_version', 'schema', 'pass', 'exit_code', 'thresholds', 'scope', 'violation_count', 'truncated', 'violations'],
            array_keys($data),
        );

        [$code, $data] = $this->jsonOut('lantern:check', ['--fail-on' => 'warning']);
        $this->assertSame(1, $code);
        $this->assertFalse($data['pass']);
        $this->assertSame('issue', $data['violations'][0]['rule']);
        $this->assertSame('w', $data['violations'][0]['issue_id']);
    }

    public function test_check_thresholds_from_flags_and_config(): void
    {
        $this->seedTrace('t1', 30, [], 500);

        [$code, $data] = $this->jsonOut('lantern:check', ['--max-queries' => 10, '--max-ms' => 100]);
        $this->assertSame(1, $code);
        $this->assertSame(['max_queries', 'max_ms'], array_column($data['violations'], 'rule'));
        $this->assertSame(['limit' => 10, 'actual' => 30, 'trace_id' => 't1'], array_intersect_key($data['violations'][0], array_flip(['limit', 'actual', 'trace_id'])));

        [$code] = $this->jsonOut('lantern:check', ['--max-queries' => 30, '--max-ms' => 500]);
        $this->assertSame(0, $code);

        config(['lantern.check.max_queries' => 5]);
        [$code] = $this->jsonOut('lantern:check');
        $this->assertSame(1, $code);
        [$code] = $this->jsonOut('lantern:check', ['--max-queries' => 99]);
        $this->assertSame(0, $code);
    }

    public function test_check_usage_errors_exit_2(): void
    {
        $this->seedTrace('t1', 1);

        foreach ([['--max-queries' => 'x'], ['--fail-on' => 'bogus'], ['--baseline' => 'does-not-exist.json'], ['--since' => 'zzz']] as $args) {
            [$code, $data] = $this->jsonOut('lantern:check', $args);
            $this->assertSame(2, $code, json_encode($args));
            $this->assertSame('usage_error', $data['error']['code']);
        }
    }

    public function test_check_baseline_and_no_new_issues(): void
    {
        $this->seedTrace('t1', 5);
        $this->seedIssue('old');
        $path = tempnam(sys_get_temp_dir(), 'lb');

        try {
            [$code] = $this->jsonOut('lantern:check', ['--write-baseline' => $path]);
            $this->assertSame(0, $code);
            $this->assertSame(['old'], json_decode((string) file_get_contents($path), true)['issues']);

            [$code] = $this->jsonOut('lantern:check', ['--baseline' => $path, '--no-new-issues' => true]);
            $this->assertSame(0, $code);

            $this->seedIssue('fresh', 'slow_query', 'info');
            [$code, $data] = $this->jsonOut('lantern:check', ['--baseline' => $path, '--no-new-issues' => true]);
            $this->assertSame(1, $code);
            $this->assertSame('new_issue', $data['violations'][0]['rule']);
            $this->assertSame('fresh', $data['violations'][0]['issue_id']);
        } finally {
            @unlink($path);
        }
    }

    public function test_check_route_scope(): void
    {
        $this->seedTrace('a', 50, ['k1'], 40, '2026-01-01 10:00:00', 'posts.index');
        $this->seedTrace('b', 2, [], 40, '2026-01-01 11:00:00', 'home');

        [$code, $data] = $this->jsonOut('lantern:check', ['--route' => 'home', '--max-queries' => 10]);
        $this->assertSame(0, $code);
        $this->assertSame(1, $data['scope']['traces_checked']);

        [$code, $data] = $this->jsonOut('lantern:check', ['--route' => 'posts.index']);
        $this->assertSame(1, $code);
        $this->assertSame('k1', $data['violations'][0]['issue_id']);

        [$code] = $this->jsonOut('lantern:check', ['--route' => 'nope']);
        $this->assertSame(3, $code);
    }

    public function test_check_violation_truncation(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->seedIssue("k$i");
        }

        [, $data] = $this->jsonOut('lantern:check', ['--limit' => 5]);

        $this->assertSame(30, $data['violation_count']);
        $this->assertCount(5, $data['violations']);
        $this->assertTrue($data['truncated']);
    }

    public function test_redactor(): void
    {
        $this->assertSame(
            "select * from `users` where `email` = ? and `id` = ? and x in (?, ...) limit ?",
            Redactor::sql("select * from `users` where `email` = 'a''b@c.com' and `id` = 42 and x in (1, 2, 3) limit 5"),
        );
        $this->assertSame('select * from t1 where a = ?', Redactor::sql('select * from t1 where a = 3.5'));
        $this->assertSame(['password' => '[redacted]', 0 => '[redacted:string]', 1 => true, 2 => null], Redactor::bindings(['password' => 'x', 0 => 'secretvalue', 1 => true, 2 => null]));
        $this->assertSame('[redacted]', Redactor::bindings(['api_token' => 'abc'], true)['api_token']);
        $this->assertSame('***@x.io', Redactor::mask('bob@x.io'));
        $this->assertSame('[token]', Redactor::mask('sk_live_abcdefghijklmnopqrstuvwxyz'));
        $this->assertSame('[number]', Redactor::mask('4111 1111 1111 1111'));
    }
}
