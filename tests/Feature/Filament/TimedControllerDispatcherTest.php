<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Filament;

use Illuminate\Routing\Contracts\ControllerDispatcher;
use Illuminate\Routing\Route;
use Lantern\Instrumentation\Lifecycle\TimedControllerDispatcher;
use Lantern\Tests\Feature\Filament\Fixtures\PageComponent;
use Lantern\Trace\Recorder;
use Lantern\Trace\SpanType;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/fixtures/LivewirePage.php';

final class TimedControllerDispatcherTest extends TestCase
{
    public function test_livewire_component_uses_class_file_and_name(): void
    {
        $recorder = new Recorder;
        $trace = $recorder->begin();
        $inner = new class implements ControllerDispatcher
        {
            public function dispatch(Route $route, $controller, $method)
            {
                return 'done';
            }

            public function getMiddleware($controller, $method)
            {
                return [];
            }
        };

        $dispatcher = new TimedControllerDispatcher($inner, fn () => $recorder);
        $result = $dispatcher->dispatch(new Route('GET', '/x', []), new PageComponent, '__invoke');

        $this->assertSame('done', $result);
        $span = null;
        foreach ($trace->spans() as $s) {
            if ($s->type === SpanType::Controller) {
                $span = $s;
            }
        }
        $this->assertNotNull($span);
        $this->assertSame(PageComponent::class, $span->name);
        $this->assertSame(__DIR__.DIRECTORY_SEPARATOR.'fixtures'.DIRECTORY_SEPARATOR.'LivewirePage.php', $span->callSite['file']);
        $this->assertSame(15, $span->callSite['line']);
        $this->assertTrue($span->isStopped());
    }
}
