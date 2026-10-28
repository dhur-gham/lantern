<?php

declare(strict_types=1);

namespace Lantern\Tests\Stress;

use Illuminate\Support\Facades\DB;

/** Storage driver is "database" but storage is unusable; the request must never notice. */
final class StorageBrokenTest extends StressTestCase
{
    protected function lanternConfig(): array
    {
        $storage = ['driver' => 'database', 'connection' => null, 'compress' => true, 'batch_size' => 1];

        return [
            'keep_rate' => 1.0,
            'storage' => str_contains($this->name(), 'db_down') ? ['connection' => 'broken'] + $storage : $storage,
        ];
    }

    public function test_missing_tables_do_not_break_the_request_and_fall_back_to_memory(): void
    {
        $this->get('/scenario/loop?n=6')->assertOk()->assertSee('6');

        $this->assertNotNull($this->sink()->last());
    }

    public function test_missing_tables_do_not_break_an_exception_response(): void
    {
        $this->getJson('/scenario/throw')->assertStatus(500)->assertExactJson(['message' => 'Server Error']);
    }

    public function test_missing_tables_many_requests_in_a_row(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->get('/scenario/ok')->assertOk();
        }

        $this->assertCount(20, $this->sink()->all());
    }

    public function test_db_down_storage_connection_does_not_break_the_request(): void
    {
        $this->get('/scenario/loop?n=6')->assertOk()->assertSee('6');
        $this->getJson('/scenario/throw')->assertStatus(500);

        $this->assertNotNull($this->sink()->last());
    }

    public function test_db_down_failure_is_logged_once_not_per_request(): void
    {
        $logged = 0;
        $this->app['events']->listen(\Illuminate\Log\Events\MessageLogged::class, function ($e) use (&$logged) {
            if (str_contains($e->message, 'Lantern storage failed')) {
                $logged++;
            }
        });

        for ($i = 0; $i < 5; $i++) {
            $this->get('/scenario/ok')->assertOk();
        }

        $this->assertSame(1, $logged);
    }

    public function test_connection_purged_mid_request_does_not_break_response(): void
    {
        $this->app['router']->get('/purge', function () {
            DB::select('select 1');
            DB::purge();

            return 'purged';
        });

        $this->get('/purge')->assertOk()->assertSee('purged');
    }

    public function test_app_queries_after_storage_failures_are_still_recorded(): void
    {
        $this->get('/scenario/ok');
        $this->get('/scenario/loop?n=6');

        $this->assertSame(6, $this->lastTrace()->meta['query_count']);
    }
}
