<?php

declare(strict_types=1);

namespace Lantern\Tests\Stress;

use Lantern\Trace\SpanType;

final class ExceptionPathsTest extends StressTestCase
{
    public function test_baseline_request_is_traced_and_setup_queries_do_not_leak(): void
    {
        $this->get('/scenario/ok')->assertOk()->assertSee('ok');

        $trace = $this->lastTrace();
        $this->assertSame(200, $trace->meta['status']);
        $this->assertSame(0, $trace->meta['query_count'] ?? 0, 'schema/seed queries leaked into the first request trace');
        $this->assertSpansClosed($trace);
    }

    public function test_controller_exception_keeps_response_and_closes_spans(): void
    {
        $this->getJson('/scenario/throw')->assertStatus(500)->assertExactJson(['message' => 'Server Error']);

        $trace = $this->lastTrace();
        $this->assertSame(500, $trace->meta['status']);
        $this->assertSpansClosed($trace);
        $this->assertNotEmpty($this->spansOf($trace, SpanType::Controller));
    }

    public function test_html_exception_page_status_is_500(): void
    {
        $this->get('/scenario/throw')->assertStatus(500);

        $this->assertSame(500, $this->lastTrace()->meta['status']);
    }

    public function test_exception_after_query_records_the_query_and_closes_everything(): void
    {
        $this->getJson('/scenario/throw-after-query')->assertStatus(500);

        $trace = $this->lastTrace();
        $this->assertCount(1, $this->queries($trace));
        $this->assertSame(500, $trace->meta['status']);
        $this->assertSpansClosed($trace);
    }

    public function test_middleware_exception(): void
    {
        $this->getJson('/mw-throw')->assertStatus(500)->assertExactJson(['message' => 'Server Error']);

        $trace = $this->lastTrace();
        $this->assertSame(500, $trace->meta['status']);
        $this->assertSpansClosed($trace);
        $this->assertEmpty($this->spansOf($trace, SpanType::Controller));
    }

    public function test_middleware_short_circuit_never_reaches_controller(): void
    {
        $this->get('/mw-short')->assertStatus(202)->assertSee('short');

        $trace = $this->lastTrace();
        $this->assertSame(202, $trace->meta['status']);
        $this->assertSpansClosed($trace);
        $this->assertNotEmpty($this->spansOf($trace, SpanType::Middleware));
    }

    public function test_query_inside_middleware_is_attributed_under_a_middleware_span(): void
    {
        $this->get('/mw-query')->assertOk();

        $trace = $this->lastTrace();
        $q = $this->queries($trace);
        $this->assertCount(1, $q);
        $this->assertSame(SpanType::Middleware, $trace->spans()[$q[0]->parentId]->type);
    }

    public function test_view_exception_closes_view_span(): void
    {
        $this->getJson('/scenario/throw-in-view')->assertStatus(500);

        $trace = $this->lastTrace();
        $this->assertSame(500, $trace->meta['status']);
        $this->assertSpansClosed($trace);
        $this->assertNotEmpty($this->spansOf($trace, SpanType::View));
    }

    public function test_form_request_rules_exception(): void
    {
        $this->postJson('/fr/rules', [])->assertStatus(500);

        $trace = $this->lastTrace();
        $this->assertSame(500, $trace->meta['status']);
        $this->assertSpansClosed($trace);
    }

    public function test_form_request_authorize_exception(): void
    {
        $this->postJson('/fr/authorize', [])->assertStatus(500);

        $this->assertSpansClosed($this->lastTrace());
    }

    public function test_form_request_prepare_for_validation_exception(): void
    {
        $this->postJson('/fr/prepare', [])->assertStatus(500);

        $this->assertSpansClosed($this->lastTrace());
    }

    public function test_form_request_validation_failure_is_422_with_validation_span_and_exists_query(): void
    {
        $this->postJson('/fr/strict', ['title' => 'x', 'author_id' => 99999])->assertStatus(422);

        $trace = $this->lastTrace();
        $this->assertSame(422, $trace->meta['status']);
        $this->assertSpansClosed($trace);
        $this->assertNotEmpty($this->spansOf($trace, SpanType::Validation));
        $this->assertNotEmpty($this->queries($trace), 'the exists rule query must be recorded');
    }

    public function test_form_request_passing_validation(): void
    {
        $this->postJson('/fr/strict', ['title' => 'x', 'author_id' => 1])->assertOk()->assertExactJson(['ok' => true]);

        $this->assertSpansClosed($this->lastTrace());
    }

    public function test_event_listener_exception_propagates_unchanged(): void
    {
        $this->getJson('/scenario/throw-in-listener')->assertStatus(500);

        $trace = $this->lastTrace();
        $this->assertSame(500, $trace->meta['status']);
        $this->assertSpansClosed($trace);
    }

    public function test_event_listener_exception_caught_by_app_returns_200(): void
    {
        $this->get('/scenario/listener-caught')->assertOk()->assertSee('caught');

        $this->assertSame(200, $this->lastTrace()->meta['status']);
    }

    public function test_api_resource_exception(): void
    {
        $this->getJson('/scenario/throw-in-resource')->assertStatus(500);

        $this->assertSpansClosed($this->lastTrace());
    }

    public function test_request_after_an_exception_starts_a_clean_trace(): void
    {
        $this->get('/scenario/ok');
        $baseline = count($this->lastTrace()->spans());

        $this->getJson('/scenario/throw-after-query');
        $this->get('/scenario/ok')->assertOk();

        $trace = $this->lastTrace();
        $this->assertSame(200, $trace->meta['status']);
        $this->assertSame($baseline, count($trace->spans()));
        $this->assertSame(0, $trace->meta['query_count'] ?? 0);
        $this->assertCount(3, $this->sink()->all());
    }

    public function test_repeated_exceptions_never_accumulate_open_spans(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->getJson('/scenario/throw-after-query')->assertStatus(500);
        }

        foreach ($this->sink()->all() as $trace) {
            $this->assertSpansClosed($trace);
            $this->assertSame(1, $trace->meta['query_count']);
        }
    }
}
