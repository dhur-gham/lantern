# How it works

Lantern records each HTTP request, Livewire update, queue job and artisan command as a **trace**: a tree of timed **spans**. When a trace finishes, the detectors run over it, the sampler decides whether to keep it, and the result is stored.

```
 request / job / command
        |
        v
 +-------------------------------- trace ---------------------------------+
 | bootstrap | middleware (each, nested) -> route -> controller            |
 |                                           |-> validation (FormRequest)  |
 |                                           |-> queries (+ call site)     |
 |                                           |-> view / Blade render       |
 |                                           |-> Livewire / Filament spans |
 |                                           '-> events (lazy loads)      |
 +------------------------------------------------------------------------+
        |
        v   trace finished
 detectors (N+1, lazy load, duplicate, slow, index, memory)
        |
        v
 tail sampling: keep if issues, slow, 5xx or failed; else keep_rate
        |
        v
 storage: memory sink  or  lantern_traces + lantern_issues (database)
        |
        v
 dashboard (/lantern)   lantern:* commands   MCP server
```

## Trace and span model

A trace has an id, a kind (`request`, `job` or `command`), start time, duration, status, and a root span. A span has a type (middleware, route, controller, validation, view, query, event, Livewire, Filament, job, command), a name, start and end times, memory at start and stop, optional metadata, an optional call site, and a parent. Spans nest, so a query made while rendering a view sits under that view, which sits under the controller.

Spans nested deeper than `max_tree_depth` (default 100) are dropped from stored and rendered trees and counted in `children_omitted`, so a pathological recursion cannot produce an unbounded payload.

## What is hooked, per stage

Everything uses public framework extension points: events, the container's `extend`/`resolving` callbacks, and documented listener APIs. No framework class is replaced on disk and nothing is monkey-patched.

| Stage | Mechanism |
|---|---|
| Request start | First `request` binding (or rebinding under Octane and tests) begins the trace as early as a service provider can; time since `LARAVEL_START` is recorded as bootstrap |
| Middleware | Global, group and route middleware are wrapped in a timing decorator through container `extend`, so each middleware gets its own nested span, including its time spent waiting on the rest of the pipeline |
| Route | `RouteMatched` names the trace and records the route |
| Controller / closure | The controller and callable dispatchers are extended with timed versions; the span carries the method's file and line |
| Validation | `ValidatesWhenResolved` resolving and afterResolving hooks time FormRequests, and the validator factory is hooked for validators made with `Validator::make` |
| View | The view engines are wrapped, so each Blade or PHP template render is a span pointing at the template |
| Queries | `Connection::beforeExecuting` is attached to each connection (on `ConnectionEstablished` and to existing connections), and `QueryExecuted` supplies the final SQL, bindings and duration. Transaction begin, commit and rollback events add markers, and logged query exceptions add the error to the matching span |
| Lazy loads | Lantern installs a wrapper on Eloquent's lazy-loading violation callback after the app has booted. The wrapper records an event span, then calls whatever callback the app had installed (chained, results and exceptions pass through). If the app did not enable prevention itself, nothing is thrown, so behavior is unchanged |
| Livewire | The `request`, `mount`, `hydrate`, `render`, `call` and `dehydrate` hooks create spans named after the component and action. Livewire update requests are traced like any other request |
| Filament | `ServingFilament` marks the trace as a panel request, and finished query spans are annotated with the resource, page or table column they came from when it can be determined |
| Jobs | `JobProcessing`, `JobProcessed`, `JobFailed`, `JobExceptionOccurred` and `JobReleasedAfterException` open and close a trace per job (`trace_jobs`, `ignore_jobs`). A sync job inside a request is a span in that request's trace |
| Commands | `CommandStarting` and `CommandFinished` trace artisan commands (`trace_commands`). Long-running loop wrappers such as `queue:work`, `schedule:work`, `horizon` and `serve`, and Lantern's own and migration commands, are skipped |
| Octane | State is reset on Octane request, task and tick events, so one worker serves many traces without leaking spans between them. Head sampling is decided per request there |

## Call sites and fingerprints

