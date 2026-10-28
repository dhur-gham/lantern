# Lantern

Laravel package that traces a request end to end and tells you what is slow and why: N+1 queries, duplicate queries, slow queries, lazy loading, memory growth and missing indexes, each with a `file:line` call site. It also traces Livewire and Filament requests, queue jobs and artisan commands, and ships a dev dashboard plus a JSON CLI that AI coding agents can drive.

Status: `0.1.0-dev`. Working name; package `dhur-gham/lantern`, namespace `Lantern\`.

## What it does

- Records a trace per execution as a tree of spans: request, bootstrap, middleware, route, controller, validation, query, view, livewire, cache, http, event, job, command. Each span has time and memory.
- Resolves the application call site (`file:line`) of every query, mapping compiled Blade back to the view.
- Detects (thresholds in `docs/CONFIG.md`):
  - `n_plus_one`: the same query shape repeated at one call site (default 5+), merged with Eloquent lazy-load violations.
  - `lazy_load`: lazy-loaded relations (needs `Model::preventLazyLoading()`; an existing app handler is still called).
  - `duplicate_query`: identical SQL and bindings repeated in one trace.
  - `slow_query`: queries over `slow_query_ms`.
  - `missing_index`: opt-in `EXPLAIN` on slow queries (`LANTERN_EXPLAIN=true`).
  - `memory_hog`: spans whose memory growth exceeds `memory_delta_mb`.
- Attributes Livewire update requests (and Filament pages, resources and columns) to component, method and phase.
- Aggregates issues across requests, ranks them by total cost, and stores traces with sampling and retention.

## Requirements and compatibility

PHP 8.2+, Laravel 11 or 12. Livewire 3/4 and Filament 4 are optional. The verified matrix (Laravel 11.57 / 12.70, Livewire 3.8 / 4.4, Filament 4.16, SQLite, MySQL 8.4, MariaDB 10.11, PostgreSQL 16) is in `docs/MATRIX.md`. PHP 8.2 itself has not been run; the code was checked for 8.3+ only syntax.

## Install

Until published, use a path repository in the host app's `composer.json`:

```json
"repositories": [
    { "type": "path", "url": "../lantern", "options": { "symlink": true } }
]
```

```
composer require dhur-gham/lantern:@dev --dev
php artisan lantern:install      # publishes config/lantern.php and the migrations
php artisan migrate
php artisan lantern:status
```

Add to `.env` (local only):

```
LANTERN_ENABLED=true
LANTERN_STORAGE_DRIVER=database
```

Lantern is off by default. `LANTERN_KILL_SWITCH=true` turns it off even when enabled. The default `memory` driver keeps traces only in process (enough for tests); the dashboard and the agent CLI need `database`. The migrations create `lantern_traces` and `lantern_issues`. Use `LANTERN_DB_CONNECTION` to store them on another connection.

## Quick start

1. Set the env vars above and migrate.
2. Optional, for lazy-load detection: `Model::preventLazyLoading(! app()->isProduction());` in `AppServiceProvider::boot()`. SQL-level N+1 detection works without it.
3. Hit a page in the app.
4. `php artisan lantern:issues` lists findings; open `/lantern` for the dashboard.

## Dashboard

Registered only while Lantern is enabled (and routes are not cached), at `/lantern` (`LANTERN_PATH`, `LANTERN_DOMAIN`, `lantern.middleware`, default `web`). Pages: trace list, trace waterfall with span `file:line`, issues ranked by total cost, issue detail. JSON endpoints: `/lantern/api/traces`, `/api/traces/{id}`, `/api/issues`, `/api/issues/{key}`. Needs `database` storage.

Access is allowed only in the `local` environment unless you set a gate (in a service provider):

```php
use Lantern\Lantern;

