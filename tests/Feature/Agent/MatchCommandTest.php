<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Agent;

use Illuminate\Support\Facades\DB;

final class MatchCommandTest extends AgentTestCase
{
    private const PROD = 'SELECT * FROM `users` WHERE `email` = %s AND `id` = %s';

    public function test_matches_a_query_with_an_issue(): void
    {
        $this->seedTrace('t1', 3, ['k1']);
        $this->seedIssue('k1', traceId: 't1');

        [$code, $d] = $this->jsonOut('lantern:match', ['--sql' => self::PROD]);

        $this->assertSame(0, $code);
        $this->assertSame('lantern.match', $d['schema']);
        $this->assertSame('exact', $d['match_type']);
        $this->assertSame('select * from `users` where `email` = ? and `id` = ?', $d['shape']);
        $m = $d['matches'][0];
        $this->assertSame('exact', $m['match_type']);
        $this->assertSame(['file' => 'app/Foo.php', 'line' => 12], $m['call_site']);
        $this->assertSame(3, $m['occurrences']);
        $this->assertSame('k1', $m['issue']['id']);
        $this->assertSame(['t1'], $m['trace_ids']);
        $this->assertStringContainsString('lantern:issue k1', $d['next']);
    }

    public function test_matches_a_span_without_an_issue(): void
    {
        $this->seedTrace('t1', 2);

        [$code, $d] = $this->jsonOut('lantern:match', ['--sql' => 'select * from users where email = :e and id = :i']);

        $this->assertSame(0, $code);
        $this->assertArrayNotHasKey('issue', $d['matches'][0]);
        $this->assertSame(2, $d['matches'][0]['occurrences']);
        $this->assertStringContainsString('lantern:trace t1', $d['next']);
    }

    public function test_resolved_issues_are_hidden_unless_included(): void
    {
        $this->seedTrace('t1', 2, ['k1']);
        $this->seedIssue('k1', traceId: 't1');
        DB::table('lantern_issues')->where('issue_key', 'k1')->update(['resolved_at' => '2026-01-03 00:00:00']);

        [, $d] = $this->jsonOut('lantern:match', ['--sql' => self::PROD]);
        $this->assertArrayNotHasKey('issue', $d['matches'][0]);
        $this->assertSame(1, $d['hidden_resolved']);

        [, $d] = $this->jsonOut('lantern:match', ['--sql' => self::PROD, '--include-resolved' => true]);
        $this->assertSame('resolved', $d['matches'][0]['issue']['status']);
    }

    public function test_similar_query_is_reported_as_similar(): void
    {
        $this->seedTrace('t1', 1);

        [$code, $d] = $this->jsonOut('lantern:match', ['--sql' => 'select * from users where email = ? and id = ? and active = ?']);

        $this->assertSame(0, $code);
        $this->assertSame('similar', $d['match_type']);
        $this->assertGreaterThanOrEqual(0.6, $d['matches'][0]['score']);
        $this->assertArrayHasKey('hint', $d);
    }

    public function test_no_match_exits_3_with_a_hint(): void
    {
        $this->seedTrace('t1', 1);

        [$code, $d] = $this->jsonOut('lantern:match', ['--sql' => 'select count(*) from invoices where paid = 1']);

        $this->assertSame(3, $code);
        $this->assertSame('no_data', $d['error']['code']);
        $this->assertNotEmpty($d['error']['hint']);
    }

    public function test_usage_errors_exit_2(): void
    {
        [$code, $d] = $this->jsonOut('lantern:match', []);
        $this->assertSame(2, $code);
        $this->assertSame('usage_error', $d['error']['code']);

        [$code] = $this->jsonOut('lantern:match', ['--sql' => 'hello there']);
        $this->assertSame(2, $code);

        [$code] = $this->jsonOut('lantern:match', ['--sql' => 'select 1', '--bogus' => 1]);
        $this->assertSame(2, $code);
    }

    public function test_output_never_contains_input_literals(): void
    {
        $this->seedTrace('t1', 1);

        [, $out] = $this->run_('lantern:match', ['--sql' => "select * from users where email = 'secret@leak.test' and id = 99123", '--format' => 'json']);

        $this->assertStringNotContainsString('secret@leak.test', $out);
        $this->assertStringNotContainsString('99123', $out);
    }

    public function test_issues_filter_by_sql(): void
    {
        $this->seedTrace('t1', 3, ['k1']);
        $this->seedIssue('k1', traceId: 't1');
        $this->seedIssue('k2', traceId: 't1');

        [$code, $d] = $this->jsonOut('lantern:issues', ['--sql' => self::PROD]);
        $this->assertSame(0, $code);
        $this->assertSame(['k1'], array_column($d['issues'], 'id'));

        [, $d] = $this->jsonOut('lantern:issues', ['--sql' => 'select * from nothing_here where a = 1']);
        $this->assertSame(0, $d['total']);
        $this->assertStringContainsString('lantern:match', $d['hint']);

        [$code] = $this->jsonOut('lantern:issues', ['--sql' => 'nope']);
        $this->assertSame(2, $code);
    }
}
