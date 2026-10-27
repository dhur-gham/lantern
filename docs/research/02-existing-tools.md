# 02 - How existing tools trace, detect N+1, and store (research for Lantern)

Method: shallow clones (`git clone --depth 1`) of each repo, source read directly (never executed), under
`...\scratchpad\oss\`. Claims marked **[src]** were read in source; **[docs]** came from web docs/search and
are less certain; **[unverified]** means I could not confirm it.

Source repos (all `github.com/...`):
laravel/telescope, barryvdh/laravel-debugbar, itsgoingd/clockwork, laravel/pulse, laravel/nightwatch,
beyondcode/laravel-query-detector, getsentry/sentry-laravel, getsentry/sentry-php, spatie/laravel-ray,
open-telemetry/opentelemetry-php-contrib (Instrumentation/Laravel), NoiseByNorthwest/php-spx.

---------------------------------------------------------------------------

## 1. Per-tool findings

### 1.1 Laravel Telescope  (https://github.com/laravel/telescope)
- **Hook**: `events->listen(QueryExecuted::class, recordQuery)` in `src/Watchers/QueryWatcher.php`. One watcher per concern (query, request, model, cache, gate, view, job...). Entries queue in memory (`Telescope::$entriesQueue`) and are written once at terminate via `Telescope::store()`.
- **Call site**: `FetchesStackTrace::getCallerFromStackTrace()` = `debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)`, first frame with a `file` that does not contain `base_path('vendor/laravel')` (option `ignore_packages=true` flips this to the whole `vendor/`), plus `ignore_paths`. Plain substring match; no frame limit; full backtrace is built on every query (cost).
- **Fingerprint**: `familyHash = md5($event->sql)` - the SQL *with `?` placeholders*, so bindings are normalized for free. The inline-bindings display SQL is produced separately by regex replace (`replaceBindings`, quotes via `PDO::quote`). No IN-list collapsing: `in (?,?)` vs `in (?,?,?)` are different families.
- **N+1**: none. Only a `slow` flag (`options.slow`, default 100 ms) + tag `slow`.
- **Storage** (`database/migrations/..._create_telescope_entries_table.php`): `telescope_entries(sequence PK, uuid unique, batch_id idx, family_hash idx, should_display_on_index, type varchar(20), content LONGTEXT json, created_at idx)`, `telescope_entries_tags(entry_uuid, tag)`, `telescope_monitoring(tag)`. Everything is a JSON blob in one table; a request and its children are tied only by `batch_id` (a flat "batch", no parent/child, no timing offsets).
- **Retention**: `telescope:prune --hours=24` (chunked `DELETE ... WHERE created_at < ?`, loops until 0 rows). Needs scheduling by the user.
- **Overhead / sampling**: no sampling; only `Telescope::filter()` / `filterBatch()` callbacks (record-everything-or-nothing per batch, canonical use: record only errors in prod). Documented as dev/staging tool.
- **Octane/queues/console**: explicitly **disabled under Octane** (`isset($_SERVER['LARAVEL_OCTANE'])` -> never starts recording). Console: allow-list-by-exclusion (`queue:work`, `horizon`, `migrate:fresh`... ignored). Queue jobs recorded via JobWatcher per job.
- **UI**: server-rendered Vue SPA under `/telescope`, list per entry type, click-through to "request -> related entries by batch_id".

### 1.2 Laravel Debugbar  (https://github.com/barryvdh/laravel-debugbar)
- **Hook**: `QueryCollector` (extends php-debugbar collector) fed by `DB::listen`. Injects toolbar HTML into the response (middleware) and persists to a file/DB "storage" so AJAX/redirect requests can be browsed.
- **Call site**: `debug_backtrace(IGNORE_ARGS|PROVIDE_OBJECT, debug_backtrace_limit=50)`, then `backtraceExcludePaths` = `/vendor/laravel/framework/src/Illuminate/{Support,Database,Events,Collections}`, `/vendor/october/rain`, debugbar itself. **Prefix/substring exclusion list rather than "is vendor"**, so third-party package frames survive (good for seeing which package issued the query). Optional editor deep-links (`backtraceEditorLinks`).
- **Per-query extras**: **memory delta** around each query (`memory_get_usage` diff, `memory`/`memory_str`, summed to `memory_usage`) - the only tool in the set with per-query memory; EXPLAIN for read-only queries on demand (`debugbar.queries.explain` route with a signed hash of connection+sql+bindings); slow-threshold highlighting; `soft_limit`/`hard_limit` (after soft limit: no bindings/backtrace captured; after hard limit: counted only) - a good cheap **overload guard**.
- **Duplicates / N+1**: grep for "duplicate" in the collector returns nothing; it shows counts and relies on third-party `beyondcode/laravel-query-detector` for N+1 (that package has a Debugbar output). **[src]**
- **Storage/retention**: php-debugbar `FileStorage` (JSON per request id) or DB; capped by `max` files (default 50) - ring-buffer style GC, no time-based TTL.
- **Octane**: works poorly/needs reset hooks; designed as a per-request dev toolbar.
- **UI**: injected bottom toolbar + OpenHandler "history" dropdown. Excellent proximity to the page being debugged, no cross-request analysis.

### 1.3 Clockwork  (https://github.com/itsgoingd/clockwork)
- **Hook**: `EloquentDataSource` listens to `QueryExecuted` (+ legacy `illuminate.query`, Transaction begin/commit/rollback, `eloquent.booted: *`, `eloquent.{retrieved,created,...}: *`) and other DataSources (cache, redis, views, events, queue, http client, notifications, Xdebug). Each DataSource implements `resolve(Request)` at the end of the request. **One data model for HTTP, `command`, `queue-job`, `test`** (`RequestType`).
- **Call site**: `Helpers/StackTrace::get()` returns objects (`StackFrame`) with `->first()/->skip()/->filter()/->limit()`, composable `StackFilter` (`isNotVendor`, `isNotNamespace`, `isNotClass`, `isNotFile`, `isNotFunction`). Also **`resolveViewName()`** - maps compiled Blade cache frames (`storage/framework/views/<hash>.php`) back to the real view name; no other tool does this for query call sites. Also fixes up `call_user_func` frames.
- **Duplicate/N+1** (`Concerns/EloquentDetectDuplicateQueries.php`): only when a frame `getRelationValue` or class `Relation` exists in the backtrace (needs `arguments=true` so args are captured = slower, hence opt-in); key = `Model-relation-file-line` where file/line is the first frame not in `itsgoingd|laravel|illuminate` vendors/namespaces. Count per key, shown as warnings; this is functionally the same algorithm as beyondcode/query-detector.
- **Fingerprint**: no SQL hash; model and duplicate keys only. Bindings are inlined in the stored `query` string (`createRunnableQuery`, keywords uppercased).
- **Storage**: `FileStorage` (default, JSON per request id), `SqlStorage` (one wide table `clockwork`: id, type, time, method, uri, controller, responseTime, responseStatus, memoryUsage, middleware, `databaseQueries` TEXT json, counters `databaseQueriesCount/SlowQueries/Selects/Inserts/...`, cache counters, `modelsActions`, ... ~60 columns where nested data is a JSON TEXT), `RedisStorage`, `SymfonyStorage`. Summary counters are real columns so listing/filtering is cheap; details are blobs. Retention: `storage_expiration` default 7 days, cleanup by lottery on write.
- **Sampling/filters**: `requests.sample` (1-in-N), `slow_only` (requests and queries), `only_on_errors`, `collect_data_always`; early filter (`passesFilters(.., 'early')`) allows counting queries without keeping details.
- **Timeline**: `Request/Timeline/{Timeline,Event}` - named events with start/end (`begin()/end()/run(closure)`), rendered as a waterfall of controller/middleware/DB/etc. Flat (no parent ids), but is the closest to a unified timeline in the OSS set.
- **UI**: browser extension + `/clockwork` standalone SPA; Timeline, Database, Cache, Events, Views, Log, Performance tabs; CLI `clockwork-cli`-style tail.

### 1.4 Laravel Pulse  (https://github.com/laravel/pulse)
- **Hook**: recorders (`src/Recorders/*`) each declare `$listen` event class; `Pulse::record()` pushes to in-memory `entries`; `Pulse::lazy()` defers resolving (e.g. user id, route) until `ingest`. Ingest on terminate, or when buffer > 5000, or via Redis stream (`pulse:work`) for high load.
- **SlowQueries recorder**: threshold per regex pattern, `sample_rate` via `Lottery::odds()`, `ignore` regex list, `max_query_length`, optional location. Location = `debug_backtrace(IGNORE_ARGS)->skip(2)` first frame with file not under `vendor/laravel/{pulse,framework}`, `artisan`, `public/index.php` - **only framework is excluded; third-party package frames count as app code**. Key = `json_encode([$sql, $location])` -> aggregated; value = duration, aggregates `max()+count()`.
- **Fingerprint**: raw SQL with `?` (placeholders, normalized by construction) + location; stored md5 as virtual/stored column `key_hash` (MySQL `unhex(md5(key))` char(16) binary, PG `md5::uuid`).
- **Schema**: `pulse_values (type, key, key_hash, value)` unique(type,key_hash) = latest-value store; `pulse_entries (timestamp, type, key, key_hash, value)` = raw events for trimming/aggregating; `pulse_aggregates (bucket, period, type, key, key_hash, aggregate, value, count)` unique(bucket,period,type,aggregate,key_hash) = **pre-aggregated rollups at several periods (1h/6h/24h/7d) upserted `ON DUPLICATE KEY UPDATE`**. No per-request detail at all.
- **Retention**: `ingest.trim.lottery [1,1000]` -> `trim()` deletes by timestamp/period; `keep` window config. 
- **Sampling**: per-recorder `sample_rate` (Lottery, random) and `shouldSampleDeterministically($seed)` = `hexdec(md5(seed))/16^32 <= rate` (hash-based so the same user/request is consistently in/out - good pattern).
- **Octane**: first-class (`Octane` flush hooks, `ingest` per request, `Pulse::flush()`); queue/console recorded via Queues + SlowJobs recorders.
- **Error isolation**: every recorder call wrapped in `Pulse::rescue()` so monitoring never breaks the app.
- **UI**: Livewire dashboard cards (slow queries, slow requests, exceptions, queues, cache, servers).

### 1.5 Laravel Nightwatch agent  (https://github.com/laravel/nightwatch; source IS public, backend is hosted/closed)
- **Hook**: ~40 small `Hooks/*` listeners, each `try/catch -> $core->report($e, handled: true)` (never break the host app). Hooks cover: `RequestBooted`, `RouteMatched`, global + route middleware, `PreparingResponse`, `ResponsePrepared`, `RequestHandled`, `Terminating`, `QueryExecuted`, cache, mail, notification, queued job (create payload -> context dehydrate), job attempt, scheduled task, Livewire, Guzzle/Http client, log processor, `OctaneListener`, `WorkerLifecycleListener`.
- **Execution stages** (`ExecutionStage` enum): `bootstrap, before_middleware, action, render, after_middleware, sending, terminating, end`. Every query/event record carries `execution_stage`, `execution_id`, `trace_id`, `execution_source`, `user`, `deploy`, `server`. That is a **coarse but cheap unified timeline**: durations per stage per request, children attributed to a stage rather than to a parent span.
- **Call site** (`src/Location.php::forQueryTrace`): walk trace; first frame whose file is not under `vendor/` -> `[file,line]` (path relative to base); else fall back to first vendor frame that is not nightwatch/framework-internal (so a package-originated query still gets a location). Excludes artisan and `public/index.php`. Trace captured in a single `debug_backtrace` by the listener.
- **Fingerprint** (`QuerySensor::hash`): `xxh128("{connection},{sql}")` after `preg_replace('/in \([\d?\s,]+\)/', 'in (...?)')` and, for inserts, `values [(?,\s)]+ -> values ...` - **IN-list and multi-row insert collapsing** (SQL is placeholder SQL, so bindings never enter the hash). Driver allow-list for normalization; unknown drivers hash the raw SQL.
- **Record fields** (`Sensors/QuerySensor`): `timestamp` (= now - duration), `_group` (hash), `sql` (mediumtext-truncated), `file`, `line`, `duration` in microseconds, `connection`, `connection_type` (read/write), `trace_id`, `execution_*`.
- **Sampling/overhead**: config `sampling.{requests,commands,exceptions,scheduled_tasks}` rates (`Core::sample()` = `random_int/PHP_INT_MAX <= rate`, decided once per execution and propagated through Laravel Context so queued jobs inherit the decision -> **trace-consistent sampling**). `ingest` goes to a local agent over TCP `127.0.0.1:2407` with 0.5 s timeouts, `event_buffer=500` (digest when full unless unsampled); a separate PHP agent process (`/agent`, box/phar) forwards upstream. Redaction of headers/payload fields built-in.
- **Memory**: only **peak memory per execution** (`memory_reset_peak_usage()` between Octane requests / queue jobs / scheduled tasks). No growth attribution.
- **Octane/queues/console**: explicit support: state flush + `resume()` + peak reset per request/job/task; Request vs Command state classes.
- **N+1**: no in-package detector; the hosted backend groups queries by `_group` and can count per request (not visible in repo).
- **UI**: hosted (nightwatch.laravel.com); not available self-hosted.

### 1.6 beyondcode/laravel-query-detector  (https://github.com/beyondcode/laravel-query-detector)
- **Hook**: `DB::listen`, captures `debug_backtrace(PROVIDE_OBJECT, 50)` (with objects, so it can inspect the `Builder`/`Relation`/model instance) per query. Middleware prints output (alert, console.log, JSON, log, Clockwork, Debugbar).
- **Algorithm** (`QueryDetector::logQuery`): only queries whose backtrace has an object that is an Eloquent `Builder`; then finds a frame `getRelationValue` (lazy attribute access) or class `Relation`; key = `md5(sql . modelClass . relationName . firstAppFrame.file . line)`; increments `count` and sums `time`. At response time reports keys with `count > threshold` (default **1**, i.e. 2+ identical lazy loads from the same file:line). Config `except` whitelist `[Model => [Relation::class, 'relationName']]`.
- **Call site**: first non-excluded frame with class+file (excludes `vendor/laravel/framework/src/Illuminate/Database` and `Events`; **keeps other vendor frames**).
- **Strength**: knows the *relation name and parent model* so the fix hint is exact (`with('posts')`). **Weakness**: only catches lazy relation loads, not N+1 via manual `find()`/`where` in loops, not `Model::find` per id; per-request in-memory only; no persistence; misses `$query->sql` binding differences intentionally (placeholder SQL) but includes file:line so same site = same group.
- Other: Laravel core `Model::preventLazyLoading()` / `handleLazyLoadingViolationsUsing()` (throws/reports per lazy load; `automaticallyEagerLoadRelationships()` in newer versions) [docs]; `stephen-tembo-dev/laravel-n1-detector` (tests/CI, SQL fingerprint, bursts of structurally-identical single-key lookups) [docs: https://packagist.org/packages/stephen-tembo-dev/laravel-n1-detector]; hosted NightOwl [docs, unverified quality].

### 1.7 Sentry (sentry-laravel + sentry-php)  (https://github.com/getsentry/sentry-laravel)
- **Hook**: `Tracing/EventHandler` subscribes to Laravel events: `RouteMatched` (names the transaction `GET /users/{id}`), `QueryExecuted`, Transaction begin/commit/rollback (spans), `PreparingResponse`/`ResponsePrepared` (response span), plus `ViewEngineDecorator` (view render spans), `Tracing/Middleware` (transaction start + `TransactionFinisher` on terminate), and Features: Cache, Queue, Console, Scheduling, HttpClient, Livewire, Folio, Pennant, Storage, Notifications, Log (breadcrumbs).
- **Query span** (`queryExecutedHandler`): span op `db.sql.query`, description = placeholder SQL, `db.system/name/server.address/port`, start = `microtime - time/1000`. Bindings only if `sql_bindings`/data-collection policy allows. Returns early when there is no *sampled* parent span (zero cost for unsampled traces).
- **Call site**: `sql_origin` (default on) adds `code.filepath/function/lineno` **only for queries >= `sql_origin_threshold_ms` (default 100 ms)** via `BacktraceHelper::findFirstInAppFrameForBacktrace` (`Frame::isInApp()` driven by `in_app_include/exclude`, vendor excluded). So full backtrace cost is paid only on slow queries.
- **N+1 detection is server-side** (Sentry backend, not in SDK) [docs: https://docs.sentry.io/product/issues/issue-details/performance-issues/n-one-queries/ , https://github.com/getsentry/sentry/discussions/37083]: operates on the span tree of a transaction; needs a "source" span followed by >= **5** repeating db spans with the same *parametrized* description, cumulative duration threshold (reported ~50 ms, configurable per project); issue fingerprint derived from transaction + parent span + repeating-span description, so occurrences group across requests into one issue. Known false positives for concurrent queries.
- **Breadcrumbs**: SQL queries, cache, logs, and HTTP are added as breadcrumbs on the *error* event (ring buffer, `max_breadcrumbs`=100 default) - an error report carries "what happened just before".
- **Sampling**: `traces_sample_rate` (head-based, decided at transaction start, propagated via `sentry-trace`/`baggage` headers and queue job payloads), `traces_sampler` callback (per-route), `profiles_sample_rate` (Excimer/Vernier-style sampling profiler under tracing), `ignore_transactions`.
- **Storage/UI**: SaaS (or self-hosted, heavy Kafka/ClickHouse stack). Event = transaction + spans[] with `trace_id/span_id/parent_span_id/op/description/start/timestamp/data/status`. Strongest UI idea set: trace waterfall, issue grouping, "suspect spans", release/commit linkage.
- **Octane**: dedicated handling in `Tracing/Middleware` and `EventHandler` (flush per request).

### 1.8 spatie/laravel-ray  (https://github.com/spatie/laravel-ray)
- Pushes payloads to a desktop app (HTTP to `localhost:23517`); a dev-time debugging channel, no storage.
- `QueryWatcher` (all queries), `DuplicateQueryWatcher` (**duplicate = identical SQL with bindings substituted** (`Str::replaceArray('?', bindings)`) seen earlier in the same process; an in-memory string list, `in_array` per query = O(n^2), and never reset between Octane requests), `SlowQueryWatcher` (threshold), per-verb watchers (Select/Insert/Update/Delete), `ConditionalQueryWatcher`, `JobWatcher`, `ViewWatcher`, `RequestWatcher`. Call site is attached by Ray's frame helper (`Spatie\Backtrace`, first non-vendor frame, `ray.remote_path/local_path` mapping for containers).
- Idea to steal: exact-duplicate (same SQL+bindings) is a *different, stronger* signal than N+1 (same shape, different bindings) and should be tracked separately.

### 1.9 OpenTelemetry PHP (Laravel auto-instrumentation)  (https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Instrumentation/Laravel)
- **Hook**: PHP extension `ext-opentelemetry` `hook()` (observer API) wraps methods of `Illuminate\Foundation\Application`, `Contracts\Http\Kernel::handle` (root server span), `Console\Command`, `Queue\Worker`, `Queue::push`, `Eloquent\Model` (`find`, `save`, ... spans), plus `Watchers/QueryWatcher` listening to `QueryExecuted` and creating a CLIENT span *after the fact* with back-dated start (`now - time*1e6` ns). Watchers for cache, logs, exceptions, HTTP client.
- **Span model**: `trace_id (16B)`, `span_id (8B)`, `parent_span_id`, `name` (`sql SELECT`), `kind`, `start/end ns`, `attributes` (`db.system.name`, `db.namespace`, `db.operation.name`, `db.query.text` = placeholder SQL, `server.address/port`), `events` (exceptions), `links`, `status`, `resource` (service.name...). W3C `traceparent` propagation; head sampling (`TraceIdRatioBased`, `ParentBased`) + tail sampling in the collector.
- **No call-site (`code.filepath/lineno`) and no N+1** in the PHP query watcher **[src]** - the semantic conventions exist (`code.*`) but are not populated. Storage/UI are external (Jaeger, Tempo, SigNoz...). Batch span processor (`BatchSpanProcessor`, queue 2048, export on timer/terminate) = bounded buffer, drop-on-overflow.
- Idea to steal: use OTel semantic-convention attribute *names* and trace/span id formats so users can export to any backend (OTLP exporter as a later add-on).

### 1.10 Profilers: Xdebug / SPX / Blackfire / Tideways
(web docs; source of SPX not cloned) 
- **Xdebug profile/trace modes** [docs: https://xdebug.org/docs/profiler , https://xdebug.org/docs/trace]: instrument *every* function call (cachegrind file / function trace with timestamps and memory); 2-20x slowdown; dev only. Trace has **memory per call** - the only way to get exact memory-growth attribution by function, but unusable under load.
- **SPX** [docs: https://github.com/NoiseByNorthwest/php-spx]: extension, keeps the *full call stack* (not caller/callee aggregates like XHProf), 22 metrics (wall/CPU time, memory, objects, included files, I/O); outputs flat profile, web UI with **timeline** + flame graph, Chrome Trace Event Format; `spx_profiler_start/stop` can be nested for long-running processes (works with Octane/workers). Enabled per request via cookie/query/env key; dev/staging only ("experimental").
- **Blackfire** [docs: https://docs.blackfire.io/]: probe on demand (browser/CLI), call graph + timeline, **assertions/tests on metrics** (e.g. "queries < 20" in CI), builds compare; production profiling sampled by the agent (Tideways/Blackfire both use small overhead sampling per transaction).
- **Tideways** [docs: https://tideways.com/profiler/php-profiler]: always-on lightweight tracing of *framework-aware spans* (HTTP, SQL, cache, view), automatic transaction naming, **deep call-trace profile triggered only for slow/erroring requests** (adaptive: low-overhead summary always, full trace on demand/threshold) - the best model for "heavy-load detection with sampling".
- Takeaways: (1) function-level tracing is a separate, opt-in mode; (2) framework-event spans are the right always-on layer; (3) trigger deep capture adaptively.

---------------------------------------------------------------------------

## 2. Comparison table

| Capability | Telescope | Debugbar | Clockwork | Pulse | Nightwatch | query-detector | Sentry | Ray | OTel PHP |
|---|---|---|---|---|---|---|---|---|---|
| Primary use | dev/stage log | dev toolbar | dev/stage + prod-lite | prod aggregates | prod hosted APM | dev N+1 alert | prod APM+errors (SaaS) | dev desk debug | export pipeline |
| Query hook | QueryExecuted | DB::listen | QueryExecuted | QueryExecuted | QueryExecuted | DB::listen | QueryExecuted | QueryExecuted | QueryExecuted |
| File:line source | first non-`vendor/laravel` (or all vendor) frame | exclusion list of Illuminate paths | StackFilter (vendor/ns/class) + Blade view resolve | skip framework+pulse | non-vendor else first non-internal vendor | exclude Illuminate Database/Events | first in-app frame, only if >=100 ms | Spatie backtrace | none |
| Frame cap | none | 50 | configurable | none | n/a | 50 | SDK default | n/a | n/a |
| Query fingerprint | md5(placeholder SQL) | none | none (dup key = model+rel+file:line) | placeholder SQL + location, md5 | xxh128(conn+SQL w/ IN/VALUES collapsed) | md5(sql+model+rel+file+line) | server-side parametrize | exact SQL+bindings | none |
| N+1 | no | no (plugin) | relation-only, opt-in | no | server-side (hidden) | relation-only | server-side span-pattern (>=5, ~50ms) | exact dups only | no |
| Slow query | flag >=100 ms | highlight | flag + slow_only | threshold per pattern | n/a (UI) | no | span only | yes | no |
| Per-request tree | batch_id only | one request | flat timeline | none | stages | none | full span tree | none | full span tree |
| Memory | no | per query delta | request peak | server cards | peak per exec | no | no | no | no |
| Storage | 1 table JSON blob + tags | files | files/SQL/Redis | 3 rollup tables | remote | memory | remote | desktop app | remote |
| Retention | prune cmd hours=24 | max N files | 7 days lottery | trim lottery + windows | remote | request | remote | none | remote |
| Sampling | filter callbacks only | soft/hard query limit | sample 1/N, slow_only | per-recorder rate (Lottery, deterministic option) | per-kind rate, propagated | none | head rate + sampler + profiles | none | ratio/parent-based |
| Octane | disabled | poor | works (reset) | first class | first class | no reset | supported | leaks dup list | per request |
| Queue/console | partial | no | yes (type column) | yes | yes + context propagation | no | yes + trace propagation | jobs | yes |
| UI | Vue SPA | toolbar | SPA/extension | Livewire cards | hosted | inline alert | SaaS waterfall/issues | desktop | external |

---------------------------------------------------------------------------

## 3. Best ideas to steal

1. **Cheap-first call-site capture** (Sentry/Nightwatch/Pulse): `debug_backtrace(IGNORE_ARGS, limit=~25)`; resolve file:line of first *app* frame; do the expensive things (full stack, PROVIDE_OBJECT, args) only for slow queries, N+1 candidates (after count passes threshold), or sampled-in traces. Nightwatch's fallback (first non-internal vendor frame when no app frame exists) is better than returning nothing.
2. **Three-tier frame classification** instead of binary vendor filter: `app` (base_path minus vendor), `package` (vendor, not laravel/framework/lantern), `framework`/`internal`. Report the first `app` frame as primary site, but keep the first `package` frame too (so "this query came from filament/tables" is visible). Clockwork `StackFilter` + Debugbar path list + Pulse skip list combined.
3. **Blade/compiled-view frame resolution** (Clockwork `resolveViewName`) so queries issued from templates point at `resources/views/x.blade.php:LINE`, not a cache hash. Use the `// path` header Blade compiled files carry plus `Blade::getPath`/ignition-style line mapping.
4. **Fingerprint = placeholder SQL, normalized** (Nightwatch): collapse `IN (...)`, `VALUES (...),(...)`, `LIMIT/OFFSET ?`, strip comments/whitespace, lower-case keywords; never hash bindings. Track *exact-duplicate* (SQL+binding hash) separately (Ray).
5. **N+1 key = shape + call-site (+ relation info when available)** (query-detector/Clockwork) combined with Sentry's **span-adjacency view** (repeating siblings under one parent) so non-relation N+1 (`User::find($id)` in a loop, `->count()` per row) is caught too.
6. **Pre-aggregate in the write path** (Pulse): upsert hourly rollups per `(fingerprint, site)` so the dashboard stays fast and raw traces can be pruned aggressively. `key_hash` as generated column.
7. **Sampling design**: Nightwatch's decide-once-per-execution + propagate via `Context` to queued jobs; Pulse's deterministic hash sampling; Tideways' "light always, deep on trigger" (tail-style: keep raw trace if slow/error/N+1-found even if not sampled - implement by buffering the compact trace in memory and deciding at terminate).
8. **Soft/hard limits** (Debugbar): after N spans stop collecting details, after M stop collecting at all but keep counters (+ memory cap). Protects the host under pathological loops (100k queries).
9. **Never break the host** (Nightwatch/Pulse): every hook try/catch, recorder wrapped in `rescue`, 0.5 s socket timeouts, drop-on-overflow buffers.
10. **Stages** (Nightwatch `ExecutionStage`) as first-class span kind for coarse request phases: bootstrap -> before_middleware -> action -> render -> after_middleware -> sending -> terminating.
11. **Breadcrumb ring buffer attached to exceptions** (Sentry): last ~50 spans/logs before an exception.
12. **Per-query memory delta** (Debugbar) and **peak memory reset** for long-running workers (Nightwatch `memory_reset_peak_usage`).
13. **Slow-query EXPLAIN on demand** with signed hash (Debugbar), read-only guard.
14. **OTel attribute names + W3C trace ids** for portability.
15. **Request-type column** (Clockwork `RequestType`) - one table for http/command/job/test.

## 4. Gaps none of them fill (Lantern's opportunity)

1. **One unified, parent/child span timeline** across: request -> global/route middleware (each middleware as span, with `before`/`after` split) -> route resolution -> controller/action -> form request validation -> policy/gate -> Eloquent/DB (+ model hydration counts) -> cache/redis -> HTTP client -> events/listeners -> view/Blade (+ components) -> Livewire lifecycle (mount/hydrate/update/render/dehydrate, per-action) -> Filament (resource page, table query, form schema, action) -> response/terminate -> dispatched jobs (linked trace). Clockwork has a flat timeline; Nightwatch has stages; Sentry has the tree but is SaaS and poor on Livewire/Filament semantic naming; Telescope has no timing relations at all.
2. **Memory-growth attribution**: nobody attributes memory *delta per span* with a bounded cost. Idea: sample `memory_get_usage(false)` at span start/end (it is a cheap O(1) call), store `mem_start/mem_delta/peak` per span; flag spans with `delta > X` and long-running worker drift (per-job/per-request baseline across a worker's lifetime -> leak suspect by `(route|job class)` trend). Optional model-hydration counter (`eloquent.retrieved`) as the usual cause ("loaded 40k models").
3. **Heavy-load safe operation**: sampling in OSS tools is either absent (Telescope) or random-only (Pulse). Missing: *adaptive* sampling by load (e.g. error-budget or rate limiter: max N traces/min, always keep errors/slow/N+1 hits), tail-based keep decision, overhead self-metering (Lantern measures its own cost per request and degrades: drop bindings -> drop backtraces -> counters only), queue-based/async persistence with bounded buffer.
4. **N+1 that explains itself with a fix** across persisted traces: query-detector shows it for one request in memory only; Sentry groups server-side without file:line tied to the lazy relation; nobody joins `(relation, parent model, call site, Blade/Livewire/Filament origin)` and ranks by *cumulative cost across requests* ("this site cost 18 s last 24h"). Also detects lazy-loaded N+1 inside Filament tables/Livewire renders.
5. **Regression-over-deploys**: compare fingerprint stats per release/commit (Sentry does; no OSS Laravel tool does).
6. **Trace-to-code usability**: editor deep links with path mapping, *source snippet around file:line stored at capture time* (Nightwatch does this only for exceptions), Blade line mapping.
7. **Octane-correct state**: most tools are not leak-safe (Ray dup list, query-detector). Need per-request context object reset on `RequestReceived`/`RequestTerminated`, and WeakMap-keyed state for Livewire/Filament components.
8. **Cross-process trace stitching without a collector**: request -> queued job -> scheduled follow-up, via Laravel `Context` (Nightwatch does the propagation but stores remotely).

---------------------------------------------------------------------------

## 5. Recommended design for Lantern

### 5.1 Capture model (process side)
- One `TraceContext` per execution (request / command / job / scheduled task / test), resolved from the container as a *scoped* instance (`app->scoped`, flushed on Octane `RequestReceived`, queue `JobProcessing`, `JobProcessed/Failed`, `Looping`).
- Stack of open spans; `startSpan(kind, name, attrs)` returns an int index (spans stored in a pre-sized array of small arrays, no objects, to keep GC/memory low). `endSpan(idx)`.
- Retroactive spans for Laravel events that only report duration after the fact (queries, cache, http) with `start = now - duration` (same as Sentry/OTel).
- Hard limits: `max_spans` (default 1000 detailed), `max_queries_detailed` (default 200), beyond that counters only + `dropped_spans`.
- Persist at terminate (after response is sent: `app->terminating` / Octane `RequestTerminated`) in one bulk insert, or hand to a queue/Redis list when `driver=async`.

### 5.2 Data model (trace/span)
```
Trace   = one execution
  trace_id        ULID/16B hex (W3C-compatible; also accepts incoming traceparent)
  kind            request | command | job | schedule | test
  name            "GET /users/{user}" | "app:sync" | "App\Jobs\X"
  started_at, duration_us, status (ok|error|slow), http_status, user_id, release, env, host, octane(bool), pid
  peak_memory, mem_start, mem_end, mem_delta, span_count, dropped_span_count
  query_count, query_time_us, query_dup_count, query_n1_count, model_hydrated_count
  sampled_reason (random|slow|error|n1|forced), parent_trace_id/parent_span_id (job from request)
Span    = one node (tree)
  span_id (int within trace), parent_id, kind, name, stage
  start_offset_us (from trace start), duration_us, self_time_us (computed at close)
  mem_delta, mem_peak?
  attrs JSON  (kind-specific)
  site_file, site_line, site_kind (app|package), site_label (e.g. "App\Livewire\Orders::render")
kinds: stage, middleware, route, controller, validation, authorization, db, model_hydrate, cache, http_client,
       event, listener, view, component, livewire, filament, job_dispatch, mail, notification, log, exception
```

### 5.3 DB schema (MySQL/MariaDB/Postgres/SQLite-compatible; prefix `lantern_`)
```
lantern_traces
  id                 ulid PK (or bigint auto + ulid unique)
  kind               varchar(12)  idx(kind, started_at)
  name               varchar(255) idx(name, started_at)       -- route/command/job
  started_at         datetime(6)  idx
  duration_us        bigint unsigned
  status             tinyint        -- 0 ok 1 error 2 slow
  http_status        smallint null
  user_id            varchar(64) null
  release            varchar(64) null
  peak_memory        bigint unsigned, mem_delta bigint
  span_count         int, dropped_span_count int
  query_count int, query_time_us bigint, dup_count int, n1_count int, hydrated_models int
  sampled_reason     varchar(12)
  parent_trace_id    ulid null
  meta               json null          -- url, method, ip(hashed), octane, host, pid

lantern_spans
  trace_id ulid, span_no int            PK(trace_id, span_no)   -- range scans per trace
  parent_no int null
  kind varchar(16), name varchar(255)
  start_offset_us int unsigned, duration_us int unsigned, self_us int unsigned
  mem_delta bigint null
  fingerprint char(16) binary null      idx(fingerprint)        -- db spans
  site_file varchar(255) null, site_line int null, site_kind tinyint null
  attrs json null                       -- sql (raw placeholder), bindings_hash, connection, rows, relation, model...
  -- partition/prune by trace started_at via trace_id ULID time component

lantern_queries (denormalized copy of db spans, optional, for fast cross-trace queries)
  trace_id, span_no, fingerprint, dup_hash char(16), duration_us, started_at, site_file, site_line, connection

lantern_issues   (the Sentry-like grouped problems)
  id bigint PK
  type            varchar(16)  n_plus_one | slow_query | duplicate_query | slow_route | memory_spike | heavy_load
  fingerprint     char(16) unique(type, fingerprint)
  title           varchar(255)       -- "N+1 on App\Models\Post -> author (3 sites)"
  site_file, site_line, route_or_job varchar(255)
  first_seen, last_seen datetime, occurrences bigint, total_time_us bigint, max_count int, max_duration_us bigint
  status          open|ignored|resolved   release_first, release_last
  sample_trace_id ulid         -- worst example
  meta json                     -- relation, parent_model, suggestion ("with('author')")

lantern_issue_events (trace -> issue link, bounded)
  issue_id, trace_id, count int, duration_us bigint   idx(issue_id, trace_id)

lantern_rollups  (Pulse-style, never pruned until long window)
  bucket int(unix hour), period smallint(3600|86400), type varchar(16), key_hash char(16),
  count bigint, sum_us bigint, max_us int, p-sketch/ histogram buckets json null
  unique(bucket, period, type, key_hash)  -- upsert ON DUPLICATE KEY / ON CONFLICT
lantern_fingerprints (dictionary)  fingerprint pk, normalized_sql text, first_seen  -- stores normalized text once, spans only reference the hash
```
Retention: `lantern:prune` (scheduled automatically via package `Schedule` if the user opts in; also lottery on ingest like Pulse/Clockwork). Tiers: raw spans 24-72 h (configurable), traces 7 d, **traces of issues kept as long as the issue is open** (pin `sample_trace_id` and N latest), rollups 90 d. Prune in id-range chunks (Telescope pattern) ordered by the PK ULID; pruning by trace_id range avoids secondary index scans. Optional date partitioning docs for MySQL/PG.

### 5.4 Fingerprinting algorithm
```
normalizeSql(sql):
  s = strip comments (/* */, --, #), collapse whitespace, lowercase keywords/idents outside quotes
  s = replace literals still inline (single-quoted strings, numbers, uuid) with ?   (raw SQL / DB::raw cases)
  s = regex IN\s*\(\s*(\?|\s|,)+\)            -> in (...?)        (Nightwatch)
  s = regex values\s*(\([?,\s]+\)\s*,?\s*)+    -> values (...?)
  s = regex (limit|offset)\s+\d+               -> $1 ?
  s = collapse `(?, ?, ...)` any arity -> (...?)
  s = replace `table_alias_123`/numbered aliases (laravel_reserved_N, exists_N) -> stable form
