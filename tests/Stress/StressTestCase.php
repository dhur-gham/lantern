<?php

declare(strict_types=1);

namespace Lantern\Tests\Stress;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lantern\Instrumentation\Eloquent\LazyLoadInstrumentation;
use Lantern\Instrumentation\Lifecycle\MemorySink;
use Lantern\Instrumentation\Query\QuerySuspend;
use Lantern\Lantern;
use Lantern\LanternServiceProvider;
use Lantern\Tests\Stress\Support\FormController;
use Lantern\Tests\Stress\Support\QueryMiddleware;
use Lantern\Tests\Stress\Support\SAuthor;
use Lantern\Tests\Stress\Support\ScenarioController;
use Lantern\Tests\Stress\Support\ShortCircuitMiddleware;
use Lantern\Tests\Stress\Support\ThrowingMiddleware;
use Lantern\Trace\Span;
use Lantern\Trace\SpanType;
use Lantern\Trace\Trace;
use Orchestra\Testbench\TestCase as Orchestra;
use ReflectionFunction;
use ReflectionProperty;

require_once __DIR__.'/Support/Fixtures.php';

abstract class StressTestCase extends Orchestra
{
    protected bool $lanternEnabled = true;

    protected bool $persist = false;

    protected bool $seed = true;

    protected string $environment = 'local';

    /** @var list<string> temp files removed on tearDown */
    private array $tmpFiles = [];

    /** Per-class (or per-test via $this->name()) overrides, keyed without the "lantern." prefix. */
    protected function lanternConfig(): array
    {
        return [];
    }

    protected function getPackageProviders($app): array
    {
        return [LanternServiceProvider::class];
    }

    protected function setUp(): void
    {
        $this->resetStatics();
        parent::setUp();

        if ($this->persist) {
            foreach (glob(__DIR__.'/../../database/migrations/*.php') as $file) {
                (require $file)->up();
            }
        }
        if ($this->seed) {
            $this->seedSchema();
        }
    }

    protected function tearDown(): void
    {
        $this->resetStatics();
        foreach ($this->tmpFiles as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function resetStatics(): void
    {
        Lantern::flush();
        Model::handleLazyLoadingViolationUsing(null);
        Model::preventLazyLoading(false);
        Model::clearBootedModels();
        (new ReflectionProperty(LazyLoadInstrumentation::class, 'installed'))->setValue(null, null);
        (new ReflectionProperty(QuerySuspend::class, 'depth'))->setValue(null, 0);
    }

    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $config = $app['config'];
        $config->set('lantern.enabled', $this->lanternEnabled);
        $config->set('lantern.kill_switch', false);
        $config->set('lantern.app_paths', [__DIR__]);
        $config->set('lantern.memory_sink_limit', 100000);
        $config->set('lantern.capture_bindings', true);
        if ($this->persist) {
            $config->set('lantern.storage', ['driver' => 'database', 'connection' => null, 'compress' => true, 'batch_size' => 1]);
            $config->set('lantern.keep_rate', 1.0);
        }
        foreach ($this->lanternConfig() as $key => $value) {
            $config->set('lantern.'.$key, $value);
        }
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];
        $config->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $config->set('app.debug', false);
        $config->set('queue.default', 'sync');
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $config->set('database.connections.second', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $config->set('database.connections.broken', ['driver' => 'sqlite', 'database' => sys_get_temp_dir().'/lantern-nope-dir/x/y.sqlite', 'prefix' => '']);

        $file = $this->tmp();
        $config->set('database.connections.rw', ['driver' => 'sqlite', 'database' => $file, 'read' => ['database' => $file], 'write' => ['database' => $file], 'prefix' => '']);

        $viewCache = sys_get_temp_dir().'/lantern-stress-views';
        @mkdir($viewCache, 0777, true);
        $config->set('view.compiled', $viewCache);
        $config->set('view.paths', [__DIR__.'/views']);
        $app['view']->addNamespace('stress', __DIR__.'/views');
        $app['env'] = $this->environment;
    }

    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->any('/scenario/{name}', ScenarioController::class);
        $router->get('/mw-throw', fn () => 'unreachable')->middleware(ThrowingMiddleware::class);
        $router->get('/mw-short', fn () => 'unreachable')->middleware(ShortCircuitMiddleware::class);
        $router->get('/mw-query', fn () => 'mw')->middleware(QueryMiddleware::class);
        $router->post('/fr/rules', [FormController::class, 'rulesThrow']);
        $router->post('/fr/authorize', [FormController::class, 'authorizeThrow']);
        $router->post('/fr/prepare', [FormController::class, 'prepareThrow']);
        $router->post('/fr/strict', [FormController::class, 'strict']);
        $router->match(['get', 'head', 'options'], '/multi', fn () => 'multi');
        $router->post('/post-only', fn () => 'posted');
        $router->get('/up', fn () => DB::select('select 1 as ignored_q') ? 'ignored' : '');
    }

    protected function tmp(): string
    {
        $f = tempnam(sys_get_temp_dir(), 'lanternstress');
        $this->tmpFiles[] = $f;

        return $f;
    }

