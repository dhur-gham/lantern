<?php

namespace Lantern;

use Illuminate\Support\ServiceProvider;
use Lantern\Console\InstallCommand;
use Lantern\Detection\Analyzer;
use Lantern\Console\StatusCommand;
use Lantern\Console\TraceDumpCommand;
use Lantern\Instrumentation\Eloquent\LazyLoadInstrumentation;
use Lantern\Instrumentation\Lifecycle\LifecycleInstrumentation;
use Lantern\Instrumentation\Lifecycle\MemorySink;
use Lantern\Instrumentation\Lifecycle\TraceSink;
use Lantern\Instrumentation\Middleware\MiddlewareInstrumentation;
use Lantern\Instrumentation\Query\QueryInstrumentation;
use Lantern\Query\CallSiteResolver;
use Lantern\Trace\Recorder;

class LanternServiceProvider extends ServiceProvider
{
    public const VERSION = '0.1.0-dev';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/lantern.php', 'lantern');

        if (! $this->tracingEnabled()) {
            return;
        }

        $this->app->scoped(Recorder::class);
        $this->app->singleton(TraceSink::class, fn ($app) => new MemorySink((int) $app['config']->get('lantern.memory_sink_limit', 20)));

        $this->app->singleton(Analyzer::class, fn ($app) => new Analyzer((array) $app['config']->get('lantern', [])));

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

        if (class_exists(LazyLoadInstrumentation::class)) {
            $this->app->booted(fn ($app) => LazyLoadInstrumentation::register($app));
        }
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/lantern.php' => config_path('lantern.php'),
            ], 'lantern-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'lantern-migrations');

            $this->commands([InstallCommand::class, StatusCommand::class, TraceDumpCommand::class]);
        }
    }

    private function tracingEnabled(): bool
    {
        return (bool) $this->app['config']->get('lantern.enabled') && ! $this->app['config']->get('lantern.kill_switch');
    }
}
