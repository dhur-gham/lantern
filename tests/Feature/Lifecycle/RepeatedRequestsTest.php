<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Lifecycle;

use ReflectionProperty;

final class RepeatedRequestsTest extends LifecycleTestCase
{
    public function test_terminating_callbacks_do_not_accumulate_across_requests(): void
    {
        $this->get('/things')->assertOk();
        $count = count((new ReflectionProperty($this->app, 'terminatingCallbacks'))->getValue($this->app));

        for ($i = 0; $i < 5; $i++) {
            $this->get('/things')->assertOk();
        }

        $this->assertSame($count, count((new ReflectionProperty($this->app, 'terminatingCallbacks'))->getValue($this->app)));
        $this->assertNotNull($this->app->make(\Lantern\Instrumentation\Lifecycle\TraceSink::class)->last());
    }
}
