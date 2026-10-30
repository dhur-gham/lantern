<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Agent;

use Illuminate\Support\Facades\DB;

final class PlanEvidenceTest extends AgentTestCase
{
    /** @param array<string, mixed> $meta */
    private function seedPlanIssue(array $meta): void
    {
        $this->seedTrace('t1', 2, ['pk1']);
        $payload = json_decode((string) DB::table('lantern_traces')->where('id', 't1')->value('payload'), true);
        $payload['meta']['issues'][0]['type'] = 'missing_index';
        $payload['meta']['issues'][0]['meta'] = $meta + $payload['meta']['issues'][0]['meta'];
        DB::table('lantern_traces')->where('id', 't1')->update(['payload' => json_encode($payload)]);
        $this->seedIssue('pk1', 'missing_index', 'warning', 10.0, 't1');
    }

    public function test_issue_evidence_carries_plan_rows_projection_and_schema_note(): void
    {
        $this->seedPlanIssue([
            'rows_read' => 820000,
            'rows_returned' => 41,
            'tables' => [['table' => 'orders', 'rows' => 820000, 'key' => null, 'type' => 'ALL']],
            'projected' => [['table' => 'orders', 'local_rows' => 820000, 'production_rows' => 838776, 'factor' => 1.0]],
            'schema_note' => 'order_items: local schema differs from production: missing merchant_id_2',
        ]);

        [, $data] = $this->jsonOut('lantern:issue', ['key' => 'pk1']);
        $evidence = $data['issue']['evidence'];

        $this->assertSame(820000, $evidence['rows_read']);
        $this->assertSame(41, $evidence['rows_returned']);
        $this->assertEquals(20000, $evidence['read_ratio']);
        $this->assertSame('orders', $evidence['tables'][0]['table']);
        $this->assertSame(838776, $evidence['projected'][0]['production_rows']);
        $this->assertSame('order_items: local schema differs from production: missing merchant_id_2', $evidence['schema_note']);

        [, $text] = $this->run_('lantern:issue', ['key' => 'pk1']);
        $this->assertStringContainsString('local schema differs from production', $text);
    }

    public function test_read_ratio_needs_a_returned_count_and_unknowns_are_omitted(): void
    {
        $this->seedPlanIssue(['rows_read' => 5000, 'rows_returned' => null]);

        [, $data] = $this->jsonOut('lantern:issue', ['key' => 'pk1']);
        $evidence = $data['issue']['evidence'];

        $this->assertSame(5000, $evidence['rows_read']);
        foreach (['rows_returned', 'read_ratio', 'tables', 'projected', 'schema_note'] as $key) {
            $this->assertArrayNotHasKey($key, $evidence);
        }
    }

    public function test_a_timed_out_replay_hints_at_the_timeout_setting(): void
    {
        $this->seedPlanIssue(['rows_read' => 5000, 'rows_returned' => null, 'rows_returned_why' => 'timeout']);

        [, $data] = $this->jsonOut('lantern:issue', ['key' => 'pk1']);
        $evidence = $data['issue']['evidence'];

        $this->assertSame('timeout', $evidence['rows_returned_why']);
        $this->assertStringContainsString('LANTERN_EXPLAIN_ANALYZE_TIMEOUT_MS', $evidence['rows_returned_hint']);
    }

    public function test_issue_without_plan_data_keeps_its_evidence_unchanged(): void
    {
        $this->seedTrace('t2', 2, ['pk2']);
        $this->seedIssue('pk2', 'n_plus_one', 'critical', 10.0, 't2');

        [, $data] = $this->jsonOut('lantern:issue', ['key' => 'pk2']);

        $this->assertSame(['trace_id', 'sample_sql', 'sql_shape', 'bindings', 'parent', 'avg_ms'], array_keys($data['issue']['evidence']));
    }

    public function test_trace_summary_shows_rows_read_on_issues_and_query_groups(): void
    {
        $this->seedPlanIssue(['rows_read' => 820000, 'rows_returned' => 41]);

        [, $data] = $this->jsonOut('lantern:trace', ['id' => 't1', '--summary' => true]);

        $this->assertSame(820000, $data['issues'][0]['rows_read']);
        $this->assertEquals(20000, $data['issues'][0]['read_ratio']);
        $this->assertSame(820000, $data['query_groups'][0]['rows_read']);
        $this->assertSame('pk1', $data['query_groups'][0]['issue_id']);
    }

    public function test_trace_summary_without_plan_data_has_no_rows_read(): void
    {
        $this->seedTrace('t3', 3, ['k1']);

        [, $data] = $this->jsonOut('lantern:trace', ['id' => 't3', '--summary' => true]);

        $this->assertArrayNotHasKey('rows_read', $data['issues'][0]);
        $this->assertArrayNotHasKey('rows_read', $data['query_groups'][0]);
    }
}
