# Lantern

**Laravel performance diagnostics for developers and AI coding agents.**

Lantern finds performance problems in your Laravel app, traces them to the code responsible, shows you the evidence, and lets you verify whether a fix worked.

**Compatibility:** see the [tested compatibility matrix](docs/COMPATIBILITY.md) for the exact Laravel, PHP, Livewire, Filament and database versions that were run and which were only reviewed.

### What problem does it solve?

N+1 queries, repeated queries and memory-hungry loops are invisible while you develop. Ten rows run fast. Ten thousand rows in production turn one innocent `foreach` into hundreds of queries and a slow page. A query log tells you *what* ran; it does not tell you *which line of your code* caused it, whether it is a pattern or a one-off, or whether your fix actually helped.

### Why use it alongside your current tools?

Because it connects the whole loop in one place: **detect an issue, inspect it down to the source line, fix the code, compare before and after, and gate the result with a check.** The same evidence is available as a dashboard for you and as structured JSON (CLI and MCP) for an AI coding agent.

### See a real result

```bash
composer require dhur-gham/lantern
php artisan lantern:install && php artisan migrate
# .env:  LANTERN_ENABLED=true   LANTERN_STORAGE_DRIVER=database
```

Load a page that lists records, then:

```bash
php artisan lantern:issues --format=json
```

The top finding (trimmed):

```json
{"type":"n_plus_one","severity":"critical",
 "title":"N+1: Post::tags lazy-loaded x50 at app/Http/Resources/PostResource.php:16", ...}
```

Fix it, reload the page, and compare:

```
$ php artisan lantern:diff <before> last
{"verdict":"improved","queries":{"before":152,"after":4,"delta":-148},
 "query_ms":{"before":213.16,"after":10.42,"delta":-202.74},"resolved":[3 n_plus_one issues],"new":[]}
```

