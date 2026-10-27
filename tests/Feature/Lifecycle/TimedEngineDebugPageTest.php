<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Lifecycle;

use Illuminate\Foundation\Exceptions\Renderer\Mappers\BladeMapper;
use Illuminate\Routing\Router;
use Illuminate\View\Engines\CompilerEngine;
use Lantern\Instrumentation\Lifecycle\TimedEngine;
use ReflectionClass;

final class TimedEngineDebugPageTest extends LifecycleTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.debug', true);
    }

    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);
        /** @var Router $router */
        $router->get('/boom', fn () => view('boom'));
    }

    public function test_wrapped_engine_is_kept_in_engine_property(): void
    {
        $engine = $this->app['view']->getEngineResolver()->resolve('blade');

        $this->assertInstanceOf(TimedEngine::class, $engine);
        $reflection = new ReflectionClass($engine);
        $this->assertTrue($reflection->hasProperty('engine'));
        $this->assertInstanceOf(CompilerEngine::class, $reflection->getProperty('engine')->getValue($engine));
    }

    public function test_blade_mapper_resolves_known_paths_without_reflection_error(): void
    {
        $this->get('/things')->assertOk();

        $mapper = $this->app->make(BladeMapper::class);
        $this->assertNull($mapper->findCompiledView('/nonexistent/compiled.php'));
    }

    public function test_debug_exception_page_renders_for_view_exception(): void
    {
        $response = $this->get('/boom');

        $response->assertStatus(500);
        $this->assertStringContainsString('view boom', (string) $response->getContent());
        $this->assertStringNotContainsString('<abbr title=\"ReflectionException\">', (string) $response->getContent());
    }
}

