<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Matrix;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lantern\Detection\Explain\ExplainRunner;
use Lantern\Detection\Issue;
use Lantern\Query\Fingerprinter;
use Lantern\Storage\DatabaseTraceStore;
use Lantern\Storage\Pruner;
use Lantern\Storage\TraceQueries;
use Lantern\Tests\TestCase;
use Lantern\Trace\Recorder;
use Lantern\Trace\Trace;

/**
 * Runs against a real server: LANTERN_TEST_DB=pgsql|mysql|mariadb (connection details overridable via
 * LANTERN_TEST_HOST / _PORT / _DATABASE / _USERNAME / _PASSWORD). Skipped when unset.
 */
final class DatabaseMatrixTest extends TestCase
{
    private const MIGRATIONS = ['2024_01_01_000001_create_lantern_traces_table', '2024_01_01_000002_create_lantern_issues_table'];

    private const DEFAULTS = [
        'pgsql' => ['port' => '35432', 'username' => 'postgres'],
        'mysql' => ['port' => '33306', 'username' => 'root'],
        'mariadb' => ['port' => '33307', 'username' => 'root'],
    ];

    private static function driver(): ?string
    {
        $db = getenv('LANTERN_TEST_DB');

        return is_string($db) && isset(self::DEFAULTS[$db]) ? $db : null;
    }

    protected function defineEnvironment($app): void
    {
        $driver = self::driver();
        if ($driver === null) {
            return;
        }

        $d = self::DEFAULTS[$driver];
        $config = [
            'driver' => $driver,
            'host' => getenv('LANTERN_TEST_HOST') ?: '127.0.0.1',
            'port' => getenv('LANTERN_TEST_PORT') ?: $d['port'],
            'database' => getenv('LANTERN_TEST_DATABASE') ?: 'matrix',
            'username' => getenv('LANTERN_TEST_USERNAME') ?: $d['username'],
            'password' => getenv('LANTERN_TEST_PASSWORD') ?: 'secret',
            'prefix' => '',
        ];
        if ($driver === 'pgsql') {
            $config += ['charset' => 'utf8', 'search_path' => 'public', 'sslmode' => 'prefer'];
        } else {
            $config += ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'];
        }

        $app['config']->set('database.default', $driver);
        $app['config']->set('database.connections.'.$driver, $config);
    }

    protected function setUp(): void
    {
        if (self::driver() === null) {
            $this->markTestSkipped('LANTERN_TEST_DB is not set to pgsql|mysql|mariadb.');
        }

        parent::setUp();
        $this->migrate('down', array_reverse(self::MIGRATIONS));
        $this->migrate('up', self::MIGRATIONS);
    }

    protected function tearDown(): void
    {
        if (self::driver() !== null && $this->app !== null) {
            $this->migrate('down', array_reverse(self::MIGRATIONS));
            Schema::dropIfExists('m_items');
        }
        parent::tearDown();
    }

    /** @param list<string> $files */
    private function migrate(string $direction, array $files): void
    {
        foreach ($files as $file) {
            (require __DIR__.'/../../../database/migrations/'.$file.'.php')->{$direction}();
        }
    }

    private function trace(array $meta = []): Trace
    {
        $recorder = new Recorder;
        $trace = $recorder->begin($meta + ['kind' => 'request', 'method' => 'GET', 'path' => '/x', 'status' => 200, 'query_count' => 3, 'query_total_ms' => 4.5]);
        $recorder->end();

        return $trace;
    }

    private function issue(string $key, int $count, float $ms, string $type = Issue::TYPE_N_PLUS_ONE): Issue
    {
        return new Issue($type, $key, 'N+1 on posts', Issue::SEV_WARNING, $count, $ms, ['file' => 'app/A.php', 'line' => 7], [1, 2]);
    }

    private function store(bool $compress = false): DatabaseTraceStore
    {
        return new DatabaseTraceStore($this->app['db'], null, $compress);
    }

    public function test_migrations_create_and_drop_tables(): void
    {
        $this->assertTrue(Schema::hasTable('lantern_traces'));
        $this->assertTrue(Schema::hasColumns('lantern_issues', ['issue_key', 'total_ms', 'last_trace_id']));

        $this->migrate('down', array_reverse(self::MIGRATIONS));
        $this->assertFalse(Schema::hasTable('lantern_traces'));
        $this->assertFalse(Schema::hasTable('lantern_issues'));
    }

    public function test_store_find_and_compressed_round_trip(): void
    {
        $a = $this->trace();
        $b = $this->trace();
        $this->store()->store($a, [$this->issue('k1', 10, 20.0)]);
        $this->store(true)->store($b, []);

        $row = $this->store()->find($a->id);
        $this->assertSame($a->id, $row['id']);
        $this->assertSame(3, (int) $row['query_count']);
        $this->assertSame(1, (int) $row['issue_count']);
        $this->assertSame($a->id, $row['payload']['id']);
        $this->assertStringStartsWith('gz:', (string) DB::table('lantern_traces')->where('id', $b->id)->value('payload'));
        $this->assertSame($b->id, $this->store()->find($b->id)['payload']['id']);
        $this->assertNull($this->store()->find('00000000-0000-0000-0000-000000000000'));
    }

