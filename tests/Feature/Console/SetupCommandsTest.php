<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Lantern\Console\InstallCommand;
use Lantern\Console\StatusCommand;
use Lantern\Tests\TestCase;

final class SetupCommandsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $kernel = $this->app->make(\Illuminate\Contracts\Console\Kernel::class);
        $kernel->registerCommand($this->app->make(StatusCommand::class));
        $kernel->registerCommand($this->app->make(InstallCommand::class));
    }

    public function test_status_warns_when_traces_would_be_missing(): void
    {
        config(['lantern.enabled' => true, 'lantern.kill_switch' => false, 'lantern.storage.driver' => 'memory', 'lantern.keep_rate' => 0.1]);
        $this->app['env'] = 'local';

        Artisan::call('lantern:status');
        $out = Artisan::output();

        $this->assertStringContainsString('Storage driver is "memory"', $out);
        $this->assertStringContainsString('Keep rate is 0.1 locally', $out);

        config(['lantern.thresholds.explain_min_ms' => 2]);
        Artisan::call('lantern:status');
        $this->assertStringContainsString('explain_min_ms is 2', Artisan::output());

        $this->app['env'] = 'testing';
        Artisan::call('lantern:status');
        $this->assertStringContainsString('enabled under the testing environment', Artisan::output());
    }

    public function test_status_is_quiet_when_configured(): void
    {
        config(['lantern.enabled' => true, 'lantern.kill_switch' => false, 'lantern.storage.driver' => 'database', 'lantern.keep_rate' => 1]);
        $this->app['env'] = 'local';

        Artisan::call('lantern:status');

        $this->assertStringNotContainsString('Keep rate is', Artisan::output());
        $this->assertStringNotContainsString('Storage driver is', Artisan::output());
    }

    public function test_install_local_writes_env_lines_and_updates_existing(): void
    {
        $path = base_path('.env');
        $had = is_file($path) ? file_get_contents($path) : null;
        file_put_contents($path, "APP_NAME=x\nLANTERN_KEEP_RATE=0.1\n");

        try {
            Artisan::call('lantern:install', ['--local' => true]);
            $env = (string) file_get_contents($path);

            $this->assertStringContainsString("APP_NAME=x\n", $env);
            $this->assertStringContainsString("LANTERN_ENABLED=true\n", $env);
            $this->assertStringContainsString("LANTERN_STORAGE_DRIVER=database\n", $env);
            $this->assertSame(1, substr_count($env, 'LANTERN_KEEP_RATE='));
            $this->assertStringContainsString("LANTERN_KEEP_RATE=1\n", $env);
        } finally {
            $had === null ? @unlink($path) : file_put_contents($path, $had);
        }
    }

    public function test_install_local_twice_reports_nothing_changed(): void
    {
        $path = base_path('.env');
        $had = is_file($path) ? file_get_contents($path) : null;
        file_put_contents($path, "APP_NAME=x
");

        try {
            Artisan::call('lantern:install', ['--local' => true]);
            $this->assertStringNotContainsString('enable it', Artisan::output());
            $first = (string) file_get_contents($path);

            Artisan::call('lantern:install', ['--local' => true]);
            $this->assertStringContainsString('already has', Artisan::output());
            $this->assertSame($first, (string) file_get_contents($path));
        } finally {
            $had === null ? @unlink($path) : file_put_contents($path, $had);
            foreach (glob(database_path('migrations').'/*_create_lantern_*') ?: [] as $f) {
                @unlink($f);
            }
        }
    }

    public function test_migrations_publish_under_their_packaged_names(): void
    {
        $paths = \Illuminate\Support\ServiceProvider::pathsToPublish(\Lantern\LanternServiceProvider::class, 'lantern-migrations');
        $this->assertSame([database_path('migrations')], array_values($paths));

        $source = (string) array_key_first($paths);
        $this->assertSame(
            ['2024_01_01_000001_create_lantern_traces_table.php', '2024_01_01_000002_create_lantern_issues_table.php'],
            array_values(array_diff((array) scandir($source), ['.', '..'])),
        );
    }
}
