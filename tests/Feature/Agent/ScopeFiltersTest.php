<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Agent;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lantern\Detection\Issue;
use Lantern\Storage\DatabaseTraceStore;
use Lantern\Trace\Recorder;

final class ScopeFiltersTest extends AgentTestCase
{
    /** @param array<string, mixed> $over */
    private function trace(string $id, array $over = []): void
    {
        DB::table('lantern_traces')->insert($over + [
            'id' => $id, 'started_at' => '2026-01-01 10:00:00', 'duration_ms' => 10, 'status' => '200', 'method' => 'GET',
            'path' => '/posts', 'route' => 'posts.index', 'query_count' => 0, 'query_total_ms' => 0, 'issue_count' => 0, 'peak_memory' => 0,
            'payload' => json_encode(['id' => $id, 'root' => ['id' => 1, 'type' => 'request', 'name' => 'GET /posts', 'duration_ms' => 10, 'children' => []], 'meta' => ['issues' => []]]),
            'created_at' => '2026-01-01 10:00:00',
        ]);
    }

    private function latestId(array $params = []): string
    {
        [$code, $data] = $this->jsonOut('lantern:trace', ['--summary' => true] + $params);
        $this->assertSame(0, $code);

        return $data['trace_id'];
    }

    private function seedFilterTraces(): void
    {
        $this->trace('verify', ['path' => '/api/otp/verify', 'route' => 'otp.verify', 'status' => '200', 'started_at' => '2026-01-01 10:00:00']);
        $this->trace('user', ['path' => '/api/user', 'route' => 'user.show', 'status' => '200', 'started_at' => '2026-01-02 10:00:00']);
        $this->trace('boom', ['path' => '/api/boom', 'route' => 'boom', 'status' => '500', 'started_at' => '2026-01-03 10:00:00']);
    }

    public function test_trace_path_pattern_picks_latest_matching(): void
    {
        $this->seedFilterTraces();

        $this->assertSame('boom', $this->latestId());
        $this->assertSame('verify', $this->latestId(['--path' => 'api/otp/*']));
        $this->assertSame('verify', $this->latestId(['--path' => '/api/otp/verify']));
        $this->assertSame('boom', $this->latestId(['--path' => '*boom']));
        $this->assertSame('user', $this->latestId(['--path' => 'api/*', '--status' => '2xx']));
    }

    public function test_trace_route_status_since_and_last_combine(): void
    {
        $this->seedFilterTraces();

        $this->assertSame('user', $this->latestId(['--route' => 'user.*']));
        $this->assertSame('boom', $this->latestId(['--status' => '500']));
        $this->assertSame('user', $this->latestId(['--status' => '200', '--since' => '2026-01-02', '--last' => true]));
        $this->assertSame('verify', $this->latestId(['--route' => 'otp.verify', '--since' => '2026-01-01', '--last' => true]));
    }

    public function test_trace_filter_literals_are_not_wildcards(): void
    {
        $this->trace('a', ['path' => '/x_y']);
        $this->trace('b', ['path' => '/xzy', 'started_at' => '2026-01-02 10:00:00']);

        $this->assertSame('a', $this->latestId(['--path' => 'x_y']));
    }

    public function test_trace_filter_without_match_exits_no_data_with_hint(): void
    {
        $this->seedFilterTraces();

        [$code, $data] = $this->jsonOut('lantern:trace', ['--summary' => true, '--path' => 'nothing/*']);

        $this->assertSame(3, $code);
        $this->assertSame('no_data', $data['error']['code']);
        $this->assertStringContainsString('No traces match', $data['error']['message']);
    }

    public function test_trace_filters_with_an_id_are_a_usage_error(): void
    {
        $this->seedFilterTraces();

        [$code] = $this->run_('lantern:trace', ['id' => 'verify', '--path' => 'api/*', '--format' => 'json']);
        $this->assertSame(2, $code);

        [$code, $data] = $this->jsonOut('lantern:trace', ['id' => 'last', '--summary' => true, '--path' => 'api/otp/*']);
        $this->assertSame(0, $code);
        $this->assertSame('verify', $data['trace_id']);
    }

