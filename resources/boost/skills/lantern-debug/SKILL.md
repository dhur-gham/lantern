---
name: lantern-debug
description: Debug slow Laravel requests and query problems (N+1, lazy loading, duplicate or slow queries, missing indexes, memory) using Lantern traces, then verify the fix with lantern:diff and lantern:check.
---

# Lantern debugging workflow

1. Enable: `LANTERN_ENABLED=true` and `LANTERN_STORAGE_DRIVER=database` locally (then migrate). Never in production without sampling.
2. Reproduce with a feature test or a real request to the route. Seed >= 3 related rows. To pick that request's trace rather than the latest one, filter: `lantern:trace --path='api/x/*' --summary --format=json` (also `--route`, `--status`, `--since`); `lantern:issues` takes the same filters. Rows from other apps sharing the database are hidden unless you pass `--all-apps`.
3. `php artisan lantern:issues --format=json --limit=5`. Pick one issue; note `evidence.trace_id` from `lantern:issue` (or `related_trace_ids[0]`) as BEFORE.
4. `php artisan lantern:issue {id} --format=json`; open `call_site.file` at `call_site.line`.
5. Fix using the pattern below. Prefer fixing the query over any config change.
6. Re-run the reproduction, then `php artisan lantern:diff {before} {after}`: expect the issue in `resolved` (or in `changed` with status `improved` when reduced but still present, even if its line moved), `new` empty, `queries.delta` negative.
6a. If you start from a production SQL (APM span, slow-query log), run `php artisan lantern:match --sql="<statement>" --format=json`: it gives the call site (`matches[].call_site`), the issue recorded on that query (`issue.id`, then `lantern:issue`) and its plan. `%s`, `?`, `$1`, `:name` and literals all work. `match_type: similar` means no stored query has exactly that shape.
7. `php artisan lantern:check --route=/posts --no-new-issues`: exit 0 means done, 1 means still failing, 3 means nothing recorded (check the env flags). `lantern:issues` agrees: an issue whose query ran again without the finding is `status: resolved` and hidden unless you pass `--include-resolved`; if it is still listed as open, the finding is still firing.

Always pass `--format=json` (the default table is large). Each result has `next`, the suggested follow-up command; `hint` explains empty or truncated results. With the `lantern` MCP server (`php artisan mcp:start lantern`) call `lantern_issues`, `lantern_issue`, `lantern_trace_summary`, `lantern_diff`, `lantern_check` instead; same JSON, same steps.

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

`lantern:issue` `evidence.sql_shape` filters or sorts on an unindexed column. Add a migration, do not edit old ones.

```php
Schema::table('comments', function (Blueprint $t) {
    $t->index(['post_id', 'created_at']);
});
```

Put equality columns first, then range/sort columns. Yes/no flags and other low-cardinality columns go last; an empty suggested column list means the filter alone would not help. `evidence.read_ratio` (rows read per row returned) appears only when the app runs with `LANTERN_EXPLAIN_ANALYZE=true`, which re-runs flagged queries once, time-limited; a high ratio means the plan, not a missing index, is the problem.

## index_not_chosen

An index that could serve the query already exists (`evidence` names it) and the optimizer did not use it. Do not write a migration. Read `evidence.rows_read` and `evidence.projected` to see how bad the scan is at production size, run `EXPLAIN ANALYZE`, then refresh statistics (`ANALYZE TABLE`) or pin the join order with a hint. If `evidence.schema_note` is present the local database lacks production indexes: run `php artisan lantern:schema-diff prod-schema.json` and load the missing ones locally before measuring again.

## plan_flip

The query is fast with a short `whereIn()` list and slow with a long one because the optimizer changes plan. Do not add an index blindly: run `php artisan lantern:probe {id} --in-sizes=1,7,20,41,100` and read `plan_changes`, then refresh statistics (`ANALYZE TABLE`), pin the join order with a hint, or process the ids in chunks below the size where the plan changes.

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
- `suggestion` is a hint, not a patch; read the code before applying it.
