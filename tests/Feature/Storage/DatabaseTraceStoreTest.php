<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Storage;

use Illuminate\Support\Facades\DB;
use Lantern\Detection\Issue;
use Lantern\Storage\DatabaseTraceStore;
use Lantern\Tests\TestCase;
use Lantern\Trace\Recorder;
use Lantern\Trace\Trace;

final class DatabaseTraceStoreTest extends TestCase
{
    private const MIGRATIONS = ['2024_01_01_000001_create_lantern_traces_table', '2024_01_01_000002_create_lantern_issues_table'];

    protected function defineEnvironment($app): void
    {
        if (getenv('LANTERN_TEST_DB') === 'mysql') {
            $app['config']->set('database.default', 'mysql');
            $app['config']->set('database.connections.mysql', [
                'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 33306, 'database' => 'demo',
                'username' => 'root', 'password' => 'secret', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
            ]);
        } else {
            $app['config']->set('database.default', 'testing');
            $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrate('down', array_reverse(self::MIGRATIONS));
        $this->migrate('up', self::MIGRATIONS);
    }

    protected function tearDown(): void
    {
        $this->migrate('down', array_reverse(self::MIGRATIONS));
        parent::tearDown();
    }

    /** @param list<string> $files */
    private function migrate(string $direction, array $files): void
    {
        foreach ($files as $file) {
            (require __DIR__.'/../../../database/migrations/'.$file.'.php')->{$direction}();
        }
    }

    private function trace(): Trace
    {
        $recorder = new Recorder;
        $trace = $recorder->begin(['kind' => 'request', 'method' => 'GET', 'path' => '/x', 'status' => 200, 'query_count' => 3, 'query_total_ms' => 4.5]);
        $recorder->end();

        return $trace;
    }

    private function issue(string $key, int $count, float $ms): Issue
    {
        return new Issue(Issue::TYPE_N_PLUS_ONE, $key, 'N+1 on posts', Issue::SEV_WARNING, $count, $ms, ['file' => 'app/A.php', 'line' => 7], [1, 2]);
    }

    private function store(bool $compress = false): DatabaseTraceStore
    {
        return new DatabaseTraceStore($this->app['db'], null, $compress);
    }

    public function test_stores_and_finds_trace(): void
    {
        $trace = $this->trace();
        $this->store()->store($trace, [$this->issue('k1', 10, 20.0)]);

        $row = $this->store()->find($trace->id);
        $this->assertSame($trace->id, $row['id']);
        $this->assertSame('GET', $row['method']);
        $this->assertSame('200', (string) $row['status']);
        $this->assertSame(3, (int) $row['query_count']);
        $this->assertSame(1, (int) $row['issue_count']);
        $this->assertSame($trace->id, $row['payload']['id']);
        $this->assertNull($this->store()->find('missing'));
    }

    public function test_compressed_payload_round_trips(): void
    {
        $trace = $this->trace();
        $this->store(true)->store($trace, []);

        $this->assertStringStartsWith('gz:', DB::table('lantern_traces')->value('payload'));
        $this->assertSame($trace->id, $this->store()->find($trace->id)['payload']['id']);
    }

    public function test_issue_upsert_accumulates(): void
    {
        $this->store()->store($this->trace(), [$this->issue('k1', 10, 20.0)]);
        $b = $this->trace();
        $this->store()->store($b, [$this->issue('k1', 5, 50.0), $this->issue('k2', 2, 1.0)]);

        $i = (array) DB::table('lantern_issues')->where('issue_key', 'k1')->first();
        $this->assertSame(2, (int) $i['occurrences']);
        $this->assertSame(15, (int) $i['count_total']);
        $this->assertEqualsWithDelta(70.0, (float) $i['total_ms'], 0.001);
        $this->assertEqualsWithDelta(10.0, (float) $i['max_ms'], 0.001);
        $this->assertSame($b->id, $i['last_trace_id']);
        $this->assertSame(2, DB::table('lantern_issues')->count());
    }

    public function test_duplicate_keys_within_trace_merge(): void
    {
        $this->store()->store($this->trace(), [$this->issue('k1', 1, 1.0), $this->issue('k1', 2, 2.0)]);

        $i = (array) DB::table('lantern_issues')->first();
        $this->assertSame(1, (int) $i['occurrences']);
        $this->assertSame(3, (int) $i['count_total']);
    }

    public function test_recent_orders_newest_first_without_payload(): void
    {
        $a = $this->trace();
        usleep(5000);
        $b = $this->trace();
        $this->store()->store($a, []);
        $this->store()->store($b, []);

        $recent = $this->store()->recent(1);
        $this->assertCount(1, $recent);
        $this->assertSame($b->id, $recent[0]['id']);
        $this->assertArrayNotHasKey('payload', $recent[0]);
    }

    public function test_storage_queries_are_not_captured(): void
    {
        $recorder = $this->app->make(Recorder::class);
        $recorder->begin();
        $before = count($recorder->trace()->spans());

        $this->store()->store($this->trace(), [$this->issue('k1', 1, 1.0)]);

        $this->assertSame($before, count($recorder->trace()->spans()));
        $this->assertSame(0, (int) ($recorder->trace()->meta['query_count'] ?? 0));
        $recorder->reset();
    }
}
