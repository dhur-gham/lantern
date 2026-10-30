<?php

namespace Lantern\Console;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Lantern\Lantern;
use Lantern\LanternServiceProvider;
use Lantern\Storage\MigrationGuard;
use Lantern\Sampling\Sampler;

class StatusCommand extends Command
{
    protected $signature = 'lantern:status {--json : Print the state as JSON}';

    protected $description = 'Show Lantern state and detected framework versions';

    public function handle(): int
    {
        $enabled = (bool) config('lantern.enabled') && ! config('lantern.kill_switch');

        $storageConnection = config('lantern.storage.connection');
        $migrationSkip = MigrationGuard::lastSkip() ?? MigrationGuard::check(is_string($storageConnection) && $storageConnection !== '' ? $storageConnection : null);

        if ($this->option('json')) {
            $this->getOutput()->write(json_encode([
                'schema_version' => 1,
                'schema' => 'lantern.status',
                'enabled' => $enabled,
                'version' => LanternServiceProvider::VERSION,
                'migration_skip' => $migrationSkip,
                'access' => $this->access($enabled),
            ], JSON_UNESCAPED_SLASHES)."
", false, \Symfony\Component\Console\Output\OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

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

        $access = $this->access($enabled);
        if ($access['surfaces'] > 0) {
            $this->line('Access: '.$access['surfaces'].' surface(s), cache store '.$access['cache_store']);
        }
        foreach ($access['warnings'] as $warning) {
            $this->warn($warning);
        }

        if ($migrationSkip === 'connection-unverified') {
            $this->warn('Lantern migrations were skipped because the storage connection could not be verified. Check lantern.storage.connection, then run php artisan migrate.');
        } elseif ($migrationSkip === 'tests-other-connection') {
            $this->line('Migration skip: Lantern migrations on the non-default connection are skipped under tests.');
        }

        return self::SUCCESS;
    }

    /** @return array{available: bool, surfaces: int, cache_store: string|null, host_verified: bool|null, warnings: list<string>} */
    private function access(bool $enabled): array
    {
        $surfaces = count((array) config('lantern.access.surfaces', []));
        $store = null;
        $hostVerified = null;
        $warnings = [];
        if ($surfaces === 0) {
            return ['available' => $enabled && \Lantern\Access\Access::available($this->laravel), 'surfaces' => 0, 'cache_store' => null, 'host_verified' => null, 'warnings' => []];
        }

        try {
            $store = $this->laravel->make(\Lantern\Access\AccessTokens::class)->storeName();
        } catch (\Throwable) {
        }
        if ($store === 'ArrayStore') {
            $warnings[] = 'The access cache store is "array": sign-in links minted by lantern:login are lost before the browser opens them. Set LANTERN_ACCESS_CACHE_STORE (or CACHE_STORE) to file, redis or database.';
        }
        if ($enabled && \Lantern\Access\Access::available($this->laravel)) {
            $url = (string) config('app.url');
            if (parse_url($url, PHP_URL_HOST) === null) {
                $warnings[] = 'APP_URL has no host ("'.$url.'"): lantern:login links would not open the right site. Set APP_URL to the address the browser uses.';
            } else {
                $hostVerified = $this->laravel->make(\Lantern\Access\HostCheck::class)->lastSeen() !== null;
                if (! $hostVerified) {
                    $warnings[] = \Lantern\Access\HostCheck::HINT;
                }
            }
        }

        return ['available' => $enabled && \Lantern\Access\Access::available($this->laravel), 'surfaces' => $surfaces, 'cache_store' => $store, 'host_verified' => $hostVerified, 'warnings' => $warnings];
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
