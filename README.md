# Lantern

**See why your Laravel request is slow, down to the file and line.** Lantern traces every request, job and artisan command as a timeline, finds N+1 queries, duplicate and slow queries, missing indexes and memory hogs, and tells you where to fix them. Humans get a dashboard; AI coding agents get a JSON CLI and an MCP server.

---

## The problem

N+1 queries, slow queries and memory growth are invisible while you develop. Ten rows in your local database run fast. Ten thousand rows in production turn one innocent `foreach` into 1,500 queries, a 2-second response and an out-of-memory worker.

Existing tools show you a list of queries. They rarely show you the story: which middleware, controller, Blade line or Livewire action issued them, which ones are the same query repeated, and what to change.

## What Lantern does

Lantern records each execution as a tree of timed spans, resolves the application `file:line` behind every query, and runs detectors over the finished trace.

A request, as a trace (illustrative):

```
GET /posts                                          312 ms   38.1 MB
├─ middleware: Authenticate                           1 ms
├─ controller: PostController@index                  14 ms
│   └─ query  select * from posts limit 50            2 ms   app/Http/Controllers/PostController.php:18
├─ view: posts.index                                294 ms
│   └─ query  select * from users where id = ?  x50  96 ms   resources/views/posts/index.blade.php:7
└─ response                                           3 ms
```

and what it reports about it (illustrative):

```
[critical] n_plus_one   N+1: Post::author lazy-loaded x50
           at resources/views/posts/index.blade.php:7        total 96 ms
           shape: select * from users where id = ?
           fix:   eager load the relation, e.g. Post::with('author')->get()
```

Fix it, hit the page again, and Lantern proves it:

```
$ php artisan lantern:diff <before> last
verdict: improved   queries: 152 -> 4 (-148)   query time: 213 ms -> 10 ms
```

## What you can do with it

- **Hunt N+1s while you build.** Click through your app locally, open `/lantern/issues`, and see every repeated query ranked by total cost with its `file:line` and the fix.
- **Audit an admin panel.** Filament tables and Livewire actions are attributed to the resource, component and column, so hidden per-row queries in `getStateUsing` closures and accessors show up.
- **Gate CI on query health.** `lantern:check --max-queries=40 --no-new-issues` fails a build when a change adds queries or a new N+1, using query counts, not flaky timings.
- **Watch production safely.** Sample a small share of traffic; slow, failing and issue-bearing requests are always kept, and issues are ranked across requests so the costliest rise to the top.
- **Find the slow job.** Queue jobs and artisan commands get their own traces, with the queries and memory they used.
- **Let an AI agent do the fixing.** It reads findings as JSON, opens the exact line, applies the fix, and proves it with `lantern:diff`. No guessing, no screenshots.

## Features

**Full request timeline**
- Spans for request, bootstrap, middleware, route, controller, validation, queries, views, Livewire, cache, HTTP client, events, jobs and commands, each with time and memory.
- Works with plain controllers, route closures, Blade, Livewire 3 and 4, and Filament.

**Queries with exact call sites**
- The application `file:line` that issued each query, including lines inside Blade templates (compiled views are mapped back to the source view).
- Queries are fingerprinted (literals and IN lists collapsed), so the same shape is grouped even when the values differ.
- Optional bound values, redacted by default outside dev mode.

**Detectors**
| Issue type | What it catches |
|---|---|
| `n_plus_one` | Same query shape repeated at one call site (default 5 or more), merged with Eloquent lazy-load violations |
| `lazy_load` | Lazily loaded relations, with model and relation names. Recorded without throwing in your app; if you set your own `preventLazyLoading` handler, it is still called |
| `duplicate_query` | Identical SQL and bindings repeated in one trace |
| `slow_query` | Queries over `slow_query_ms` (default 100) |
| `missing_index` | Opt-in `EXPLAIN` on slow queries (`LANTERN_EXPLAIN=true`) |
| `memory_hog` | Spans whose memory growth exceeds `memory_delta_mb` (default 32) |

**Livewire and Filament attribution**
- Livewire update requests are attributed to component, method and phase; Filament pages, resources and columns are labelled, so "the N+1 is in `PostResource`, column `tags`" is one glance.

