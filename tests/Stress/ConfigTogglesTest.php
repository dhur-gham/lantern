<?php

declare(strict_types=1);

namespace Lantern\Tests\Stress;

use Lantern\Detection\Detector;
use Lantern\Detection\Detectors\NPlusOneDetector;

final class ThrowingDetector implements Detector
{
    public function __construct(private readonly array $config = []) {}

    public function detect(\Lantern\Trace\Trace $trace): array
    {
        throw new \RuntimeException('detector boom');
    }
}

final class ConfigTogglesTest extends StressTestCase
{
    protected function lanternConfig(): array
    {
        $name = $this->name();

        return match (true) {
            str_contains($name, 'ignore_paths_custom') => ['ignore_paths' => ['scenario/ok', 'scenario/ok/*']],
            str_contains($name, 'ignore_tables_empty') => ['ignore_tables' => []],
            str_contains($name, 'ignore_tables_custom') => ['ignore_tables' => ['s_authors']],
            str_contains($name, 'sql_pattern_substring') => ['ignore_sql_patterns' => ['s_authors'], 'ignore_tables' => []],
            str_contains($name, 'sql_pattern_regex') => ['ignore_sql_patterns' => ['/where id = \?/'], 'ignore_tables' => []],
            str_contains($name, 'sql_pattern_invalid') => ['ignore_sql_patterns' => ['/(unclosed', '/'], 'ignore_tables' => []],
            str_contains($name, 'bindings_off') => ['capture_bindings' => false],
            str_contains($name, 'null_in_profile') => ['capture_bindings' => null, 'mode' => 'profile', 'call_site_mode' => 'lazy'],
            str_contains($name, 'null_in_dev') => ['capture_bindings' => null, 'mode' => 'dev'],
            str_contains($name, 'normalized_off') => ['capture_normalized_sql' => false],
            str_contains($name, 'call_site_all') => ['call_site_mode' => 'all'],
            str_contains($name, 'call_site_lazy') => ['call_site_mode' => 'lazy'],
            str_contains($name, 'binding_max_length') => ['binding_max_length' => 10],
            str_contains($name, 'detectors_empty') => ['detectors' => []],
            str_contains($name, 'bogus_detector') => ['detectors' => [ThrowingDetector::class, 'Nope\\Missing\\Detector', \stdClass::class, NPlusOneDetector::class]],
            str_contains($name, 'max_issues') => ['thresholds' => ['max_issues_per_trace' => 1, 'n_plus_one_min_repeats' => 5, 'duplicate_min_repeats' => 2]],
            str_contains($name, 'sink_limit') => ['memory_sink_limit' => 3],
            str_contains($name, 'last_trace_file') => ['debug_write_last_trace' => true],
            str_contains($name, 'kill_switch_config') => ['kill_switch' => true],
            default => [],
        };
    }

    public function test_sampling_rate_one_traces_every_request(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->get('/scenario/ok');
        }

