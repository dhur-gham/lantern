<?php

declare(strict_types=1);

namespace Lantern\Tests\Stress;

use Illuminate\Support\Facades\DB;

final class MultiConnectionTest extends StressTestCase
{
    protected function setUp(): void
    {
        $this->persist = str_contains($this->name(), 'storage_connection');
        parent::setUp();
    }

    protected function lanternConfig(): array
    {
        if (str_contains($this->name(), 'storage_connection')) {
            return ['storage' => ['driver' => 'database', 'connection' => 'second', 'compress' => true, 'batch_size' => 1]];
        }

        return [];
    }

    /** @return list<string> */
    private function connections(): array
    {
        return array_map(fn ($s) => $s->meta['connection'], $this->queries($this->lastTrace()));
    }

    public function test_each_query_carries_its_connection_name(): void
    {
        $this->get('/scenario/second-conn')->assertOk();

        $this->assertSame(['second', 'testing', 'second'], $this->connections());
    }

    public function test_connection_created_lazily_after_boot_is_hooked(): void
    {
        $this->app['router']->get('/lazy-conn', function () {
            DB::connection('second')->select('select 1');

            return 'ok';
        });

        $this->get('/lazy-conn')->assertOk();

        $this->assertSame(['second'], $this->connections());
    }

    public function test_read_write_split_connection_records_every_statement(): void
    {
        $this->get('/scenario/rw-conn')->assertOk();

        $this->assertSame(['rw', 'rw', 'rw'], $this->connections());
        $this->assertSame(3, $this->lastTrace()->meta['query_count']);
    }

    public function test_purged_connection_is_hooked_again(): void
    {
        $this->get('/scenario/rw-conn');
        DB::purge('rw');

        $this->get('/scenario/rw-conn')->assertOk();

        $this->assertSame(['rw', 'rw', 'rw'], $this->connections());
    }

    public function test_purged_in_memory_connection_does_not_break_the_next_request(): void
    {
        DB::purge('second');
        DB::connection('second')->getSchemaBuilder()->create('s_other', function ($t) {
            $t->id();
            $t->string('v')->nullable();
        });

        $this->get('/scenario/second-conn')->assertOk();

        $this->assertCount(3, $this->queries($this->lastTrace()));
    }

    public function test_same_sql_on_two_connections_is_not_counted_together_as_duplicates_of_one_connection(): void
    {
        $this->app['router']->get('/two-conns', function () {
            DB::connection('testing')->select('select 1 as x');
            DB::connection('second')->select('select 1 as x');

            return 'ok';
        });

        $this->get('/two-conns')->assertOk();

        $this->assertEmpty($this->issuesOf($this->lastTrace(), 'duplicate_query'), 'identical SQL on different connections is not a duplicate');
    }

    public function test_transactions_on_two_connections_record_their_own_levels(): void
    {
        $this->app['router']->get('/two-tx', function () {
            DB::connection('testing')->transaction(function () {
                DB::connection('second')->transaction(function () {
                    DB::connection('second')->select('select 1');
                    DB::connection('testing')->select('select 2');
                });
            });

            return 'ok';
        });

        $this->get('/two-tx')->assertOk();

        $levels = array_map(fn ($s) => $s->connection ?? ($s->meta['connection'].':'.$s->meta['transaction_level']), $this->queries($this->lastTrace()));
        $this->assertSame(['second:1', 'testing:1'], $levels);
    }

    public function test_storage_connection_traces_land_in_the_storage_database_and_are_not_self_recorded(): void
    {
        $this->get('/scenario/loop?n=6')->assertOk();

        $this->assertSame(1, DB::connection('second')->table('lantern_traces')->count());
        $this->assertSame(6, (int) DB::connection('second')->table('lantern_traces')->value('query_count'));
        $this->assertSame(0, DB::connection('testing')->getSchemaBuilder()->hasTable('lantern_traces') ? 1 : 0);
    }

    public function test_storage_connection_ignores_app_queries_on_that_connection_by_design(): void
    {
        $this->get('/scenario/second-conn')->assertOk();

        $payload = (new \Lantern\Storage\DatabaseTraceStore($this->app['db'], 'second'))->find(DB::connection('second')->table('lantern_traces')->value('id'));
        $names = array_map(fn ($c) => $c['meta']['connection'] ?? null, $payload['payload']['root']['children'] ?? []);
        $this->assertSame(1, (int) $payload['query_count']);
        $this->assertNotContains('second', $names);
    }
}
