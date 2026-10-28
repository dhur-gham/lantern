<?php

namespace Lantern\Console;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Lantern\LanternServiceProvider;
use Lantern\Sampling\Sampler;

class StatusCommand extends Command
{
    protected $signature = 'lantern:status';

    protected $description = 'Show Lantern state and detected framework versions';

    public function handle(): int
    {
        $enabled = (bool) config('lantern.enabled') && ! config('lantern.kill_switch');

        $this->line('Lantern: '.($enabled ? 'enabled' : 'disabled'));
        $this->line('Version: '.LanternServiceProvider::VERSION);
        $this->line('Mode: '.config('lantern.mode'));
        $this->line('Laravel: '.$this->laravel->version());
        $this->line('Livewire: '.$this->version('livewire/livewire'));

        $driver = (string) config('lantern.storage.driver');
        $keep = config('lantern.keep_rate');
        $keep = $keep === null ? Sampler::defaultKeepRate() : (float) $keep;
        $this->line('Storage: '.$driver);
        $this->line('Keep rate: '.$keep);

        if ($enabled && $driver !== 'database') {
            $this->warn('Storage driver is "'.$driver.'": traces are not persisted, so CLI reads find nothing. Set LANTERN_STORAGE_DRIVER=database.');
        }
        if ($enabled && $keep < 1.0 && $this->laravel->isLocal()) {
            $this->warn('Keep rate is '.$keep.' locally: clean traces are dropped, so an "after" trace may be missing. Set LANTERN_KEEP_RATE=1.');
        }
        if ($enabled && (float) config('lantern.thresholds.explain_min_ms', 0) > 0) {
            $this->warn('thresholds.explain_min_ms is '.config('lantern.thresholds.explain_min_ms').': missing_index depends on query timing. Set LANTERN_EXPLAIN_MIN_MS=0 or re-publish the config (lantern:install --force).');
        }
        if ($enabled && $this->laravel->environment('testing')) {
            $this->warn('Lantern is enabled under the testing environment; keep it off in phpunit.xml.');
        }

        return self::SUCCESS;
    }

    private function version(string $package): string
    {
        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($package)) {
            return InstalledVersions::getPrettyVersion($package) ?? 'unknown';
        }

        return 'not installed';
    }
}
