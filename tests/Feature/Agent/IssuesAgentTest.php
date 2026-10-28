<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Agent;

final class IssuesAgentTest extends AgentTestCase
{
    public function test_schema_snapshot(): void
    {
        $this->seedTrace('t1', 6, ['k1']);
        $this->seedIssue('k1', traceId: 't1');

        [$code, $data] = $this->jsonOut('lantern:issues');

        $this->assertSame(0, $code);
        $this->assertSame(['schema_version', 'schema', 'generated_at', 'total', 'returned', 'offset', 'truncated', 'issues'], array_keys($data));
        $this->assertSame(1, $data['schema_version']);
        $this->assertSame('lantern.issues', $data['schema']);
        $this->assertSame(['id', 'type', 'severity', 'title', 'call_site', 'occurrences', 'total_ms', 'suggestion'], array_keys($data['issues'][0]));
        $this->assertSame(['file' => 'app/Foo.php', 'line' => 12, 'function' => 'Foo@bar'], $data['issues'][0]['call_site']);
        $this->assertSame('k1', $data['issues'][0]['id']);
    }

    public function test_full_finding_snapshot(): void
    {
        $this->seedTrace('t1', 6, ['k1']);
        $this->seedIssue('k1', traceId: 't1');

        [, $data] = $this->jsonOut('lantern:issues', ['--full' => true]);

        $this->assertSame(
            ['id', 'type', 'severity', 'title', 'call_site', 'occurrences', 'count_total', 'total_ms', 'max_ms', 'suggestion', 'first_seen', 'last_seen', 'related_trace_ids', 'evidence'],
            array_keys($data['issues'][0]),
        );
        $this->assertSame(['t1'], $data['issues'][0]['related_trace_ids']);
        $this->assertSame(['trace_id', 'sample_sql', 'sql_shape', 'bindings', 'parent', 'avg_ms'], array_keys($data['issues'][0]['evidence']));
    }

    public function test_empty_store(): void
    {
        [$code, $data] = $this->jsonOut('lantern:issues');
        $this->assertSame(0, $code);
        $this->assertSame(0, $data['total']);
        $this->assertSame([], $data['issues']);

        [$code, $out] = $this->run_('lantern:issues');
        $this->assertSame(0, $code);
        $this->assertStringContainsString('No issues recorded yet.', $out);
    }

    public function test_large_store_pagination(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->seedIssue(sprintf('k%03d', $i), totalMs: (float) (1000 - $i));
        }

        [, $p1] = $this->jsonOut('lantern:issues', ['--limit' => 50]);
        $this->assertSame(120, $p1['total']);
        $this->assertSame(50, $p1['returned']);
        $this->assertTrue($p1['truncated']);
        $this->assertSame(50, $p1['next_offset']);
        $this->assertStringContainsString('--offset=50', $p1['hint']);
        $this->assertSame('k000', $p1['issues'][0]['id']);

        [, $p3] = $this->jsonOut('lantern:issues', ['--limit' => 50, '--offset' => 100]);
        $this->assertSame(20, $p3['returned']);
        $this->assertFalse($p3['truncated']);
        $this->assertArrayNotHasKey('next_offset', $p3);
        $this->assertSame('k100', $p3['issues'][0]['id']);

        [, $out] = $this->run_('lantern:issues', ['--limit' => 5, '--format' => 'ndjson']);
        $lines = explode("\n", $out);
        $this->assertCount(6, $lines);
        $this->assertSame('k000', json_decode($lines[0], true)['id']);
        $this->assertSame(120, json_decode($lines[5], true)['_meta']['total']);
    }

    public function test_filters_fields_and_since(): void
    {
        $this->seedIssue('a', 'n_plus_one', 'critical', 50.0);
        $this->seedIssue('b', 'slow_query', 'warning', 120.0, null, now()->subMinutes(5)->format('Y-m-d H:i:s'));

        [, $data] = $this->jsonOut('lantern:issues', ['--type' => 'slow_query', '--fields' => 'id,total_ms']);
        $this->assertEquals([['id' => 'b', 'total_ms' => 120.0]], $data['issues']);

        [, $data] = $this->jsonOut('lantern:issues', ['--since' => '1h']);
        $this->assertSame(['b'], array_column($data['issues'], 'id'));

        [$code, $data] = $this->jsonOut('lantern:issues', ['--fields' => 'nope']);
        $this->assertSame(2, $code);
        $this->assertSame('usage_error', $data['error']['code']);
        $this->assertSame(1, $data['schema_version']);

        [$code] = $this->jsonOut('lantern:issues', ['--since' => 'garbage']);
        $this->assertSame(2, $code);
        [$code] = $this->jsonOut('lantern:issues', ['--limit' => 'x']);
        $this->assertSame(2, $code);
    }

    public function test_table_and_markdown_formats(): void
    {
        $this->seedIssue('a');

        [, $table] = $this->run_('lantern:issues', ['--format' => 'table']);
        $this->assertStringContainsString('app/Foo.php:12', $table);
        $this->assertStringContainsString('| Title a', $table);

        [, $md] = $this->run_('lantern:issues', ['--format' => 'markdown']);
        $this->assertStringContainsString('| id | type |', $md);
        $this->assertStringNotContainsString("\e[", $md);
    }

    public function test_not_configured_is_json_error_exit_2(): void
    {
        config(['lantern.storage.driver' => 'memory']);

        [$code, $data] = $this->jsonOut('lantern:issues');

        $this->assertSame(2, $code);
        $this->assertSame('config_error', $data['error']['code']);
    }

    public function test_invalid_format_is_usage_error(): void
    {
        [$code, $out] = $this->run_('lantern:issues', ['--format' => 'xml']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('Invalid --format', $out);
    }

    public function test_issue_detail_redacts_evidence(): void
    {
        $this->seedTrace('t1', 6, ['k1']);
        $this->seedIssue('k1', traceId: 't1');

        [$code, $data] = $this->jsonOut('lantern:issue', ['key' => 'k1']);
        $e = $data['issue']['evidence'];

        $this->assertSame(0, $code);
        $this->assertSame('t1', $e['trace_id']);
        $this->assertSame('select * from `users` where `email` = ? and `id` = ?', $e['sample_sql']);
        $this->assertSame(['[redacted:string]', '[redacted:number]'], $e['bindings']);
        $this->assertSame('PostController@index', $e['parent']);
        $this->assertStringNotContainsString('jane@example.com', (string) json_encode($data));

        [, $data] = $this->jsonOut('lantern:issue', ['key' => 'k1', '--with-bindings' => true]);
        $this->assertSame(['***@example.com', 7], $data['issue']['evidence']['bindings']);
    }

    public function test_issue_prefix_and_not_found(): void
    {
        $this->seedIssue('abcdef123456');

        [$code, $data] = $this->jsonOut('lantern:issue', ['key' => 'abcdef']);
        $this->assertSame(0, $code);
        $this->assertSame('abcdef123456', $data['issue']['id']);

        [$code, $data] = $this->jsonOut('lantern:issue', ['key' => 'zzzzzz']);
        $this->assertSame(2, $code);
        $this->assertSame('not_found', $data['error']['code']);
    }
}
