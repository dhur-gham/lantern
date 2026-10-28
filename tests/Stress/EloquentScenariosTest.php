<?php

declare(strict_types=1);

namespace Lantern\Tests\Stress;

use Lantern\Trace\SpanType;

final class EloquentScenariosTest extends StressTestCase
{
    private function assertFlagged(string $scenario, int $min = 5): void
    {
        $this->get('/scenario/'.$scenario)->assertOk();

        $trace = $this->lastTrace();
        $found = array_merge($this->issuesOf($trace, 'n_plus_one'), $this->issuesOf($trace, 'lazy_load'));
        $this->assertNotEmpty($found, "$scenario: expected an N+1/lazy-load issue, got ".json_encode($this->issueTypes($trace)));
        $this->assertGreaterThanOrEqual($min, max(array_column($found, 'count')));
        $this->assertSpansClosed($trace);
    }

    private function assertClean(string $scenario): void
    {
        $this->get('/scenario/'.$scenario)->assertOk();

        $trace = $this->lastTrace();
        $this->assertEmpty($this->issuesOf($trace, 'n_plus_one'), "$scenario: false N+1 positive: ".json_encode(array_column($this->issuesOf($trace, 'n_plus_one'), 'title')));
        $this->assertEmpty($this->issuesOf($trace, 'lazy_load'), "$scenario: false lazy-load positive");
    }

    public function test_lazy_loading_in_a_loop_is_flagged_with_the_loop_line(): void
    {
        $this->assertFlagged('lazy-loop', 30);

        $issue = $this->issuesOf($this->lastTrace(), 'n_plus_one')[0] ?? $this->issuesOf($this->lastTrace(), 'lazy_load')[0];
        $this->assertStringEndsWith('Fixtures.php', $issue['call_site']['file']);
    }

    public function test_lazy_loop_response_is_unchanged(): void
    {
        $this->get('/scenario/lazy-loop')->assertOk()->assertSee('30');
    }

    public function test_eager_loading_is_clean(): void
    {
        $this->assertClean('eager');
        $this->assertCount(2, $this->queries($this->lastTrace()));
    }

    public function test_with_count_is_clean(): void
    {
        $this->assertClean('with-count');
        $this->assertCount(1, $this->queries($this->lastTrace()));
    }

    public function test_chunk_is_not_reported_as_n_plus_one(): void
    {
        $this->assertClean('chunk');
    }

    public function test_chunk_by_id_is_not_reported_as_n_plus_one(): void
    {
        $this->assertClean('chunk-id');
    }

    public function test_cursor_is_clean_and_complete(): void
    {
        $this->assertClean('cursor');
        $this->get('/scenario/cursor')->assertSee('30');
    }

    public function test_lazy_collection_is_not_reported_as_n_plus_one(): void
    {
        $this->assertClean('lazy');
        $this->get('/scenario/lazy')->assertSee('30');
    }

    public function test_paginate_is_clean_and_returns_a_page(): void
    {
        $this->getJson('/scenario/paginate?page=2')->assertOk()->assertJsonPath('current_page', 2)->assertJsonCount(7, 'data');

        $this->assertCount(2, $this->queries($this->lastTrace()));
        $this->assertEmpty($this->issuesOf($this->lastTrace(), 'n_plus_one'));
    }

    public function test_simple_paginate(): void
    {
        $this->getJson('/scenario/simple-paginate')->assertOk()->assertJsonCount(7, 'data');

        $this->assertCount(1, $this->queries($this->lastTrace()));
    }

    public function test_soft_delete_cycle_counts_and_is_clean(): void
    {
        $this->get('/scenario/soft-delete')->assertOk()->assertSee('27/30/3/29');

        $this->assertEmpty($this->issuesOf($this->lastTrace(), 'n_plus_one'));
        $this->assertSpansClosed($this->lastTrace());
    }

    public function test_polymorphic_lazy_load_is_flagged_for_both_types(): void
    {
        $this->get('/scenario/poly-lazy')->assertOk();

        $trace = $this->lastTrace();
        $this->assertNotEmpty($this->issuesOf($trace, 'n_plus_one') ?: $this->issuesOf($trace, 'lazy_load'));
    }

    public function test_polymorphic_eager_load_is_clean(): void
    {
        $this->assertClean('poly-eager');
        $this->assertCount(3, $this->queries($this->lastTrace()));
    }

    public function test_n_plus_one_inside_blade_foreach(): void
    {
        $this->assertFlagged('blade-n1', 30);
        $this->assertNotEmpty($this->spansOf($this->lastTrace(), SpanType::View));
    }

    public function test_blade_with_eager_loading_is_clean(): void
    {
        $this->assertClean('blade-eager');
    }

    public function test_n_plus_one_inside_api_resource(): void
    {
        $this->assertFlagged('resource-n1', 30);
    }

    public function test_api_resource_with_eager_loading_is_clean(): void
    {
        $this->assertClean('resource-eager');
    }

    public function test_n_plus_one_inside_accessor(): void
    {
        $this->assertFlagged('accessor-n1', 30);
    }

    public function test_n_plus_one_inside_observer(): void
    {
        $this->assertFlagged('observer-n1', 10);
    }

    public function test_n_plus_one_inside_event_listener(): void
    {
        $this->assertFlagged('listener-n1', 30);
    }

    public function test_n_plus_one_inside_sync_queued_job_keeps_the_request_trace(): void
    {
        $this->assertFlagged('job-n1', 30);

        $trace = $this->lastTrace();
        $this->assertSame('request', $trace->meta['kind']);
        $this->assertSame(200, $trace->meta['status']);
    }

    public function test_repeated_eloquent_requests_report_the_same_issue_each_time(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->get('/scenario/lazy-loop')->assertOk();
        }

        $counts = array_map(fn ($t) => count($t->meta['issues']), $this->sink()->all());
        $this->assertSame([$counts[0], $counts[0], $counts[0]], $counts);
        $this->assertGreaterThan(0, $counts[0]);
    }
}
