<?php

namespace Lantern;

use Illuminate\Support\ServiceProvider;
use Lantern\Console\InstallCommand;
use Lantern\Console\PruneCommand;
use Lantern\Detection\Analyzer;
use Lantern\Http\Middleware\Authorize;
use Lantern\Storage\TraceQueries;
use Lantern\Console\StatusCommand;
use Lantern\Console\TraceDumpCommand;
use Lantern\Instrumentation\Eloquent\LazyLoadInstrumentation;
use Lantern\Instrumentation\Lifecycle\LifecycleInstrumentation;
use Lantern\Instrumentation\Lifecycle\MemorySink;
use Lantern\Instrumentation\Lifecycle\PersistSink;
use Lantern\Sampling\Sampler;
use Lantern\Storage\DatabaseTraceStore;
use Lantern\Storage\TraceStore;
use Lantern\Instrumentation\Lifecycle\TraceSink;
use Lantern\Instrumentation\Livewire\LivewireInstrumentation;
use Lantern\Instrumentation\Middleware\MiddlewareInstrumentation;
use Lantern\Instrumentation\Query\QueryInstrumentation;
use Lantern\Query\CallSiteResolver;
use Lantern\Trace\Recorder;

class LanternServiceProvider extends ServiceProvider
{
    public const VERSION = '0.5.1';

    /** Detectors added after 0.3: merged into a published `detectors` list that predates them. */
    public const ADDED_DETECTORS = [\Lantern\Detection\Detectors\PlanFlipDetector::class];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/lantern.php', 'lantern');
        $this->mergeDetectors();
        $this->ignoreOwnPath();

        // Readers (CLI, MCP, dashboard) use the configured storage connection even while tracing is off.
        $this->app->singleton(TraceQueries::class, fn ($app) => new TraceQueries($app['db'], $app['config']->get('lantern.storage.connection')));

        if (! $this->tracingEnabled()) {
            return;
        }

        $this->app->scoped(Recorder::class);
        // Head sampling: single-execution processes decide once here (an unsampled one records nothing and registers
        // no hooks). Long-lived workers keep hooks registered and decide per execution at trace begin.
        $sampler = new Sampler((array) $this->app['config']->get('lantern', []));
        if (! isset($_SERVER['LARAVEL_OCTANE']) && ! $this->app->runningInConsole()) {
            if (! $sampler->headDecision()) {
                return;
            }
            $sampler->grantFirst();
        }

        $this->app->singleton(MemorySink::class, fn ($app) => new MemorySink((int) $app['config']->get('lantern.memory_sink_limit', 20)));
        $this->app->instance(Sampler::class, $sampler);
        $this->app->singleton(TraceStore::class, fn ($app) => new DatabaseTraceStore(
            $app['db'],
            $app['config']->get('lantern.storage.connection'),
            (bool) $app['config']->get('lantern.storage.compress', false),
            self::appName($app['config']),
        ));
        $this->app->singleton(TraceSink::class, function ($app) {
            $config = $app['config'];
            if ($config->get('lantern.storage.driver', 'memory') === 'database' && interface_exists(TraceStore::class) && $app->bound(TraceStore::class)) {
                return new PersistSink(
                    $app->make(TraceStore::class),
                    $app->make(Sampler::class),
                    $app->make(MemorySink::class),
                    $app,
                    (int) $config->get('lantern.storage.batch_size', 1),
                );
            }

            return $app->make(MemorySink::class);
        });

        $this->app->singleton(Analyzer::class, fn ($app) => new Analyzer((array) $app['config']->get('lantern', [])));

        // Jobs/commands listen before Lifecycle so a job trace is finished before its JobProcessed reset runs.
        if (class_exists(\Lantern\Instrumentation\Job\JobInstrumentation::class)) {
            \Lantern\Instrumentation\Job\JobInstrumentation::register($this->app);
        }
        if (class_exists(\Lantern\Instrumentation\Command\CommandInstrumentation::class)) {
            \Lantern\Instrumentation\Command\CommandInstrumentation::register($this->app);
        }

        // Lifecycle first: it begins the trace as early as a provider can.
        $lifecycle = new LifecycleInstrumentation($this->app);
        $this->app->instance(LifecycleInstrumentation::class, $lifecycle);
        $lifecycle->register();

        if (class_exists(MiddlewareInstrumentation::class)) {
            MiddlewareInstrumentation::register($this->app);
        }

        if (class_exists(QueryInstrumentation::class) && class_exists(CallSiteResolver::class)) {
            $queries = new QueryInstrumentation($this->app);
            $this->app->instance(QueryInstrumentation::class, $queries);
            $queries->register();
        }

        if (class_exists(\Livewire\Livewire::class) && class_exists(LivewireInstrumentation::class)) {
            LivewireInstrumentation::register($this->app);
        }

