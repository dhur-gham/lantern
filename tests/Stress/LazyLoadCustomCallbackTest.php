<?php

declare(strict_types=1);

namespace Lantern\Tests\Stress;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;

/** App that already set preventLazyLoading with its own callback; its callback must keep working. */
final class LazyLoadCustomCallbackTest extends StressTestCase
{
    public static int $calls = 0;

    /** @var list<string> */
    public static array $relations = [];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        self::$calls = 0;
        self::$relations = [];
        Model::preventLazyLoading(true);
        Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation): void {
            self::$calls++;
            self::$relations[] = $relation;
        });
    }

    public function test_app_callback_is_invoked_for_every_lazy_load(): void
    {
        $this->get('/scenario/lazy-loop')->assertOk()->assertSee('30');

        $this->assertSame(30, self::$calls);
        $this->assertSame(['author'], array_values(array_unique(self::$relations)));
    }

    public function test_lantern_still_records_the_events(): void
    {
        $this->get('/scenario/lazy-loop')->assertOk();

        $lazy = array_filter($this->lastTrace()->spans(), fn ($s) => ($s->meta['kind'] ?? null) === 'lazy_load');
        $this->assertCount(30, $lazy);
    }

    public function test_app_callback_keeps_invoking_across_many_requests(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->get('/scenario/lazy-loop')->assertOk();
        }

        $this->assertSame(150, self::$calls);
    }

    public function test_app_callback_works_when_lantern_has_no_active_trace(): void
    {
        $post = \Lantern\Tests\Stress\Support\SPost::all();
        foreach ($post as $p) {
            $p->author;
        }

        $this->assertSame(30, self::$calls);
    }

    public function test_app_callback_exception_passes_through(): void
    {
        Model::handleLazyLoadingViolationUsing(null);
        $cb = function () {
            throw new \DomainException('app says no');
        };
        (new \ReflectionProperty(\Lantern\Instrumentation\Eloquent\LazyLoadInstrumentation::class, 'installed'))->setValue(null, null);
        Model::handleLazyLoadingViolationUsing($cb);
        \Lantern\Instrumentation\Eloquent\LazyLoadInstrumentation::register($this->app);

        $this->expectException(\DomainException::class);
        foreach (\Lantern\Tests\Stress\Support\SPost::all() as $p) {
            $p->author;
        }
    }
}
