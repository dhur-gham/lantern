<?php

declare(strict_types=1);

namespace Lantern\Tests\Stress;

use Lantern\Trace\Span;
use Lantern\Trace\SpanType;
use Lantern\Trace\Trace;

final class TransactionAndFailureTest extends StressTestCase
{
    /** @return list<string> names of transaction marker spans, in order */
    private function txNames(Trace $trace): array
    {
        return array_values(array_map(
            fn (Span $s) => $s->name,
            array_filter($trace->spans(), fn (Span $s) => $s->type === SpanType::Query && ($s->meta['kind'] ?? null) === 'transaction'),
        ));
    }

    /** @return list<Span> */
    private function failed(Trace $trace): array
    {
        return array_values(array_filter($this->queries($trace), fn (Span $s) => $s->meta['failed'] === true));
    }

    public function test_simple_transaction_markers(): void
    {
        $this->get('/scenario/tx-commit')->assertOk();

        $tx = $this->txNames($this->lastTrace());
        $this->assertSame('BEGIN', $tx[0]);
        $this->assertSame('COMMIT', end($tx));
        $this->assertCount(1, $this->queries($this->lastTrace()));
    }

    public function test_nested_transactions_use_savepoints_and_record_levels(): void
    {
        $this->get('/scenario/tx-nested-ok')->assertOk();

        $trace = $this->lastTrace();
        $levels = array_map(fn (Span $s) => $s->meta['transaction_level'], $this->queries($trace));
        $this->assertSame([1, 2], $levels);
        $this->assertSame(2, count(array_keys($this->txNames($trace), 'BEGIN')));
        $this->assertSpansClosed($trace);
    }

    public function test_inner_rollback_caught_by_app_outer_commits(): void
    {
        $this->get('/scenario/tx-inner-rollback')->assertOk()->assertSee('author2');

        $names = $this->txNames($this->lastTrace());
        $this->assertContains('ROLLBACK', $names);
        $this->assertSame('COMMIT', end($names));
        $this->assertSpansClosed($this->lastTrace());
    }

    public function test_outer_rollback_discards_inner_commit(): void
    {
        $this->get('/scenario/tx-outer-rollback')->assertOk()->assertSee('author1');

        $names = $this->txNames($this->lastTrace());
        $this->assertContains('ROLLBACK', $names);
        $this->assertSpansClosed($this->lastTrace());
    }

    public function test_manual_begin_rollback_commit_balances(): void
    {
        $this->get('/scenario/tx-manual')->assertOk()->assertSee('0');

        $names = $this->txNames($this->lastTrace());
        $this->assertSame(2, count(array_keys($names, 'BEGIN')));
        $this->assertContains('ROLLBACK', $names);
        $this->assertContains('COMMIT', $names);
    }

    public function test_deeply_nested_savepoints(): void
    {
        $this->get('/scenario/tx-deep?n=30')->assertOk();

        $trace = $this->lastTrace();
        $q = $this->queries($trace);
        $this->assertCount(1, $q);
        $this->assertSame(30, $q[0]->meta['transaction_level']);
        $this->assertSpansClosed($trace);
    }

    public function test_transaction_does_not_leak_level_into_next_request(): void
    {
        $this->get('/scenario/tx-deep?n=5');
        $this->get('/scenario/ok');

        $this->assertSame(0, $this->lastTrace()->meta['query_count'] ?? 0);
    }

    public function test_syntax_error_caught_by_app_is_flagged_failed_and_response_ok(): void
    {
        $this->get('/scenario/catch-syntax')->assertOk()->assertSee('caught');

        $trace = $this->lastTrace();
        $failed = $this->failed($trace);
        $this->assertCount(1, $failed);
        $this->assertSame(1, $trace->meta['failed_query_count']);
        $this->assertSpansClosed($trace);
    }

    public function test_missing_table_failure(): void
    {
        $this->get('/scenario/catch-missing')->assertOk()->assertSee('caught');

        $this->assertCount(1, $this->failed($this->lastTrace()));
    }

    public function test_unique_constraint_violation_is_failed_and_first_insert_is_ok(): void
    {
        $this->get('/scenario/catch-unique')->assertOk()->assertSee('caught');

        $trace = $this->lastTrace();
        $this->assertCount(1, $this->failed($trace));
        $this->assertCount(2, $this->queries($trace));
    }

    public function test_reported_query_exception_adds_error_details(): void
    {
        $this->get('/scenario/catch-reported')->assertOk();

        $failed = $this->failed($this->lastTrace());
        $this->assertCount(1, $failed);
        $this->assertNotEmpty($failed[0]->meta['error_class'] ?? null, 'reported QueryException should attach the error class');
    }

    public function test_failure_followed_by_success_does_not_mislabel_the_success(): void
    {
        $this->get('/scenario/fail-then-ok')->assertOk();

        $q = $this->queries($this->lastTrace());
        $this->assertCount(2, $q);
        $this->assertTrue($q[0]->meta['failed']);
        $this->assertFalse($q[1]->meta['failed']);
    }

    public function test_same_failing_sql_twice_gives_two_failed_spans(): void
    {
        $this->get('/scenario/catch-twice')->assertOk();

        $this->assertCount(2, $this->failed($this->lastTrace()));
    }

    public function test_failure_as_last_statement_is_flushed_at_request_end(): void
    {
        $this->get('/scenario/fail-last')->assertOk();

        $trace = $this->lastTrace();
        $this->assertCount(1, $this->failed($trace));
        $this->assertSame(1, $trace->meta['query_count']);
    }

    public function test_fifty_caught_failures(): void
    {
        $this->get('/scenario/catch-many?n=50')->assertOk();

        $trace = $this->lastTrace();
        $this->assertCount(50, $this->failed($trace));
        $this->assertSame(50, $trace->meta['failed_query_count']);
        $this->assertSpansClosed($trace);
    }

    public function test_failed_queries_are_not_reported_as_slow_or_n_plus_one(): void
    {
        $this->get('/scenario/catch-many?n=30')->assertOk();

        $types = $this->issueTypes($this->lastTrace());
        $this->assertNotContains('n_plus_one', $types);
        $this->assertNotContains('slow_query', $types);
    }

    public function test_deadlock_retries_run_the_callback_three_times_and_do_not_break(): void
    {
        $this->get('/scenario/deadlock')->assertOk()->assertSee('attempts=3');

        $names = $this->txNames($this->lastTrace());
        $this->assertSame(3, count(array_keys($names, 'BEGIN')));
        $this->assertSame(3, count(array_keys($names, 'ROLLBACK')));
        $this->assertSpansClosed($this->lastTrace());
    }

    public function test_failure_in_one_request_does_not_pollute_the_next(): void
    {
        $this->get('/scenario/catch-syntax');
        $this->get('/scenario/ok');
        $this->get('/scenario/fail-then-ok');

        $traces = $this->sink()->all();
        $this->assertSame(0, $traces[1]->meta['query_count'] ?? 0);
        $this->assertSame(1, $traces[2]->meta['failed_query_count']);
    }
}