**Jobs and artisan commands**
- Queue jobs and artisan commands are traced as their own traces (`LANTERN_TRACE_JOBS`, `LANTERN_TRACE_COMMANDS`). Long-running wrappers such as `queue:work` and `schedule:work` are skipped.

**Dashboard**
- Trace list, a waterfall per trace, and an issues page ranked by total cost across requests. See below.

**Sampling, retention, low overhead**
- Head sampling plus tail sampling: slow, 5xx, failed and issue-bearing traces are always kept; clean ones are kept at `keep_rate` (default 10%).
- Traces buffer in memory and are written at the end of the request. Retention is configurable and pruned with `lantern:prune`.
- Off by default; a hard kill switch is available.

**Redaction**
- Names containing `password`, `token` or `secret` are always redacted; long string bindings are truncated. The agent CLI shows bindings as masked or type placeholders unless you pass `--with-bindings`.

## How it compares

| | Lantern | Telescope | Debugbar | Clockwork | Pulse | Sentry / Nightwatch |
|---|---|---|---|---|---|---|
| Per-request timeline | Yes, nested spans | Flat entries grouped by batch | Yes (toolbar) | Yes (flat timeline) | No | Yes |
| Query call site | `file:line`, incl. Blade lines | Caller frame | Backtrace | Backtrace, Blade view names | Slow queries only | Slow queries only (Sentry); Nightwatch yes |
| Built-in N+1 detection | Yes | No | No (add-on package) | Lazy-load duplicates | No | N+1 detected server-side (Sentry); not in the Nightwatch agent |
| Duplicate, slow, missing-index, memory detectors | Yes | Slow flag only | Memory per query, on-demand EXPLAIN | Slow queries | Slow queries | Partly, hosted |
| Cross-request issue ranking | Yes | No | No | No | Aggregates only, no per-request detail | Yes, hosted |
| Self-hosted | Yes | Yes | Yes | Yes | Yes | Sentry or Nightwatch is a hosted service |
| Agent-friendly CLI and MCP | Yes | No | No | No | No | No |

Honest take: Telescope, Debugbar and Clockwork are excellent request inspectors and Pulse is a good production health dashboard; use them where they fit. Sentry and Nightwatch are production monitoring services with alerting and teams. Lantern is the one built around finding and verifying query and memory problems in code, locally or with sampling in production.

## Quick start

```bash
# Not on Packagist yet: add the repository first
composer config repositories.lantern vcs git@github.com:dhur-gham/lantern.git
composer require dhur-gham/lantern        # add --dev if you only run it locally

php artisan lantern:install               # publishes config/lantern.php and the migrations
php artisan migrate
```

Then in `.env`:

```dotenv
LANTERN_ENABLED=true
LANTERN_STORAGE_DRIVER=database   # needed for the CLI, agents, and history; default is in-memory
```

`lantern:install` only publishes files (`--force` overwrites) and does not enable Lantern. Requests you make now are traced. Open `/lantern`.

## Dashboard

Visit `/lantern` (change with `LANTERN_PATH` or `LANTERN_DOMAIN`). It is registered only while Lantern is enabled and, by default, only allows the `local` environment. Gate it yourself, for example in a service provider:

```php
use Lantern\Lantern;

Lantern::auth(fn ($request) => $request->user()?->isAdmin());
```

Pages: traces, a trace waterfall, issues ranked by total cost, and a single-issue view. A JSON API sits under `/lantern/api/traces` and `/lantern/api/issues`.

## CLI

All commands use `php artisan` and read stored traces (database driver).

```bash
php artisan lantern:issues --format=json --limit=5   # ranked findings
php artisan lantern:issue <id> --format=json         # one finding with redacted evidence
php artisan lantern:trace --last --summary           # totals, issues, slowest query groups
php artisan lantern:trace --last --tree              # collapsed span tree (also --spans, --slow)
php artisan lantern:diff <before> last               # resolved / new issues, query deltas
php artisan lantern:check --route=/posts             # pass/fail gate
php artisan lantern:status                           # state and detected framework versions
php artisan lantern:prune --dry-run                  # retention cleanup (--days, --issues-days)
```

Real output from a run against a seeded app:

