<?php

declare(strict_types=1);

namespace Lantern\Tests\Stress\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\Rule;
use PDOException;
use RuntimeException;

class SAuthor extends Model
{
    protected $table = 's_authors';

    public $timestamps = false;

    protected $guarded = [];

    public function posts(): HasMany
    {
        return $this->hasMany(SPost::class, 'author_id');
    }
}

class SPost extends Model
{
    use SoftDeletes;

    protected $table = 's_posts';

    public $timestamps = false;

    protected $guarded = [];

    public function author(): BelongsTo
    {
        return $this->belongsTo(SAuthor::class, 'author_id');
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(SComment::class, 'commentable');
    }

    public function getAuthorNameAttribute(): string
    {
        return (string) $this->author?->name;
    }
}

class SVideo extends Model
{
    protected $table = 's_videos';

    public $timestamps = false;

    protected $guarded = [];
}

class SComment extends Model
{
    protected $table = 's_comments';

    public $timestamps = false;

    protected $guarded = [];

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }
}

class SPostObserver
{
    public function saved(SPost $post): void
    {
        SComment::where('commentable_id', $post->id)->count();
    }
}

class SPostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'author' => $this->author->name];
    }
}

class SEvent
{
}

class ThrowingListener
{
    public function handle(SEvent $e): void
    {
        throw new RuntimeException('listener boom');
    }
}

class NPlusOneListener
{
    public function handle(SEvent $e): void
    {
        foreach (SPost::all() as $post) {
            $post->author->name;
        }
    }
}

class NPlusOneJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        foreach (SPost::all() as $post) {
            $post->author->name;
        }
    }
}

class ThrowingMiddleware
{
    public function handle($request, \Closure $next)
    {
        throw new RuntimeException('middleware boom');
    }
}

class ShortCircuitMiddleware
{
    public function handle($request, \Closure $next)
    {
        return response('short', 202);
    }
}

class QueryMiddleware
{
    public function handle($request, \Closure $next)
    {
        DB::select('select 1 as mw');

        return $next($request);
    }
}

class ThrowInRulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        throw new RuntimeException('rules boom');
    }
}

class ThrowInAuthorizeRequest extends FormRequest
{
    public function authorize(): bool
    {
        throw new RuntimeException('authorize boom');
    }

    public function rules(): array
    {
        return [];
    }
}

class ThrowInPrepareRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        throw new RuntimeException('prepare boom');
    }

    public function rules(): array
    {
        return [];
    }
}

class StrictRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['title' => ['required', 'string'], 'author_id' => ['required', Rule::exists('s_authors', 'id')]];
    }
}

class FormController extends Controller
{
    public function rulesThrow(ThrowInRulesRequest $r)
    {
        return 'unreachable';
    }

    public function authorizeThrow(ThrowInAuthorizeRequest $r)
    {
        return 'unreachable';
    }

    public function prepareThrow(ThrowInPrepareRequest $r)
    {
        return 'unreachable';
    }

    public function strict(StrictRequest $r)
    {
        return response()->json(['ok' => true]);
    }
}

