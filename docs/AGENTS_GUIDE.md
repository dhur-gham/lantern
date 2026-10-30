# Using Lantern with AI coding agents

Lantern gives agents machine-readable performance findings and a way to prove a fix worked: find, fix, verify. Full command reference: `docs/CLI.md`.

## Setup

1. Install the package as a dev dependency, then `php artisan lantern:install` and `php artisan migrate`.
2. In `.env`: `LANTERN_ENABLED=true` and `LANTERN_STORAGE_DRIVER=database`. The agent commands exit 2 without the database driver. Keep Lantern off in production, or use sampling (`docs/CONFIG.md`). Set `LANTERN_DB_CONNECTION=lantern` so traces survive switching `DB_DATABASE`.
3. To record every request while reproducing locally, set `LANTERN_KEEP_RATE=1`.

## The loop

1. Reproduce the slow request (feature test or real request). Seed at least 3 related rows or N+1 will not appear.
2. `php artisan lantern:issues --format=json --limit=10`. Each issue has a stable `id`, `type`, `severity` and `call_site.file`/`line`.
3. `php artisan lantern:issue {id} --format=json`. `evidence.trace_id` is the BEFORE trace; `evidence.sql_shape` is the normalized SQL.
4. Fix the call site (eager load, index, `select()`, chunking).
5. Re-run the reproduction, then `php artisan lantern:diff {before} last`. Expect the issue in `resolved` (or in `changed` with status `improved` when it is reduced but still present, even if its line moved), `new` empty and verdict `improved`.
6. `php artisan lantern:check --route=<name or path> --no-new-issues` is the gate. `php artisan lantern:issues` also shows it: once the query runs again without the finding, the issue is marked `resolved` and hidden (list it with `--include-resolved`); it reopens if the finding fires again.
3a. For `missing_index` and `index_not_chosen`, read `evidence.rows_read` (and `read_ratio` when present; it needs `LANTERN_EXPLAIN_ANALYZE=true`, which re-runs flagged queries once under a time limit; when it is missing, `evidence.rows_returned_why` says why; `timeout` means raise `LANTERN_EXPLAIN_ANALYZE_TIMEOUT_MS`), `evidence.projected` (the scan's size here against production) and `evidence.schema_note`. An `index_not_chosen` finding names an index that already exists: do not write a migration for it. A `schema_note` means the local database lacks production indexes, so compare with `php artisan lantern:schema-diff prod-schema.json` (snapshot made with `lantern:schema-snapshot` where production is reachable, path in `LANTERN_SCHEMA_SNAPSHOT`) before trusting local timings.
7. For a `plan_flip` finding (fast with a short `whereIn()` list, slow with a long one), `php artisan lantern:probe {id} --in-sizes=1,7,20,41,100` shows where the plan changes.
7a. When you start from a production SQL (an APM span, a slow-query log), `php artisan lantern:match --sql="<the statement>" --format=json` returns the call site, the issue recorded on it and its plan. Placeholders `%s`, `?`, `$1` and `:name` and literal values all work. `match_type: similar` means no stored query has exactly that shape; check the `hint`. `lantern:issues --sql="..."` lists only the issues on that query.

To target one endpoint, filter instead of guessing the latest trace: `lantern:trace --path='api/otp/*' --summary --format=json` (also `--route`, `--status`, `--since`) and `lantern:issues --path=... --format=json`. Only the current app's rows are read; add `--all-apps` when several apps share the database.

Always pass `--format=json`; the default table is larger and meant for humans. Results carry `next` (suggested next command) and `hint` (empty or truncated results). When `truncated` is true use `next_offset`.

Exit codes: 0 ok, 1 check failed, 2 usage/config/storage/not-found, 3 no data.

## From a production error to a verified fix

When the starting point is a trace in an error or APM tool:

1. Take the slow or failing SQL from the span and run `php artisan lantern:match --sql="<statement>" --format=json` to find the call site and the issue recorded on it.
2. Reproduce the request locally with production-shaped data using a probe script (below). Reproduce the production condition itself: if a field is empty locally but set in production, or the table has a handful of rows instead of thousands, the probe hides the bug.
3. `php artisan lantern:issues --route='record:*' --format=json` lists what the probe triggered.
4. `php artisan lantern:issue {id} --format=json` gives `call_site.file` and `line`.
5. Fix the code.
6. Re-run the same probe with `--diff=<name>` (the record name, or the BEFORE trace id), or call `php artisan lantern:diff {before} last`. Expect the issue under `resolved` and verdict `improved`.

A probe is a PHP file that builds the data and wraps the code to measure:

```php
<?php
// probes/export.php
use Lantern\Facades\Lantern;

$customer = Customer::factory()->hasInvoices(200)->create(['plan' => $args['plan'] ?? 'pro']);

Lantern::record('invoice-export', fn () => app(InvoiceExporter::class)->run($customer), ['customer' => $customer->id]);
```

```bash
php artisan lantern:run probes/export.php --repeat=5 --plan=enterprise
php artisan lantern:run probes/export.php --diff=invoice-export   # after the fix
```

`lantern:run` works only with `APP_ENV=local`, turns Lantern on for that process, and rolls the script's database writes back unless you pass `--commit`. Extra `--key=value` options arrive in `$args`. `--repeat=N` adds a median summary per record name. It is not available over MCP because it executes code. Details: `docs/CLI.md`.

`Lantern::record()` can also stay in application code: it is a plain call when Lantern is off, and inside a request, job or command it adds a span and keeps that trace.

### Real request chains in a browser

When the problem only shows up across several real requests (a login, a redirect, a Livewire flow), drive the app in a browser and read the traces afterwards with `lantern:issues --path=...` or `--route=...`.

1. Log in through the application's own means (its login form or a local seeder), so the session is genuine.
2. Playwright CLI using the installed Chrome as the driver: `playwright-cli open <url> --browser=chrome`; `state-save` and `state-load` keep the logged-in session between runs. Flags vary by version, so check `playwright-cli --help`.
3. Alternative: Chrome DevTools MCP started with `--autoConnect`, which attaches to your own Chrome and inherits its cookies.

## CI gate

```bash
php artisan lantern:check --write-baseline=lantern-baseline.json   # once: accept the current state
php artisan lantern:check --baseline=lantern-baseline.json --no-new-issues --max-queries=30
```

Run the app's feature tests with Lantern enabled first so there are traces to check. Prefer query counts and issue presence over milliseconds; timing is noisy.

## MCP server

```bash
composer require laravel/mcp --dev
```

Lantern registers a local server named `lantern` automatically when `laravel/mcp` is installed. In the host app's `.mcp.json`:

```json
{
  "mcpServers": {
    "lantern": { "command": "php", "args": ["artisan", "mcp:start", "lantern"] }
  }
}
```

Run it from the app root with the same `.env` flags. Tools (read-only): `lantern_issues` (`type severity sort since path route status app all_apps limit offset full`), `lantern_issue` (`id`), `lantern_trace_summary` (`trace_id slow path route status since app all_apps limit`), `lantern_diff` (`before after`), `lantern_check` (`route path status app all_apps max_queries max_ms fail_on no_new_issues baseline since limit`). Each returns the same JSON as the matching command. Errors come back with `isError: true` and the JSON error object; a failing check is a normal result with `pass: false`. `next` and `hint` name the matching tool.

## Laravel Boost

With Laravel Boost, `php artisan boost:install` or `boost:update` loads Lantern's guideline (`resources/boost/guidelines/core.blade.php`) and the `lantern-debug` skill (`resources/boost/skills/lantern-debug/SKILL.md`, fix recipes per issue type).

## Copy-paste snippet

For the host app's `AGENTS.md` or equivalent:

```markdown
## Lantern (performance traces)

Use Lantern when a request is slow or runs many queries. Enable locally with `LANTERN_ENABLED=true` and `LANTERN_STORAGE_DRIVER=database`; never in production without sampling.

- Find: `php artisan lantern:issues --format=json --limit=10`; fix the highest-severity issue at `call_site.file:line`.
- Inspect: `php artisan lantern:issue {id} --format=json`, `php artisan lantern:trace --last --summary --format=json`.
- Verify after editing: `php artisan lantern:diff {before} last` then `php artisan lantern:check --route=<route> --no-new-issues` (exit 0 = fixed, 1 = still failing, 3 = no data).
- Fix the query (eager load, index, select columns); never ignore an issue or raise a threshold to pass.
- Seed at least 3 related rows when reproducing. Bindings are redacted; do not try to recover them.
```

## Safety

- Traces are stored in the app database; keep Lantern off in production or sample.
- Bindings and SQL literals are redacted. `lantern:issue --with-bindings` shows masked values, for local debugging only.