        $this->assertCount(10, $this->sink()->all());
    }

    public function test_ignore_paths_default_skips_lantern_own_paths_and_custom_ignored_route(): void
    {
        $this->get('/up')->assertOk();
        $this->get('/lantern/api/traces');

        $this->assertTrue($this->sink()->last() === null);
    }

    public function test_ignore_paths_custom_skips_exact_match_but_not_others(): void
    {
        $this->get('/scenario/ok');
        $this->assertTrue($this->sink()->last() === null);

        $this->get('/scenario/json');
        $this->assertNotNull($this->sink()->last());
    }

    public function test_ignored_request_does_not_swallow_the_next_traced_one(): void
    {
        $this->get('/up');
        $this->get('/scenario/ok')->assertOk();

        $this->assertSame('GET /scenario/{name}', $this->lastTrace()->root()->name);
        $this->assertCount(1, $this->sink()->all());
    }

    public function test_queries_in_ignored_request_are_not_recorded_anywhere(): void
    {
        $this->get('/up');
        $this->get('/scenario/ok');

        $this->assertSame(0, $this->lastTrace()->meta['query_count'] ?? 0);
    }

    public function test_ignore_tables_default_hides_framework_tables_from_n_plus_one(): void
    {
        $this->get('/scenario/sessions-loop')->assertOk();

        $this->assertEmpty($this->issuesOf($this->lastTrace(), 'n_plus_one'));
        $this->assertEmpty($this->issuesOf($this->lastTrace(), 'duplicate_query'));
    }

    public function test_ignore_tables_empty_reports_the_same_loop(): void
    {
        $this->get('/scenario/sessions-loop')->assertOk();

        $this->assertNotEmpty($this->issuesOf($this->lastTrace(), 'n_plus_one'));
    }

    public function test_ignore_tables_custom_hides_the_listed_table(): void
    {
        $this->get('/scenario/loop?n=10')->assertOk();

        $this->assertEmpty($this->issuesOf($this->lastTrace(), 'n_plus_one'));
    }

    public function test_sql_pattern_substring_hides_matching_queries(): void
    {
        $this->get('/scenario/loop?n=10')->assertOk();

        $this->assertEmpty($this->issuesOf($this->lastTrace(), 'n_plus_one'));
    }

    public function test_sql_pattern_regex_hides_matching_queries(): void
    {
        $this->get('/scenario/loop?n=10')->assertOk();

        $this->assertEmpty($this->issuesOf($this->lastTrace(), 'n_plus_one'));
    }

    public function test_sql_pattern_invalid_regex_does_not_break_the_request(): void
    {
        $this->get('/scenario/loop?n=10')->assertOk();

        $this->assertNotNull($this->sink()->last());
    }

    public function test_capture_bindings_off_stores_no_bindings(): void
    {
        $this->get('/scenario/loop?n=3')->assertOk();

        foreach ($this->queries($this->lastTrace()) as $q) {
            $this->assertArrayNotHasKey('bindings', $q->meta);
        }
    }

    public function test_capture_bindings_on_stores_bindings(): void
    {
        $this->get('/scenario/loop?n=3')->assertOk();

        $this->assertSame([1], $this->queries($this->lastTrace())[0]->meta['bindings']);
    }

    public function test_bindings_null_in_profile_mode_is_off(): void
    {
        $this->get('/scenario/loop?n=3')->assertOk();

        $this->assertArrayNotHasKey('bindings', $this->queries($this->lastTrace())[0]->meta);
    }

    public function test_bindings_null_in_dev_mode_is_on(): void
    {
        $this->get('/scenario/loop?n=3')->assertOk();

        $this->assertArrayHasKey('bindings', $this->queries($this->lastTrace())[0]->meta);
    }

    public function test_capture_bindings_off_still_detects_duplicates_via_bindings_key(): void
    {
        $this->app['config']->set('lantern.capture_bindings', false);

        $this->get('/scenario/same-loop?n=3')->assertOk();

        $this->assertNotEmpty($this->issuesOf($this->lastTrace(), 'duplicate_query'));
    }

    public function test_capture_bindings_off_distinct_params_are_not_duplicates(): void
    {
        $this->app['config']->set('lantern.capture_bindings', false);

        $this->get('/scenario/loop?n=3')->assertOk();

        $this->assertEmpty($this->issuesOf($this->lastTrace(), 'duplicate_query'));
    }

    public function test_normalized_off_omits_the_key_but_still_detects(): void
    {
        $this->get('/scenario/loop?n=6')->assertOk();

        $this->assertArrayNotHasKey('normalized_sql', $this->queries($this->lastTrace())[0]->meta);
        $this->assertNotEmpty($this->issuesOf($this->lastTrace(), 'n_plus_one'));
    }

    public function test_binding_max_length_is_respected(): void
    {
        $this->get('/scenario/bindings?kind=unicode')->assertOk();

        $this->assertSame('héllo wörl...', $this->queries($this->lastTrace())[0]->meta['bindings'][0]);
    }

    public function test_call_site_all_resolves_every_query(): void
    {
        $this->get('/scenario/loop?n=3')->assertOk();

        foreach ($this->queries($this->lastTrace()) as $q) {
            $this->assertNotNull($q->callSite);
            $this->assertStringEndsWith('Fixtures.php', $q->callSite['file']);
        }
    }

    public function test_call_site_lazy_skips_cheap_unrepeated_queries(): void
    {
        $this->get('/scenario/loop?n=3')->assertOk();

        foreach ($this->queries($this->lastTrace()) as $q) {
            $this->assertNull($q->callSite);
        }
    }

    public function test_call_site_lazy_backfills_earlier_queries_once_a_fingerprint_repeats(): void
    {
        $this->get('/scenario/loop?n=12')->assertOk();

        $trace = $this->lastTrace();
        foreach ($this->queries($trace) as $q) {
            $this->assertNotNull($q->callSite, 'span '.$q->id.' not backfilled');
        }
        $issues = $this->issuesOf($trace, 'n_plus_one');
        $this->assertCount(1, $issues);
        $this->assertSame(12, $issues[0]['count']);
    }

    public function test_call_site_lazy_exactly_at_threshold_is_detected_with_full_count(): void
    {
        $this->get('/scenario/loop?n=5')->assertOk();

        $issues = $this->issuesOf($this->lastTrace(), 'n_plus_one');
        $this->assertCount(1, $issues);
        $this->assertSame(5, $issues[0]['count']);
    }

    public function test_detectors_empty_yields_no_issues_but_a_trace(): void
    {
        $this->get('/scenario/loop?n=10')->assertOk();

        $this->assertSame(0, $this->lastTrace()->meta['issue_count']);
    }

    public function test_a_throwing_missing_or_bogus_detector_never_blocks_the_others(): void
    {
        $this->get('/scenario/loop?n=10')->assertOk()->assertSee('10');

        $this->assertNotEmpty($this->issuesOf($this->lastTrace(), 'n_plus_one'));
    }

    public function test_max_issues_per_trace_caps_the_list(): void
    {
        $this->app['router']->get('/many-issues', function () {
            foreach (['s_authors', 's_posts', 's_videos'] as $table) {
                for ($i = 0; $i < 6; $i++) {
                    \Illuminate\Support\Facades\DB::select('select * from '.$table.' where id = ?', [$i]);
                }
            }

            return 'ok';
        });

        $this->get('/many-issues')->assertOk();

        $this->assertCount(1, $this->lastTrace()->meta['issues']);
    }

    public function test_memory_sink_limit_keeps_only_the_newest(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->get('/scenario/ok');
        }

        $this->assertCount(3, $this->sink()->all());
    }

    public function test_last_trace_file_is_written_as_valid_json(): void
    {
        $file = storage_path('lantern/last-trace.json');
        @unlink($file);

        $this->get('/scenario/loop?n=6')->assertOk();

        $this->assertFileExists($file);
        $data = json_decode((string) file_get_contents($file), true);
        $this->assertSame($this->lastTrace()->id, $data['id']);
        @unlink($file);
    }

    public function test_kill_switch_config_records_nothing(): void
    {
        $this->assertFalse($this->app->bound(\Lantern\Trace\Recorder::class));
        $this->get('/scenario/loop?n=10')->assertOk()->assertSee('10');
    }
}