    public function test_issue_upsert_accumulates_and_merges_duplicates(): void
    {
        $this->store()->store($this->trace(), [$this->issue('k1', 10, 20.0)]);
        $b = $this->trace();
        $this->store()->store($b, [$this->issue('k1', 5, 50.0), $this->issue('k2', 1, 1.0), $this->issue('k2', 2, 2.0)]);

        $i = (array) DB::table('lantern_issues')->where('issue_key', 'k1')->first();
        $this->assertSame(2, (int) $i['occurrences']);
        $this->assertSame(15, (int) $i['count_total']);
        $this->assertEqualsWithDelta(70.0, (float) $i['total_ms'], 0.001);
        $this->assertEqualsWithDelta(10.0, (float) $i['max_ms'], 0.001);
        $this->assertSame($b->id, $i['last_trace_id']);

        $k2 = (array) DB::table('lantern_issues')->where('issue_key', 'k2')->first();
        $this->assertSame(1, (int) $k2['occurrences']);
        $this->assertSame(3, (int) $k2['count_total']);
        $this->assertSame(2, DB::table('lantern_issues')->count());
    }

    public function test_trace_queries(): void
    {
        $ok = $this->trace(['status' => 200, 'path' => '/orders/100%_off']);
        $err = $this->trace(['status' => 503, 'path' => '/boom']);
        $this->store()->store($ok, [$this->issue('k1', 3, 9.0)]);
        $this->store()->store($err, []);

        $q = new TraceQueries($this->app['db']);
        $this->assertCount(2, $q->traces());
        $this->assertSame([$err->id], array_column($q->traces(['status' => '5xx']), 'id'));
        $this->assertSame([$ok->id], array_column($q->traces(['status' => '200']), 'id'));
        $this->assertSame([$ok->id], array_column($q->traces(['has_issues' => '1']), 'id'));
        $this->assertSame([$ok->id], array_column($q->traces(['path' => '100%_']), 'id'));
        $this->assertSame([], $q->traces(['min_ms' => 100000]));
        $this->assertStringContainsString('T', $q->traces()[0]['started_at']);

        $this->assertSame($ok->id, $q->trace($ok->id)['payload']['id']);
        $this->assertNull($q->trace('00000000-0000-0000-0000-000000000000'));

        $issues = $q->issues(['type' => Issue::TYPE_N_PLUS_ONE, 'sort' => 'occurrences']);
        $this->assertSame(['k1'], array_column($issues, 'issue_key'));
        $this->assertSame(['file' => 'app/A.php', 'line' => 7], $q->issue('k1')['call_site']);
        $this->assertNull($q->issue('nope'));
    }

    public function test_pruner(): void
    {
        $old = $this->trace();
        $new = $this->trace();
        $this->store()->store($old, [$this->issue('old', 1, 1.0)]);
        $this->store()->store($new, [$this->issue('new', 1, 1.0)]);
        DB::table('lantern_traces')->where('id', $old->id)->update(['started_at' => now()->subDays(30)]);
        DB::table('lantern_issues')->where('issue_key', 'old')->update(['last_seen' => now()->subDays(90)]);

        $this->assertSame(['traces' => 1, 'issues' => 1], (new Pruner)->prune(dryRun: true));
        $this->assertSame(2, DB::table('lantern_traces')->count());
        $this->assertSame(['traces' => 1, 'issues' => 1], (new Pruner)->prune());
        $this->assertSame([$new->id], DB::table('lantern_traces')->pluck('id')->all());
        $this->assertSame(['new'], DB::table('lantern_issues')->pluck('issue_key')->all());
    }

    public function test_fingerprint_collapses_postgres_placeholders(): void
    {
        $f = new Fingerprinter;
        $this->assertSame(
            $f->fingerprint('select * from "posts" where "id" = $1 and "user_id" = $2'),
            $f->fingerprint('select * from "posts" where "id" = ? and "user_id" = ?'),
        );
        $this->assertSame($f->fingerprint('select * from t where a in ($1, $2, $3)'), $f->fingerprint('select * from t where a in ($1)'));
    }

    public function test_explain_runner_parses_the_plan(): void
    {
        Schema::dropIfExists('m_items');
        Schema::create('m_items', function (Blueprint $t) {
            $t->id();
            $t->string('sku');
            $t->string('label')->default('');
        });
        foreach (array_chunk(range(1, 5000), 500) as $chunk) {
            DB::table('m_items')->insert(array_map(fn (int $i): array => ['sku' => 's'.$i, 'label' => 'x'], $chunk));
        }
        $this->analyze('m_items');

        $q = $this->quote('m_items');
        $sku = $this->quote('label');
        $runner = new ExplainRunner;

        $plan = $runner->explain(null, "select * from {$q} where {$sku} = ?", ['x']);
        $this->assertNotNull($plan);
        $this->assertTrue($plan['full_scan']);
        $this->assertSame('m_items', $plan['table']);
        $this->assertGreaterThan(0, $plan['rows']);

        $plan = $runner->explain(null, "select * from {$q} where ".$this->quote('id').' = ?', [5]);
        $this->assertNotNull($plan);
        $this->assertFalse($plan['full_scan']);

        $this->assertNull($runner->explain(null, "delete from {$q} where {$sku} = ?", ['x']));
        $this->assertNull($runner->explain(null, 'select * from nope_missing where a = ?', [1]));
        $this->assertSame(5000, DB::table('m_items')->count());
    }

    private function quote(string $name): string
    {
        return self::driver() === 'pgsql' ? '"'.$name.'"' : '`'.$name.'`';
    }

    private function analyze(string $table): void
    {
        DB::statement(self::driver() === 'pgsql' ? "analyze {$table}" : "analyze table {$table}");
    }
}
