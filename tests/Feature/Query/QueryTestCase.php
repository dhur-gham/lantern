<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Query;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lantern\Tests\TestCase;
use Lantern\Trace\Recorder;
use Lantern\Trace\Span;
use Lantern\Trace\SpanType;
use Lantern\Trace\Trace;

class QAuthor extends Model
{
    protected $table = 'q_authors';

    public $timestamps = false;

    protected $guarded = [];
}

class QPost extends Model
{
    protected $table = 'q_posts';

    public $timestamps = false;

    protected $guarded = [];

    public function author(): BelongsTo
    {
        return $this->belongsTo(QAuthor::class, 'author_id');
    }
}

class QueryProbeController extends Controller
{
    /** Line of the lazy access, read by the test. */
    public static int $lazyLine = 0;

    public function lazy()
    {
        $names = [];
        foreach (QPost::all() as $post) {
            self::$lazyLine = __LINE__ + 1;
            $names[] = $post->author->name;
        }

        return response()->json($names);
    }

    public function failing()
    {
        DB::select('select * from q_missing_table where id = ?', [1]);

        return 'unreachable';
    }

    public function nested()
    {
        return app(Recorder::class)->measure(SpanType::Validation, 'inner', function () {
            DB::select('select 1');

            return 'ok';
        });
    }
}

abstract class QueryTestCase extends TestCase
{
    protected bool $lanternEnabled = true;

    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('lantern.enabled', $this->lanternEnabled);
        $app['config']->set('lantern.kill_switch', false);
        $app['config']->set('lantern.app_paths', [dirname(__DIR__, 2)]);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    protected function defineRoutes($router): void
    {
        $router->get('/lazy', [QueryProbeController::class, 'lazy']);
        $router->get('/failing', [QueryProbeController::class, 'failing']);
        $router->get('/nested', [QueryProbeController::class, 'nested']);
    }

    protected function seedTables(): void
    {
        Schema::create('q_authors', function (Blueprint $t) {
            $t->id();
            $t->string('name');
        });
        Schema::create('q_posts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('author_id');
        });

        foreach (['a', 'b', 'c'] as $i => $name) {
            QAuthor::create(['name' => $name]);
            QPost::create(['author_id' => $i + 1]);
        }
    }

    protected function recorder(): Recorder
    {
        return $this->app->make(Recorder::class);
    }

    /** @return list<Span> */
    protected function queries(Trace $trace): array
    {
        return array_values(array_filter(
            $trace->spans(),
            fn (Span $s) => $s->type === SpanType::Query && ($s->meta['kind'] ?? null) !== 'transaction',
        ));
    }
}
