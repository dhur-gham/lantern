<?php

declare(strict_types=1);

namespace Lantern\Tests\Stress;

use Lantern\Instrumentation\Lifecycle\TraceFormatter;
use Lantern\Storage\DatabaseTraceStore;
use Lantern\Trace\Recorder;
use Lantern\Trace\SpanType;

final class QueryPayloadTest extends StressTestCase
{
    private function binding(string $kind): mixed
    {
        $this->get('/scenario/bindings?kind='.$kind)->assertOk()->assertSee('1');
        $q = $this->queries($this->lastTrace());
        $this->assertCount(1, $q);

        return $q[0]->meta['bindings'][0];
    }

    public function test_ten_thousand_row_result(): void
    {
        $this->seedBig(10000);

        $this->get('/scenario/big-result')->assertOk()->assertSee('10000');

        $trace = $this->lastTrace();
        $this->assertCount(1, $this->queries($trace));
        $this->assertSpansClosed($trace);
    }

    public function test_giant_string_binding_is_truncated_and_response_unchanged(): void
    {
        $value = $this->binding('giant');

        $this->assertLessThanOrEqual(203, mb_strlen($value));
        $this->assertStringEndsWith('...', $value);
    }

    public function test_binary_binding_is_summarised(): void
    {
        $this->assertSame('[binary 1000 bytes]', $this->binding('binary'));
    }

    public function test_unicode_binding_is_preserved(): void
    {
        $this->assertSame('héllo wörld 日本語 Ωmega', $this->binding('unicode'));
    }

    public function test_emoji_binding_truncates_on_a_character_boundary_and_json_encodes(): void
    {
        $value = $this->binding('emoji');

        $this->assertTrue(mb_check_encoding($value, 'UTF-8'));
        $this->assertNotFalse(json_encode($this->lastTrace()->toArray()));
        $this->assertSame(200, mb_strlen(rtrim($value, '.')));
    }

    public function test_long_unicode_binding_is_truncated_by_characters_not_bytes(): void
    {
        $value = $this->binding('long-unicode');

        $this->assertSame(str_repeat('日本語', 66).'日本', mb_substr($value, 0, 200));
        $this->assertTrue(mb_check_encoding($value, 'UTF-8'));
    }

    public function test_null_binding_stays_null(): void
    {
        $this->get('/scenario/bindings?kind=null')->assertOk();

        $this->assertNull($this->queries($this->lastTrace())[0]->meta['bindings'][0]);
    }

    public function test_datetime_binding_is_formatted(): void
    {
        $this->assertSame('2024-02-29 12:34:56', $this->binding('date'));
    }

    public function test_bool_and_float_bindings_keep_their_type(): void
    {
        $this->assertTrue($this->binding('bool'));
        $this->assertSame(1.5e300, $this->binding('float'));
    }

    public function test_named_bindings_are_captured_and_secrets_redacted(): void
    {
        $this->app['router']->get('/named', function () {
            return response()->json(\Illuminate\Support\Facades\DB::select('select :a as a, :password as p, :api_token as t, :Secret_Key as s', ['a' => 1, 'password' => 'hunter2', 'api_token' => 'tok', 'Secret_Key' => 'sk']));
        });

        $this->getJson('/named')->assertOk();

        $b = $this->queries($this->lastTrace())[0]->meta['bindings'];
        $this->assertSame(1, $b['a']);
        $this->assertSame('[redacted]', $b['password']);
        $this->assertSame('[redacted]', $b['api_token']);
        $this->assertSame('[redacted]', $b['Secret_Key']);
    }

    public function test_positional_secret_values_are_not_redacted_by_design_but_truncated(): void
    {
        $this->app['router']->get('/pos', fn () => response()->json(\Illuminate\Support\Facades\DB::select('select ? as p', [str_repeat('s', 500)])));

        $this->getJson('/pos')->assertOk();

        $this->assertSame(203, strlen($this->queries($this->lastTrace())[0]->meta['bindings'][0]));
    }

    public function test_object_bindings_do_not_break_recording(): void
    {
        $this->app['router']->get('/obj', function () {
            try {
                \Illuminate\Support\Facades\DB::select('select ? as p', [new \stdClass]);
            } catch (\Throwable) {
            }

            return 'ok';
        });

        $this->get('/obj')->assertOk()->assertSee('ok');
        $this->assertSpansClosed($this->lastTrace());
    }

