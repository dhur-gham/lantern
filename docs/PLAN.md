# Lantern: implementation plan (working name, renameable)

Package: trace a request end-to-end, detect N+1 / slow / duplicate queries and memory growth, store traces with file:line call sites, show them in a dev UI.
Targets: Laravel 12 (primary), 11 (supported). Livewire 3 and 4, Filament 4 and 5. PHP 8.2+.
Research: `docs/research/01-laravel-lifecycle.md`, `02-existing-tools.md`, `03-filament-livewire.md`.

## Design decisions (from research)

- **Core model:** a trace is a tree of spans. Span types: `request`, `bootstrap`, `middleware`, `route`, `controller`, `validation`, `query`, `view`, `livewire`, `cache`, `http`, `event`, `job`, `command`.
- **Hooks:**
  - Queries: `DB::listen` (`QueryExecuted`).
  - Request: `RouteMatched`, `RequestHandled`, `Terminating`.
  - Views: composing/creating events.
  - Eloquent: a lazy-loading violation callback that chains to any existing app handler.
  - Livewire: `Livewire::listen` hooks.
- **Gaps we fill ourselves** (the framework has no events for them):
  - failed queries;
  - per-middleware timing, by wrapping the middleware pipeline;
  - controller and validation timing;
  - end of view render.
- **Call site:** one `debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, N)` per query, take the first frame under `base_path('app')` (fall back to the first non-internal vendor frame), and map compiled Blade paths back to the view name.
  - Cost control: capture for every query only in dev/profile mode. Otherwise capture only for queries slower than a threshold or for queries flagged as repeats.
- **Fingerprint:** normalize SQL (collapse `IN (...)` lists and multi-row `VALUES`, strip literals), then `xxh128`.
- **N+1 detection** has two layers:
  1. Eloquent level: lazy-load violations give model, relation and call site, with high precision.
  2. SQL level: the same fingerprint at the same call site at least N times (default 5) in one trace, for N+1s that don't go through relations.
  - Also flagged: exact duplicate queries (same SQL and bindings), slow queries, and missing-index suspects through an optional `EXPLAIN` on flagged queries in dev only.
- **Memory:** `memory_get_usage` at span start and end gives per-span deltas. Peak comes from `memory_get_peak_usage`. Flag spans whose delta exceeds a threshold, and flag unbounded `get()` calls by hydrated model count.
- **Livewire/Filament:** the update URL is not fixed in Livewire 4, so detect with `Livewire::isLivewireRequest()`. Attribute queries to component, phase and method with a stack from the Livewire hooks, plus the first frame under `app/` (for example `app/Filament/...`).
- **Storage:** `traces`, `spans`, `issues`, `fingerprints` tables.
  - Issues are aggregated across requests and ranked by total cost.
  - Two retention tiers with a prune command.
  - Sampling decided once per execution. Always keep slow, error and issue-bearing traces.
- **Octane and queue workers:** reset all per-request state, because the query log and cumulative query time persist across requests.
- **Overhead:** buffer in memory, write after the response is sent (terminable), and have a hard kill-switch config.

## Phases

Each phase has one goal and a verification gate. Agents in a phase never share a file.

### Phase 0: Foundations (done / in progress)
- **Goal:** research, repo, demo app.
- **Agents:** the three research agents, plus the demo app build agent.
- **Gate:** the demo app's tests pass and its README lists the ground-truth bugs.

### Phase 1: Package skeleton and verified assumptions
- **Goal:** an installable package that does nothing yet, plus proof of the ~9 unverified framework claims.
- **Work:**
  - `composer.json` (path repo, orchestra/testbench matrix for 11/12), service provider, config, `lantern:install`.
  - Spike tests for each unverified claim in research section 16.
  - Write results into `docs/research/04-verified.md`.
- **Gate:** the package installs into demo-app via path repo and boots without errors. Spike tests are green or the plan is amended.

### Phase 2: Trace core (in-memory)
- **Goal:** build the span tree for HTTP requests.
- **Work:** `TraceContext`, span recorder with timing and memory, middleware/route/controller/validation wrappers, `RequestHandled`/`Terminating` finalization, Octane reset.
- **Gate:** a trace of `/api/posts/n-plus-one` shows the full ordered tree, in unit tests and via a dump command.

### Phase 3: Query capture and call sites
- **Goal:** every query becomes a span with file:line.
- **Work:** `DB::listen`, backtrace filter, Blade-to-view mapping, fingerprinting, failed-query capture.
- **Gate:** the n-plus-one endpoint's queries point at the real lines in the demo app, including the accessor and API Resource cases.

### Phase 4: Detectors
- **Goal:** the issue engine.
- **Work:** N+1 (both layers), duplicates, slow queries, unindexed-query suspects, memory-hog detector.
- **Gate:** every ground-truth bug in the demo README is detected, and the `fixed` twins produce zero issues (no false positives).

### Phase 5: Livewire and Filament attribution
- **Goal:** `/livewire-{hash}/update` requests and Filament pages are traced by component and method.
- **Work:** Livewire hook adapter that supports v3 and v4, plus Filament-aware labelling.
- **Gate:** the Filament PostResource N+1 is attributed to the right resource and column.

### Phase 6: Storage, sampling, retention
- **Goal:** persist traces safely under load.
- **Work:** migrations, deferred writes, sampling that always keeps slow, error and issue-bearing traces, aggregation, `lantern:prune`.
- **Gate:** a load run (wrk or k6 against the seeded 20k posts, 200k comments) shows overhead within budget (target: under 5% p50 at the default sampling rate) with no memory leak across 1,000 requests.

### Phase 7: Developer UI and CLI
- **Goal:** the "debug it" experience.
- **Work:** a trace list and a waterfall timeline with each span's file:line, an issues page ranked by total cost, a CLI (`lantern:issues`, `lantern:trace {id}`), and auth gating like Telescope.
- **Gate:** a manual walkthrough of the demo bugs in the UI.

### Phase 8: Real-DB load testing and hardening
- **Goal:** prove it under real conditions, and cover queues, commands and Octane.
- **Work:** MySQL (Docker, or a scratch DB on `vps_dazy`), job and command tracing, an Octane run, a Laravel 11 matrix run, and docs/README.
- **Gate:** the full test matrix is green.

## Sequencing
Phases 1 to 4 are sequential. Within them, agents work in parallel on separate files: for example in Phase 3 one agent writes the query listener while another writes the fingerprinter and Blade mapper. Phase 5 and the storage half of Phase 6 can run in parallel after Phase 4. Phase 7 starts once the schema is fixed in Phase 6.

## Open items
- **Database for load tests:** Docker Desktop is not running, and the local MySQL needs credentials. Options are to start Docker, supply credentials, or use a scratch database on the VPS.
- **Names:** the package name and namespace are placeholders (`lantern`).