*(Real output from a seeded Laravel 12 application, 50 posts per page. Details [below](#a-real-before-and-after).)*

---

## The problem

Query logs and request profilers show you what happened. Fixing a performance problem needs more than that:

- **Context.** Which controller, Blade line, Livewire action or Filament column issued this query?
- **Pattern.** Is it the same query shape repeated 50 times, or 50 different queries?
- **Priority.** Which problem across all your requests costs the most?
- **Proof.** After you changed the code, did the behaviour actually improve, and will it stay that way?

Lantern is built around those four questions.

## Why Lantern is different

Lantern does not claim to be the only tool that can spot an N+1 query. What it provides is the complete workflow around it, in one package.

| | The win |
|---|---|
| **Find the code behind the query problem** | Queries are resolved to the application `file:line` that issued them where source attribution succeeds, including Blade templates, and tied to their execution context (request, middleware, controller, Livewire component, Filament resource and column, job, command). Less time guessing which part of the app causes the database work. |
| **Turn raw queries into actionable issues** | Six detectors group queries into issues: N+1, lazy loading, duplicate queries, slow queries, possible missing indexes and memory-heavy execution. You start from a suspected problem with its evidence and a suggested direction, not from a long query log. |
| **Verify the fix instead of assuming it worked** | `lantern:diff` compares two traces (queries, query time, issues resolved, issues introduced). `lantern:issue` shows the evidence behind a finding. |
| **Make performance debugging usable by AI coding agents** | Stable issue IDs, versioned JSON output, trace summaries, diffs, checks with exit codes, an MCP server and a Laravel Boost guideline and skill. An agent can inspect evidence, change code, and check the result without guessing. Lantern does not rewrite your code; the agent or you does. |
| **Add performance checks to your workflow** | `lantern:check` exits `0` (ok), `1` (findings over threshold), `2` (usage or config error) or `3` (no data), so it can run in scripts and CI. Supported gates are listed [below](#ci-and-scripts). |

### The Lantern workflow

1. **Detect** a potential performance issue.
2. **Inspect** the issue, its trace and the source location.
3. **Fix** the underlying code.
4. **Compare** the result against the previous behaviour.
5. **Check** the evidence to help catch the problem if it returns.

The goal: spend less time searching through logs and more time fixing the code responsible.

## A real before and after

Setup: a seeded Laravel 12 application (20,000 posts, 200,000 comments, MySQL 8.4), an API endpoint that returns 50 posts through a JSON resource. Lantern recorded the request with the database storage driver.

**1. Detect.** `lantern:issues --format=json` lists three critical issues from one request, each with its call site (titles shortened here, one line each):

```
critical  n_plus_one  N+1: Post::author lazy-loaded x50   app/Http/Resources/PostResource.php:14
critical  n_plus_one  N+1: comment count query x50        app/Http/Resources/PostResource.php:15
critical  n_plus_one  N+1: Post::tags lazy-loaded x50     app/Http/Resources/PostResource.php:16
```

**2. Inspect.** `lantern:issue <id> --format=json` returns the evidence (trimmed):

```json
{"issue":{"type":"n_plus_one","severity":"critical",
  "title":"N+1: Post::tags lazy-loaded x50 at app/Http/Resources/PostResource.php:16",
  "call_site":{"file":"app/Http/Resources/PostResource.php","line":16},
  "evidence":{"trace_id":"db034b8d-...","sql_shape":"select `tags`.*, ... where `post_tag`.`post_id` = ?","avg_ms":1.35}},
 "next":"Fix call_site, re-run the request, then: php artisan lantern:diff db034b8d-... last"}
```

**3. Fix.** The resource read `$this->author`, `$this->comments()->count()` and `$this->tags` once per post. The controller now loads them up front:

```php
// before
Post::query()->paginate(50);

// after
Post::query()->with(['author', 'tags'])->withCount('comments')->paginate(50);
```

**4. Compare.** Request the endpoint again, then `lantern:diff <before> last`:

```json
{"verdict":"improved",
 "before":{"path":"/api/posts/n-plus-one","issue_count":3},
 "after":{"path":"/api/posts/fixed","issue_count":0},
 "queries":{"before":152,"after":4,"delta":-148},
 "query_ms":{"before":213.16,"after":10.42,"delta":-202.74},
 "resolved":[3 n_plus_one issues],"new":[]}
```

**5. Check.**

```
$ php artisan lantern:check --route=/api/posts/fixed --no-new-issues      # exit 0
$ php artisan lantern:check --route=/api/posts/n-plus-one --no-new-issues # exit 1, lists the 3 violations
```

Query counts here went from 152 to 4. Timings are from one machine and one run; use counts and issue presence, not milliseconds, as your regression signal.

## What you can do with it

- **Hunt N+1s while you build.** Click through your app locally, open the dashboard's issues page, and see repeated queries ranked by total cost with their `file:line`.
- **Audit an admin panel.** Filament tables and Livewire actions are attributed to the resource, component and column, so per-row queries inside `getStateUsing` closures and accessors show up.
- **Gate CI on query health.** Fail a build when a change adds queries or a new N+1.
- **Watch a sample of production traffic.** Slow, failing and issue-bearing requests are always kept; issues are ranked across requests.
- **Find the slow job.** Queue jobs and artisan commands get their own traces.
- **Let an AI agent investigate and verify.** It reads findings as JSON, opens the line, proposes a change, and checks the result with `lantern:diff`.

## Features

### What Lantern records

**Tracing.** Lantern can trace requests, queue jobs and Artisan commands, subject to your configuration and sampling. Each traced execution is a tree of timed spans with memory: bootstrap, middleware, route, controller, validation, queries, views, Livewire phases, jobs and commands. It handles controllers, route closures, Blade, Livewire 3 and 4 and Filament.

**Call sites.** Lantern resolves queries to application source locations where source attribution succeeds. Queries issued only by framework code can have no application line (they inherit the nearest traced span's location, or have none). Blade call sites are mapped back from the compiled template to the source view; the line can be approximate in complex templates. Queries are fingerprinted (literals, bindings and `IN` list length removed), so the same shape is grouped even when values differ.

**Livewire and Filament.** Update requests are attributed to component, method and phase; Filament pages, resources and columns are labelled in the trace and on issues.

**Storage and retention.** In-memory (default, last 20 traces) or your database (`lantern_traces`, `lantern_issues`), optionally gzip-compressed. Head sampling (`sampling_rate`) decides per request, job or command whether to record at all. Tail sampling applies when writing to the database: slow (`keep_slow_ms`), 5xx, failed and issue-bearing traces are always kept, and clean ones are kept at `keep_rate` (default 10%). The in-memory driver keeps everything that was recorded, up to its last 20. `lantern:prune` deletes traces after `retention.days` (default 7) and issues after `retention.issues_days` (default 30); it is not scheduled for you, so add it to your scheduler.

### What it detects

| Detector | Catches | Evidence | Needs | Known limits |
|---|---|---|---|---|
| `n_plus_one` | Same query shape repeated from one call site (default 5 or more) | Call site, count, total and average ms, SQL shape, suggested fix | Nothing | `chunk()`, `lazy()` and `each()` paging are excluded on purpose; a deliberate repeated lookup in a loop is still reported |
| `lazy_load` | A relation lazily loaded on many models in a loop | Model, relation, call site | Eloquent relations. Lantern installs its own recording hook after the app boots (automatic; it does not throw unless your app already enabled prevention). Your own `handleLazyLoadingViolationUsing` handler is chained and still runs; one registered after Lantern boots replaces Lantern's hook | Eloquent only reports models loaded in batches of two or more, so single-model lookups are not flagged; Laravel's automatic eager loading produces no violations |
| `duplicate_query` | Identical SQL and bindings run more than once in a trace | Call sites, count | Bindings captured (`capture_bindings`, on by default in dev mode) | Without bindings it cannot tell duplicates from different parameters, so it skips them |
| `slow_query` | Queries over the threshold (default 100 ms) | Time, count, max, call site | Nothing | Time is what the database connection reports |
| `missing_index` | A SELECT that `EXPLAIN` plans as a full scan on a filtered column | Plan summary, suggested columns | `LANTERN_EXPLAIN=true`; MySQL, MariaDB, PostgreSQL or SQLite | Opt-in; SELECT only; the suggested index is a starting point and depends on your data |
| `memory_hog` | A span that grows memory sharply (default 32 MB), or an unbounded SELECT followed by growth | Span, memory delta, call site | Nothing | The first request after a cold start can show autoload memory at very low thresholds |

Details, thresholds, severities and fixes for each are in [docs/DETECTORS.md](docs/DETECTORS.md).

### What it helps you verify

**Dashboard.** A trace list, a waterfall per trace, an issues page ranked by total cost across requests, and an issue detail page. See [Dashboard](#dashboard).

**CLI and MCP.** `lantern:issues`, `issue`, `trace`, `diff` (before/after) and `check` (a gate with exit codes), plus `status`, `prune` and `install`. Five read-only MCP tools are available if you install the optional `laravel/mcp`. See [docs/CLI.md](docs/CLI.md).

**Controls.** Sampling rate, tail sampling, ignored paths, tables, SQL patterns, jobs and commands, binding capture and redaction, thresholds, a kill switch. Everything is in [docs/CONFIG.md](docs/CONFIG.md).

## How it compares

These tools approach performance from different angles. This table describes focus, not exclusive capabilities or gaps: each product does more than one line can say, so read its official documentation (linked in the first column) before choosing.

| Tool | Primary strength | Lantern's intended distinction |
|---|---|---|
| [Laravel Telescope](https://laravel.com/docs/telescope) | Inspect requests, queries, jobs and other application activity | An explicit issue, trace, diff and check workflow around query and memory problems |
| [Laravel Debugbar](https://github.com/barryvdh/laravel-debugbar) | Convenient request-level debugging and profiling | Structured issue analysis and CLI/agent-oriented verification |
| [Clockwork](https://underground.works/clockwork) | Request profiling and timeline in the browser | Cross-request issue ranking and verification commands |
| [Laravel Pulse](https://laravel.com/docs/pulse) | Application performance and usage metrics | Investigating individual code-level problems and checking changes |
| [Sentry](https://docs.sentry.io/platforms/php/guides/laravel/) / [Nightwatch](https://nightwatch.laravel.com/docs) | Error monitoring, tracing and production observability as hosted services | Laravel-specific detectors and a workflow built around code fixes and verification, self-hosted in your database |

They work together: use a profiler or monitor for what it is good at, and Lantern for the detect, inspect, fix, compare loop.

## Quick start

```bash
# 1. Install
composer require dhur-gham/lantern          # add --dev if you only run it locally

# 2. Publish config and migrations, create the tables
php artisan lantern:install
php artisan migrate
```

```dotenv
# 3. Enable it (off by default)
LANTERN_ENABLED=true
LANTERN_STORAGE_DRIVER=database    # needed for the dashboard, the CLI and issue history
```

4. **Reproduce an issue.** Open a page or endpoint that loops over records and touches relations.
5. **Run your first diagnostic.**

```bash
php artisan lantern:status                  # is it on, which framework versions it detected
php artisan lantern:issues --format=json    # ranked findings with call sites
```

or open `/lantern` in the browser.

## Dashboard

Visit `/lantern` (change with `LANTERN_PATH` or `LANTERN_DOMAIN`). It is registered only while Lantern is enabled and, by default, only allows the `local` environment. Gate it yourself, for example in a service provider:

```php
use Lantern\Lantern;

Lantern::auth(fn ($request) => $request->user()?->isAdmin());
```

Pages: the trace list (filters for status, slow and issues), a waterfall per trace with each span's call site, SQL and memory, an issues page ranked by total cost, and a single-issue view with its recent traces. A JSON API sits under `/lantern/api/traces` and `/lantern/api/issues`.

## CLI

The analysis commands (`issues`, `issue`, `trace`, `diff`, `check`) read stored traces (database storage driver) and accept `--format=table|json|ndjson|markdown`; `status` and `prune` have no `--format`. `issues`, `issue` and `trace` default to a table, while `diff` and `check` print JSON by default. Agents should pass `--format=json`: the table is two to five times larger.

```bash
php artisan lantern:issues --format=json --limit=5   # ranked findings
php artisan lantern:issue <id> --format=json         # one finding with redacted evidence
php artisan lantern:trace --last --summary           # totals, issues, slowest query groups (views: --summary, --tree, --spans)
php artisan lantern:trace --slow --summary           # the slowest of the 100 most recent traces (ignores --last)
php artisan lantern:diff <before> last               # resolved and new issues, query deltas
php artisan lantern:check --route=/posts             # pass/fail gate
php artisan lantern:status                           # state and detected framework versions
php artisan lantern:prune --dry-run                  # retention cleanup (--days, --issues-days)
```

Notes: `last` is the most recent trace of any kind, so a queue job or command that ran after your page load can be "last" (common helpers such as `migrate`, `tinker` and `lantern:*` are ignored by default; see `ignore_commands`). Without a view flag, `lantern:trace` prints a tree limited to depth 6 (`--depth` changes it).

Full reference: [docs/CLI.md](docs/CLI.md).

### CI and scripts

`lantern:check` exits `0` ok, `1` findings over threshold, `2` usage or config error, `3` no data. Supported gates:

```bash
php artisan lantern:check --route=/posts --max-queries=40 --no-new-issues
```

Flags: `--max-queries`, `--max-ms`, `--fail-on=critical|warning|info`, `--no-new-issues`, `--baseline=file.json`, `--write-baseline=file.json`, `--since=24h`, `--route`. Defaults live under `lantern.check` in the config. Scope matters: without `--route`, `--since` or a baseline, the check looks at every stored trace, so old findings keep failing it. `--no-new-issues` only means "nothing new" relative to a baseline (`--baseline=file.json`); without one, any issue counts as new. `--write-baseline=file.json` records the current findings as that baseline and exits `0` even if it lists violations. The check reads traces that already exist, so exercise the code first (feature tests, a smoke script, a staging run) with Lantern enabled and database storage. Use query counts and issue presence rather than milliseconds, which are noisy.

## Built for AI agents

Every agent command emits versioned JSON with `next` (the suggested follow-up command) and `hint` (why a result is empty, truncated or failed). Issue IDs are fingerprints that do not change when line numbers shift, so an agent can refer to the same finding across edits.

**A real run.** A fresh agent given only the agents guide completed find, inspect, fix, diff and check against a seeded application; the tool output for the whole loop was under 2,500 tokens. Default sizes: `lantern:issues --format=json` for 7 issues is about 840 tokens, `lantern:issue` about 290, `lantern:diff` about 345, a passing `lantern:check` about 65.

```
lantern:issues --format=json     -> pick the top finding, note call_site and next
lantern:issue <id> --format=json -> read the evidence and the suggested direction
(edit call_site.file at call_site.line, re-run the request)
lantern:diff <before> last       -> verdict improved, issue under "resolved"
lantern:check --route=<route> --no-new-issues    -> exit 0
```

**MCP server (optional).** MCP is not enabled by installing Lantern alone: it needs `laravel/mcp` (^1.1) in your app (`composer require --dev laravel/mcp`). Once that package is present, Lantern registers a local read-only server named `lantern` with `lantern_issues`, `lantern_issue`, `lantern_trace_summary`, `lantern_diff` and `lantern_check`. They return the same JSON as the CLI.

```bash
claude mcp add lantern -- php artisan mcp:start lantern
```

or in `.mcp.json`:

```json
{"mcpServers":{"lantern":{"command":"php","args":["artisan","mcp:start","lantern"]}}}
```

**Laravel Boost.** Lantern ships a Boost guideline and a `lantern-debug` skill with fix patterns for each issue type. Setup and a copy-paste snippet for your own `AGENTS.md` are in [docs/AGENTS_GUIDE.md](docs/AGENTS_GUIDE.md).

## Trust and limitations

### Tested versions

| Component | Status |
|---|---|
| Laravel 11, 12 | Tested on both |
| PHP 8.3, 8.4 | Tested. 8.2 is declared; the code uses no 8.3+ syntax, but the suite was not run on it |
| Livewire 3, 4 | Tested on both |
| Filament 4 | Tested in the package suite |
| Filament 5 | Exercised end to end in a separate seeded application; not part of the package's own matrix |
| SQLite, MySQL 8.4, MariaDB 10.11, PostgreSQL 16 | Tested (migrations, storage, pruning, fingerprints, `EXPLAIN` parsing) |
| Octane | Reviewed with simulated events; not run under a live Octane server |
| Laravel MCP | Tested |

The package's own suite is 558 tests plus a 261-test stress suite covering failures in every layer, odd data, config toggles and storage faults. Full matrix: [docs/COMPATIBILITY.md](docs/COMPATIBILITY.md).

### Measured overhead

Median request time (p50, ms), in-process through the HTTP kernel, median of three runs of 300 requests after warm-up (every run returned status 200), MySQL 8.4, 20,000 posts and 200,000 comments, PHP 8.4 on one Windows machine, Lantern on versus off. `call_site_mode` at its dev-mode default (`all`), `keep_rate=0.1`.

| Route | Off | Memory storage | Database storage |
|---|---|---|---|
| API list, eager loaded (4 queries) | 10.93 | 11.68 (+6.9%) | 11.71 (+7.1%) |
| Blade page, eager loaded | 7.42 | 7.83 (+5.5%) | 7.94 (+7.0%) |
| API list with an N+1 (152 queries) | 217.7 | 234.5 (+7.7%) | 217.3 (about 0%, within noise) |

These are measurements from one environment, not guarantees, and they are noisy: with Lantern off, the same Blade route ranged from 7.04 to 8.86 ms between runs and the N+1 route from 192 to 245 ms, so differences of a few points are not meaningful. An earlier measurement of the same routes on the same data gave +2.7% to +3.9% on the API list and +9% to +16% on the N+1 route; expect overhead of roughly 3 to 10% on fast routes, which is about 0.4 to 0.8 ms per request. The cost is mostly per query (a call site and a span each) plus a small fixed cost per request, so it shows most on very fast routes and query-heavy requests. A concurrent-load test with a real server was not run. Measure on your own workload; sampling and `call_site_mode=lazy` reduce the cost.

**Reproduce it.** Seed your own data, then run this script from your app root once with Lantern off and once on, on the same machine with the same data. It boots the app once and sends requests through the HTTP kernel in-process (no web server, so it measures the framework, Lantern and the database, not network or concurrency):

```php
<?php
// bench.php: php bench.php /your/route [requests]
define('LARAVEL_START', microtime(true));
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$route = $argv[1] ?? '/';
$requests = (int) ($argv[2] ?? 300);
$warmup = 20;
$times = [];

for ($i = 0; $i < $requests + $warmup; $i++) {
    $request = Illuminate\Http\Request::create($route, 'GET');
    $start = hrtime(true);
    $response = $kernel->handle($request);
    $ms = (hrtime(true) - $start) / 1e6;
    $kernel->terminate($request, $response);
    if ($i >= $warmup) {
        $times[] = $ms;
    }
}

sort($times);
printf("p50 %.2f ms  p95 %.2f ms  (%d requests, status %d)\n",
    $times[intdiv(count($times), 2)], $times[(int) (count($times) * 0.95)], count($times), $response->getStatusCode());
```

```bash
# bash (in Git Bash on Windows, prefix with MSYS_NO_PATHCONV=1 so the route is not rewritten)
LANTERN_ENABLED=false php bench.php /posts 300                                    # the "Off" column
LANTERN_ENABLED=true  php bench.php /posts 300                                    # the "Memory storage" column
LANTERN_ENABLED=true  LANTERN_STORAGE_DRIVER=database php bench.php /posts 300   # the "Database storage" column
```

```powershell
# PowerShell
$env:LANTERN_ENABLED = 'false'; php bench.php /posts 300
$env:LANTERN_ENABLED = 'true';  php bench.php /posts 300
$env:LANTERN_STORAGE_DRIVER = 'database'; php bench.php /posts 300
```

Check that the status printed is the one you expect (a mistyped route returns a fast 404 that makes any tool look free). Run each mode a few times and compare medians; single runs on a busy machine are noisy, which is why the Blade row above is flagged.

### What is captured

- **Always:** timing, memory, span names, query SQL shape (literals normalized), call sites, issue details.
- **Bound values:** recorded when `capture_bindings` is on. Left unset, it follows `mode`, and `mode` defaults to `dev`, so a default install captures bindings; set `LANTERN_MODE=profile` or `LANTERN_CAPTURE_BINDINGS=false` for shared or production environments. Stored values are kept as-is, with strings truncated at 200 characters and objects reduced to their class name. Redaction by name (`password`, `token`, `secret`) only applies to *named* bindings, not to Laravel's usual positional `?` bindings, so a password bound positionally is stored in clear text. The agent CLI and MCP mask binding values unless you pass `--with-bindings`. The dashboard and its JSON API show stored values, so keep the `Lantern::auth` gate tight.
- **Where it lives:** your own database, in `lantern_traces` and `lantern_issues` (or in memory, last 20 traces, with the default driver). Nothing is sent to a third party.
- **Limiting it:** `LANTERN_ENABLED=false` or `LANTERN_KILL_SWITCH=true` stops recording; `capture_bindings=false` stops value capture; `ignore_paths`, `ignore_tables`, `ignore_sql_patterns`, `ignore_jobs` and `ignore_commands` exclude work; `sampling_rate` and `keep_rate` limit volume; `retention.days` and `lantern:prune` bound storage.

### Safety

Disabled unless enabled, and a disabled Lantern registers no listeners. Recording and storage failures are isolated from the request. Lantern's own queries and its dashboard are never traced. In a 1,000-request run memory drifted by under 1 MB.

### Known limits

- The dashboard, CLI, MCP tools and issue history need `LANTERN_STORAGE_DRIVER=database`.
- `lazy_load` does not see single-row lazy loads or Laravel's automatic eager loading; repeating ones are still caught as `n_plus_one`.
- `missing_index` relies on `EXPLAIN` and is opt-in; SQL Server is reviewed only.
- Detectors use thresholds and heuristics; expect some false positives and tune `thresholds.*` and the ignore lists. Lantern does not catch every performance problem and does not prevent regressions on its own; it makes them visible and checkable.
- The Boost guideline and skill follow the documented package layout but were not verified against a live `boost:install`.
- Timing is noisy; gate on query counts and issue presence.

## Documentation

- [Configuration](docs/CONFIG.md)
- [Detectors](docs/DETECTORS.md)
- [How it works](docs/HOW_IT_WORKS.md)
- [CLI reference](docs/CLI.md)
- [Agents guide](docs/AGENTS_GUIDE.md)
- [Compatibility](docs/COMPATIBILITY.md)

## License

MIT
