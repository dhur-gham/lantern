<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Detection;

use Lantern\Detection\Analyzer;
use Lantern\Detection\Detectors\DuplicateQueryDetector;
use Lantern\Detection\Detectors\MemoryHogDetector;
use Lantern\Detection\Detectors\NPlusOneDetector;
use Lantern\Detection\Detectors\SlowQueryDetector;
use Lantern\Tests\TestCase;
use Lantern\Trace\Recorder;
use Lantern\Trace\SpanType;
use Lantern\Trace\Trace;

final class ThresholdConfigTest extends TestCase
{
    private const KEYS = ['n_plus_one_min_repeats', 'duplicate_min_repeats', 'slow_query_ms', 'memory_delta_mb', 'explain_min_ms', 'max_issues_per_trace'];

    public function test_config_defines_every_threshold_under_thresholds_only(): void
    {
        foreach (self::KEYS as $key) {
            $this->assertNotNull(config("lantern.thresholds.$key"), $key);
            $this->assertNull(config("lantern.$key"), "$key must not also live at the top level");
        }
        $this->assertContains('sessions', config('lantern.ignore_tables'));
        $this->assertIsArray(config('lantern.ignore_sql_patterns'));
    }

    public function test_detector_sources_never_read_a_threshold_from_the_top_level(): void
    {
        $dir = dirname(__DIR__, 3).'/src/Detection';
        $files = array_merge(glob($dir.'/*.php') ?: [], glob($dir.'/Detectors/*.php') ?: []);
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            foreach (self::KEYS as $key) {
                // a read like $config['slow_query_ms'] (not preceded by ['thresholds'])
                $this->assertSame(0, preg_match('/config\[\''.$key.'\'\]/', $src), basename($file)." reads $key from the top level");
            }
        }
    }

    public function test_changed_thresholds_in_the_real_config_drive_the_detectors(): void
    {
        config([
            'lantern.thresholds.n_plus_one_min_repeats' => 3,
            'lantern.thresholds.duplicate_min_repeats' => 4,
            'lantern.thresholds.slow_query_ms' => 5,
            'lantern.thresholds.memory_delta_mb' => 1,
        ]);
        $cfg = (array) config('lantern');

        $this->assertCount(1, (new NPlusOneDetector($cfg))->detect($this->queries(3, 'x', 1.0)));
        $this->assertCount(0, (new DuplicateQueryDetector($cfg))->detect($this->queries(3, 'x', 1.0)));
        $this->assertCount(1, (new DuplicateQueryDetector($cfg))->detect($this->queries(4, 'x', 1.0)));
        $this->assertCount(1, (new SlowQueryDetector($cfg))->detect($this->queries(1, 'y', 6.0)));

        $r = new Recorder;
        $r->begin([], 0);
        $c = $r->start(SpanType::Controller, 'C@a');
        $r->stop($c);
        $c->memoryEnd = $c->memoryStart + 2 * 1048576;
        $this->assertCount(1, (new MemoryHogDetector($cfg))->detect($r->end()));

        $this->assertNotEmpty((new Analyzer($cfg))->analyze($this->queries(3, 'z', 1.0)));
    }

    private function queries(int $n, string $table, float $ms): Trace
    {
        $r = new Recorder;
        $r->begin([], 0);
        for ($i = 0; $i < $n; $i++) {
            $sql = "select * from \"$table\" where \"id\" = ?";
            $r->recordClosed(SpanType::Query, $sql, 0, (int) ($ms * 1_000_000), [
                'sql' => $sql, 'fingerprint' => md5($sql), 'bindings' => [1], 'bindings_key' => '1', 'time_ms' => $ms, 'failed' => false,
            ], ['file' => 'app/A.php', 'line' => 1]);
        }

        return $r->end();
    }
}
