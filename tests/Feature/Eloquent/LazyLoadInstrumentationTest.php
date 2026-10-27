<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Eloquent;

use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Database\Eloquent\Model;
use Lantern\Instrumentation\Eloquent\LazyLoadInstrumentation;
use Lantern\Tests\Feature\Query\QPost;
use Lantern\Tests\Feature\Query\QueryTestCase;
use Lantern\Trace\SpanType;
use ReflectionProperty;

require_once __DIR__.'/../Query/QueryTestCase.php';

final class LazyLoadInstrumentationTest extends QueryTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The provider installs the hook itself once the app booted; these tests start from a clean slate.
        Model::handleLazyLoadingViolationUsing(null);
        Model::preventLazyLoading(false);
        (new ReflectionProperty(LazyLoadInstrumentation::class, 'installed'))->setValue(null, null);
    }

    protected function tearDown(): void
    {
        Model::handleLazyLoadingViolationUsing(null);
        Model::preventLazyLoading(false);
        (new ReflectionProperty(LazyLoadInstrumentation::class, 'installed'))->setValue(null, null);

        parent::tearDown();
    }

    /** @return list<\Lantern\Trace\Span> */
    private function lazyEvents(): array
    {
        return array_values(array_filter(
            $this->recorder()->trace()->spans(),
            fn ($s) => $s->type === SpanType::Event && ($s->meta['kind'] ?? null) === 'lazy_load',
        ));
    }

    public function test_n_lazy_loads_produce_n_events_without_changing_behaviour(): void
    {
        $this->seedTables();
        LazyLoadInstrumentation::register($this->app);
        $this->recorder()->begin();

        $names = [];
        $line = 0;
        foreach (QPost::all() as $post) {
            $line = __LINE__ + 1;
            $names[] = $post->author->name;
        }

        $this->assertSame(['a', 'b', 'c'], $names);
        $events = $this->lazyEvents();
        $this->assertCount(3, $events);
        foreach ($events as $event) {
            $this->assertSame('lazy_load QPost::author', $event->name);
            $this->assertSame(QPost::class, $event->meta['model']);
            $this->assertSame('author', $event->meta['relation']);
            $this->assertSame(0.0, $event->durationMs());
            $this->assertSame($line, $event->callSite['line']);
            $this->assertStringEndsWith('LazyLoadInstrumentationTest.php', $event->callSite['file']);
        }
    }

    public function test_no_exception_when_app_did_not_enable_prevention(): void
    {
        $this->seedTables();
        $this->assertFalse(Model::preventsLazyLoading());
        LazyLoadInstrumentation::register($this->app);
        $this->recorder()->begin();

        $post = QPost::all()->first();
        $this->assertSame('a', $post->author->name);
        $this->assertCount(1, $this->lazyEvents());
    }

    public function test_default_throw_preserved_when_app_enabled_prevention(): void
    {
        $this->seedTables();
        Model::preventLazyLoading(true);
        LazyLoadInstrumentation::register($this->app);
        $this->recorder()->begin();

        $post = QPost::all()->first();
        try {
            $post->author;
            $this->fail('expected violation');
        } catch (LazyLoadingViolationException) {
        }
        $this->assertCount(1, $this->lazyEvents());
    }

    public function test_existing_app_callback_still_invoked(): void
    {
        $this->seedTables();
        $seen = [];
        Model::handleLazyLoadingViolationUsing(function (Model $m, string $relation) use (&$seen) {
            $seen[] = [$m::class, $relation];
        });
        LazyLoadInstrumentation::register($this->app);
        $this->recorder()->begin();

        QPost::all()->first()->author;

        $this->assertSame([[QPost::class, 'author']], $seen);
        $this->assertCount(1, $this->lazyEvents());
    }

    public function test_register_is_idempotent_and_inactive_recorder_is_silent(): void
    {
        $this->seedTables();
        $seen = 0;
        Model::handleLazyLoadingViolationUsing(function () use (&$seen) {
            $seen++;
        });
        LazyLoadInstrumentation::register($this->app);
        LazyLoadInstrumentation::register($this->app);

        QPost::all()->first()->author; // recorder not active

        $this->assertSame(1, $seen);
        $this->assertNull($this->recorder()->trace());
    }
}
