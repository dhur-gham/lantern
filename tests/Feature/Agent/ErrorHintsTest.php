<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Agent;

use Illuminate\Support\Facades\Schema;

final class ErrorHintsTest extends AgentTestCase
{
    public function test_unknown_option_exits_2_not_1(): void
    {
        [$code, $data] = $this->jsonOut('lantern:check', ['--bogus' => true]);
        $this->assertSame(2, $code);
        $this->assertSame('usage_error', $data['error']['code']);
        $this->assertStringContainsString('--bogus', $data['error']['message']);
        $this->assertStringContainsString('lantern:check --help', $data['error']['hint']);

        [$code, $out] = $this->run_('lantern:issues', ['--bogus' => true]);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('--bogus', $out);
    }

    public function test_missing_argument_exits_2(): void
    {
        [$code, $data] = $this->jsonOut('lantern:diff', ['before' => 't1']);
        $this->assertSame(2, $code);
        $this->assertSame('usage_error', $data['error']['code']);
        $this->assertStringContainsString('after', $data['error']['message']);
    }

    public function test_invalid_type_and_severity_are_rejected(): void
    {
        [$code, $data] = $this->jsonOut('lantern:issues', ['--type' => 'nplus1']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('n_plus_one', $data['error']['hint']);

        [$code, $data] = $this->jsonOut('lantern:issues', ['--severity' => 'bad']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('critical', $data['error']['hint']);
    }

    public function test_short_issue_prefix_says_why(): void
    {
        $this->seedIssue('abcdef123456');

        [$code, $data] = $this->jsonOut('lantern:issue', ['key' => 'abc']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('at least 6 characters', $data['error']['hint']);
    }

    public function test_empty_store_json_explains_how_to_record(): void
    {
        [$code, $data] = $this->jsonOut('lantern:issues');
        $this->assertSame(0, $code);
        $this->assertSame(0, $data['total']);
        $this->assertStringContainsString('LANTERN_ENABLED=true', $data['hint']);
        $this->assertArrayNotHasKey('next', $data);

        $this->seedIssue('k1');
        [, $data] = $this->jsonOut('lantern:issues', ['--type' => 'slow_query']);
        $this->assertStringContainsString('drop --type', $data['hint']);
    }

    public function test_missing_tables_hint_suggests_migrate_and_message_has_no_sql_dump(): void
    {
        Schema::drop('lantern_issues');

        [$code, $data] = $this->jsonOut('lantern:issues');
        $this->assertSame(2, $code);
        $this->assertSame('storage_error', $data['error']['code']);
        $this->assertStringContainsString('php artisan migrate', $data['error']['hint']);
        $this->assertStringNotContainsString('SQL:', $data['error']['message']);
    }

    public function test_check_failure_carries_next(): void
    {
        $this->seedTrace('t1', 6, ['k1']);
        $this->seedIssue('k1', traceId: 't1');

        [$code, $data] = $this->jsonOut('lantern:check');
        $this->assertSame(1, $code);
        $this->assertArrayHasKey('next', $data);
    }
}
