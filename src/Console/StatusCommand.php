<?php

namespace Lantern\Console;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Lantern\LanternServiceProvider;

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
