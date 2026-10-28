<?php

namespace Lantern\Tests\Feature\Lifecycle;

use Illuminate\Routing\Router;
use Lantern\Tests\TestCase;
use Lantern\Trace\Recorder;
use Lantern\Trace\Span;
use Lantern\Trace\SpanType;
use Lantern\Trace\Trace;

require_once __DIR__.'/Fixtures.php';

abstract class LifecycleTestCase extends TestCase
{
    protected bool $lanternEnabled = true;

    protected bool $killSwitch = false;

    /** Providers register before defineEnvironment() runs, so set the switches right after config loads. */
    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('lantern.enabled', $this->lanternEnabled);
        $app['config']->set('lantern.kill_switch', $this->killSwitch);
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('view.paths', [__DIR__.'/views']);
        $app['config']->set('view.compiled', sys_get_temp_dir());
    }

    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->get('/things', [ThingController::class, 'index']);
        $router->post('/things', [ThingController::class, 'store']);
        $router->get('/invokable', InvokableController::class);
        $router->get('/validator', fn () => \Illuminate\Support\Facades\Validator::make(['a' => 1], ['a' => 'integer'])->validate());
        $router->get('/closure', fn () => 'closure');
        $router->get('/named', fn () => 'named')->name('things.named');
        $router->get('/lantern/internal', fn () => 'ignored');
    }

    protected function recorder(): Recorder
    {
        return $this->app->make(Recorder::class);
    }

    /** @return list<Span> */
    protected function spansOfType(Trace $trace, SpanType $type): array
    {
        return array_values(array_filter($trace->spans(), fn (Span $s) => $s->type === $type));
    }
}