class ScenarioController extends Controller
{
    public function __invoke(Request $request, string $name)
    {
        $n = (int) $request->query('n', 5000);

        return match ($name) {
            'ok' => 'ok',
            'json' => response()->json(['a' => 1]),
            'json-null' => response()->json(null),
            'json-big' => response()->json(['d' => str_repeat('x', 1_000_000)]),
            'empty' => response(''),
            'nocontent' => response()->noContent(),
            'redirect' => redirect('/scenario/ok'),
            'redirect-away' => redirect()->away('https://example.com/x'),
            'redirect-perm' => redirect('/scenario/ok', 301),
            'abort404' => abort(404),
            'abort403' => abort(403),
            'abort503' => abort(503),
            'validate422' => $request->validate(['x' => 'required']),
            'validate-exists' => $request->validate(['x' => ['required', Rule::exists('s_authors', 'id')]]),
            'stream' => response()->stream(function () {
                echo 'a';
                DB::select('select 1 as streamed');
                echo 'b';
            }, 200, ['Content-Type' => 'text/plain']),
            'stream-download' => response()->streamDownload(function () {
                echo 'bin';
            }, 'f.txt'),
            'binary' => response(random_bytes(4096), 200, ['Content-Type' => 'application/octet-stream']),
            'file' => response()->file(__FILE__),
            'download' => response()->download(__FILE__),
            'throw' => throw new RuntimeException('boom'),
            'throw-after-query' => $this->throwAfterQuery(),
            'throw-in-view' => view('stress::boom'),
            'throw-in-listener' => $this->throwInListener(),
            'listener-caught' => $this->listenerCaught(),
            'throw-in-resource' => $this->throwInResource(),
            'plain-view' => view('stress::plain'),

            'catch-syntax' => $this->catching(fn () => DB::select('selec nope from nowhere')),
            'catch-missing' => $this->catching(fn () => DB::select('select * from s_missing where id = ?', [1])),
            'catch-unique' => $this->catchUnique(),
            'catch-reported' => $this->catchReported(),
            'catch-many' => $this->catchMany($n),
            'catch-twice' => $this->catchTwice(),
            'fail-then-ok' => $this->failThenOk(),
            'deadlock' => $this->deadlock(),
            'fail-last' => $this->catching(fn () => DB::select('select * from s_missing')),

            'tx-commit' => $this->txCommit(),
            'tx-nested-ok' => $this->txNestedOk(),
            'tx-inner-rollback' => $this->txInnerRollback(),
            'tx-outer-rollback' => $this->txOuterRollback(),
            'tx-manual' => $this->txManual(),
            'tx-deep' => $this->txDeep($n),

            'big-result' => $this->bigResult(),
            'bindings' => $this->bindings((string) $request->query('kind', 'giant')),
            'giant-sql' => $this->giantSql(),
            'many-queries' => $this->manyQueries($n),
            'distinct-queries' => $this->distinctQueries($n),
            'loop' => $this->loop($n),
            'loop-ids' => $this->loopIds($n),
            'same-loop' => $this->sameLoop($n),

            'second-conn' => $this->secondConn(),
            'rw-conn' => $this->rwConn(),

            'lazy-loop' => $this->lazyLoop(),
            'eager' => $this->eager(),
            'with-count' => $this->withCount(),
            'chunk' => $this->chunk(),
            'chunk-id' => $this->chunkById(),
            'cursor' => $this->cursor(),
            'lazy' => $this->lazyCollection(),
            'paginate' => $this->paginate(),
            'simple-paginate' => $this->simplePaginate(),
            'soft-delete' => $this->softDelete(),
            'poly-lazy' => $this->polyLazy(),
            'poly-eager' => $this->polyEager(),
            'blade-n1' => view('stress::posts', ['posts' => SPost::all()]),
            'blade-eager' => view('stress::posts', ['posts' => SPost::with('author')->get()]),
            'resource-n1' => SPostResource::collection(SPost::all()),
            'resource-eager' => SPostResource::collection(SPost::with('author')->get()),
            'accessor-n1' => $this->accessorN1(),
            'observer-n1' => $this->observerN1(),
            'listener-n1' => $this->listenerN1(),
            'job-n1' => $this->jobN1(),
            'lazy-prevention-flag' => response()->json(['x' => 1]),

            'sessions-loop' => $this->tableLoop('sessions', 10),
            'ignored-table-loop' => $this->tableLoop((string) $request->query('table', 'sessions'), 10),

            default => abort(404),
        };
    }

    private function throwAfterQuery(): never
    {
        DB::select('select 1 as before_boom');

        throw new RuntimeException('boom after query');
    }

    private function throwInListener(): string
    {
        Event::listen(SEvent::class, ThrowingListener::class);
        event(new SEvent);

        return 'unreachable';
    }

    private function listenerCaught(): string
    {
        Event::listen(SEvent::class, ThrowingListener::class);
        try {
            event(new SEvent);
        } catch (RuntimeException) {
        }

        return 'caught';
    }

    private function throwInResource(): mixed
    {
        return (new class(['a' => 1]) extends JsonResource {
            public function toArray(Request $request): array
            {
                throw new RuntimeException('resource boom');
            }
        });
    }

    private function catching(\Closure $fn): string
    {
        try {
            $fn();
        } catch (QueryException) {
            return 'caught';
        }

        return 'not-failed';
    }

    private function catchUnique(): string
    {
        DB::table('s_unique')->insert(['code' => 'dup']);

        return $this->catching(fn () => DB::table('s_unique')->insert(['code' => 'dup']));
    }

