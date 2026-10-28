<?php

declare(strict_types=1);

namespace Lantern\Tests\Stress;

use Lantern\Trace\SpanType;

final class ResponseKindsTest extends StressTestCase
{
    public function test_redirect_302(): void
    {
        $this->get('/scenario/redirect')->assertRedirect('/scenario/ok');

        $trace = $this->lastTrace();
        $this->assertSame(302, $trace->meta['status']);
        $this->assertSpansClosed($trace);
    }

    public function test_permanent_redirect_301(): void
    {
        $this->get('/scenario/redirect-perm')->assertStatus(301);

        $this->assertSame(301, $this->lastTrace()->meta['status']);
    }

    public function test_redirect_away(): void
    {
        $this->get('/scenario/redirect-away')->assertRedirect('https://example.com/x');

        $this->assertSame(302, $this->lastTrace()->meta['status']);
    }

    public function test_unmatched_route_404_has_no_route_span_but_a_named_root(): void
    {
        $this->get('/definitely/not/here')->assertNotFound();

        $trace = $this->lastTrace();
        $this->assertSame(404, $trace->meta['status']);
        $this->assertSame('GET /definitely/not/here', $trace->root()->name);
        $this->assertEmpty($this->spansOf($trace, SpanType::Route));
        $this->assertSpansClosed($trace);
    }

    public function test_abort_status_codes_are_recorded(): void
    {
        foreach ([404 => 'abort404', 403 => 'abort403', 503 => 'abort503'] as $status => $name) {
            $this->get('/scenario/'.$name)->assertStatus($status);
            $this->assertSame($status, $this->lastTrace()->meta['status']);
        }
    }

    public function test_validation_422_json(): void
    {
        $this->postJson('/scenario/validate422', [])->assertStatus(422)->assertJsonValidationErrors('x');

        $trace = $this->lastTrace();
        $this->assertSame(422, $trace->meta['status']);
        $this->assertNotEmpty($this->spansOf($trace, SpanType::Validation));
        $this->assertSpansClosed($trace);
    }

    public function test_validation_422_with_database_rule_records_the_query(): void
    {
        $this->postJson('/scenario/validate-exists', ['x' => 12345])->assertStatus(422);

        $this->assertNotEmpty($this->queries($this->lastTrace()));
    }

    public function test_streamed_response_body_is_intact_and_late_queries_do_not_leak(): void
    {
        $response = $this->get('/scenario/stream');
        $response->assertOk();
        $this->assertSame('ab', $response->streamedContent());

        $this->get('/scenario/ok')->assertOk();
        $trace = $this->lastTrace();
        $this->assertSame(0, $trace->meta['query_count'] ?? 0, 'a query from the previous stream callback leaked into the next trace');
        $this->assertSpansClosed($trace);
    }

    public function test_stream_download(): void
    {
        $response = $this->get('/scenario/stream-download');
        $response->assertOk()->assertHeader('content-disposition');
        $this->assertSame('bin', $response->streamedContent());
        $this->assertSame(200, $this->lastTrace()->meta['status']);
    }

    public function test_binary_response_bytes_are_untouched(): void
    {
        $response = $this->get('/scenario/binary');

        $response->assertOk();
        $this->assertSame(4096, strlen($response->getContent()));
        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        $this->assertSpansClosed($this->lastTrace());
    }

    public function test_file_and_download_responses(): void
    {
        $this->get('/scenario/file')->assertOk();
        $this->assertSame(200, $this->lastTrace()->meta['status']);

        $this->get('/scenario/download')->assertOk()->assertDownload();
        $this->assertSame(200, $this->lastTrace()->meta['status']);
    }

    public function test_no_content_204(): void
    {
        $response = $this->get('/scenario/nocontent');
        $response->assertNoContent();
        $this->assertSame('', $response->getContent());
        $this->assertSame(204, $this->lastTrace()->meta['status']);
    }

    public function test_empty_string_response(): void
    {
        $response = $this->get('/scenario/empty')->assertOk();
        $this->assertSame('', $response->getContent());
        $this->assertSame(200, $this->lastTrace()->meta['status']);
    }

    public function test_json_responses_including_null_and_huge(): void
    {
        $this->getJson('/scenario/json')->assertOk()->assertExactJson(['a' => 1]);
        $this->getJson('/scenario/json-null')->assertOk();
        $big = $this->getJson('/scenario/json-big')->assertOk();
        $this->assertSame(1_000_000, strlen($big->json('d')));
        $this->assertSpansClosed($this->lastTrace());
    }

    public function test_head_request_is_traced_with_method(): void
    {
        $response = $this->call('HEAD', '/multi');

        $response->assertOk();
        $trace = $this->lastTrace();
        $this->assertSame('HEAD', $trace->meta['method']);
        $this->assertSame(200, $trace->meta['status']);
    }

    public function test_options_request_is_traced(): void
    {
        $this->call('OPTIONS', '/multi')->assertOk();

        $this->assertSame('OPTIONS', $this->lastTrace()->meta['method']);
    }

    public function test_method_not_allowed_405(): void
    {
        $this->get('/post-only')->assertStatus(405);

        $trace = $this->lastTrace();
        $this->assertSame(405, $trace->meta['status']);
        $this->assertSpansClosed($trace);
    }

    public function test_options_on_post_only_route_is_answered_by_the_framework_and_traced(): void
    {
        $response = $this->call('OPTIONS', '/post-only');

        $response->assertOk();
        $this->assertSame('', $response->getContent());
        $this->assertSame('OPTIONS', $this->lastTrace()->meta['method']);
        $this->assertSpansClosed($this->lastTrace());
    }

    public function test_every_request_kind_in_sequence_leaves_all_traces_valid(): void
    {
        foreach (['ok', 'redirect', 'abort404', 'nocontent', 'json', 'binary', 'throw'] as $name) {
            $this->get('/scenario/'.$name);
        }

        $traces = $this->sink()->all();
        $this->assertCount(7, $traces);
        foreach ($traces as $trace) {
            $this->assertSpansClosed($trace);
            $this->assertNotNull($trace->meta['status']);
        }
        $this->assertCount(7, array_unique(array_map(fn ($t) => $t->id, $traces)));
    }
}