    public function test_issues_filter_by_the_trace_that_last_recorded_them(): void
    {
        $this->seedFilterTraces();
        $this->seedIssue('iv', traceId: 'verify');
        $this->seedIssue('iu', traceId: 'user');
        $this->seedIssue('ib', traceId: 'boom');

        $ids = fn (array $p): array => array_column($this->jsonOut('lantern:issues', $p)[1]['issues'], 'id');

        $this->assertEqualsCanonicalizing(['iv'], $ids(['--path' => 'api/otp/*']));
        $this->assertEqualsCanonicalizing(['iu'], $ids(['--route' => 'user.*']));
        $this->assertEqualsCanonicalizing(['ib'], $ids(['--status' => '5xx']));
        $this->assertEqualsCanonicalizing(['iv', 'iu'], $ids(['--path' => 'api/*', '--status' => '200']));
        $this->assertEqualsCanonicalizing(['iv', 'iu', 'ib'], $ids([]));
    }

    public function test_issues_without_match_hint_mentions_the_filters(): void
    {
        $this->seedFilterTraces();
        $this->seedIssue('iv', traceId: 'verify');

        [, $data] = $this->jsonOut('lantern:issues', ['--path' => 'nothing/*']);

        $this->assertSame(0, $data['total']);
        $this->assertStringContainsString('--path', $data['hint']);
    }

    public function test_app_scope_defaults_to_current_app_and_keeps_untagged_rows(): void
    {
        config(['lantern.app' => 'shop-app']);
        $this->trace('mine', ['app' => 'shop-app', 'started_at' => '2026-01-01 10:00:00']);
        $this->trace('theirs', ['app' => 'storefront', 'started_at' => '2026-01-02 10:00:00']);
        $this->trace('old', ['app' => null, 'started_at' => '2025-12-01 10:00:00']);
        $this->seedIssue('im', traceId: 'mine');
        $this->seedIssue('il', traceId: 'theirs');
        $this->seedIssue('io', traceId: 'old');
        DB::table('lantern_issues')->where('issue_key', 'im')->update(['app' => 'shop-app']);
        DB::table('lantern_issues')->where('issue_key', 'il')->update(['app' => 'storefront']);

        $ids = fn (array $p): array => array_column($this->jsonOut('lantern:issues', $p)[1]['issues'], 'id');

        $this->assertEqualsCanonicalizing(['im', 'io'], $ids([]));
        $this->assertEqualsCanonicalizing(['il', 'io'], $ids(['--app' => 'storefront']));
        $this->assertEqualsCanonicalizing(['im', 'il', 'io'], $ids(['--all-apps' => true]));

        $this->assertSame('mine', $this->latestId());
        $this->assertSame('theirs', $this->latestId(['--all-apps' => true]));
        $this->assertSame('theirs', $this->latestId(['--app' => 'storefront']));

        [$code] = $this->run_('lantern:issues', ['--app' => 'x', '--all-apps' => true, '--format' => 'json']);
        $this->assertSame(2, $code);
    }

    public function test_app_scope_empty_result_hint_names_the_app(): void
    {
        config(['lantern.app' => 'shop-app']);
        $this->trace('theirs', ['app' => 'storefront']);
        $this->seedIssue('il', traceId: 'theirs');
        DB::table('lantern_issues')->where('issue_key', 'il')->update(['app' => 'storefront']);

        [, $data] = $this->jsonOut('lantern:issues');

        $this->assertSame(0, $data['total']);
        $this->assertStringContainsString('--all-apps', $data['hint']);

        [$code, $err] = $this->jsonOut('lantern:trace', ['--summary' => true]);
        $this->assertSame(3, $code);
        $this->assertStringContainsString('--all-apps', $err['error']['hint']);
    }

