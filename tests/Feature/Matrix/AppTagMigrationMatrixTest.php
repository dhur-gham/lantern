<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Matrix;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class AppTagMigrationMatrixTest extends MatrixTestCase
{
    private const CREATE = ['2024_01_01_000001_create_lantern_traces_table', '2024_01_01_000002_create_lantern_issues_table'];

    private const APP = ['2024_01_01_000003_add_app_to_lantern_tables'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrate('down', array_reverse(self::CREATE));
        $this->migrate('up', self::CREATE);
    }

    protected function tearDown(): void
    {
        if (self::driver() !== null && $this->app !== null) {
            $this->migrate('down', array_reverse(self::CREATE));
        }
        parent::tearDown();
    }

    private function dropAppColumns(): void
    {
        foreach (['lantern_traces', 'lantern_issues'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropIndex(['app']);
                $t->dropColumn('app');
            });
        }
    }

    public function test_new_installs_already_have_the_app_column(): void
    {
        $this->assertTrue(Schema::hasColumn('lantern_traces', 'app'));
        $this->assertTrue(Schema::hasColumn('lantern_issues', 'app'));

        $this->migrate('up', self::APP);

        $this->assertTrue(Schema::hasColumn('lantern_traces', 'app'), 'adding it again is a no-op');
    }

    public function test_upgrade_adds_the_indexed_column_and_down_removes_it(): void
    {
        $this->dropAppColumns();
        $this->assertFalse(Schema::hasColumn('lantern_traces', 'app'));
        $this->assertFalse(Schema::hasColumn('lantern_issues', 'app'));

        $this->migrate('up', self::APP);
        foreach (['lantern_traces', 'lantern_issues'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'app'), $table);
            $this->assertTrue(Schema::hasIndex($table, ['app']), $table.' app index');
        }

        $this->migrate('up', self::APP);

        $this->migrate('down', self::APP);
        foreach (['lantern_traces', 'lantern_issues'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
            $this->assertFalse(Schema::hasColumn($table, 'app'), $table);
        }

        $this->migrate('up', self::APP);
        $this->assertTrue(Schema::hasColumn('lantern_issues', 'app'));
    }
}
