<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Lantern\Console\InstallCommand;
use Lantern\Storage\MigrationGuard;
use Lantern\Tests\TestCase;

final class MigrationTestGuardTest extends TestCase
{
    private function migration(string $file): object
    {
        return require __DIR__.'/../../../database/migrations/'.$file.'.php';
    }

    public function test_migrations_on_a_non_default_connection_are_skipped_under_tests(): void
    {
        config(['database.connections.other' => config('database.connections.'.config('database.default')), 'lantern.storage.connection' => 'other']);

        $this->assertTrue($this->app->runningUnitTests());
        $this->assertTrue(MigrationGuard::skip('other'));

        $this->migration('2024_01_01_000001_create_lantern_traces_table')->up();

        $this->assertFalse(Schema::connection('other')->hasTable('lantern_traces'));
    }

    public function test_migrations_run_on_the_default_connection_or_when_unset(): void
    {
        config(['lantern.storage.connection' => null]);
        $this->assertFalse(MigrationGuard::skip(null));
        $this->assertFalse(MigrationGuard::skip((string) config('database.default')));

        $this->migration('2024_01_01_000001_create_lantern_traces_table')->up();

        $this->assertTrue(Schema::hasTable('lantern_traces'));
    }

    public function test_sql_output_is_still_complete_for_a_non_default_connection(): void
    {
        config(['database.connections.other' => config('database.connections.'.config('database.default')), 'lantern.storage.connection' => 'other']);
        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->registerCommand($this->app->make(InstallCommand::class));

        Artisan::call('lantern:install', ['--sql' => true]);

        $this->assertMatchesRegularExpression('/create table "lantern_traces"/i', Artisan::output());
    }

    public function test_install_mentions_the_phpunit_setting(): void
    {
        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->registerCommand($this->app->make(InstallCommand::class));

        Artisan::call('lantern:install');

        $out = Artisan::output();
        $this->assertStringContainsString('LANTERN_DB_CONNECTION', $out);
        $this->assertStringContainsString('phpunit.xml', $out);
    }

    public function test_skip_reasons_are_recorded_and_shown_by_status(): void
    {
        MigrationGuard::reset();
        $this->assertNull(MigrationGuard::lastSkip());

        config(['database.connections.other' => config('database.connections.'.config('database.default')), 'lantern.storage.connection' => 'other']);
        MigrationGuard::skip('other');
        $this->assertSame('tests-other-connection', MigrationGuard::lastSkip());

        config(['database.connections.broken' => ['driver' => 'nope']]);
        MigrationGuard::reset();
        $this->assertTrue(MigrationGuard::skip('broken'));
        $this->assertSame('connection-unverified', MigrationGuard::lastSkip());

        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->registerCommand($this->app->make(\Lantern\Console\StatusCommand::class));
        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->registerCommand($this->app->make(InstallCommand::class));

        Artisan::call('lantern:status', ['--json' => true]);
        $this->assertSame('connection-unverified', json_decode(Artisan::output(), true)['migration_skip']);

        Artisan::call('lantern:status');
        $this->assertStringContainsString('could not be verified', Artisan::output());

        Artisan::call('lantern:install');
        $this->assertStringContainsString('could not be verified', Artisan::output());

        MigrationGuard::reset();
    }
}
