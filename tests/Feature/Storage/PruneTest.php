<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Storage;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lantern\Console\PruneCommand;
use Lantern\Storage\Pruner;
use Lantern\Tests\TestCase;

final class PruneTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('lantern_traces', function (Blueprint $t) {
            $t->id();
            $t->dateTime('started_at')->index();
            $t->dateTime('created_at')->nullable();
        });
        Schema::create('lantern_issues', function (Blueprint $t) {
            $t->string('issue_key')->primary();
            $t->dateTime('last_seen')->index();
        });

        foreach ([1, 3, 10, 20] as $d) {
            DB::table('lantern_traces')->insert(['started_at' => now()->subDays($d)]);
        }
        foreach (['a' => 5, 'b' => 20, 'c' => 40, 'd' => 60] as $k => $d) {
            DB::table('lantern_issues')->insert(['issue_key' => $k, 'last_seen' => now()->subDays($d)]);
        }
    }

    public function test_old_rows_deleted_recent_kept(): void
    {
        $r = (new Pruner)->prune();

        $this->assertSame(['traces' => 2, 'issues' => 2], $r);
        $this->assertSame(2, DB::table('lantern_traces')->count());
        $this->assertSame(2, DB::table('lantern_issues')->count());
    }

    public function test_issue_retention_is_independent(): void
    {
        $r = (new Pruner)->prune(traceDays: 365, issueDays: 10);

        $this->assertSame(['traces' => 0, 'issues' => 3], $r);
        $this->assertSame(4, DB::table('lantern_traces')->count());
        $this->assertSame(1, DB::table('lantern_issues')->count());
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $r = (new Pruner)->prune(dryRun: true);

        $this->assertSame(['traces' => 2, 'issues' => 2], $r);
        $this->assertSame(4, DB::table('lantern_traces')->count());
    }

    public function test_chunking_handles_more_than_one_chunk(): void
    {
        $rows = [];
        for ($i = 0; $i < 2500; $i++) {
            $rows[] = ['started_at' => now()->subDays(30)];
        }
        foreach (array_chunk($rows, 500) as $c) {
            DB::table('lantern_traces')->insert($c);
        }

        $this->assertSame(2502, (new Pruner)->prune()['traces']);
    }

    public function test_command_options(): void
    {
        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->registerCommand($this->app->make(PruneCommand::class));

        $this->artisan('lantern:prune', ['--days' => 2, '--issues-days' => 100, '--dry-run' => true])
            ->expectsOutput('Would delete 3 traces and 0 issues.')
            ->assertSuccessful();

        $this->artisan('lantern:prune', ['--days' => 2])
            ->expectsOutput('Deleted 3 traces and 2 issues.')
            ->assertSuccessful();
        $this->assertSame(1, DB::table('lantern_traces')->count());
    }
}
