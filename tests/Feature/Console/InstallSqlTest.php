<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Lantern\Console\InstallCommand;
use Lantern\Tests\TestCase;

final class InstallSqlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->registerCommand($this->app->make(InstallCommand::class));
    }

    public function test_sql_prints_every_migration_without_touching_the_database(): void
    {
        $this->assertFalse(Schema::hasTable('lantern_traces'));

        $code = Artisan::call('lantern:install', ['--sql' => true]);
        $out = Artisan::output();

        $this->assertSame(0, $code);
        $this->assertStringStartsWith('-- Lantern migration SQL for sqlite', $out);
        foreach (['create_lantern_traces_table', 'create_lantern_issues_table', 'add_app_to_lantern_tables', 'create_lantern_plan_samples_table'] as $name) {
            $this->assertStringContainsString('-- 2024_01_01_', $out);
            $this->assertStringContainsString($name, $out);
        }
        $this->assertMatchesRegularExpression('/create table "lantern_traces"/i', $out);
        $this->assertMatchesRegularExpression('/create table "lantern_plan_samples"/i', $out);
        $this->assertStringContainsString('(already part of the create statements above)', $out);
        $this->assertDoesNotMatchRegularExpression('/alter table "lantern_traces" add column "app"/i', $out);
        $this->assertFalse(Schema::hasTable('lantern_traces'));
        $this->assertFalse(Schema::hasTable('lantern_plan_samples'));
    }

    public function test_since_limits_the_output_to_schema_added_in_that_version_or_later(): void
    {
        $code = Artisan::call('lantern:install', ['--sql' => true, '--since' => '0.4.0']);
        $out = Artisan::output();

        $this->assertSame(0, $code);
        $this->assertStringNotContainsString('create_lantern_traces_table', $out);
        $this->assertStringNotContainsString('create_lantern_issues_table', $out);
        $this->assertStringContainsString('add_app_to_lantern_tables', $out);
        $this->assertStringContainsString('create_lantern_plan_samples_table', $out);
        $this->assertMatchesRegularExpression('/alter table "lantern_issues" add column "app"/i', $out);
        $this->assertStringNotContainsString('already part of the create statements', $out);
    }

    public function test_since_0_4_1_keeps_only_the_sql_hash_column(): void
    {
        $code = Artisan::call('lantern:install', ['--sql' => true, '--since' => '0.4.1']);
        $out = Artisan::output();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('add_sql_hash_to_lantern_plan_samples_table', $out);
        $this->assertStringNotContainsString('add_app_to_lantern_tables', $out);
        $this->assertStringNotContainsString('create_lantern_plan_samples_table', $out);
        $this->assertMatchesRegularExpression('/alter table "lantern_plan_samples" add column "sql_hash"/i', $out);
    }

    public function test_every_statement_ends_with_a_semicolon_and_comments_name_the_migration(): void
    {
        Artisan::call('lantern:install', ['--sql' => true]);

        foreach (explode("\n", trim(Artisan::output())) as $line) {
            $this->assertTrue($line === '' || str_starts_with($line, '--') || str_ends_with($line, ';'), 'Unexpected line: '.$line);
        }
    }

    public function test_the_storage_connection_setting_is_restored(): void
    {
        config(['lantern.storage.connection' => 'testing']);

        Artisan::call('lantern:install', ['--sql' => true, '--connection' => 'testing']);

        $this->assertSame('testing', config('lantern.storage.connection'));
    }

    public function test_bad_arguments_exit_with_a_usage_error(): void
    {
        $this->assertSame(2, Artisan::call('lantern:install', ['--sql' => true, '--since' => 'soon']));
        $this->assertSame(2, Artisan::call('lantern:install', ['--sql' => true, '--connection' => 'nope']));
        $this->assertSame(2, Artisan::call('lantern:install', ['--since' => '0.4.0']));
    }
}