**Call site.** When a query runs, Lantern walks the PHP backtrace (up to `backtrace_limit` frames) and picks the first frame in your code: files under `app_paths` (by default `app/`, `resources/views` and `routes/`). Frames inside Lantern and, when an application frame exists, vendor frames are passed over. If a query is made entirely from vendor code, the nearest vendor frame is kept and marked as such. The result is the file and line, the enclosing function and class, the Blade view name when the frame is a compiled view, and a flag when a `chunk`/`lazy`/`each` frame is on the stack. With `call_site_mode` set to `lazy`, the backtrace is only taken for slow queries and for queries whose shape has repeated in the trace; earlier members of a repeated shape are backfilled when the repeat is noticed. In `dev` mode every query is resolved.

**Fingerprint.** SQL is normalized by a single-pass scanner: whitespace and comments collapse, keywords and unquoted identifiers are lowercased, quoted identifiers are preserved, and string and number literals, `IN` lists and multi-row `VALUES` collapse to placeholders. A hash (xxh128, or MD5 where unavailable) of the result is the fingerprint. Queries that differ only in their parameters share a fingerprint; the N+1 and slow-query detectors group by fingerprint plus call site, and the duplicate detector additionally requires identical bindings.

## Sampling

- **Head sampling** (`sampling_rate`) is decided once per execution, before recording. In a normal web request or a single-run process, an unsampled execution registers no hooks at all. In long-lived workers (Octane, queue workers) the hooks stay registered and the decision is made at the start of each trace.
- **Tail sampling** runs when a trace finishes: it is always kept if it has issues, took at least `keep_slow_ms` (1000), returned a 5xx status or failed. Any other trace is kept with probability `keep_rate` (1 when `APP_ENV=local`, else 0.1).

With the defaults (`sampling_rate` 1.0) every request is observed and only uninteresting fast, clean traces are mostly dropped before they reach storage.

## Storage and retention

`storage.driver` chooses where finished traces go.

- `memory` (default): the last `memory_sink_limit` traces are held in the process. Useful for tests and ad-hoc inspection; the dashboard and agent commands need the database driver.
- `database`: two tables on `storage.connection` (the default connection when unset).

| Table | Contents |
|---|---|
| `lantern_traces` | One row per kept trace: id, start time, duration, status, method, path, route, query count and total query time, issue count, peak memory, and the full span tree as a payload (gzip-compressed when `storage.compress` is on). Indexed on start time, duration and issue count |
| `lantern_issues` | One row per distinct issue key: type, title, severity, occurrences, total count, total and max time, call site, suggestion, first seen, last seen, last trace id. The same problem seen again updates its row instead of adding one |

Traces are buffered and written at the end of the request (`storage.batch_size`, default 1 flushes each trace; the buffer is always flushed at termination). Run `php artisan lantern:prune` on a schedule to apply retention: traces older than `retention.days` (7) and issues not seen for `retention.issues_days` (30) are deleted in chunks.

```php
Schedule::command('lantern:prune')->daily();
```

## Overhead and safety

- **Never breaks a request.** Every listener, hook and decorator runs inside a guard that swallows exceptions. A detector that throws is skipped. A storage failure (missing table, database down, oversized payload) is logged once and dropped; the request continues.
- **Disabled means nothing runs.** With `enabled` false, or `kill_switch` true, the service provider registers no listeners, no container extensions and no routes. There is no per-request cost beyond loading the provider and merging config.
- **Production-safe defaults.** `enabled` is false until you set it. The dashboard and JSON API are registered only while Lantern is enabled and are gated by `Lantern::auth()`, which by default allows the `local` environment only.
- **Behavior preserved.** Wrapped middleware, dispatchers and view engines forward calls and exceptions unchanged. The lazy-load hook chains to any callback you registered and does not introduce exceptions you did not already have.
- **Bounded memory.** Spans past `max_tree_depth` are dropped, `thresholds.max_issues_per_trace` caps issues, binding values are truncated at `binding_max_length`, and bindings named password, token or secret are always redacted. Per-trace state is released when the trace ends; a 500-request run showed no growth in Lantern's own state.
- **Cost control.** Call-site resolution (a backtrace per query) is the main per-query cost; `call_site_mode=lazy` limits it to slow and repeating queries. EXPLAIN runs only when `explain` is on, only for the slowest few shapes in a trace, and only for SELECT statements. Lower `sampling_rate` or `keep_rate` for busy environments.
- **Tracing itself is not traced.** The dashboard path (`path`) and Lantern's own console commands are excluded.
