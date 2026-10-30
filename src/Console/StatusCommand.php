<?php

namespace Lantern\Console;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Lantern\Lantern;
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
        $connection = config('lantern.storage.connection');
        $this->line('Storage: '.$driver);
        if ($driver === 'database') {
            $this->line('Storage connection: '.(is_string($connection) && $connection !== '' ? $connection : 'default ('.config('database.default').')'));
            $this->line('App: '.(LanternServiceProvider::appName($this->laravel['config']) ?? 'none'));
        }
        $this->line('Keep rate: '.$keep);

        $active = LanternServiceProvider::effectiveDetectors(config('lantern.detectors'), config('lantern.disabled_detectors'));
        $this->line('Detectors: '.($active === [] ? 'none' : implode(', ', array_map(fn (string $c): string => class_basename($c), $active))));
        if ($enabled) {
            $off = array_map(fn ($c): string => ltrim((string) $c, '\\'), (array) config('lantern.disabled_detectors', []));
            $on = array_map(fn (string $c): string => ltrim($c, '\\'), $active);
            foreach (LanternServiceProvider::packageDetectors() as $class) {
                if (! in_array(ltrim($class, '\\'), $on, true) && ! in_array(ltrim($class, '\\'), $off, true)) {
                    $this->warn(class_basename($class).' is off (not in detectors). Add \\'.ltrim($class, '\\').'::class to the detectors array in config/lantern.php, or list it in disabled_detectors to silence this.');
                }
            }
        }

        if ($enabled && $driver === 'database' && ! (is_string($connection) && $connection !== '')) {
            $this->warn('LANTERN_DB_CONNECTION is not set: traces live in the application\'s default database and move with DB_CONNECTION/DB_DATABASE. Set LANTERN_DB_CONNECTION=lantern for a dedicated SQLite file (storage/lantern/lantern.sqlite), then run php artisan migrate.');
        }

        if ($enabled && $driver !== 'database') {
            $this->warn('Storage driver is "'.$driver.'": traces are not persisted, so CLI reads find nothing. Set LANTERN_STORAGE_DRIVER=database.');
        }
        if ($enabled && $keep < 1.0 && $this->laravel->isLocal()) {
            $this->warn('Keep rate is '.$keep.' locally: clean traces are dropped, so an "after" trace may be missing. Set LANTERN_KEEP_RATE=1.');
        }
        if ($enabled && (float) config('lantern.thresholds.explain_min_ms', 0) > 0) {
            $this->warn('thresholds.explain_min_ms is '.config('lantern.thresholds.explain_min_ms').': missing_index depends on query timing. Set LANTERN_EXPLAIN_MIN_MS=0 or re-publish the config (lantern:install --force).');
        }
        if ($enabled && $this->laravel->environment('local') && ! Lantern::hasAuth() && ! $this->isLoopbackUrl((string) config('app.url'))) {
            $this->warn('Lantern is enabled with APP_ENV=local and APP_URL "'.config('app.url').'" is not loopback. If this is a server, set APP_ENV=production and gate the dashboard with Lantern::auth(); the default gate serves only loopback clients.');
        }
        if ($enabled && $this->laravel->environment('testing')) {
            $this->warn('Lantern is enabled under the testing environment; keep it off in phpunit.xml.');
        }

        return self::SUCCESS;
    }

    private function isLoopbackUrl(string $url): bool
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        return $host === 'localhost' || $host === '::1' || $host === '[::1]' || str_starts_with($host, '127.') || str_ends_with($host, '.test') || str_ends_with($host, '.localhost');
    }

    private function version(string $package): string
    {
        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($package)) {
            return InstalledVersions::getPrettyVersion($package) ?? 'unknown';
        }

        return 'not installed';
    }
}
