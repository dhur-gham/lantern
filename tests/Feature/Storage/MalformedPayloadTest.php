<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Storage;

use Lantern\Storage\TraceQueries;
use Illuminate\Support\Facades\DB;
use Lantern\Tests\TestCase;

final class MalformedPayloadTest extends TestCase
{
    public function test_unreadable_gz_payload_yields_null_payload_and_error(): void
    {
        foreach (glob(__DIR__.'/../../../database/migrations/*.php') as $file) {
            (require $file)->up();
        }
        DB::table('lantern_traces')->insert([
            'id' => 'bad-gz', 'started_at' => '2026-01-01 00:00:00', 'duration_ms' => 1, 'query_count' => 0,
            'query_total_ms' => 0, 'issue_count' => 0, 'peak_memory' => 0, 'created_at' => '2026-01-01 00:00:00',
            'payload' => 'gz:'.base64_encode('garbage'),
        ]);

        $row = $this->app->make(TraceQueries::class)->trace('bad-gz');

        $this->assertNull($row['payload']);
        $this->assertSame('payload_unreadable', $row['error']);
    }
}