    public function test_nine_hundred_bindings(): void
    {
        $this->app['router']->get('/many-bindings', function () {
            $ids = range(1, 900);

            return response()->json(['n' => \Illuminate\Support\Facades\DB::table('s_authors')->whereIn('id', $ids)->count()]);
        });

        $this->getJson('/many-bindings')->assertOk()->assertJson(['n' => 10]);

        $q = $this->queries($this->lastTrace());
        $this->assertCount(900, $q[0]->meta['bindings']);
        $this->assertStringContainsString('in (?)', $q[0]->meta['normalized_sql']);
    }

    public function test_one_megabyte_sql_literal(): void
    {
        $this->get('/scenario/giant-sql')->assertOk()->assertSee('1000000');

        $q = $this->queries($this->lastTrace());
        $this->assertCount(1, $q);
        $this->assertLessThanOrEqual(80, mb_strlen($q[0]->name));
        $this->assertSame('select ? as v', $q[0]->meta['normalized_sql']);
    }

    public function test_five_thousand_queries_in_one_request_are_bounded_in_time_and_memory(): void
    {
        $before = memory_get_usage();
        $t = microtime(true);

        $this->get('/scenario/many-queries?n=5000')->assertOk()->assertSee('5000');

        $elapsed = microtime(true) - $t;
        $trace = $this->lastTrace();
        $this->assertSame(5000, $trace->meta['query_count']);
        $this->assertLessThan(20.0, $elapsed, 'tracing 5k queries took too long');
        $this->assertLessThan(120 * 1048576, memory_get_usage() - $before, 'tracing 5k queries grew memory too much');
        $this->assertNotEmpty($this->issuesOf($trace, 'n_plus_one'));
    }

    public function test_five_thousand_distinct_queries(): void
    {
        $this->get('/scenario/distinct-queries?n=5000')->assertOk();

        $trace = $this->lastTrace();
        $this->assertSame(5000, $trace->meta['query_count']);
        $this->assertEmpty($this->issuesOf($trace, 'n_plus_one'));
    }

    public function test_five_thousand_query_trace_formats_and_encodes(): void
    {
        $this->get('/scenario/many-queries?n=5000');
        $trace = $this->lastTrace();

        $text = TraceFormatter::tree($trace);

        $this->assertStringContainsString('Issues', $text);
        $this->assertNotFalse(json_encode($trace->toArray(), JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    public function test_ten_thousand_spans_nested_two_hundred_deep_do_not_crash(): void
    {
        $r = new Recorder;
        $trace = $r->begin();
        for ($chain = 0; $chain < 50; $chain++) {
            $open = [];
            for ($d = 0; $d < 200; $d++) {
                $open[] = $r->start(SpanType::Event, "s{$chain}-{$d}");
            }
            while ($open !== []) {
                $r->stop(array_pop($open));
            }
        }
        $r->end();

        $this->assertGreaterThanOrEqual(10001, count($trace->spans()));
        $array = $trace->toArray();
        $this->assertIsArray($array['root']);
        $this->assertNotEmpty(TraceFormatter::fromArray($array));
        $this->assertNotFalse(json_encode($array));
        $this->assertGreaterThan(0, $trace->peakMemory());
    }

    public function test_spans_closed_out_of_order_at_depth_are_all_stopped_by_end(): void
    {
        $r = new Recorder;
        $trace = $r->begin();
        for ($d = 0; $d < 300; $d++) {
            $r->start(SpanType::Event, 'd'.$d);
        }
        $r->end();

        foreach ($trace->spans() as $span) {
            $this->assertTrue($span->isStopped());
        }
    }

    public function test_one_thousand_deep_trace_is_stored_without_throwing(): void
    {
        $this->persist = true;
        $r = new Recorder;
        $trace = $r->begin(['kind' => 'request', 'method' => 'GET', 'path' => '/deep', 'status' => 200]);
        for ($d = 0; $d < 1000; $d++) {
            $r->start(SpanType::Event, 'd'.$d);
        }
        $r->end();
        \Illuminate\Support\Facades\Schema::dropIfExists('lantern_issues');
        \Illuminate\Support\Facades\Schema::dropIfExists('lantern_traces');
        foreach (glob(__DIR__.'/../../database/migrations/*.php') as $file) {
            (require $file)->up();
        }

        (new DatabaseTraceStore($this->app['db']))->store($trace, []);

        $found = (new DatabaseTraceStore($this->app['db']))->find($trace->id);
        $this->assertNotNull($found);
        $this->assertSame($trace->id, $found['payload']['id'] ?? null);
    }
}
