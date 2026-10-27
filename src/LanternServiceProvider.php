<?php

namespace Lantern;

use Illuminate\Support\ServiceProvider;
use Lantern\Console\InstallCommand;
use Lantern\Console\StatusCommand;

class LanternServiceProvider extends ServiceProvider
{
    public const VERSION = '0.1.0-dev';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/lantern.php', 'lantern');
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

            $this->commands([InstallCommand::class, StatusCommand::class]);
        }
    }
}