```
$ php artisan lantern:issue 042bce --format=json
{"issue":{"id":"042bce3e...","type":"n_plus_one","severity":"critical",
 "title":"N+1: Post::tags lazy-loaded x50 at app/Http/Resources/PostResource.php:16",
 "call_site":{"file":"app/Http/Resources/PostResource.php","line":16}, ...},
 "next":"Fix call_site, re-run the request, then: php artisan lantern:diff db034b8d-... last"}

$ php artisan lantern:diff db034b8d-... last
{"verdict":"improved","queries":{"before":152,"after":4,"delta":-148},
 "query_ms":{"before":213.16,"after":10.42,"delta":-202.74},"resolved":[...],"new":[]}
```

Output formats are `table`, `json`, `ndjson` and `markdown` (`--pretty` for JSON).

## Built for AI agents

Every agent command emits stable JSON with `next` (the suggested follow-up) and `hint` (why a result is empty or truncated). A whole find, inspect, fix, diff, check loop costs under 2,500 tokens of tool output in measured runs.

Exit codes of `lantern:check`: `0` ok, `1` findings over threshold, `2` usage or config error, `3` no data.

```bash
php artisan lantern:issues --format=json --limit=5
php artisan lantern:issue <id> --format=json                    # open call_site.file at call_site.line, fix it
php artisan lantern:diff <before> last                          # expect the issue under "resolved"
php artisan lantern:check --route=/posts --no-new-issues        # exit 0 = done
```

Useful gate flags: `--max-queries`, `--max-ms`, `--fail-on=critical|warning|info`, `--no-new-issues`, `--baseline=file.json`, `--write-baseline=file.json`, `--since=24h`. Defaults live under `lantern.check` in the config.

**MCP server.** If `laravel/mcp` is installed, Lantern registers a local server named `lantern` (run `php artisan mcp:start lantern`) with the tools `lantern_issues`, `lantern_issue`, `lantern_trace_summary`, `lantern_diff` and `lantern_check`.

**Laravel Boost.** Lantern ships a Boost guideline and a `lantern-debug` skill; `php artisan boost:install` or `boost:update` picks them up. Details in [docs/AGENTS_GUIDE.md](docs/AGENTS_GUIDE.md).

## Production safety

- Disabled unless `LANTERN_ENABLED=true`; `LANTERN_KILL_SWITCH=true` stops all recording instantly.
- `LANTERN_SAMPLING_RATE` and tail sampling bound cost; `LANTERN_MODE=profile` with `call_site_mode=lazy` resolves call sites only for repeated or slow queries.
- Measured overhead (median, MySQL, 20k posts and 200k comments, memory sink): about +3% on an API route, +9% on an N+1 route, and about 0.5 ms on a sub-5 ms page. With database storage: +3 to +4% on the API route, higher on N+1 routes (about +16%). The under-5% target was not met on every route, so measure on yours.
- Recording failures are isolated from the request; 1,000 mixed requests drifted memory by under 1 MB.
- Gate the dashboard with `Lantern::auth()`; unauthorised users get 403.
- Prune on a schedule: `$schedule->command('lantern:prune')->daily();`.

## Compatibility

PHP 8.2+, Laravel 11 and 12, Livewire 3 and 4, Filament 4. Tested on SQLite, MySQL 8.4, MariaDB 10.11 and PostgreSQL 16. PHP 8.2 itself has not been run in the test matrix. See [docs/COMPATIBILITY.md](docs/COMPATIBILITY.md).

## Documentation

- [Configuration](docs/CONFIG.md)
- [Compatibility](docs/COMPATIBILITY.md)
- [How it works](docs/HOW_IT_WORKS.md)
- [Detectors](docs/DETECTORS.md)
- [CLI reference](docs/CLI.md)
- [Agents guide](docs/AGENTS_GUIDE.md)

## Limitations

- Version `0.1.0-dev`; the API may change.
- The CLI, agent tools and issue history need `LANTERN_STORAGE_DRIVER=database`; the default memory driver keeps only the last 20 traces in-process.
- `lazy_load` does not see single-row lazy loads or Laravel's automatic eager loading; those are still caught as `n_plus_one` when they repeat.
- `missing_index` relies on `EXPLAIN` and is opt-in.
- Timing is noisy; gate CI on query counts and issue presence rather than milliseconds.
- Overhead is small but not zero; it is highest on very fast routes and query-heavy requests.

## License

MIT