    protected function seedSchema(): void
    {
        $schema = Schema::connection('testing');
        $schema->create('s_authors', function (Blueprint $t) {
            $t->id();
            $t->string('name');
        });
        $schema->create('s_posts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('author_id');
            $t->string('title')->default('t');
            $t->unsignedInteger('views')->default(0);
            $t->softDeletes();
        });
        $schema->create('s_videos', function (Blueprint $t) {
            $t->id();
            $t->string('title');
        });
        $schema->create('s_comments', function (Blueprint $t) {
            $t->id();
            $t->string('commentable_type');
            $t->unsignedBigInteger('commentable_id');
            $t->string('body')->default('b');
        });
        $schema->create('s_unique', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
        });
        $schema->create('sessions', function (Blueprint $t) {
            $t->integer('id')->nullable();
        });
        $schema->create('s_other_marker', function (Blueprint $t) {
            $t->id();
        });
        Schema::connection('second')->create('s_other', function (Blueprint $t) {
            $t->id();
            $t->string('v')->nullable();
        });

        DB::connection('rw')->getSchemaBuilder()->create('s_rw', function (Blueprint $t) {
            $t->id();
            $t->string('v')->nullable();
        });

        DB::table('s_authors')->insert(array_map(fn ($i) => ['name' => 'author'.$i], range(1, 10)));
        DB::table('s_posts')->insert(array_map(fn ($i) => ['author_id' => ($i % 10) + 1, 'title' => 'post'.$i], range(1, 30)));
        DB::table('s_videos')->insert(array_map(fn ($i) => ['title' => 'video'.$i], range(1, 6)));
        $comments = [];
        foreach (range(1, 6) as $i) {
            $comments[] = ['commentable_type' => \Lantern\Tests\Stress\Support\SPost::class, 'commentable_id' => $i, 'body' => 'c'];
            $comments[] = ['commentable_type' => \Lantern\Tests\Stress\Support\SVideo::class, 'commentable_id' => $i, 'body' => 'c'];
        }
        DB::table('s_comments')->insert($comments);
    }

    protected function seedBig(int $rows = 10000): void
    {
        Schema::create('s_big', function (Blueprint $t) {
            $t->id();
            $t->string('a');
            $t->text('b');
        });
        foreach (array_chunk(range(1, $rows), 500) as $chunk) {
            DB::table('s_big')->insert(array_map(fn ($i) => ['a' => 'row'.$i, 'b' => str_repeat('p', 50)], $chunk));
        }
    }

    protected function sink(): MemorySink
    {
        return $this->app->make(MemorySink::class);
    }

    protected function lastTrace(): Trace
    {
        $trace = $this->sink()->last();
        $this->assertNotNull($trace, 'no trace was recorded');

        return $trace;
    }

    /** @return list<Span> */
    protected function queries(Trace $trace): array
    {
        return array_values(array_filter(
            $trace->spans(),
            fn (Span $s) => $s->type === SpanType::Query && ($s->meta['kind'] ?? null) !== 'transaction',
        ));
    }

    /** @return list<Span> */
    protected function spansOf(Trace $trace, SpanType $type): array
    {
        return array_values(array_filter($trace->spans(), fn (Span $s) => $s->type === $type));
    }

    /** @return list<string> */
    protected function issueTypes(Trace $trace): array
    {
        return array_values(array_map(fn ($i) => $i['type'], (array) ($trace->meta['issues'] ?? [])));
    }

    /** @return list<array<string, mixed>> */
    protected function issuesOf(Trace $trace, string $type): array
    {
        return array_values(array_filter((array) ($trace->meta['issues'] ?? []), fn ($i) => $i['type'] === $type));
    }

    protected function assertSpansClosed(Trace $trace): void
    {
        $spans = $trace->spans();
        $this->assertNotEmpty($spans);
        foreach ($spans as $span) {
            $this->assertTrue($span->isStopped(), 'span left open: '.$span->type->value.' '.$span->name);
            $this->assertGreaterThanOrEqual($span->startNs, $span->endNs, 'negative duration: '.$span->name);
            if ($span->parentId !== null) {
                $this->assertArrayHasKey($span->parentId, $spans, 'orphan span '.$span->name);
            }
        }
        $this->assertNotNull($trace->root());
    }

    /** Number of Lantern-owned listeners attached to an event. */
    protected function lanternListeners(string $event): int
    {
        $count = 0;
        foreach ($this->app['events']->getListeners($event) as $listener) {
            $inner = (new ReflectionFunction($listener))->getStaticVariables()['listener'] ?? null;
            if (! $inner instanceof \Closure) {
                continue;
            }
            $scope = (new ReflectionFunction($inner))->getClosureScopeClass()?->getName();
            if ($scope !== null && str_starts_with($scope, 'Lantern\\') && ! str_starts_with($scope, 'Lantern\\Tests\\')) {
                $count++;
            }
        }

        return $count;
    }

    protected function fakeQueryEvent(): QueryExecuted
    {
        return new QueryExecuted('select 1', [], 0.1, DB::connection());
    }
}