fingerprint(sql, connection)        = xxh128(connection . "\0" . normalizeSql(sql))[:16 bytes]
siteKey                              = "{site_file}:{site_line}"   (app frame, path relative to base_path)
shapeSiteKey = fingerprint . siteKey                       -- N+1 / dup candidate key
dupHash(sql, bindings)               = xxh128(fingerprint . serialize(normalizedBindings))   -- exact duplicate
```
Binding normalization for dup detection: DateTime -> ISO string, bool -> int, Stringable cast, `null` explicit, floats formatted, large blobs -> `len:crc32`. Bindings themselves are NOT stored by default (privacy); only `dupHash` and optional redacted preview behind a config flag.

### 5.5 N+1 / duplicate / slow detection (at terminate over the in-memory span list, O(n) with a hash map)
1. Group `db` spans by `shapeSiteKey` (fingerprint + app call site) **and** by parent span id if parent is the same (adjacency, Sentry idea: loops produce siblings).
2. **N+1 if** group `count >= n1_threshold (default 3; Sentry 5; query-detector 2)` **and** `sum_duration >= n1_min_total_ms (default 5 ms)` **and** bindings vary (distinct `dupHash` count >= 2 - differentiates from pure duplicate) **and** query is SELECT. Evidence: first N+1 span index, count, total time, relation/parent model if captured.
3. **Relation enrichment** (cheap, only for groups that crossed threshold, so the heavy `PROVIDE_OBJECT` backtrace is taken lazily: keep a cheap one for each query and re-capture with objects for the 2nd..3rd repetition of the same shapeSiteKey): detect `getRelationValue`/`Relation` frames (Clockwork/query-detector) to emit `relation`, `parent model`, and suggestion `->with('relation')`. Also hook `Model::preventLazyLoading` violation callback if user enabled it, and `Eloquent retrieved` count to confirm.
4. **Exact duplicate** if `dupHash` repeats within a trace (count >= 2, default; ignore inside explicit transactions only if config says so).
5. **Slow** if `duration >= slow_ms (default 100)`; **slow route** by p95 from rollups, not static only; **heavy** if `query_count > query_budget (default 50)` or `hydrated_models > N` or `mem_delta > M`.
6. Issue fingerprint (cross-request grouping): `xxh128(type . routeOrJobName . shapeSiteKey)` (excludes release/user/binding so occurrences aggregate); upsert `lantern_issues` and rollups.
7. Allow-list: config `except` like query-detector (`[Model::class => ['relation']]`) plus ignore by `site_file` glob and by fingerprint.

### 5.6 Hooks and overhead plan
- Listeners: `RouteMatched`, `RequestHandled`, `QueryExecuted`, `Cache*`, `MessageLogged`, `JobProcessing/Processed/Failed/Queueing`, Eloquent `retrieved`, `ViewComposing` + custom `ViewEngineDecorator`/Blade compiler hook for view spans, `Illuminate\Routing\Events\*`, Livewire hooks (`ComponentHook`s: mount/hydrate/update/call/render/dehydrate/exception) and Filament via its own lifecycle events/ `Filament::serving` + resource/page `mount`, table query `getTableQuery` wrappers (feature-detected, optional).
- Middleware span coverage: wrap pipeline stages by decorating `Router::gatherRouteMiddleware`/pipeline "through" with timing closures (or a `Pipeline` subclass binding), with `Octane`-safe state.
- Overhead budget: target < 1 ms + < 15 us per span (call-site capture is the expensive part: ~20-80 us with limit 25 and IGNORE_ARGS).
- Sampling: decide at execution start (`random_int` or deterministic hash on `trace_id`), propagate via `Context`/`traceparent` to jobs; **keep-rules override at terminate** (error, slow, N+1 detected, forced header `X-Lantern-Debug`). Under load: rate limiter (token bucket in APCu/cache, `max_traces_per_minute`), then degrade ladder: full -> no backtraces -> counters only -> off. Self-timing: record `lantern_overhead_us` on each trace and auto-degrade when avg > budget.
- Persistence: default write at terminate (after response flush); `driver=redis|queue` buffers for high traffic with batch insert (Pulse ingest pattern); try/catch everything; errors go to `Log::channel('single')` once per minute, never throw.
- Console: skip `queue:work`, `schedule:work`, `horizon*`, `octane:*`, `migrate*` by default (Telescope list) but trace each *job* and each scheduled task as its own trace.

### 5.7 UI recommendations
- Trace list (route/job, status, duration, queries, N+1 badge, memory) -> trace detail waterfall (span tree, collapsible, self-time bars, stage bands, memory delta lane, click span -> file:line editor link + source snippet).
- Issues tab (Sentry-like): group by fingerprint, occurrences, total time cost, first/last seen, release, sample trace, suggestion text.
- Heatmap/rollups: per-route p50/p95, query count trend, memory per worker.
- Dev ergonomics: optional toolbar (Debugbar-style) showing counts + link to trace; `X-Lantern-Trace-Id` response header (Clockwork-style) so any HTTP client can link a request to its trace.
- Self-contained: Livewire or Inertia-free vanilla/Alpine bundle shipped prebuilt (no Node at install), gated by Gate `viewLantern` like Telescope/Pulse.

---------------------------------------------------------------------------

## 6. Source URL index
- Telescope: https://github.com/laravel/telescope (`src/Watchers/QueryWatcher.php`, `src/Watchers/FetchesStackTrace.php`, `src/Telescope.php`, `database/migrations/2018_08_08_100000_create_telescope_entries_table.php`, `src/Console/PruneCommand.php`)
- Debugbar: https://github.com/barryvdh/laravel-debugbar (`src/DataCollector/QueryCollector.php`)
- Clockwork: https://github.com/itsgoingd/clockwork (`Clockwork/DataSource/EloquentDataSource.php`, `Concerns/EloquentDetectDuplicateQueries.php`, `Helpers/StackFilter.php`, `Helpers/StackTrace.php`, `Storage/SqlStorage.php`, `Request/Timeline/`)
- Pulse: https://github.com/laravel/pulse (`src/Recorders/SlowQueries.php`, `src/Recorders/Concerns/Sampling.php`, `src/Pulse.php`, `database/migrations/*_create_pulse_tables.php`, `config/pulse.php`)
- Nightwatch: https://github.com/laravel/nightwatch (`src/Sensors/QuerySensor.php`, `src/Location.php`, `src/ExecutionStage.php`, `src/Core.php`, `src/Concerns/CapturesState.php`, `src/Hooks/`, `config/nightwatch.php`)
- query-detector: https://github.com/beyondcode/laravel-query-detector (`src/QueryDetector.php`, `config/config.php`)
- Sentry Laravel: https://github.com/getsentry/sentry-laravel (`src/Sentry/Laravel/Tracing/EventHandler.php`, `BacktraceHelper.php`, `config/sentry.php`); Sentry docs: https://docs.sentry.io/product/issues/issue-details/performance-issues/n-one-queries/ ; https://github.com/getsentry/sentry/discussions/37083 ; https://github.com/getsentry/sentry/discussions/34968
- Ray: https://github.com/spatie/laravel-ray (`src/Watchers/DuplicateQueryWatcher.php`, `QueryWatcher.php`)
- OTel: https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Instrumentation/Laravel (`src/Watchers/QueryWatcher.php`, `src/Hooks/Illuminate/`)
- SPX: https://github.com/NoiseByNorthwest/php-spx ; comparison: https://andrej.prus.dev/blog/php-profiling-production-blackfire-tideways-spx-compared
- Others: https://packagist.org/packages/stephen-tembo-dev/laravel-n1-detector ; Xdebug https://xdebug.org/docs/profiler ; Blackfire https://docs.blackfire.io/ ; Tideways https://tideways.com/profiler/php-profiler

Confidence notes: Blackfire/Tideways/Xdebug details and Sentry's exact N+1 thresholds are from docs/secondary sources, not read in source (Sentry backend is not in the SDK repos). Nightwatch backend grouping/UI is closed. Debugbar "no duplicate detection" is from a grep of the collector in the cloned version only.