Lantern::auth(fn ($request) => $request->user()?->isAdmin() === true);
```

A failed gate returns 403.

## CLI reference

Agent commands (`issues`, `issue`, `trace`, `diff`, `check`) read the database store and exit 2 when `LANTERN_STORAGE_DRIVER` is not `database`. `--format` is `table`, `json`, `ndjson` or `markdown`; `--pretty` pretty-prints JSON. JSON goes to stdout; payloads carry `schema_version` (1) and `schema` (`lantern.issues`, `lantern.issue`, `lantern.trace.summary`, `lantern.trace.tree`, `lantern.trace.spans`, `lantern.diff`, `lantern.check`). Errors in json format are `{"schema_version":1,"error":{"code","message","hint","exit_code"}}`.

| Command | Purpose |
|---|---|
| `lantern:install [--force]` | Publish config and migrations. |
| `lantern:status` | Enabled/disabled, version, mode, Laravel and Livewire versions. |
| `lantern:issues` | List issues. `--type= --severity= --sort=total_ms\|occurrences\|last_seen --since= --limit=10 --offset=0 --fields= --full --format=table --pretty`. |
| `lantern:issue {key}` | One issue with redacted evidence. `key` may be a unique prefix of 6+ chars. `--with-bindings --fields= --format=table --pretty`. |
| `lantern:trace {id?}` | Stored trace (default latest). `--last --slow --summary --tree --spans --min-ms= --depth=6 --limit=10 --fields= --json --format=table --pretty`. Default view is the summary; `--json` is the legacy full dump. |
| `lantern:diff {before} {after}` | Compare two traces (`last` allowed). Verdict `improved`, `regressed` or `same`; `resolved`, `new`, `unchanged` issues; query and time deltas. Default `--format=json`. |
| `lantern:check` | CI gate. `--max-queries= --max-ms= --fail-on=critical\|warning\|info --no-new-issues --baseline=file --write-baseline=file --route= --since= --traces=50 --limit=20 --format=json --pretty`. Threshold defaults come from `config('lantern.check')`. |
| `lantern:prune` | Delete past retention. `--days= --issues-days= --dry-run`. |
| `lantern:trace:dump [--last]` | Print the latest in-process or `last-trace.json` trace as a tree (set `LANTERN_DEBUG_WRITE_LAST_TRACE=true`). |

`--since` takes `30m`, `24h`, `7d`, `2w` (units s, m, h, d, w) or a date.

Exit codes (agent commands): 0 ok, 1 `lantern:check` failed, 2 usage, config, storage or not-found error, 3 no data. Severities: `critical`, `warning`, `info`. Issue types: `n_plus_one`, `lazy_load`, `duplicate_query`, `slow_query`, `missing_index`, `memory_hog`.

## Use with AI agents

The loop is find, fix, verify: `lantern:issues --format=json` for findings (stable `id`, `type`, `call_site.file`/`line`), `lantern:issue {id} --format=json` for evidence, edit the code, then `lantern:diff {before} {after}` and `lantern:check --route=/x --no-new-issues`. Full guide: `docs/AGENTS_GUIDE.md`.

- Laravel Boost: `resources/boost/guidelines/core.blade.php` and `resources/boost/skills/lantern-debug/SKILL.md` are picked up by `boost:install` / `boost:update`.
- Without Boost, paste the snippet from `docs/AGENTS_GUIDE.md` into the host app's `AGENTS.md` or `CLAUDE.md`.
- MCP: `lantern_*` tools over stdio, see `docs/AGENTS_GUIDE.md` (needs `laravel/mcp`). Without it agents use the artisan commands.

## Configuration summary

Full reference: `docs/CONFIG.md`. Env vars: `LANTERN_ENABLED`, `LANTERN_KILL_SWITCH`, `LANTERN_MODE` (`dev` or `profile`), `LANTERN_CALL_SITE_MODE` (`all` or `lazy`), `LANTERN_SAMPLING_RATE`, `LANTERN_STORAGE_DRIVER` (`memory` or `database`), `LANTERN_DB_CONNECTION`, `LANTERN_EXPLAIN`, `LANTERN_CAPTURE_BINDINGS`, `LANTERN_PATH`, `LANTERN_DOMAIN`, `LANTERN_RETENTION_DAYS`, `LANTERN_ISSUES_RETENTION_DAYS`, `LANTERN_N_PLUS_ONE_MIN_REPEATS`, `LANTERN_DUPLICATE_MIN_REPEATS`, `LANTERN_SLOW_QUERY_MS`, `LANTERN_MEMORY_DELTA_MB`, `LANTERN_EXPLAIN_MIN_MS`.

## Sampling, retention, overhead

- `sampling_rate` (default 1.0) is decided once per execution. When a trace finishes, slow (`keep_slow_ms`, default 1000), 5xx, failed and issue-bearing traces are always kept; clean ones are kept with probability `keep_rate` (default 0.1).
- Retention: traces 7 days, issues 30 days. Run `lantern:prune` daily (schedule it).
- Writes are buffered and happen after the response is sent.
- Measured in-process on MySQL (20k posts, 200k comments, p50, lazy call sites): `/api/posts/fixed` 10.62 ms off vs 10.94 ms on (+3%); with database storage +3 to 4%. A sub-5 ms route adds about 0.5 ms (+12%); a 179 ms N+1 route +9 to 17%. Memory drift over 1,000 requests with database storage: 0.9 MB. Details in `docs/PLAN.md` (phase 6).

## Production safety

- Keep `LANTERN_ENABLED` off in production, or use `LANTERN_MODE=profile` (call sites only for slow or repeated queries) with a low `LANTERN_SAMPLING_RATE`.
- Bindings: `capture_bindings` defaults to on in `dev` mode only. Bindings named password/token/secret are always redacted; long strings are truncated (`binding_max_length`). CLI output redacts SQL literals and bindings; `lantern:issue --with-bindings` shows masked values (emails, long tokens and card-like numbers masked).
- Skipped: `ignore_paths` (dashboard, `up`, debugbar, telescope, horizon), `ignore_tables` (sessions, cache, jobs, migrations, ...), `ignore_sql_patterns`, `ignore_jobs`, and loop commands (`queue:work`, `schedule:work`, ...).
- Gate the dashboard with `Lantern::auth()`.

## Limitations

- Livewire `profile` events fire only when `app.debug` is true.
- `EXPLAIN` runs only when `LANTERN_EXPLAIN=true`.
- Adversarial testing (`docs/STRESS.md`) found detector, fingerprint and storage bugs; all are fixed and covered by the Stress suite.
- Octane and PHP 8.2 are not verified.
- The agent CLI and dashboard require database storage.

## How it works

The service provider hooks `DB::listen` (plus per-connection `beforeExecuting` for failed queries), route and terminating events, a decorated Blade engine, wrapped middleware, controller dispatcher and validator, Livewire and Filament hooks, and queue and command events. Spans go into an in-memory recorder; when the execution ends, detectors run over the finished trace, the sampler decides, and the sink writes after the response. Issue ids fingerprint type, query shape and call site, so they survive line changes. Verified framework facts: `docs/research/04-verified.md`.

## Testing

```
php vendor/bin/phpunit                                              # package suite
php vendor/bin/phpunit --testsuite Stress                           # adversarial suite (docs/STRESS.md)
LANTERN_TEST_DB=pgsql php vendor/bin/phpunit tests/Feature/Matrix   # real DB matrix (docs/MATRIX.md)
```

## License

MIT
