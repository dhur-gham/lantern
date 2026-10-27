<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Console;

use Illuminate\Support\Facades\DB;
use Lantern\Console\IssuesCommand;
use Lantern\Console\TraceCommand;
use Lantern\Tests\TestCase;

abstract class ConsoleTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('lantern.storage.driver', 'database');
        $app['config']->set('database.default', 'testing');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(dirname(__DIR__, 3).'/database/migrations');
        $kernel = $this->app->make(\Illuminate\Contracts\Console\Kernel::class);
        $kernel->registerCommand($this->app->make(IssuesCommand::class));
        $kernel->registerCommand($this->app->make(TraceCommand::class));
    }

    protected function seedTrace(string $id, int $ms, string $at): void
    {
        $payload = [
            'id' => $id,
            'total_ms' => $ms,
            'peak_memory' => 2097152,
            'root' => ['type' => 'request', 'name' => 'GET /posts', 'duration_ms' => $ms, 'self_ms' => 1.0, 'children' => [
                ['type' => 'query', 'name' => 'select * from posts', 'duration_ms' => 2.5, 'self_ms' => 2.5, 'children' => []],
            ]],
            'meta' => ['status' => 200, 'issues' => [['severity' => 'high', 'type' => 'n_plus_one', 'title' => 'Title a', 'count' => 30, 'total_ms' => 50, 'suggestion' => 'Fix a']]],
        ];

        DB::table('lantern_traces')->insert([
            'id' => $id, 'started_at' => $at, 'duration_ms' => $ms, 'status' => '200', 'method' => 'GET', 'path' => '/posts',
            'route' => 'posts.index', 'query_count' => 1, 'query_total_ms' => 2.5, 'issue_count' => 1, 'peak_memory' => 2097152,
            'payload' => json_encode($payload), 'created_at' => $at,
        ]);
    }

    protected function seedIssue(string $key, string $type, string $severity, float $totalMs, ?string $traceId = null): void
    {
        DB::table('lantern_issues')->insert([
            'issue_key' => $key, 'type' => $type, 'title' => "Title $key", 'severity' => $severity, 'occurrences' => 3,
            'count_total' => 30, 'total_ms' => $totalMs, 'max_ms' => 5, 'call_site' => json_encode(['file' => 'app/Foo.php', 'line' => 12]),
            'suggestion' => "Fix $key", 'first_seen' => '2026-01-01 00:00:00', 'last_seen' => '2026-01-02 00:00:00', 'last_trace_id' => $traceId,
        ]);
    }
}
