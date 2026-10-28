<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Agent;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Lantern\Agent\Commands;
use Lantern\Tests\TestCase;

abstract class AgentTestCase extends TestCase
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
        foreach (Commands::all() as $class) {
            $kernel->registerCommand($this->app->make($class));
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array{0: int, 1: string}
     */
    protected function run_(string $command, array $params = []): array
    {
        $code = Artisan::call($command, $params);

        return [$code, trim(Artisan::output())];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{0: int, 1: array<string, mixed>}
     */
    protected function jsonOut(string $command, array $params = []): array
    {
        [$code, $out] = $this->run_($command, $params + ['--format' => 'json']);
        $this->assertStringNotContainsString("\e[", $out, 'no ANSI in json output');
        $data = json_decode($out, true);
        $this->assertIsArray($data, 'stdout must be a single JSON document: '.$out);

        return [$code, $data];
    }

    protected function seedIssue(string $key, string $type = 'n_plus_one', string $severity = 'critical', float $totalMs = 10.0, ?string $traceId = null, string $lastSeen = '2026-01-02 00:00:00'): void
    {
        DB::table('lantern_issues')->insert([
            'issue_key' => $key, 'type' => $type, 'title' => "Title $key", 'severity' => $severity, 'occurrences' => 3,
            'count_total' => 30, 'total_ms' => $totalMs, 'max_ms' => 5,
            'call_site' => json_encode(['file' => base_path('app/Foo.php'), 'line' => 12, 'function' => 'Foo@bar']),
            'suggestion' => "Fix $key", 'first_seen' => '2026-01-01 00:00:00', 'last_seen' => $lastSeen, 'last_trace_id' => $traceId,
        ]);
    }

    /**
     * @param list<string> $issueKeys
     * @param array<string, mixed> $over
     */
    protected function seedTrace(string $id, int $queries, array $issueKeys = [], int $ms = 40, string $at = '2026-01-01 10:00:00', string $route = 'posts.index', string $sql = "select * from `users` where `email` = 'jane@example.com' and `id` = 7"): void
    {
        $children = [];
        $spanIds = [];
        for ($i = 0; $i < $queries; $i++) {
            $spanIds[] = $i + 2;
            $children[] = [
                'id' => $i + 2, 'type' => 'query', 'name' => $sql, 'start_ms' => $i, 'duration_ms' => 1.5, 'self_ms' => 1.5,
                'call_site' => ['file' => base_path('app/Foo.php'), 'line' => 12],
                'meta' => ['sql' => $sql, 'normalized_sql' => 'select * from `users` where `email` = ? and `id` = ?', 'fingerprint' => 'fp1', 'bindings' => ['jane@example.com', 7]],
                'children' => [],
            ];
        }
        $issues = array_map(fn (string $k): array => [
            'key' => $k, 'type' => 'n_plus_one', 'severity' => 'critical', 'title' => "Title $k", 'count' => $queries, 'total_ms' => $queries * 1.5,
            'call_site' => ['file' => base_path('app/Foo.php'), 'line' => 12], 'span_ids' => $spanIds,
            'meta' => ['sample_sql' => $sql, 'parent' => 'PostController@index', 'avg_ms' => 1.5], 'suggestion' => "Fix $k",
        ], $issueKeys);

        $payload = [
            'id' => $id, 'total_ms' => $ms, 'peak_memory' => 2097152,
            'root' => ['id' => 1, 'type' => 'request', 'name' => 'GET /posts', 'start_ms' => 0, 'duration_ms' => $ms, 'self_ms' => 1.0, 'call_site' => null, 'meta' => [], 'children' => $children],
            'meta' => ['status' => 200, 'issues' => $issues],
        ];

        DB::table('lantern_traces')->insert([
            'id' => $id, 'started_at' => $at, 'duration_ms' => $ms, 'status' => '200', 'method' => 'GET', 'path' => '/posts',
            'route' => $route, 'query_count' => $queries, 'query_total_ms' => $queries * 1.5, 'issue_count' => count($issues), 'peak_memory' => 2097152,
            'payload' => json_encode($payload), 'created_at' => $at,
        ]);
    }
}
