# Detectors

Lantern runs eight detectors against every finished trace. Each reports an issue with a type, a severity (`critical`, `warning`, `info`), a call site (file and line), the number of occurrences, the total time, and a suggested fix. Issues with the same key are merged across requests, so the dashboard and `lantern:issues` show one row per problem with `first_seen`, `last_seen` and a running count.

The detector list is the `detectors` array in `config/lantern.php`. List a class in `disabled_detectors` to turn that detector off. Detectors added after 0.3 (currently `PlanFlipDetector`) are merged into a `detectors` list published before they existed, so an upgrade needs no config edit; the older detectors stay off if you removed them from your list. `lantern:status` shows the active detectors and warns about any that are off. A detector that throws is skipped and never affects the request.

The query detectors (N+1, duplicate, slow, missing index, index not chosen, plan flip) skip transaction markers, failed queries and framework plumbing (see [False positives](#false-positives-and-ignoring-queries)).

| Type | Catches | Default trigger |
|---|---|---|
| `n_plus_one` | Same query shape run repeatedly from one call site | 5 or more repeats |
| `lazy_load` | Relation loaded lazily on many models in a loop | 5 or more lazy loads at one site |
| `duplicate_query` | Identical SQL and bindings run more than once in a trace | 2 or more repeats |
| `slow_query` | A query slower than the threshold | 100 ms |
| `missing_index` | A SELECT that EXPLAIN reports as a full scan with no suitable index | needs `explain` on |
| `index_not_chosen` | A SELECT that has a suitable index but EXPLAIN chose a full scan | needs `explain` on |
| `plan_flip` | An `IN (...)` query whose plan changes, or whose cost outgrows the list, as the list gets longer | needs `explain` on; samples across requests need the database driver |
| `memory_hog` | A span that grows memory sharply, or an unbounded SELECT followed by growth | 32 MB |

## n_plus_one

**What it catches.** A query that repeats once per row of a loop, for example loading each post's author inside `foreach ($posts as $post)`.

**How it is detected.** Shapes are explained in the order they first appear, up to 25 per trace, never ranked by time, so the same SQL gives the same finding on every run. Every query is normalized into a fingerprint: literals, bindings, `IN (...)` list length and insert row count are removed, so `where user_id = 1` and `where user_id = 2` share one shape. Queries are grouped by fingerprint plus call site. A group with at least `thresholds.n_plus_one_min_repeats` members becomes an issue. Queries issued from inside `chunk()`, `chunkById()`, `lazy()`, `lazyById()`, `each()` and their variants are paging, not N+1, and are never grouped.

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

**How it is detected.** Off by default; set `explain` to `true`. When a trace finishes, Lantern takes the query shapes that took at least `thresholds.explain_min_ms` (default 0, so every SELECT shape qualifies and the result does not depend on one run's timing; never above `slow_query_ms`), runs `EXPLAIN` on one sample of each against the same connection (`EXPLAIN QUERY PLAN` on SQLite). Only SELECT statements are explained. A shape is reported when the plan shows a full scan and the query has a WHERE or JOIN predicate other than a primary-key lookup. Where the database estimates rows (MySQL, MariaDB, PostgreSQL), scans of 100 rows or fewer are ignored; SQLite has no estimate, so any scan with a column predicate counts.

**How the suggested index is built.** The suggestion comes from the scanned table only: aliases and qualified names (`o.status`, `orders.status`) are resolved through the SQL, and a column that belongs to another table in the join is never suggested. Within the table:

- Equality columns come first, then `IN`, then range (`<`, `>`, `between`, `like`) columns. Negated predicates (`<>`, `not in`, `is not null`) cannot use an index and are ignored. When the table also has plain filters, its join columns are left out.
- Columns with only a handful of distinct values (at most `explain_low_cardinality`, default 5, or a boolean type such as `tinyint(1)`) are moved to the end, so a yes/no flag is never the first column. If every filtered column is like that, no column list is suggested.
- A real distinct count always decides. MySQL and MariaDB only keep one for columns that lead an index, so a flag that leads none (a `tinyint(4)` called `state`, an integer called `is_vip`) has no count. For those the column's name (`is_*`, `has_*`, `can_*`, `should_*`, `was_*`, `did_*`, `need_*`, `*_flag`) and type (`tinyint`, `bit`, `boolean`, or an `enum` with a few values) are used instead.
- A column that already has its own unique index is skipped, and the list is capped at `explain_max_index_columns` (default 4) columns.
- Distinct counts and column types come from the catalog (`SHOW INDEX` and `information_schema` on MySQL/MariaDB, `pg_stats` on PostgreSQL). SQLite has no such source, so only the filter kind and a flag-style name are used there.

**OR across tables.** When the top-level `WHERE` is an `OR` whose branches touch different tables, no single index can serve it, so no columns are suggested. The suggestion reads: "OR across tables: no single index can serve this; check the plan (EXPLAIN ANALYZE), the join order, or split it into a UNION."

**Plan evidence.** The issue meta keeps the per-table plan (`tables`: table, estimated rows, chosen key, access type), `rows_read` (estimated rows read by the scans), `rows_returned` (`null` unless `explain_analyze` is on, see below) and `columns_by_table`. `lantern:issue` shows `rows_read` (plus `read_ratio`, rows read per row returned, once `rows_returned` is known) and the per-table plan under `evidence`; `lantern:trace --summary` adds `rows_read` to the finding and its query group, and query groups with equal time are ordered by it.

**Rows returned (opt-in).** The estimate says how much the plan reads; the ratio that points at a bad plan is how much it reads against how much it returns. With `explain_analyze` on, Lantern runs each flagged query one more time after the request and stores the rows it returned as `rows_returned`, so `lantern:issue` can show, for example, `rows_read` 820000 and `read_ratio` 20000 for a query that returned 41 rows. **This executes the query a second time** (inside a read-only transaction on PostgreSQL), under a time limit of `explain_analyze_timeout_ms` (default 2000), for at most `explain_analyze_max` queries per trace (default 3). MySQL uses `EXPLAIN ANALYZE` with a `MAX_EXECUTION_TIME` hint, PostgreSQL uses `EXPLAIN (ANALYZE)` with `statement_timeout`, and MariaDB counts the rows inside a derived table under `max_statement_time` (so a `select *` over a join with repeated column names cannot be measured). SQLite is skipped. A run that hits the limit, fails, or carries a lock or side effect (`FOR UPDATE`, `INTO`, `sleep()`, advisory locks, sequences) is not replayed and leaves `rows_returned` as `null`. It never affects the request.

The replay does not depend on `capture_bindings`. While `explain_analyze` is on, each query keeps its real bindings in memory for the first execution of each query shape (up to 25 shapes per trace, values up to 8 KB and 64 KB per query; anything larger or not a plain value is skipped). Lantern uses them to replay the query and to run the plan `EXPLAIN` with the real values, then drops them as soon as the trace has been analysed. They are never stored, logged or returned by any command, API or dashboard; stored bindings stay redacted exactly as `capture_bindings` says. A query that already starts with an optimizer hint block (`select /*+ ... */`) keeps its hints on MySQL: the time limit is added to that block, and an existing `MAX_EXECUTION_TIME` stays if it is tighter.

**Production projection.** Local tables are usually far smaller than production, so a scan that looks cheap here can be the slow one there. When production row counts are known, from `production_row_counts` or the `row_counts` of the `schema_snapshot` file, each scanned table gets a `projected` entry in the issue meta (`table`, `local_rows`, `production_rows`, `factor`) and the suggestion ends with, for example, "reads 75k rows here, about 839k (11x) in production". With a snapshot, tables whose indexes differ from production also get `schema_note`, for example "order_items: local schema differs from production: missing merchant_id_2", which `lantern:issue` shows under `evidence`. Nothing changes when neither is configured.

**Severity.** `warning`; `critical` when the projected production rows of a scanned table reach `thresholds.production_scan_rows` (default 500000, `0` turns it off). `index_not_chosen` follows the same rule.

**Config.** `explain` (`LANTERN_EXPLAIN`), `explain_analyze` (`LANTERN_EXPLAIN_ANALYZE`, default off), `explain_analyze_max` (`LANTERN_EXPLAIN_ANALYZE_MAX`, 3), `explain_analyze_timeout_ms` (`LANTERN_EXPLAIN_ANALYZE_TIMEOUT_MS`, 2000), `thresholds.explain_min_ms`, `thresholds.slow_query_ms`. Optional top-level keys that are not in the published config file: `explain_min_rows` (row floor, default 100), `explain_low_cardinality` (default 5) and `explain_max_index_columns` (default 4).

**Example.**

```
warning   missing_index   Possible missing index on orders
          full scan on orders, ~48210 rows, possible keys: none
          Fix: Consider an index on (customer_id, status, is_archived)
```

**Fix.**

```php
Schema::table('orders', fn ($t) => $t->index(['customer_id', 'status']));
```

Treat the suggestion as a starting point: column order, and whether an index pays off at all, depend on your data. Small tables are planned as scans even when an index exists.

## index_not_chosen

**What it catches.** A full scan where an index that could have helped already exists and the plan did not use it. Writing a migration for such an index is wasted work; the problem is the plan.

**How it is detected.** Runs inside the `missing_index` detector (needs `explain` on). When the plan shows a full scan, Lantern reads the indexes of every table in the plan (`SHOW INDEX` on MySQL/MariaDB, `pg_index` on PostgreSQL; SQLite is skipped). A table is a candidate when an index starts with a column that the query filters on, or, for tables other than the scanned one, joins on, and that index is not the key the plan chose. A table whose chosen key already starts with a filtered column counts as served. Filter-led indexes win over join-led ones, then the index matching the longest run of query columns. The finding names `table.index`, for example `order_items.merchant_id_2`, even when the full scan is on another table.

**Severity.** `warning`, or `critical` under the production projection rule described for `missing_index`. The meta also carries `projected` and `schema_note` when production data is configured.

**Config.** `explain` (`LANTERN_EXPLAIN`), `thresholds.explain_min_ms`, `thresholds.slow_query_ms`, `thresholds.production_scan_rows`.

**Why this happens.** The optimizer can skip an index because of stale statistics, a different join order than you expect, or a cost estimate that changes with the number of values in an `IN (...)` list.

**Example.**

```
warning   index_not_chosen   Index exists but was not chosen: order_items.merchant_id_2
          full scan on orders, ~820000 rows
          Fix: The optimizer chose a different plan (full scan on orders). Check it with EXPLAIN ANALYZE, force the order with a join-order hint, or refresh statistics with ANALYZE TABLE order_items.
```

**Fix.** Look at the real plan first (`EXPLAIN ANALYZE`), then either refresh statistics (`ANALYZE TABLE order_items`) or pin the join order with an optimizer hint such as MySQL's `JOIN_ORDER(order_items, orders)` or `STRAIGHT_JOIN`. The issue meta carries `table`, `index`, `index_columns`, `used_key` and `scanned_table`.

## plan_flip

**What it catches.** A query whose plan changes as an `IN (...)` list grows, or whose cost grows much faster than the list. A `whereIn()` over 7 ids can use an index range while the same query over 41 ids scans a large table, so a request that is fast in development with few values is slow in production with many.

**How it is detected.** Off by default; set `explain` to `true`. Query spans record the length of their longest `IN (...)` list (`in_size`); the fingerprint ignores list length, so all sizes share one shape. Each trace adds one sample per shape and list size: the average time, and an `EXPLAIN` plan (table, key, scan or not, estimated rows) refreshed at most every `plan_flip.refresh_minutes`. Samples live in `lantern_plan_samples` (database storage driver; run the migrations), so sizes seen in different requests are compared. Without that table, or with the memory driver, only sizes seen in the current trace are compared. Per trace at most 10 shapes are sampled and 3 EXPLAINs run; each shape keeps at most 64 sizes (exact up to 64, then about 19% steps). A finding is raised when, between two sizes:

- the chosen plan differs (table, key or scan) and the larger size costs more than the list growth explains: it newly scans, or its time or estimated rows grew faster than the list; or
- time or estimated rows grew more than `plan_flip.factor` times faster than the list.

The larger size must take at least `plan_flip.min_ms` or read at least `plan_flip.min_rows`, so tiny tables do not report. The issue key depends only on the query shape, not on the call-site line.

**After a fix.** A sample belongs to the SQL text it was taken from. The shape fingerprint ignores comments, so each sample also stores a hash of the SQL as written (comments and optimizer hints included; parameter values and list length excluded). When the text changes, for example after adding a `JOIN_ORDER` hint, the shape's old samples are discarded and the new text is explained afresh, so the finding ends as soon as the fixed query runs. Samples taken before the hash column existed (or before running its migration, which adds `sql_hash`) are rewritten the first time the shape runs again. A sample is also re-EXPLAINed, at most every 5 minutes, when a run takes `plan_flip.time_deviation` times more or less than its stored average, which catches a fix that changes no SQL text (a new index, refreshed statistics). If that EXPLAIN finds a different plan, the average starts over instead of mixing old and new runs.

**Severity.** `critical` when the larger size averages at least 5 times `thresholds.slow_query_ms`, otherwise `warning`.

**Config.** `explain` (`LANTERN_EXPLAIN`), `plan_flip.factor` (`LANTERN_PLAN_FLIP_FACTOR`, 3), `plan_flip.min_ms` (`LANTERN_PLAN_FLIP_MIN_MS`, 10), `plan_flip.min_rows` (`LANTERN_PLAN_FLIP_MIN_ROWS`, 1000), `plan_flip.refresh_minutes` (`LANTERN_PLAN_FLIP_REFRESH_MINUTES`, 60), `plan_flip.time_deviation` (`LANTERN_PLAN_FLIP_TIME_DEVIATION`, 4; 0 turns the time trigger off). Samples are pruned with traces by `lantern:prune`.

**Example.**

```
critical  plan_flip   Plan changes as the IN list grows: index merchant_id_2 on order_items at 7 values, full scan on orders at 41
          66 ms at 7 values, 2900 ms and ~820000 rows at 41
          Fix: The optimizer treats 7 and 41 values differently. Compare plans with: php artisan lantern:probe 3f9c2a41b7d0 --in-sizes=7,41
```

`php artisan lantern:probe {id} --in-sizes=1,7,20,41,100` re-runs EXPLAIN for the finding's query at those list sizes, filled with the most frequent values of the filtered column, and lists where the plan changes (see [CLI](CLI.md#lanternprobe-key)).

**Fix.** Look at the real plan with `EXPLAIN ANALYZE`, then refresh statistics (`ANALYZE TABLE order_items`), pin the join order with an optimizer hint (MySQL `JOIN_ORDER(...)`, `STRAIGHT_JOIN`), or keep the list below the size where the plan changes:

```php
foreach (array_chunk($merchantIds, 20) as $chunk) {
    $counts = $counts->merge(Order::whereIn('merchant_id', $chunk)->get());
}
```

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
