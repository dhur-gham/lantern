<?php

declare(strict_types=1);

namespace Lantern\Tests\Stress;

use Illuminate\Support\Facades\DB;
use Lantern\Storage\DatabaseTraceStore;
use Lantern\Trace\Recorder;
use Lantern\Trace\SpanType;

final class StoragePersistedTest extends StressTestCase
{
    protected bool $persist = true;

    protected function lanternConfig(): array
    {
        $name = $this->name();
        $storage = ['driver' => 'database', 'connection' => null, 'compress' => true, 'batch_size' => 1];

        return match (true) {
            str_contains($name, 'gzip_off') => ['storage' => ['compress' => false] + $storage],
            str_contains($name, 'batch_five') => ['storage' => ['batch_size' => 5] + $storage],
            str_contains($name, 'keep_rate_zero') => ['keep_rate' => 0.0, 'keep_slow_ms' => 100000],
            default => [],
        };
    }

    private function row(string $id): object
    {
        return DB::table('lantern_traces')->where('id', $id)->first();
    }

    public function test_request_trace_is_persisted_with_status_and_query_count(): void
    {
        $this->get('/scenario/loop?n=6')->assertOk();

        $row = DB::table('lantern_traces')->first();
        $this->assertSame('200', $row->status);
        $this->assertSame(6, (int) $row->query_count);
        $this->assertSame('GET', $row->method);
        $this->assertGreaterThan(0, (int) $row->issue_count);
    }

    public function test_exception_request_is_persisted_with_status_500(): void
    {
        $this->getJson('/scenario/throw-after-query')->assertStatus(500);

        $row = DB::table('lantern_traces')->first();
        $this->assertNotNull($row, '500 trace was not stored');
        $this->assertSame('500', $row->status);
        $this->assertSame(1, (int) $row->query_count);
    }

    public function test_gzip_on_payload_is_prefixed_and_roundtrips(): void
    {
        $this->get('/scenario/loop?n=6');

        $row = DB::table('lantern_traces')->first();
        $this->assertStringStartsWith('gz:', $row->payload);
        $found = (new DatabaseTraceStore($this->app['db']))->find($row->id);
        $this->assertSame($row->id, $found['payload']['id']);
    }

    public function test_gzip_off_payload_is_plain_json(): void
    {
        $this->get('/scenario/loop?n=6');

        $row = DB::table('lantern_traces')->first();
        $this->assertStringStartsWith('{', $row->payload);
        $this->assertIsArray(json_decode($row->payload, true));
    }

    public function test_batch_five_still_flushes_every_request_at_terminate(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->get('/scenario/loop?n=6');
        }

        $this->assertSame(3, DB::table('lantern_traces')->count());
    }

    public function test_keep_rate_zero_drops_healthy_traces_but_keeps_errors_and_issues(): void
    {
        $this->get('/scenario/ok');
        $this->assertSame(0, DB::table('lantern_traces')->count());

        $this->getJson('/scenario/throw');
        $this->assertSame(1, DB::table('lantern_traces')->count());

        $this->get('/scenario/loop?n=6');
        $this->assertSame(2, DB::table('lantern_traces')->count());
    }

    public function test_issue_rows_merge_across_requests(): void
    {
        $this->get('/scenario/loop?n=6');
        $this->get('/scenario/loop?n=6');

        $issue = DB::table('lantern_issues')->where('type', 'n_plus_one')->first();
        $this->assertNotNull($issue);
        $this->assertSame(2, (int) $issue->occurrences);
        $this->assertSame(12, (int) $issue->count_total);
        $this->assertSame(1, DB::table('lantern_issues')->where('type', 'n_plus_one')->count());
    }

    public function test_storing_never_records_its_own_queries(): void
    {
        $this->get('/scenario/loop?n=3');

        $this->assertSame(3, (int) DB::table('lantern_traces')->first()->query_count);
        $payload = (new DatabaseTraceStore($this->app['db']))->find(DB::table('lantern_traces')->value('id'))['payload'];
        $this->assertStringNotContainsString('lantern_traces', json_encode($payload));
        $this->assertStringNotContainsString('lantern_issues', json_encode($payload));
    }

    public function test_ten_megabyte_payload_is_stored_and_request_is_unchanged(): void
    {
        $this->app['router']->get('/huge', function () {
            $sql = str_repeat('x', 200_000);
            for ($i = 0; $i < 50; $i++) {
                DB::select("select '".$sql.$i."' as v");
            }

            return 'huge';
        });

        $this->get('/huge')->assertOk()->assertSee('huge');

        $row = DB::table('lantern_traces')->first();
        $this->assertNotNull($row);
        $this->assertSame(50, (int) $row->query_count);
    }

    public function test_invalid_utf8_nan_and_resources_in_meta_do_not_throw(): void
    {
        $r = new Recorder;
        $trace = $r->begin(['kind' => 'request', 'method' => 'GET', 'path' => "/\xB1\x31", 'status' => 200, 'weird' => [NAN, INF, "\xB1\x31", fopen('php://memory', 'r')]]);
        $span = $r->start(SpanType::Event, "bad\xC3\x28name", ['sql' => "select '\xFF'", 'f' => fn () => 1]);
        $r->stop($span);
        $r->end();

        (new DatabaseTraceStore($this->app['db'], null, true))->store($trace, []);

        $found = (new DatabaseTraceStore($this->app['db']))->find($trace->id);
        $this->assertNotNull($found);
        $this->assertSame($trace->id, $found['payload']['id']);
    }

    public function test_overlong_path_and_method_are_truncated_not_rejected(): void
    {
        $r = new Recorder;
        $trace = $r->begin(['kind' => 'request', 'method' => 'PROPFINDPROPFIND', 'path' => '/'.str_repeat('é', 600), 'status' => 200]);
        $r->end();

        (new DatabaseTraceStore($this->app['db']))->store($trace, []);

        $row = $this->row($trace->id);
        $this->assertSame(10, strlen($row->method));
        $this->assertSame(255, mb_strlen($row->path));
    }

    public function test_storing_the_same_trace_twice_does_not_corrupt_the_first_row(): void
    {
        $r = new Recorder;
        $trace = $r->begin(['kind' => 'request', 'method' => 'GET', 'path' => '/dup', 'status' => 200]);
        $r->end();
        $store = new DatabaseTraceStore($this->app['db']);
        $store->store($trace, []);

        try {
            $store->store($trace, []);
        } catch (\Throwable) {
        }

        $this->assertSame(1, DB::table('lantern_traces')->where('id', $trace->id)->count());
    }

    public function test_one_hundred_requests_persist_one_hundred_rows(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $this->get('/scenario/ok');
        }

        $this->assertSame(100, DB::table('lantern_traces')->count());
    }
}