    public function test_check_honours_path_status_and_app(): void
    {
        config(['lantern.app' => 'shop-app']);
        $this->seedFilterTraces();
        DB::table('lantern_traces')->where('id', 'user')->update(['query_count' => 90, 'app' => 'storefront']);
        DB::table('lantern_traces')->where('id', 'verify')->update(['query_count' => 70, 'app' => 'shop-app']);

        [$code, $data] = $this->jsonOut('lantern:check', ['--max-queries' => 50, '--path' => 'api/otp/*']);
        $this->assertSame(1, $code);
        $this->assertSame('verify', $data['violations'][0]['trace_id']);
        $this->assertSame('api/otp/*', $data['scope']['path']);

        [$code] = $this->jsonOut('lantern:check', ['--max-queries' => 50, '--path' => 'api/user']);
        $this->assertSame(3, $code, 'the storefront trace is outside the current app');

        [$code, $data] = $this->jsonOut('lantern:check', ['--max-queries' => 50, '--path' => 'api/user', '--all-apps' => true]);
        $this->assertSame(1, $code);
        $this->assertSame('user', $data['violations'][0]['trace_id']);
    }

    public function test_store_tags_traces_and_issues_with_the_app(): void
    {
        $store = new DatabaseTraceStore($this->app['db'], null, false, 'shop-app');
        $recorder = new Recorder;
        $trace = $recorder->begin(['kind' => 'request', 'method' => 'GET', 'path' => '/x', 'status' => 200]);
        $recorder->end();
        $issue = new Issue(Issue::TYPE_N_PLUS_ONE, 'k1', 'N+1', Issue::SEV_WARNING, 5, 5.0, ['file' => 'a.php', 'line' => 1], [1]);

        $store->store($trace, [$issue]);

        $this->assertSame('shop-app', DB::table('lantern_traces')->value('app'));
        $this->assertSame('shop-app', DB::table('lantern_issues')->value('app'));

        (new DatabaseTraceStore($this->app['db'], null, false, null))->store($this->freshTrace(), [$issue]);
        $this->assertSame('shop-app', DB::table('lantern_issues')->where('issue_key', 'k1')->value('app'), 'an untagged write keeps the existing tag');
        $this->assertSame(2, (int) DB::table('lantern_issues')->value('occurrences'));
    }

    public function test_store_still_persists_before_the_app_migration_has_run(): void
    {
        (require dirname(__DIR__, 3).'/database/migrations/2024_01_01_000003_add_app_to_lantern_tables.php')->down();
        $this->assertFalse(Schema::hasColumn('lantern_traces', 'app'));

        $store = new DatabaseTraceStore($this->app['db'], null, false, 'shop-app');
        $issue = new Issue(Issue::TYPE_N_PLUS_ONE, 'k1', 'N+1', Issue::SEV_WARNING, 5, 5.0, null, [1]);
        $store->store($this->freshTrace(), [$issue]);
        $store->store($this->freshTrace(), [$issue]);

        $this->assertSame(2, DB::table('lantern_traces')->count());
        $this->assertSame(2, (int) DB::table('lantern_issues')->value('occurrences'));
    }

    public function test_app_migration_upgrades_existing_tables_once(): void
    {
        $migration = require dirname(__DIR__, 3).'/database/migrations/2024_01_01_000003_add_app_to_lantern_tables.php';
        $migration->down();
        $this->assertFalse(Schema::hasColumn('lantern_issues', 'app'));

        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasColumn('lantern_traces', 'app'));
        $this->assertTrue(Schema::hasColumn('lantern_issues', 'app'));
    }

    private function freshTrace(): \Lantern\Trace\Trace
    {
        $recorder = new Recorder;
        $trace = $recorder->begin(['kind' => 'request', 'method' => 'GET', 'path' => '/x', 'status' => 200]);
        $recorder->end();

        return $trace;
    }
}
