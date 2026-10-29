<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Matrix;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lantern\Tests\TestCase;
use Lantern\Trace\Recorder;
use Lantern\Trace\SpanType;
use Lantern\Trace\Trace;

/**
 * Base for tests that run on a real server: LANTERN_TEST_DB=pgsql|mysql|mariadb (connection details overridable via
 * LANTERN_TEST_HOST / _PORT / _DATABASE / _USERNAME / _PASSWORD). Skipped when unset.
 */
abstract class MatrixTestCase extends TestCase
{
    private const DEFAULTS = [
        'pgsql' => ['port' => '35432', 'username' => 'postgres'],
        'mysql' => ['port' => '33306', 'username' => 'root'],
        'mariadb' => ['port' => '33307', 'username' => 'root'],
    ];

    protected const TABLES = ['mx_items', 'mx_orders', 'mx_tickets'];

    protected static function driver(): ?string
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
        $this->dropTables();
    }

    protected function tearDown(): void
    {
        if (self::driver() !== null && $this->app !== null) {
            $this->dropTables();
        }
        parent::tearDown();
    }

    protected function dropTables(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }

    /** @param list<string> $files */
    protected function migrate(string $direction, array $files): void
    {
        foreach ($files as $file) {
            (require __DIR__.'/../../../database/migrations/'.$file.'.php')->{$direction}();
        }
    }

    protected function isPgsql(): bool
    {
        return self::driver() === 'pgsql';
    }

    protected function analyze(string ...$tables): void
    {
        foreach ($tables as $table) {
            DB::statement($this->isPgsql() ? "analyze {$table}" : "analyze table {$table}");
        }
    }

    /**
     * orders (6000 rows, unindexed payment_status), items (4000 rows, indexed by order_id and by
     * merchant_id, order_id, is_approved) and tickets (3000 rows, no index at all besides the key).
     */
    protected function seedShop(): void
    {
        Schema::create('mx_orders', function (Blueprint $t) {
            $t->increments('order_id');
            $t->string('payment_status', 12);
            $t->boolean('is_frozen')->default(false);
        });
        Schema::create('mx_items', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('order_id');
            $t->unsignedInteger('merchant_id');
            $t->boolean('is_approved')->default(false);
            $t->index('order_id', 'mx_order_id_idx');
            $t->index(['merchant_id', 'order_id', 'is_approved'], 'mx_merchant_id_2');
        });
        Schema::create('mx_tickets', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('owner_id');
            $t->boolean('is_open')->default(false);
            $t->string('title', 40)->default('t');
        });

        $statuses = ['paid', 'pending', 'failed'];
        foreach (array_chunk(range(0, 5999), 500) as $chunk) {
            DB::table('mx_orders')->insert(array_map(fn (int $i): array => ['payment_status' => $statuses[$i % 3], 'is_frozen' => (bool) ($i % 2)], $chunk));
        }
        foreach (array_chunk(range(0, 3999), 500) as $chunk) {
            DB::table('mx_items')->insert(array_map(fn (int $i): array => ['order_id' => ($i * 7) % 6000 + 1, 'merchant_id' => $i % 50 + 1, 'is_approved' => (bool) ($i % 2)], $chunk));
        }
        foreach (array_chunk(range(0, 2999), 500) as $chunk) {
            DB::table('mx_tickets')->insert(array_map(fn (int $i): array => ['owner_id' => $i % 300 + 1, 'is_open' => (bool) ($i % 2)], $chunk));
        }

        $this->analyze('mx_orders', 'mx_items', 'mx_tickets');
    }

    /** @param list<mixed> $bindings */
    protected function queryTrace(string $sql, array $bindings = [], array $extra = []): Trace
    {
        $recorder = new Recorder;
        $trace = $recorder->begin(['kind' => 'request', 'method' => 'GET', 'path' => '/x', 'status' => 200]);
        $now = hrtime(true);
        $recorder->recordClosed(SpanType::Query, 'q', $now, $now + 60_000_000, $extra + [
            'sql' => $sql, 'bindings' => $bindings, 'fingerprint' => md5($sql), 'connection' => self::driver(), 'time_ms' => 60.0, 'failed' => false,
        ], ['file' => 'app/Shop.php', 'line' => 10]);

        return $trace;
    }
}
