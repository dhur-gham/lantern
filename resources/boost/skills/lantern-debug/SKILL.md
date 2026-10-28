---
name: lantern-debug
description: Debug slow Laravel requests and query problems (N+1, lazy loading, duplicate or slow queries, missing indexes, memory) using Lantern traces, then verify the fix with lantern:diff and lantern:check.
---

# Lantern debugging workflow

1. Enable: `LANTERN_ENABLED=true` locally. Never in production without sampling.
2. Reproduce in-process (feature test or `php artisan lantern:replay /posts`). Seed >= 3 related rows.
3. `php artisan lantern:issues --format=json --limit=5`. Pick one issue; note `evidence.example_trace_id` as BEFORE.
4. `php artisan lantern:issue {id} --format=json`; open `location.file` at `location.line`.
5. Fix using the pattern below. Prefer fixing the query over any config change.
6. Re-run the reproduction, then `php artisan lantern:diff {before} {after}`: expect the issue in `resolved`, `new` empty, query delta negative.
7. `php artisan lantern:check --route=/posts --no-new-issues --format=json`: exit 0 means done, 1 means still failing, 3 means nothing recorded (check the env flag).

Never silence an issue to pass the check. Gate on query count and issue presence, not milliseconds (timing is noisy).

## n_plus_one / lazy_load

A query runs once per row in a loop; `lazy_load` is the same via an unloaded relation.

```php
// before
$posts = Post::all();
foreach ($posts as $p) { echo $p->author->name; }
// after
$posts = Post::with('author')->get();
```

Counts only: `Post::withCount('comments')`. Prevent regressions: `Model::preventLazyLoading(! app()->isProduction())`.

## duplicate_query

Same SQL and bindings repeated in one request.

```php
// before
$user = User::find($id); /* ... later ... */ $user = User::find($id);
// after: load once and pass it, or memoize
$user ??= User::find($id);
```

## slow_query

Large result set or heavy scan. Select fewer columns, paginate, chunk.

```php
// before
$rows = Order::all();
// after
Order::select('id', 'total')->where('status', 'paid')->chunkById(500, fn ($rows) => $this->handle($rows));
```

## missing_index

`evidence.sql_shape` filters or sorts on an unindexed column. Add a migration, do not edit old ones.

```php
Schema::table('comments', function (Blueprint $t) {
    $t->index(['post_id', 'created_at']);
});
```

Put equality columns first, then range/sort columns.

## memory_hog

Loading the full set into memory.

```php
// before
User::all()->each(fn ($u) => $u->notify());
// after
User::lazyById(200)->each(fn ($u) => $u->notify());
```

Also drop unused `with()` relations and large `select *` columns (text/json).

## Pitfalls

- A test with 1 row never triggers N+1: seed more.
- Bindings are redacted; do not try to recover them.
- If `suggest.confidence` is low, read the code before applying the hint.
