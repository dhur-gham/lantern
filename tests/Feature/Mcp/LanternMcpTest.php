<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Mcp;

use Illuminate\Support\Carbon;
use Laravel\Mcp\Facades\Mcp;
use Lantern\Mcp\LanternServer;
use Lantern\Mcp\Server;
use Lantern\Mcp\Tools\LanternCheck;
use Lantern\Mcp\Tools\LanternDiff;
use Lantern\Mcp\Tools\LanternIssue;
use Lantern\Mcp\Tools\LanternIssues;
use Lantern\Mcp\Tools\LanternTraceSummary;
use Lantern\Tests\Feature\Agent\AgentTestCase;

final class LanternMcpTest extends AgentTestCase
{
    private const TOOLS = [LanternIssues::class, LanternIssue::class, LanternTraceSummary::class, LanternDiff::class, LanternCheck::class];

    protected function getPackageProviders($app): array
    {
        $providers = parent::getPackageProviders($app);
        if (class_exists(\Laravel\Mcp\Server\McpServiceProvider::class)) {
            $providers[] = \Laravel\Mcp\Server\McpServiceProvider::class;
        }

        return $providers;
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! Server::available()) {
            $this->markTestSkipped('laravel/mcp is not installed.');
        }
        Carbon::setTestNow('2026-02-01 00:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function seedAll(): void
    {
        $this->seedTrace('t1', 6, ['k1']);
        $this->seedTrace('t2', 2, [], at: '2026-01-01 11:00:00');
        $this->seedIssue('k1', traceId: 't1');
    }

    public function test_register_adds_local_server(): void
    {
        $this->assertTrue(Server::register('lantern'));
        $this->assertNotNull(Mcp::getLocalServer('lantern'));
    }

    public function test_tool_list_and_annotations(): void
    {
        LanternServer::tools()->assertRegistered(self::TOOLS);

        $tools = array_map(fn (string $c): array => (new $c)->toArray(), self::TOOLS);
        $this->assertSame(['lantern_issues', 'lantern_issue', 'lantern_trace_summary', 'lantern_diff', 'lantern_check'], array_column($tools, 'name'));
        foreach ($tools as $t) {
            $this->assertTrue($t['annotations']['readOnlyHint'], $t['name']);
            $this->assertSame('object', $t['inputSchema']['type']);
        }
    }

    public function test_input_schemas(): void
    {
        $issues = (new LanternIssues)->toArray()['inputSchema'];
        $this->assertSame(['type', 'severity', 'sort', 'since', 'path', 'route', 'status', 'app', 'all_apps', 'include_resolved', 'resolved', 'limit', 'offset', 'full'], array_keys((array) $issues['properties']));
        $this->assertSame(50, $issues['properties']['limit']['maximum']);
        $this->assertSame(['critical', 'warning', 'info'], $issues['properties']['severity']['enum']);

        $this->assertSame(['id'], (new LanternIssue)->toArray()['inputSchema']['required']);
        $this->assertSame(['before', 'after'], (new LanternDiff)->toArray()['inputSchema']['required']);
        $this->assertArrayNotHasKey('required', (new LanternCheck)->toArray()['inputSchema']);
    }

    public function test_issues_equals_cli_json(): void
    {
        $this->seedAll();
        [, $cli] = $this->jsonOut('lantern:issues', ['--limit' => 5]);
        $cli['next'] = 'the lantern_issue tool with id=k1';

        LanternServer::tool(LanternIssues::class, ['limit' => 5])->assertOk()->assertStructuredContent($cli);
        $this->assertSame('k1', $cli['issues'][0]['id']);
    }

    public function test_issue_equals_cli_json(): void
    {
        $this->seedAll();
        [, $cli] = $this->jsonOut('lantern:issue', ['key' => 'k1']);
        $cli['next'] = 'Fix call_site, re-run the request, then: the lantern_diff tool with before=t1, after=last';

        LanternServer::tool(LanternIssue::class, ['id' => 'k1'])->assertOk()->assertStructuredContent($cli);
    }

    public function test_trace_summary_equals_cli_json(): void
    {
        $this->seedAll();
        [, $cli] = $this->jsonOut('lantern:trace', ['id' => 't1', '--summary' => true]);
        $cli['next'] = 'the lantern_issue tool with id=k1';
        LanternServer::tool(LanternTraceSummary::class, ['trace_id' => 't1'])->assertOk()->assertStructuredContent($cli);

        [, $latest] = $this->jsonOut('lantern:trace', ['--summary' => true]);
        unset($latest['next']);
        LanternServer::tool(LanternTraceSummary::class)->assertOk()->assertStructuredContent($latest);
    }

    public function test_diff_equals_cli_json(): void
    {
        $this->seedAll();
        [, $cli] = $this->jsonOut('lantern:diff', ['before' => 't1', 'after' => 't2']);

        LanternServer::tool(LanternDiff::class, ['before' => 't1', 'after' => 't2'])->assertOk()->assertStructuredContent($cli);
        $this->assertSame('improved', $cli['verdict']);
    }

    public function test_check_failure_is_a_normal_result(): void
    {
        $this->seedAll();
        [$code, $cli] = $this->jsonOut('lantern:check', ['--max-queries' => 3]);
        $this->assertSame(1, $code);
        $this->assertFalse($cli['pass']);

        LanternServer::tool(LanternCheck::class, ['max_queries' => 3])->assertOk()->assertStructuredContent($cli);
    }

    public function test_text_content_carries_the_json(): void
    {
        $this->seedAll();

        LanternServer::tool(LanternIssue::class, ['id' => 'k1'])->assertSee('"schema":"lantern.issue"');
    }

    public function test_output_is_redacted(): void
    {
        $this->seedAll();

        LanternServer::tool(LanternIssues::class, ['full' => true])->assertOk()->assertDontSee('jane@example.com');
        LanternServer::tool(LanternIssue::class, ['id' => 'k1'])->assertDontSee('jane@example.com');
        LanternServer::tool(LanternTraceSummary::class, ['trace_id' => 't1'])->assertDontSee('jane@example.com');
    }

    public function test_empty_store_errors(): void
    {
        LanternServer::tool(LanternTraceSummary::class)->assertHasErrors(['no_data']);
        LanternServer::tool(LanternCheck::class)->assertHasErrors(['no_data']);
        LanternServer::tool(LanternIssue::class, ['id' => 'missing'])->assertHasErrors(['not_found']);
        LanternServer::tool(LanternDiff::class, ['before' => 'a', 'after' => 'b'])->assertHasErrors();
    }

    public function test_unconfigured_storage_errors(): void
    {
        config()->set('lantern.storage.driver', 'memory');

        LanternServer::tool(LanternIssues::class)->assertHasErrors(['config_error']);
    }

    public function test_validation_errors(): void
    {
        LanternServer::tool(LanternIssues::class, ['limit' => 500])->assertHasErrors(['limit']);
        LanternServer::tool(LanternIssue::class)->assertHasErrors(['id']);
    }

    public function test_hints_point_at_mcp_tools_not_the_cli(): void
    {
        $this->seedAll();

        LanternServer::tool(LanternIssue::class, ['id' => 'zzzzzz'])
            ->assertHasErrors()
            ->assertSee('the lantern_issues tool')
            ->assertDontSee('php artisan lantern:issues');

        LanternServer::tool(LanternIssues::class)
            ->assertOk()
            ->assertSee('the lantern_issue tool');
    }
}