    private function catchReported(): string
    {
        try {
            DB::select('select * from s_missing_reported where id = ?', [1]);
        } catch (QueryException $e) {
            report($e);
        }

        return 'reported';
    }

    private function catchMany(int $n): string
    {
        for ($i = 0; $i < $n; $i++) {
            $this->catching(fn () => DB::select('select * from s_missing'));
        }

        return 'many';
    }

    private function catchTwice(): string
    {
        $this->catching(fn () => DB::select('select * from s_missing where id = ?', [1]));
        $this->catching(fn () => DB::select('select * from s_missing where id = ?', [1]));

        return 'twice';
    }

    private function failThenOk(): string
    {
        $this->catching(fn () => DB::select('select * from s_missing'));
        DB::select('select 1 as fine');

        return 'ok';
    }

    private function deadlock(): string
    {
        $attempts = 0;
        try {
            DB::transaction(function () use (&$attempts) {
                $attempts++;
                throw new QueryException('testing', 'update s_authors set name = ?', ['x'], new PDOException('Deadlock found when trying to get lock; try restarting transaction'));
            }, 3);
        } catch (QueryException) {
        }

        return 'attempts='.$attempts;
    }

    private function txCommit(): string
    {
        DB::transaction(fn () => DB::table('s_authors')->where('id', 1)->update(['name' => 'tx']));

        return 'ok';
    }

    private function txNestedOk(): string
    {
        DB::transaction(function () {
            DB::select('select 1 as outer_q');
            DB::transaction(function () {
                DB::select('select 2 as inner_q');
            });
        });

        return 'ok';
    }

    private function txInnerRollback(): string
    {
        DB::transaction(function () {
            DB::table('s_authors')->where('id', 1)->update(['name' => 'outer']);
            try {
                DB::transaction(function () {
                    DB::table('s_authors')->where('id', 2)->update(['name' => 'inner']);
                    throw new RuntimeException('inner fail');
                });
            } catch (RuntimeException) {
            }
        });

        return (string) DB::table('s_authors')->where('id', 2)->value('name');
    }

    private function txOuterRollback(): string
    {
        try {
            DB::transaction(function () {
                DB::transaction(fn () => DB::table('s_authors')->where('id', 1)->update(['name' => 'inner-ok']));
                throw new RuntimeException('outer fail');
            });
        } catch (RuntimeException) {
        }

        return (string) DB::table('s_authors')->where('id', 1)->value('name');
    }

    private function txManual(): string
    {
        DB::beginTransaction();
        DB::beginTransaction();
        DB::select('select 1 as manual');
        DB::rollBack();
        DB::commit();

        return (string) DB::transactionLevel();
    }

    private function txDeep(int $depth): string
    {
        $run = function (int $level) use (&$run, $depth) {
            if ($level >= $depth) {
                return DB::select('select 1 as deepest');
            }

            return DB::transaction(fn () => $run($level + 1));
        };
        $run(0);

        return 'deep';
    }

    private function bigResult(): string
    {
        $rows = DB::table('s_big')->get();

        return (string) $rows->count();
    }

    private function bindings(string $kind): string
    {
        $value = match ($kind) {
            'giant' => str_repeat('x', 5_000_000),
            'binary' => random_bytes(1000),
            'unicode' => 'héllo wörld 日本語 Ωmega',
            'emoji' => str_repeat('😀👨‍👩‍👧‍👦', 300),
            'null' => null,
            'date' => Carbon::parse('2024-02-29 12:34:56'),
            'bool' => true,
            'float' => 1.5e300,
            'long-unicode' => str_repeat('日本語', 500),
            default => 'x',
        };

        $rows = DB::select('select ? as v', [$value]);

        return (string) count($rows);
    }

    private function giantSql(): string
    {
        $rows = DB::select("select '".str_repeat('a', 1_000_000)."' as v");

        return (string) strlen((string) $rows[0]->v);
    }

    private function manyQueries(int $n): string
    {
        for ($i = 0; $i < $n; $i++) {
            DB::select('select 1 as one');
        }

        return (string) $n;
    }

    private function distinctQueries(int $n): string
    {
        for ($i = 0; $i < $n; $i++) {
            DB::select('select 1 as c'.$i);
        }

        return (string) $n;
    }

    private function loop(int $n): string
    {
        for ($i = 0; $i < $n; $i++) {
            DB::select('select * from s_authors where id = ?', [$i + 1]);
        }

        return (string) $n;
    }

