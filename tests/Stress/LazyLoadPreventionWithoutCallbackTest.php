<?php

declare(strict_types=1);

namespace Lantern\Tests\Stress;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;

/** App that enabled prevention without a callback: the framework's exception must still be thrown. */
final class LazyLoadPreventionWithoutCallbackTest extends StressTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        Model::preventLazyLoading(true);
    }

    public function test_default_violation_exception_is_still_thrown(): void
    {
        $this->getJson('/scenario/lazy-loop')->assertStatus(500);
    }

    public function test_exception_type_is_the_frameworks(): void
    {
        $this->withoutExceptionHandling();
        $this->expectException(LazyLoadingViolationException::class);

        $this->get('/scenario/lazy-loop');
    }

    public function test_eager_loaded_code_is_unaffected(): void
    {
        $this->get('/scenario/eager')->assertOk()->assertSee('30');
    }

    public function test_trace_is_still_stored_for_the_failing_request(): void
    {
        $this->getJson('/scenario/lazy-loop');

        $trace = $this->lastTrace();
        $this->assertSame(500, $trace->meta['status']);
        $this->assertSpansClosed($trace);
    }
}
