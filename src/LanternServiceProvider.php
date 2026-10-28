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
    public const VERSION = '0.3.0';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/lantern.php', 'lantern');
        $this->ignoreOwnPath();

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

        $this->app->singleton(TraceQueries::class, fn ($app) => new TraceQueries($app['db'], $app['config']->get('lantern.storage.connection')));

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