        if (class_exists(\Lantern\Instrumentation\Filament\FilamentInstrumentation::class)) {
            \Lantern\Instrumentation\Filament\FilamentInstrumentation::register($this->app);
        }

        if (class_exists(LazyLoadInstrumentation::class)) {
            $this->app->booted(fn ($app) => LazyLoadInstrumentation::register($app));
        }
    }

    public function boot(): void
    {
        $this->registerStorageConnection();
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'lantern');
        $this->registerRoutes();

        // Local MCP server for AI agents; a no-op unless laravel/mcp is installed.
        if (class_exists(\Lantern\Mcp\Server::class)) {
            \Lantern\Mcp\Server::register();
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/lantern.php' => config_path('lantern.php'),
            ], 'lantern-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'lantern-migrations');

            $this->commands(array_values(array_filter([
                InstallCommand::class, StatusCommand::class, TraceDumpCommand::class, PruneCommand::class,
                ...\Lantern\Agent\Commands::all(),
            ], 'class_exists')));
        }
    }

    /**
     * The detectors that run: the configured list, plus detectors added since 0.3 that an older published list
     * lacks, minus `disabled_detectors`. Older detectors removed from a published list stay off.
     *
     * @return list<string>
     */
    public static function effectiveDetectors(mixed $published, mixed $disabled): array
    {
        $list = array_values(array_filter(is_array($published) ? $published : [], 'is_string'));
        $have = array_map(fn (string $c): string => ltrim($c, '\\'), $list);
        foreach (self::ADDED_DETECTORS as $class) {
            if (! in_array($class, $have, true)) {
                $list[] = $class;
            }
        }
        $off = array_map(fn (string $c): string => ltrim($c, '\\'), array_filter(is_array($disabled) ? $disabled : [], 'is_string'));

        return array_values(array_filter($list, fn (string $c): bool => ! in_array(ltrim($c, '\\'), $off, true)));
    }

    /** @return list<string> the detectors shipped in the package's default config */
    public static function packageDetectors(): array
    {
        static $defaults = null;
        if ($defaults === null) {
            try {
                $config = require __DIR__.'/../config/lantern.php';
                $defaults = array_values(array_filter((array) ($config['detectors'] ?? []), 'is_string'));
            } catch (\Throwable) {
                $defaults = [];
            }
        }

        return $defaults;
    }

    private function mergeDetectors(): void
    {
        try {
            $config = $this->app['config'];
            $config->set('lantern.detectors', self::effectiveDetectors($config->get('lantern.detectors'), $config->get('lantern.disabled_detectors')));
        } catch (\Throwable) {
        }
    }

    /** Name stored on each trace and issue: `lantern.app`, else `app.name`. */
    public static function appName(\Illuminate\Contracts\Config\Repository $config): ?string
    {
        $name = $config->get('lantern.app') ?: $config->get('app.name');

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * `LANTERN_DB_CONNECTION=lantern` without a connection of that name gets a dedicated SQLite file, so traces
     * stay put when the application's own database settings change. Failures are swallowed: storage then reports
     * its own error when read or written.
     */
    private function registerStorageConnection(): void
    {
        $config = $this->app['config'];
        if ($config->get('lantern.storage.connection') !== 'lantern' || $config->get('database.connections.lantern') !== null) {
            return;
        }

        try {
            $dir = storage_path('lantern');
            $file = $dir.DIRECTORY_SEPARATOR.'lantern.sqlite';
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            if (! is_file($file)) {
                @touch($file);
            }
            $config->set('database.connections.lantern', [
                'driver' => 'sqlite',
                'database' => $file,
                'prefix' => '',
                'foreign_key_constraints' => false,
            ]);
        } catch (\Throwable) {
        }
    }

    private function ignoreOwnPath(): void
    {
        $config = $this->app['config'];
        $path = trim((string) $config->get('lantern.path', 'lantern'), '/');
        $ignored = (array) $config->get('lantern.ignore_paths', []);
        foreach ([$path, $path.'/*'] as $pattern) {
            if (! in_array($pattern, $ignored, true)) {
                $ignored[] = $pattern;
            }
        }
        $config->set('lantern.ignore_paths', $ignored);
    }

    private function registerRoutes(): void
    {
        if (! $this->tracingEnabled() || $this->app->routesAreCached()) {
            return;
        }

        $config = $this->app['config'];
        \Illuminate\Support\Facades\Route::group([
            'domain' => $config->get('lantern.domain'),
            'prefix' => trim((string) $config->get('lantern.path', 'lantern'), '/'),
            'middleware' => array_merge((array) $config->get('lantern.middleware', ['web']), [Authorize::class]),
        ], fn () => $this->loadRoutesFrom(__DIR__.'/../routes/lantern.php'));
    }

    private function tracingEnabled(): bool
    {
        return (bool) $this->app['config']->get('lantern.enabled') && ! $this->app['config']->get('lantern.kill_switch');
    }
}
