<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Lantern\Detection\Analyzer;
use Lantern\Detection\Detectors\LazyLoadDetector;
use Lantern\Detection\Detectors\MemoryHogDetector;
use Lantern\Detection\Detectors\MissingIndexDetector;
use Lantern\Detection\Detectors\NPlusOneDetector;
use Lantern\Detection\Detectors\DuplicateQueryDetector;
use Lantern\Detection\Detectors\PlanFlipDetector;
use Lantern\Detection\Detectors\SlowQueryDetector;
use Lantern\LanternServiceProvider;
use Lantern\Tests\TestCase;

final class DetectorConfigTest extends TestCase
{
    /** The detectors list a 0.3.x install published, before PlanFlipDetector existed. */
    private function publishedList(): array
    {
        return [
            NPlusOneDetector::class,
            DuplicateQueryDetector::class,
            SlowQueryDetector::class,
            MemoryHogDetector::class,
            MissingIndexDetector::class,
            LazyLoadDetector::class,
        ];
    }

    private function boot(array $detectors, array $disabled = []): void
    {
        config(['lantern.detectors' => $detectors, 'lantern.disabled_detectors' => $disabled]);
        (new LanternServiceProvider($this->app))->register();
    }

    public function test_default_config_runs_plan_flip_once(): void
    {
        $detectors = config('lantern.detectors');

        $this->assertSame(1, count(array_keys($detectors, PlanFlipDetector::class, true)));
        $this->assertSame([], config('lantern.disabled_detectors'));
    }

    public function test_a_published_list_without_plan_flip_gets_it_merged(): void
    {
        $this->boot($this->publishedList());

        $this->assertContains(PlanFlipDetector::class, config('lantern.detectors'));
        $this->assertCount(7, config('lantern.detectors'));

        $this->boot(config('lantern.detectors'));
        $this->assertCount(7, config('lantern.detectors'), 'merging is idempotent');
    }

    public function test_a_leading_backslash_entry_is_not_duplicated(): void
    {
        $this->boot([...$this->publishedList(), '\\'.PlanFlipDetector::class]);

        $this->assertCount(7, config('lantern.detectors'));
    }

    public function test_the_analyzer_runs_the_merged_detector(): void
    {
        $this->boot($this->publishedList());

        $property = new \ReflectionProperty(Analyzer::class, 'detectors');
        $classes = array_map(fn ($d) => $d::class, $property->getValue(new Analyzer((array) config('lantern'))));

        $this->assertContains(PlanFlipDetector::class, $classes);
    }

    public function test_disabled_detectors_opts_out(): void
    {
        $this->boot($this->publishedList(), [PlanFlipDetector::class, '\\'.SlowQueryDetector::class]);

        $this->assertNotContains(PlanFlipDetector::class, config('lantern.detectors'));
        $this->assertNotContains(SlowQueryDetector::class, config('lantern.detectors'));
        $this->assertContains(NPlusOneDetector::class, config('lantern.detectors'));
    }

    public function test_an_older_detector_removed_from_the_list_stays_off(): void
    {
        $list = array_values(array_filter($this->publishedList(), fn (string $c): bool => $c !== MemoryHogDetector::class));
        $this->boot($list);

        $this->assertNotContains(MemoryHogDetector::class, config('lantern.detectors'));
        $this->assertContains(PlanFlipDetector::class, config('lantern.detectors'));
    }

    public function test_a_malformed_config_never_throws(): void
    {
        config(['lantern.detectors' => 'nope', 'lantern.disabled_detectors' => 5]);
        (new LanternServiceProvider($this->app))->register();

        $this->assertSame([PlanFlipDetector::class], config('lantern.detectors'));
    }

    public function test_status_lists_active_detectors_and_warns_about_ones_that_are_off(): void
    {
        config(['lantern.enabled' => true, 'lantern.kill_switch' => false, 'lantern.storage.driver' => 'database', 'lantern.keep_rate' => 1]);
        $list = array_values(array_filter($this->publishedList(), fn (string $c): bool => $c !== MemoryHogDetector::class));
        $this->boot($list);

        Artisan::call('lantern:status');
        $out = Artisan::output();

        $this->assertStringContainsString('Detectors: NPlusOneDetector', $out);
        $this->assertStringContainsString('PlanFlipDetector', $out);
        $this->assertStringContainsString('MemoryHogDetector is off (not in detectors)', $out);
        $this->assertStringContainsString('disabled_detectors', $out);
        $this->assertStringNotContainsString('PlanFlipDetector is off', $out);
    }

    public function test_status_does_not_warn_about_disabled_detectors(): void
    {
        config(['lantern.enabled' => true, 'lantern.kill_switch' => false, 'lantern.storage.driver' => 'database', 'lantern.keep_rate' => 1]);
        $list = array_values(array_filter($this->publishedList(), fn (string $c): bool => $c !== MemoryHogDetector::class));
        $this->boot($list, [MemoryHogDetector::class, PlanFlipDetector::class]);

        Artisan::call('lantern:status');
        $out = Artisan::output();

        $this->assertStringNotContainsString('is off (not in detectors)', $out);
        $this->assertStringNotContainsString('PlanFlipDetector', $out);
    }

    public function test_status_is_quiet_about_detectors_when_lantern_is_disabled(): void
    {
        config(['lantern.enabled' => false]);
        $this->boot(array_slice($this->publishedList(), 0, 2));

        Artisan::call('lantern:status');
        $out = Artisan::output();

        $this->assertStringContainsString('Detectors: ', $out);
        $this->assertStringNotContainsString('is off (not in detectors)', $out);
    }
}
