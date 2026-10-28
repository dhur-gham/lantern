<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Query;

use Illuminate\Support\Facades\DB;
use Lantern\Trace\SpanType;

require_once __DIR__.'/QueryTestCase.php';

final class QueryInstrumentationTest extends QueryTestCase
{
    public function test_successful_query_span_with_meta_and_call_site(): void
    {
        config(['lantern.capture_bindings' => true]);
        $this->seedTables();
        $this->recorder()->begin();

        $line = __LINE__ + 1;
        DB::select('select * from q_authors where name = ? and id > ?', ['a', 0]);

        $span = $this->queries($this->recorder()->trace())[0];
        $this->assertSame(SpanType::Query, $span->type);
        $this->assertSame('select * from q_authors where name = ? and id > ?', $span->meta['sql']);
        $this->assertSame(['a', 0], $span->meta['bindings']);
        $this->assertFalse($span->meta['failed']);
        $this->assertNotEmpty($span->meta['fingerprint']);
        $this->assertArrayHasKey('normalized_sql', $span->meta);
        $this->assertSame('testing', $span->meta['connection']);
        $this->assertNull($span->meta['rows']);
        $this->assertIsFloat($span->meta['time_ms']);
        $this->assertEqualsWithDelta($span->meta['time_ms'], $span->durationMs(), 0.01);
        $this->assertLessThanOrEqual(80, mb_strlen($span->name));
        $this->assertSame($line, $span->callSite['line']);
        $this->assertStringEndsWith('QueryInstrumentationTest.php', $span->callSite['file']);

        $meta = $this->recorder()->trace()->meta;
        $this->assertSame(1, $meta['query_count']);
        $this->assertGreaterThan(0, $meta['query_total_ms']);
    }

    public function test_same_fingerprint_for_different_bindings(): void
    {
        $this->seedTables();
        $this->recorder()->begin();

        DB::select('select * from q_authors where id = ?', [1]);
        DB::select('select * from q_authors where id = ?', [2]);

        [$a, $b] = $this->queries($this->recorder()->trace());
        $this->assertSame($a->meta['fingerprint'], $b->meta['fingerprint']);
        $this->assertNotSame($a->meta['bindings_key'], $b->meta['bindings_key']);
    }

    public function test_bindings_redacted_and_truncated(): void
    {
        $this->seedTables();
        config(['lantern.capture_bindings' => true, 'lantern.binding_max_length' => 10]);
        $this->recorder()->begin();

        DB::select('select * from q_authors where name = :name and :password = :password', [
            'name' => str_repeat('x', 50), 'password' => 'hunter2',
        ]);

        $bindings = $this->queries($this->recorder()->trace())[0]->meta['bindings'];
        $this->assertSame(str_repeat('x', 10).'...', $bindings['name']);
        $this->assertSame('[redacted]', $bindings['password']);
    }

    public function test_bindings_off_when_configured(): void
    {
        $this->seedTables();
        config(['lantern.capture_bindings' => false]);
        $this->recorder()->begin();

        DB::select('select * from q_authors where id = ?', [1]);

        $this->assertArrayNotHasKey('bindings', $this->queries($this->recorder()->trace())[0]->meta);
    }

    public function test_failed_query_swallowed_is_flagged_on_next_query(): void
    {
        $this->seedTables();
        $this->recorder()->begin();

        try {
            DB::select('select * from nope');
        } catch (\Throwable) {
        }
        DB::select('select 1');

        $spans = $this->queries($this->recorder()->trace());
        $this->assertCount(2, $spans);
        $this->assertTrue($spans[0]->meta['failed']);
        $this->assertSame('select * from nope', $spans[0]->meta['sql']);
        $this->assertFalse($spans[1]->meta['failed']);
        $this->assertSame(1, $this->recorder()->trace()->meta['failed_query_count']);
        $this->assertSame(2, $this->recorder()->trace()->meta['query_count']);
    }

    public function test_failed_query_reported_in_request_carries_error(): void
    {
        $this->seedTables();

        $this->get('/failing')->assertStatus(500);

        $trace = $this->recorder()->trace();
        $failed = array_values(array_filter($this->queries($trace), fn ($s) => $s->meta['failed']));
        $this->assertCount(1, $failed);
        $this->assertStringContainsString('q_missing_table', $failed[0]->meta['sql']);
        $this->assertSame(\PDOException::class, $failed[0]->meta['error_class']);
        $this->assertStringContainsString('q_missing_table', $failed[0]->meta['error_message']);
        $this->assertStringEndsWith('QueryTestCase.php', $failed[0]->callSite['file']);
        $this->assertSame(1, $trace->meta['failed_query_count']);
    }

    public function test_queries_nest_under_current_span(): void
    {
        $this->get('/nested')->assertOk();

        $trace = $this->recorder()->trace();
        $inner = array_values(array_filter($trace->spans(), fn ($s) => $s->name === 'inner'))[0];
        $query = $this->queries($trace)[0];
        $this->assertSame($inner->id, $query->parentId);
    }

    public function test_transactions_recorded(): void
    {
        $this->seedTables();
        $this->recorder()->begin();

        DB::transaction(fn () => DB::select('select 1'));
        try {
            DB::transaction(function () {
                throw new \RuntimeException('x');
            });
        } catch (\RuntimeException) {
        }

        $names = array_map(
            fn ($s) => $s->name,
            array_filter($this->recorder()->trace()->spans(), fn ($s) => $s->type === SpanType::Query && ($s->meta['kind'] ?? null) === 'transaction'),
        );
        $this->assertSame(['BEGIN', 'COMMIT', 'BEGIN', 'ROLLBACK'], array_values($names));
    }

    public function test_inactive_recorder_records_nothing(): void
    {
        $this->seedTables();

        DB::select('select 1');

        $this->assertNull($this->recorder()->trace());
    }

    public function test_lazy_load_n_plus_one_resolves_to_access_line(): void
    {
        $this->seedTables();

        $this->get('/lazy')->assertOk();

        $trace = $this->recorder()->trace();
        $authorQueries = array_values(array_filter($this->queries($trace), fn ($s) => str_contains($s->meta['sql'], 'q_authors')));
        $this->assertCount(3, $authorQueries);

        $fingerprints = array_unique(array_map(fn ($s) => $s->meta['fingerprint'], $authorQueries));
        $this->assertCount(1, $fingerprints);

        foreach ($authorQueries as $span) {
            $this->assertStringEndsWith('QueryTestCase.php', $span->callSite['file']);
            $this->assertSame(QueryProbeController::$lazyLine, $span->callSite['line']);
        }
    }

    public function test_bindings_default_off_outside_local_and_redact_sensitive_values(): void
    {
        $this->seedTables();
        config(['lantern.capture_bindings' => null]);
        $this->app['env'] = 'production';
        $this->recorder()->begin();
        DB::select('select * from q_authors where id = ?', [1]);
        $q = array_values(array_filter($this->recorder()->trace()->spans(), fn ($s) => $s->type === SpanType::Query))[0];
        $this->assertArrayNotHasKey('bindings', $q->meta);

        config(['lantern.capture_bindings' => true]);
        $this->recorder()->reset();
        $this->recorder()->begin();
        DB::select('select * from q_authors where name = ? and id = ?', ['jane@example.com', 1]);
        $q = array_values(array_filter($this->recorder()->trace()->spans(), fn ($s) => $s->type === SpanType::Query))[0];
        $this->assertSame(['[redacted]', 1], array_values($q->meta['bindings']));
    }
}
