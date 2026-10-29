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
        $this->assertSame(['schema_version', 'schema', 'verdict', 'before', 'after', 'dimensions', 'queries', 'query_ms', 'duration_ms', 'resolved', 'new', 'changed', 'threshold', 'unchanged'], array_keys($data));
        $this->assertSame(['before' => 51, 'after' => 3, 'delta' => -48], $data['queries']);
        $this->assertSame(['before' => 100, 'after' => 20, 'delta' => -80], $data['duration_ms']);
        $this->assertSame(['k1'], array_column($data['resolved'], 'id'));
        $this->assertSame(['k3'], array_column($data['new'], 'id'));
        $this->assertSame([], $data['unchanged']);
        $this->assertSame(['k2'], array_column($data['changed'], 'id'));
        $this->assertSame('improved', $data['changed'][0]['status']);
        $this->assertSame(51, $data['changed'][0]['before']['count']);
        $this->assertSame(3, $data['changed'][0]['after']['count']);
        $this->assertSame('improved', $data['verdict']);
        $this->assertSame(['queries' => 'improved', 'query_ms' => 'improved', 'duration_ms' => 'improved', 'findings' => ['resolved' => 1, 'new' => 1, 'improved' => 1, 'regressed' => 0, 'verdict' => 'improved']], $data['dimensions']);

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

    /** @param array<string, mixed> $over */
    private function seedShapeTrace(string $id, string $at, array $over = []): void
    {
        $this->seedTrace($id, 3, [], 100, $at);
        $issue = array_replace([
            'key' => 'key-'.$id, 'type' => 'slow_query', 'severity' => 'warning', 'title' => 'Slow query at app/Foo.php', 'count' => 1, 'total_ms' => 100.0,
            'call_site' => ['file' => base_path('app/Foo.php'), 'line' => 121, 'function' => 'Foo@bar'], 'span_ids' => [2],
            'meta' => ['fingerprint' => 'fpq'], 'suggestion' => null,
        ], $over);
        $payload = json_decode((string) \Illuminate\Support\Facades\DB::table('lantern_traces')->where('id', $id)->value('payload'), true);
        $payload['meta']['issues'] = [$issue];
        \Illuminate\Support\Facades\DB::table('lantern_traces')->where('id', $id)->update(['payload' => json_encode($payload), 'issue_count' => 1]);
    }

    public function test_diff_matches_a_moved_query_instead_of_resolved_plus_new(): void
    {
        $this->seedShapeTrace('b1', '2026-01-01 10:00:00', ['total_ms' => 2731.0]);
        $this->seedShapeTrace('b2', '2026-01-01 11:00:00', [
            'total_ms' => 247.0,
            'call_site' => ['file' => base_path('app/Foo.php'), 'line' => 126, 'function' => 'Foo@bar'],
        ]);

        [, $data] = $this->jsonOut('lantern:diff', ['before' => 'b1', 'after' => 'b2']);

        $this->assertSame([], $data['resolved']);
        $this->assertSame([], $data['new']);
        $this->assertCount(1, $data['changed']);
        $this->assertSame('improved', $data['changed'][0]['status']);
        $this->assertSame('key-b1', $data['changed'][0]['before_id']);
        $this->assertSame('key-b2', $data['changed'][0]['id']);
        $this->assertEquals(2731.0, $data['changed'][0]['before']['total_ms']);
        $this->assertEquals(247.0, $data['changed'][0]['after']['total_ms']);
        $this->assertSame(121, $data['changed'][0]['before']['call_site']['line']);
        $this->assertSame(126, $data['changed'][0]['after']['call_site']['line']);
        $this->assertSame('improved', $data['verdict']);
        $this->assertSame(1, $data['dimensions']['findings']['improved']);
    }

    public function test_diff_marks_a_matched_finding_that_got_slower_as_regressed(): void
    {
        $this->seedShapeTrace('r1', '2026-01-01 10:00:00', ['total_ms' => 247.0]);
        $this->seedShapeTrace('r2', '2026-01-01 11:00:00', [
            'total_ms' => 2731.0,
            'call_site' => ['file' => base_path('app/Foo.php'), 'line' => 126, 'function' => 'Foo@bar'],
        ]);

        [, $data] = $this->jsonOut('lantern:diff', ['before' => 'r1', 'after' => 'r2']);

        $this->assertSame([], $data['new']);
        $this->assertSame('regressed', $data['changed'][0]['status']);
        $this->assertSame('regressed', $data['verdict']);
        $this->assertSame(1, $data['dimensions']['findings']['regressed']);
    }

    public function test_diff_noise_within_tolerance_is_unchanged_and_other_function_is_a_different_shape(): void
    {
        $this->seedShapeTrace('n1', '2026-01-01 10:00:00', ['total_ms' => 200.0]);
        $this->seedShapeTrace('n2', '2026-01-01 11:00:00', ['total_ms' => 210.0]);
        $this->seedShapeTrace('n3', '2026-01-01 12:00:00', [
            'total_ms' => 200.0,
            'call_site' => ['file' => base_path('app/Foo.php'), 'line' => 121, 'function' => 'Foo@other'],
        ]);

        [, $data] = $this->jsonOut('lantern:diff', ['before' => 'n1', 'after' => 'n2']);
        $this->assertSame('unchanged', $data['changed'][0]['status']);
        $this->assertSame(['key-n2'], array_column($data['unchanged'], 'id'));

        [, $data] = $this->jsonOut('lantern:diff', ['before' => 'n1', 'after' => 'n3']);
        $this->assertSame(['key-n1'], array_column($data['resolved'], 'id'));
        $this->assertSame(['key-n3'], array_column($data['new'], 'id'));
        $this->assertSame([], $data['changed']);
    }

    public function test_finding_shape_ignores_line_but_not_function_or_fingerprint(): void
    {
        $base = ['type' => 'n_plus_one', 'key' => 'x', 'title' => 't', 'call_site' => ['file' => base_path('app/Foo.php'), 'line' => 10, 'function' => 'A@b'], 'meta' => ['fingerprint' => 'f1']];
        $moved = array_replace($base, ['key' => 'y', 'call_site' => ['file' => base_path('app/Foo.php'), 'line' => 99, 'function' => 'A@b']]);

        $this->assertSame(\Lantern\Agent\Findings::shape($base), \Lantern\Agent\Findings::shape($moved));
        $this->assertNotSame(\Lantern\Agent\Findings::shape($base), \Lantern\Agent\Findings::shape(array_replace_recursive($base, ['call_site' => ['function' => 'A@c']])));
        $this->assertNotSame(\Lantern\Agent\Findings::shape($base), \Lantern\Agent\Findings::shape(array_replace_recursive($base, ['meta' => ['fingerprint' => 'f2']])));
        $this->assertSame('dup-key', \Lantern\Agent\Findings::shape(['type' => 'duplicate_query', 'key' => 'dup-key', 'call_site' => $base['call_site']]));
    }

    public function test_diff_ignores_timing_noise_and_threshold_findings(): void
    {
        $this->seedTrace('a', 3, [], 100, '2026-01-01 10:00:00');
        $this->seedTrace('b', 3, [], 110, '2026-01-01 11:00:00');

        [, $data] = $this->jsonOut('lantern:diff', ['before' => 'a', 'after' => 'b']);
        $this->assertSame('same', $data['dimensions']['duration_ms']);
        $this->assertSame('same', $data['verdict']);

        $key = \Lantern\Detection\Issue::key('missing_index', 'fp1');
        $this->seedTrace('c', 3, [$key], 100, '2026-01-01 12:00:00');
        \Illuminate\Support\Facades\DB::table('lantern_traces')->where('id', 'c')->update([
            'payload' => str_replace('"type":"n_plus_one"', '"type":"missing_index"', (string) \Illuminate\Support\Facades\DB::table('lantern_traces')->where('id', 'c')->value('payload')),
        ]);

        [, $data] = $this->jsonOut('lantern:diff', ['before' => 'a', 'after' => 'c']);
        $this->assertSame([], $data['new']);
        $this->assertSame([$key], array_column($data['threshold'], 'id'));
        $this->assertSame('same', $data['verdict']);
    }
}
