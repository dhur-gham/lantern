<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Console;

final class TraceCommandTest extends ConsoleTestCase
{
    public function test_empty_storage_message(): void
    {
        $this->artisan('lantern:trace', ['--last' => true])->expectsOutputToContain('No traces recorded yet.')->assertSuccessful();
    }

    public function test_not_configured_message(): void
    {
        config(['lantern.storage.driver' => 'memory']);

        $this->artisan('lantern:trace')->expectsOutputToContain('not configured')->assertFailed();
    }

    public function test_unknown_id_fails(): void
    {
        $this->artisan('lantern:trace', ['id' => 'nope'])->expectsOutputToContain('Trace not found')->assertFailed();
    }

    public function test_tree_by_id_includes_issues(): void
    {
        $this->seedTrace('t1', 40, '2026-01-01 10:00:00');
        $this->seedIssue('a', 'n_plus_one', 'high', 50.0, 't1');

        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('lantern:trace', ['id' => 't1']));
        $out = \Illuminate\Support\Facades\Artisan::output();
        $this->assertStringContainsString('GET /posts', $out);
        $this->assertStringContainsString('select * from posts', $out);
        $this->assertStringContainsString('Issues (1)', $out);
    }

    public function test_last_and_slow_pick_different_traces(): void
    {
        $this->seedTrace('old-slow', 900, '2026-01-01 10:00:00');
        $this->seedTrace('new-fast', 10, '2026-01-02 10:00:00');

        $this->artisan('lantern:trace', ['--last' => true])->expectsOutputToContain('new-fast')->assertSuccessful();
        $this->artisan('lantern:trace', ['--slow' => true])->expectsOutputToContain('old-slow')->assertSuccessful();
    }

    public function test_json_output(): void
    {
        $this->seedTrace('t1', 40, '2026-01-01 10:00:00');

        $this->artisan('lantern:trace', ['id' => 't1', '--json' => true])->expectsOutputToContain('"id": "t1"')->assertSuccessful();
    }
}
