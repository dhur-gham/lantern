# Detectors

Lantern runs six detectors against every finished trace. Each reports an issue with a type, a severity (`critical`, `warning`, `info`), a call site (file and line), the number of occurrences, the total time, and a suggested fix. Issues with the same key are merged across requests, so the dashboard and `lantern:issues` show one row per problem with `first_seen`, `last_seen` and a running count.

The detector list is the `detectors` array in `config/lantern.php`. Remove a class to turn that detector off. A detector that throws is skipped and never affects the request.

The query detectors (N+1, duplicate, slow, missing index) skip transaction markers, failed queries and framework plumbing (see [False positives](#false-positives-and-ignoring-queries)).

| Type | Catches | Default trigger |
|---|---|---|
| `n_plus_one` | Same query shape run repeatedly from one call site | 5 or more repeats |
| `lazy_load` | Relation loaded lazily on many models in a loop | 5 or more lazy loads at one site |
| `duplicate_query` | Identical SQL and bindings run more than once in a trace | 2 or more repeats |
| `slow_query` | A query slower than the threshold | 100 ms |
| `missing_index` | A SELECT that EXPLAIN reports as a full scan | needs `explain` on |
| `memory_hog` | A span that grows memory sharply, or an unbounded SELECT followed by growth | 32 MB |

## n_plus_one

**What it catches.** A query that repeats once per row of a loop, for example loading each post's author inside `foreach ($posts as $post)`.

**How it is detected.** Every query is normalized into a fingerprint: literals, bindings, `IN (...)` list length and insert row count are removed, so `where user_id = 1` and `where user_id = 2` share one shape. Queries are grouped by fingerprint plus call site. A group with at least `thresholds.n_plus_one_min_repeats` members becomes an issue. Queries issued from inside `chunk()`, `chunkById()`, `lazy()`, `lazyById()`, `each()` and their variants are paging, not N+1, and are never grouped.

**Severity.** `critical` when the count is at least 4 times the threshold (20 by default) or the group's total time reaches 500 ms. Otherwise `warning`.

**Config.** `thresholds.n_plus_one_min_repeats` (`LANTERN_N_PLUS_ONE_MIN_REPEATS`, default 5).

**Example.**

```
critical  n_plus_one   N+1: select * from users where users.id = ? limit 1 x42 at app/Http/Controllers/PostController.php:31
          42 queries, 61.4 ms total, avg 1.5 ms
          Fix: Eager load the relation with with('user') (or load()/loadMissing()) before the loop.
```

**Fix.**

```php
// Before: one query for posts, then one per post
$posts = Post::latest()->take(40)->get();
foreach ($posts as $post) {
    echo $post->user->name;
}

// After: two queries total
$posts = Post::with('user')->latest()->take(40)->get();
```

For per-row counts the suggestion is `withCount()`:

```php
// Before
foreach ($posts as $post) { $post->comments()->count(); }
// After
$posts = Post::withCount('comments')->get();
```

## lazy_load

**What it catches.** The Eloquent cause of most N+1 problems: a relationship property read on a model that was loaded in a batch without that relation eager loaded.

**How it is detected.** Lantern hooks Eloquent's lazy-loading violation callback after the application has booted. Each lazy load records an event with the model, relation name and call site. Events are grouped by model, relation and call site, and a group with at least `thresholds.n_plus_one_min_repeats` events becomes an issue. When the same loop also produced an `n_plus_one` issue at the same call site, the two are merged into one issue carrying the query numbers plus the model and relation.

Lantern turns lazy-load prevention on internally so the hook fires, but it only throws Laravel's `LazyLoadingViolationException` if your application had enabled prevention itself. If your app already registered its own violation callback, it is still called with the same arguments. Eloquent only reports lazy loading on models hydrated in batches of two or more, so a single-model lookup is not flagged.

**Severity.** `critical` at 4 times the threshold, otherwise `warning`.

**Config.** Shares `thresholds.n_plus_one_min_repeats`.

**Example.**

```
warning   lazy_load    Lazy load: Post::author x12
          app/Http/Controllers/PostController.php:31
          Fix: Eager load the relation on the parent query: ->with('author').
```

**Fix.** Add `->with('author')` to the parent query. To catch these early in development, call `Model::preventLazyLoading(! app()->isProduction())` in a service provider.

## duplicate_query

**What it catches.** The exact same SQL with the exact same bindings executed more than once in one trace, for instance a settings lookup repeated in a view, a policy and a controller.

**How it is detected.** Queries are grouped by connection, SQL and bindings. Because identical parameters are required, this needs bindings: with `capture_bindings` off, queries without bindings information are skipped rather than guessed at. Capture is on by default in `dev` mode.

**Severity.** `warning` at 3 or more repeats, `info` at exactly 2.

**Config.** `thresholds.duplicate_min_repeats` (`LANTERN_DUPLICATE_MIN_REPEATS`, default 2), `capture_bindings`.

**Example.**

```
warning   duplicate_query   Duplicate query x3: select * from settings where key = ? limit 1
          call sites: app/Support/Settings.php:14, resources/views/layout.blade.php:9
          Fix: Run this query once and reuse the result: hoist it out of the loop, memoize it, or cache it.
```

**Fix.**

```php
// Before
function setting(string $key) { return Setting::where('key', $key)->value('value'); }

// After: memoize per request
function setting(string $key) {
    static $cache = [];
    return $cache[$key] ??= Setting::where('key', $key)->value('value');
}
```

A duplicate that is already part of an N+1 group is dropped so the same loop is not listed twice.

## slow_query

**What it catches.** Individual queries at or above the slow threshold.

**How it is detected.** Time is the duration the database connection reports for each query. Slow queries are grouped by fingerprint and call site, so the same slow statement run several times is one issue with a count, a total and the maximum.

**Severity.** `critical` when the slowest execution is at least 5 times the threshold (500 ms by default), otherwise `warning`.

**Config.** `thresholds.slow_query_ms` (`LANTERN_SLOW_QUERY_MS`, default 100).

**Example.**

```
critical  slow_query   Slow query: select * from orders where status = ? order by created_at desc (742 ms) at app/Services/Report.php:58
          Fix: Check the query plan (EXPLAIN), add an index on the filtered/sorted columns, or narrow the selected rows and columns.
```

**Fix.**

```php
// Before: scans and sorts the whole table
Order::where('status', 'paid')->latest()->get();

// After: composite index plus a bounded result
Schema::table('orders', fn ($t) => $t->index(['status', 'created_at']));
Order::where('status', 'paid')->latest()->take(100)->get();
```

## missing_index

**What it catches.** SELECT statements that the database plans as a full table scan on a filtered column.

**How it is detected.** Off by default; set `explain` to `true`. When a trace finishes, Lantern takes the query shapes that took at least `thresholds.explain_min_ms` (default 0, so every SELECT shape qualifies and the result does not depend on one run's timing; never above `slow_query_ms`), picks the slowest five, and runs `EXPLAIN` on one sample of each against the same connection (`EXPLAIN QUERY PLAN` on SQLite). Only SELECT statements are explained. A shape is reported when the plan shows a full scan and the query has a WHERE predicate other than a primary-key lookup. Where the database estimates rows (MySQL, MariaDB, PostgreSQL), scans of 100 rows or fewer are ignored; SQLite has no estimate, so any scan with a column predicate counts. The suggested index lists the columns found in the WHERE clause.

**Severity.** Always `warning`.

**Config.** `explain` (`LANTERN_EXPLAIN`), `thresholds.explain_min_ms`, `thresholds.slow_query_ms`. The row floor is read from an optional top-level `explain_min_rows` key (default 100) that is not in the published config file.

**Example.**

```
warning   missing_index   Possible missing index on orders
          full scan on orders, ~48210 rows, possible keys: none
          Fix: Consider an index on (customer_id, status)
```

**Fix.**

```php
Schema::table('orders', fn ($t) => $t->index(['customer_id', 'status']));
```

Treat the suggestion as a starting point: column order, and whether an index pays off at all, depend on your data. Small tables are planned as scans even when an index exists.

## memory_hog

**What it catches.** Route, controller, view or query spans where PHP memory grows by at least the threshold, and `SELECT` statements with no `LIMIT` that are immediately followed by a large allocation (loading a whole table into a collection).

**How it is detected.** Each span records memory use at start and stop, plus peak growth. A span at or above `thresholds.memory_delta_mb` is flagged. When a parent and a child are both flagged and the child accounts for 80 percent or more of the parent's growth, only the child is reported. An unbounded query (a plain `select ... from table [where|order]` with no limit, join, aggregate or group by) is flagged when the next sibling span grows memory past the threshold, and its issue says so.

**Severity.** `critical` at 4 times the threshold (128 MB by default), otherwise `warning`.

**Config.** `thresholds.memory_delta_mb` (`LANTERN_MEMORY_DELTA_MB`, default 32). Setting it to 0 disables the detector.

**Example.**

```
critical  memory_hog   Memory growth: +146.3 MB in query select * from events
          This query has no LIMIT and is followed by a large memory increase: add a limit/paginate() or process rows with chunkById()/cursor().
```

**Fix.**

```php
// Before: hydrates every row at once
foreach (Event::all() as $event) { /* ... */ }

// After: constant memory
Event::query()->chunkById(500, function ($events) {
    foreach ($events as $event) { /* ... */ }
});
// or
foreach (Event::query()->lazyById(500) as $event) { /* ... */ }
```

## False positives and ignoring queries

- **Framework tables.** Queries whose primary table (the one after `FROM`, `INTO` or `UPDATE`) is in `ignore_tables` are skipped by the four query detectors. Defaults: `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `migrations`, `password_reset_tokens`. Setting the array replaces the defaults, so include the ones you still want.
- **SQL patterns.** `ignore_sql_patterns` entries are plain substrings, or regular expressions when they start with `/`. A string starting with `/` that is not a valid regex is treated as a substring.

  ```php
  'ignore_sql_patterns' => ['telescope_entries', '/^select .* from "audits"/i'],
  ```
- **Paging.** `chunk`, `chunkById`, `lazy`, `lazyById`, `each` and `eachById` (and the descending and ordered variants) are recognized from the call stack and excluded from N+1.
- **Transactions and failures.** BEGIN/COMMIT/ROLLBACK markers and queries that threw an exception are never counted.
- **Whole detectors.** Remove a class from `detectors`, or raise a threshold, to silence a category.
- **Which executions are traced.** `ignore_paths`, `ignore_jobs`, `trace_jobs` and `trace_commands` decide what is traced at all; see [Configuration](CONFIG.md).
- **Issue volume.** `thresholds.max_issues_per_trace` (default 50) caps issues per trace, highest severity and time first.