    private function loopIds(int $n): string
    {
        for ($i = 0; $i < $n; $i++) { DB::select('select * from s_authors where id = ?', [$i + 1]); }

        return (string) $n;
    }

    private function sameLoop(int $n): string
    {
        for ($i = 0; $i < $n; $i++) {
            DB::select('select * from s_authors where id = ?', [1]);
        }

        return (string) $n;
    }

    private function secondConn(): string
    {
        DB::connection('second')->select('select * from s_other');
        DB::select('select 1 as main');
        DB::connection('second')->table('s_other')->insert(['v' => 'x']);

        return 'ok';
    }

    private function rwConn(): string
    {
        $c = DB::connection('rw');
        $c->table('s_rw')->insert(['v' => 'w']);
        $c->select('select * from s_rw');
        $c->table('s_rw')->insert(['v' => 'w2']);

        return 'ok';
    }

    private function lazyLoop(): string
    {
        $names = [];
        foreach (SPost::all() as $post) {
            $names[] = $post->author->name;
        }

        return (string) count($names);
    }

    private function eager(): string
    {
        $names = [];
        foreach (SPost::with('author')->get() as $post) {
            $names[] = $post->author->name;
        }

        return (string) count($names);
    }

    private function withCount(): string
    {
        $sum = 0;
        foreach (SAuthor::withCount('posts')->get() as $a) {
            $sum += $a->posts_count;
        }

        return (string) $sum;
    }

    private function chunk(): string
    {
        $seen = 0;
        SPost::query()->orderBy('id')->chunk(4, function ($rows) use (&$seen) {
            $seen += $rows->count();
        });

        return (string) $seen;
    }

    private function chunkById(): string
    {
        $seen = 0;
        SPost::query()->chunkById(4, function ($rows) use (&$seen) {
            $seen += $rows->count();
        });

        return (string) $seen;
    }

    private function cursor(): string
    {
        $seen = 0;
        foreach (SPost::query()->cursor() as $post) {
            $seen++;
        }

        return (string) $seen;
    }

    private function lazyCollection(): string
    {
        $seen = 0;
        foreach (SPost::query()->lazy(4) as $post) {
            $seen++;
        }

        return (string) $seen;
    }

    private function paginate(): mixed
    {
        return response()->json(SPost::query()->orderBy('id')->paginate(7));
    }

    private function simplePaginate(): mixed
    {
        return response()->json(SPost::query()->orderBy('id')->simplePaginate(7));
    }

    private function softDelete(): string
    {
        SPost::query()->whereIn('id', [1, 2, 3])->delete();
        $live = SPost::count();
        $all = SPost::withTrashed()->count();
        $trashed = SPost::onlyTrashed()->count();
        SPost::onlyTrashed()->where('id', 1)->restore();
        SPost::withTrashed()->where('id', 2)->forceDelete();
        $after = SPost::withTrashed()->count();

        return "$live/$all/$trashed/$after";
    }

    private function polyLazy(): string
    {
        $types = [];
        foreach (SComment::all() as $comment) {
            $types[] = class_basename($comment->commentable);
        }

        return implode(',', array_unique($types));
    }

    private function polyEager(): string
    {
        $c = 0;
        foreach (SComment::with('commentable')->get() as $comment) {
            $c += $comment->commentable ? 1 : 0;
        }

        return (string) $c;
    }

    private function accessorN1(): string
    {
        $names = [];
        foreach (SPost::all() as $post) {
            $names[] = $post->author_name;
        }

        return (string) count($names);
    }

    private function observerN1(): string
    {
        SPost::observe(SPostObserver::class);
        for ($i = 0; $i < 10; $i++) {
            SPost::create(['author_id' => 1, 'title' => 'obs'.$i]);
        }

        return 'ok';
    }

    private function listenerN1(): string
    {
        Event::listen(SEvent::class, NPlusOneListener::class);
        event(new SEvent);

        return 'ok';
    }

    private function jobN1(): string
    {
        dispatch(new NPlusOneJob);

        return 'ok';
    }

    private function tableLoop(string $table, int $n): string
    {
        for ($i = 0; $i < $n; $i++) {
            DB::select('select * from '.$table.' where id = ?', [$i]);
        }

        return 'ok';
    }
}
