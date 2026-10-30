<?php

namespace Lantern\Tests;

use Lantern\LanternServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [LanternServiceProvider::class];
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // application graphs are cyclic (closures bound to the container), so without this they pile up across a long run
        gc_collect_cycles();
    }
}
