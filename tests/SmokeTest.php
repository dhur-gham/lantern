<?php

namespace Lantern\Tests;

use Illuminate\Support\Facades\Artisan;
use Lantern\LanternServiceProvider;

class SmokeTest extends TestCase
{
    public function test_provider_boots(): void
    {
        $this->assertTrue($this->app->providerIsLoaded(LanternServiceProvider::class));
    }

    public function test_config_is_merged_with_defaults(): void
    {
        $this->assertFalse(config('lantern.enabled'));
        $this->assertSame(5, config('lantern.thresholds.n_plus_one_min_repeats'));
        $this->assertSame(100, config('lantern.thresholds.slow_query_ms'));
        $this->assertArrayHasKey('kill_switch', config('lantern'));
    }

    public function test_commands_are_registered(): void
    {
        $all = Artisan::all();
        $this->assertArrayHasKey('lantern:install', $all);
        $this->assertArrayHasKey('lantern:status', $all);
    }

    public function test_status_command_output(): void
    {
        $this->artisan('lantern:status')
            ->expectsOutputToContain('Lantern: disabled')
            ->expectsOutputToContain('Laravel:')
            ->expectsOutputToContain('Livewire:')
            ->assertSuccessful();
    }
}
