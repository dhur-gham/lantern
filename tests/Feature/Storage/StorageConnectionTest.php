<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Storage;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lantern\Agent\Commands;
use Lantern\Detection\Issue;
use Lantern\LanternServiceProvider;
use Lantern\Storage\DatabaseTraceStore;
use Lantern\Tests\TestCase;
use Lantern\Trace\Recorder;

final class StorageConnectionTest extends TestCase
{
    private string $file = '';

    protected function defineEnvironment($app): void
    {
        $this->file = $app->storagePath().DIRECTORY_SEPARATOR.'lantern'.DIRECTORY_SEPARATOR.'lantern.sqlite';
        @unlink($this->file);

        $app['config']->set('lantern.storage.driver', 'database');
        $app['config']->set('lantern.storage.connection', 'lantern');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        @unlink($this->file);
    }

    private function migrate(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 3).'/database/migrations');
        $kernel = $this->app->make(\Illuminate\Contracts\Console\Kernel::class);
        foreach (Commands::all() as $class) {
            $kernel->registerCommand($this->app->make($class));
        }
    }

    public function test_named_lantern_connection_gets_a_dedicated_sqlite_file(): void
    {
        $config = config('database.connections.lantern');

        $this->assertSame('sqlite', $config['driver']);
        $this->assertSame($this->file, $config['database']);
        $this->assertFileExists($this->file);
    }

    public function test_tables_traces_and_cli_reads_live_on_that_connection_not_the_default(): void
    {
        $this->migrate();

        $this->assertTrue(Schema::connection('lantern')->hasTable('lantern_traces'));
        $this->assertTrue(Schema::connection('lantern')->hasColumn('lantern_issues', 'app'));
        $this->assertFalse(Schema::connection('testing')->hasTable('lantern_traces'));

        $recorder = new Recorder;
        $trace = $recorder->begin(['kind' => 'request', 'method' => 'GET', 'path' => '/x', 'status' => 200]);
        $recorder->end();
        $issue = new Issue(Issue::TYPE_N_PLUS_ONE, 'k1', 'N+1', Issue::SEV_WARNING, 5, 5.0, ['file' => 'a.php', 'line' => 1], [1]);
        (new DatabaseTraceStore($this->app['db'], 'lantern'))->store($trace, [$issue]);

        $this->assertSame(1, DB::connection('lantern')->table('lantern_traces')->count());

        $this->assertSame(0, Artisan::call('lantern:issues', ['--format' => 'json']));
        $data = json_decode(trim(Artisan::output()), true);
        $this->assertSame(['k1'], array_column($data['issues'], 'id'));
    }

    public function test_a_connection_already_defined_under_that_name_is_left_alone(): void
    {
        config(['database.connections.lantern' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);

        $register = new \ReflectionMethod(LanternServiceProvider::class, 'registerStorageConnection');
        $register->invoke(new LanternServiceProvider($this->app));

        $this->assertSame(':memory:', config('database.connections.lantern.database'));
    }

    public function test_other_connection_names_are_not_touched(): void
    {
        config(['lantern.storage.connection' => 'reporting', 'database.connections.lantern' => null]);

        $register = new \ReflectionMethod(LanternServiceProvider::class, 'registerStorageConnection');
        $register->invoke(new LanternServiceProvider($this->app));

        $this->assertNull(config('database.connections.lantern'));
    }

    public function test_status_prints_the_storage_connection_and_warns_when_unset(): void
    {
        config(['lantern.enabled' => true]);

        Artisan::call('lantern:status');
        $named = Artisan::output();
        $this->assertStringContainsString('Storage connection: lantern', $named);
        $this->assertStringNotContainsString('LANTERN_DB_CONNECTION is not set', $named);

        config(['lantern.storage.connection' => null]);
        Artisan::call('lantern:status');
        $default = Artisan::output();
        $this->assertStringContainsString('Storage connection: default (testing)', $default);
        $this->assertStringContainsString('LANTERN_DB_CONNECTION is not set', $default);
    }

    public function test_app_name_prefers_the_lantern_setting(): void
    {
        config(['app.name' => 'Portal', 'lantern.app' => null]);
        $this->assertSame('Portal', LanternServiceProvider::appName($this->app['config']));

        config(['lantern.app' => 'shop-app']);
        $this->assertSame('shop-app', LanternServiceProvider::appName($this->app['config']));

        config(['lantern.app' => null, 'app.name' => '']);
        $this->assertNull(LanternServiceProvider::appName($this->app['config']));
    }
}
