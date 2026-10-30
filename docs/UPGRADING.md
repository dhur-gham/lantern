# Upgrading

Each section lists what changes in your application when you move to that release. Run `composer update dhur-gham/lantern` first, then follow the steps for every release you are crossing.

Lantern loads its migrations from the package, so `php artisan migrate` is enough after an update. You do not need to publish them again, even if you published them earlier: a published copy only overrides the package file with the same name.

If your team applies schema changes by hand, print the SQL instead of migrating (see [Applying the schema by hand](#applying-the-schema-by-hand)).

## 0.7.0

**No schema change.** Nothing to migrate.

**New: `lantern:login`.** A local-only way into an authenticated app for an agent or a developer. `php artisan lantern:login panel --to=/admin/items/1/edit` prints a one-time sign-in link for a session guard (valid 5 minutes, works once, stored hashed); `lantern:login api` prints a short-lived Sanctum token as headers; a `custom` surface calls a class in your app (`Lantern\Access\ProvidesCredentials`) that mints credentials. `--list` shows surfaces and what is outstanding, `--revoke [surface]` / `--revoke --all` undoes it. JSON schemas `lantern.login`, `lantern.login.list` and `lantern.login.revoke`. See the [CLI reference](CLI.md#lanternlogin-surface). It is not an MCP tool, because it mints credentials.

**New: `lantern:init`** proposes surfaces from your auth guards and installed packages; `--write` saves them to `config/lantern-access.php`, which is merged into `lantern.access.surfaces` (the main config wins on a clash). **New: `Lantern::actingAs('panel')`** signs a surface's user in for code in the same process, such as `Livewire::test()`. `lantern:status --json` has an `access` object and warns about an `array` cache store, an `APP_URL` without a host, or an `APP_URL` host that no recorded request has confirmed.

**Config.** A new `access` block: `enabled` (`LANTERN_ACCESS`, default true), `cache_store` (`LANTERN_ACCESS_CACHE_STORE`), `fire_login_event` (default false) and `surfaces`. A published config without it keeps working with these defaults; add the block from the package config to define surfaces. See [Configuration](CONFIG.md#access).

**Security notes.** Everything in the feature requires `APP_ENV=local`, Lantern enabled and `access.enabled`; otherwise the route `/_lantern/access/{token}` is not registered and the command exits 2. The route refuses requests with `X-Forwarded-*`, `Forwarded` or `X-Real-IP` headers, takes only relative `--to` paths, and answers 404 for unknown, used and expired links. Sign-in links do not fire the `Login` event unless `fire_login_event` is on. Tokens and the list of issued credentials live in the cache store, which must not be `array`. `APP_URL` must have a host and is checked against the host of the last recorded request (exit 2 on a mismatch); with no recorded request the JSON carries `host_verified: false` and a `hint`, and `lantern:status` warns. Links are single use because the cache store reads and deletes the token in one step; Redis does not make that atomic, so in theory two requests at the same instant could both use a link. The `_lantern` path is never traced. `laravel/sanctum` is a dev dependency of the package for its own tests only; your app needs it only for `sanctum` surfaces.

## 0.6.0

**No schema change.** Nothing to migrate.

**New: record any code.** Lantern used to start a trace only for HTTP requests, queued jobs and Artisan commands. `Lantern::record('name', fn () => ...)` (facade `Lantern\Facades\Lantern`) now records whatever the closure runs as one trace, analysed and stored like a request: its queries, lazy loads and spans, with `file:line` for every finding. The trace has the route `record:<name>`, is always kept, and `context` (ids and small values, redacted and truncated like bindings) is stored with it. With Lantern off it only calls the closure, so probes can stay in place. Inside a request, job or command it is a child span and the enclosing trace is kept even when sampling would drop it.

**New: `lantern:run`.** `php artisan lantern:run probes/edit-item.php --item=1` runs a script with the app booted and Lantern on for that process, even when `LANTERN_ENABLED` is off, and only when `APP_ENV=local`. Each `Lantern::record()` block becomes a trace; code outside blocks is not recorded. The script gets `$args` (every `--key=value`) and `$lantern`. The script's database writes are rolled back unless you pass `--commit`, on every connection the script touches (including ones it opens itself); the JSON reports `rolled_back` and `connections`, per connection. Traces for code outside `Lantern::record()` blocks are not stored. Requests made by recorded code (`Livewire::test()`, a test client) become child spans of the record trace instead of traces of their own. Rolling back skips `afterCommit` callbacks and jobs dispatched after commit. `--repeat=N` reports medians per record name and `--diff=<trace id or name>` compares the first trace with a stored one. `lantern:run` is not an MCP tool because it executes code. See the [CLI reference](CLI.md#lanternrun).

**Changed: Livewire trace routes.** A removed query now also resolves its finding when the remaining queries of that function come from closures inside it. Livewire update requests are named `livewire:<Component>::<method>` (batched calls joined with `+`; Filament schema calls append their first parameter), so `lantern:issues --route='livewire:*EditItem*'` finds them. Routes recorded by 0.5.x had the form `livewire:<Component>@<method>`; filters and `lantern:check --route=` that name the old form need updating. A new span type, `record`, appears in trace JSON.

## 0.5.1

**Schema.** One new migration, `2024_01_01_000007_add_site_hash_to_lantern_issues_table`, adds a nullable `site_hash` column to `lantern_issues`. Run `php artisan migrate`, or print the SQL with `php artisan lantern:install --sql --since=0.5.1`. Until it has run, Lantern keeps working as before and findings are only resolved when their query runs clean (see below).

**A removed query now resolves its finding.** Until now a finding resolved only when its query shape ran again without producing it, so a fix that deleted the query left the finding open. It now also resolves when a later trace of the same route runs the finding's call-site function without the query and the call-site file has changed since the finding last fired (the file's content hash is stored on the finding). The same code skipping the query is a data difference, not a fix, and the finding stays open. Job and command traces now carry a route (`job:<class>`, `command:<name>`), so this works for them too; traces without a route are not used for this. The route shows in `lantern:issues --route=` and `lantern:check --route=`.

**Fixes.**

- A chunked bulk write (`UPDATE` or `DELETE` with an `IN` list, `BETWEEN` or an id range; multi-row `INSERT`; `INSERT ... SELECT`) is no longer reported as `n_plus_one`. A statement that pins one row, with an equality on every column of the table's primary or another unique key (read from the index catalog on MySQL and PostgreSQL; `id` and `uuid` when the catalog has nothing), still counts as a per-row loop even with an `IN` list in the same `WHERE`.
- Closure line numbers no longer enter a finding's identity, so adding lines above a closure does not turn a finding into resolved plus new in `lantern:diff`.
- A stored trace payload is capped at 4 MiB: above that, strings over 8 KiB are cut with a `...[truncated N bytes]` marker, and a payload still over the cap is stored as `{"truncated": true, "original_bytes": N}`. Encoding no longer holds the JSON and its compressed copy at the same time. Normal traces are far below the cap.
- When a `rows_returned` replay hits its time limit, the evidence now carries `rows_returned_hint` pointing at `LANTERN_EXPLAIN_ANALYZE_TIMEOUT_MS`.
- Lantern's migrations are skipped while the host application runs its tests if `LANTERN_DB_CONNECTION` points at a different connection from the application's default, so `php artisan test` no longer creates Lantern tables in the real Lantern database. Add `<env name="LANTERN_DB_CONNECTION" value=""/>` to `phpunit.xml`. A skip caused by a connection that could not be verified is reported by `lantern:install` and `lantern:status` (`lantern:status --json` adds `migration_skip`).

## 0.5.0

**No schema change.** Nothing to migrate.

**New: `lantern:match`.** Paste a SQL statement from production (an APM span description or a log line) and get the call site, the issue recorded on that query and its plan. Placeholders `%s`, `%d`, `%(name)s`, `?`, `$1` and `:name`, literal values, case, whitespace, quoting, comments, IN lists of any length and trailing `LIMIT`/`OFFSET` values all match. See the [CLI reference](CLI.md#lanternmatch). `lantern:issues` takes the same statement as `--sql=`. MCP: new tool `lantern_match` and a `sql` input on `lantern_issues`. Existing query fingerprints and stored issues are unchanged. The command searches the traces already stored, so a query that has not run since Lantern was enabled is reported as no match (exit 3).

## 0.4.2

**Schema.** One new migration, `2024_01_01_000006_add_resolved_state_to_lantern_issues_table`, adds nullable columns to `lantern_issues`: `resolved_at` and `shape` (indexed), plus `in_size` and `sql_hash`, and fills `shape` for existing `missing_index`, `index_not_chosen` and `plan_flip` rows so they can resolve without waiting to fire again. Run `php artisan migrate`, or print the SQL with `php artisan lantern:install --sql --since=0.4.2`. Until it has run, Lantern keeps storing traces and issues as before, issues are always shown as open, and `--resolved` lists nothing.

**Fixed findings leave `lantern:issues`.** After a fix, `plan_flip`, `index_not_chosen` and the other query findings used to stay listed as open (usually critical) because only `lantern:diff` knew they were gone. A stored issue is now marked resolved when a later trace runs its query shape (same query, call-site file and function, whatever the line) without producing the finding, unless that run used a smaller `IN (...)` list than the one the finding fired on and the SQL text is the same (a smaller input is not a fix; a changed query, such as an added hint, is). It reopens if the finding fires again. Resolved issues are hidden from `lantern:issues` and do not fail `lantern:check`; list them with `--include-resolved` or `--resolved` (MCP `lantern_issues`: `include_resolved`, `resolved`). Issues have new `status` and `resolved_at` fields, and `status` is now in the default field list, so scripts that compared the default fields exactly need updating. Plan based findings resolve only on runs with `explain` on, and `duplicate_query`, `lazy_load` and `memory_hog` are not resolved automatically.

**Behavior changes.**

- `explain_analyze` now works with the default `capture_bindings` setting. In 0.4.1 it replayed a query only when its stored bindings were complete, and stored bindings are redacted or absent by default, so `rows_returned` stayed `null`. While `explain_analyze` is on, the real values of each query shape's first execution are kept in memory for the replay and for the plan `EXPLAIN`, then dropped when the trace has been analysed; they are never stored or shown. With the option off, nothing changes. Because the plan `EXPLAIN` now sees real values, a scan that was hidden by `NULL` parameters can start showing up as a finding.
- On MySQL the replay's time limit is merged into a query's own `/*+ ... */` hint block instead of skipping queries that have one.
- `lantern:probe` fills the `IN (...)` lists with the most frequent values of the filtered column instead of the lowest ones, and smaller lists are prefixes of larger ones. A value with few or no rows gets a different plan from a typical one, so probing with it could report a plan change that does not exist (for example from 1 to 7 values). `ids_source` is now `frequent_values` (was `table`) or `synthetic`; with `synthetic` the output adds an `ids_note`. Scripts that compared `ids_source` to `table` need the new value.

## 0.4.1

**Schema.** One new migration, `2024_01_01_000005_add_sql_hash_to_lantern_plan_samples_table`, adds a nullable `sql_hash` column to `lantern_plan_samples`. Run `php artisan migrate`. Until it has run, plan-flip samples keep working but cannot tell that a query's SQL text changed.

**Detectors added after your published config are now picked up.** A config published on 0.3.x keeps its own `detectors` list, which replaced the package default, so detectors added since (currently `PlanFlipDetector`) never ran and nothing said so. From 0.4.1 any detector the package ships that is missing from your list is merged in automatically. To keep one off, list it in the new `disabled_detectors` key:

```php
'disabled_detectors' => [\Lantern\Detection\Detectors\PlanFlipDetector::class],
```

`php artisan lantern:status` lists the active detectors and warns about package detectors that are off and not listed in `disabled_detectors`.

**Behavior changes.**

- `plan_flip` samples now remember a hash of the exact SQL text, hints and comments included. A sample taken from different SQL is discarded, so adding an optimizer hint no longer leaves a stale `plan_flip` finding behind. Samples are also re-explained sooner when a run's time moves far from the stored average (`plan_flip.time_deviation`).
- `missing_index` suggestions keep flag-style columns out of the lead position even when no index covers them and their distinct count is unknown. A column counts as a flag by its name (`is_`, `has_`, `can_`, `should_`, `was_`, `did_`, `need_` prefixes or a `_flag` suffix) or its type (`tinyint`, `bit`, `boolean`, short `enum`). A real distinct count still decides when one is available.
- New opt-in `explain_analyze` (`LANTERN_EXPLAIN_ANALYZE`): runs a flagged query a second time after the request to record `rows_returned`, which `lantern:issue` uses for the rows read against rows returned ratio. It executes SQL, so leave it off in environments where that is not acceptable. See [Configuration](CONFIG.md).

## 0.4.0

**Schema.** Two migrations:

| Migration | Change |
|---|---|
| `2024_01_01_000003_add_app_to_lantern_tables` | Adds a nullable, indexed `app` column to `lantern_traces` and `lantern_issues`. |
| `2024_01_01_000004_create_lantern_plan_samples_table` | Creates `lantern_plan_samples`, which stores one row per query shape and `IN (...)` list size. |

Run `php artisan migrate`. A store that has not been migrated yet falls back to writing traces without the `app` tag.

**Detectors.** `PlanFlipDetector` is new. If you published `config/lantern.php` before 0.4.0, add it to your `detectors` list, or move to 0.4.1 where it is merged in for you. It needs `explain` on.

**The `app` tag.** Traces and issues are tagged with `lantern.app` (`LANTERN_APP`, default `APP_NAME`). The CLI now shows only the current app, plus older rows that have no tag; pass `--all-apps` to see everything or `--app=` to pick one. On issues the tag is last-writer-wins: the issue key does not include the app, so two apps that hit an identical query shape share one row.

**Storage connection.** `LANTERN_DB_CONNECTION=lantern` stores traces in `storage/lantern/lantern.sqlite`, independent of `DB_DATABASE`. The default is unchanged. Moving existing traces is not automatic; run `php artisan migrate` after switching and start fresh, or copy the three `lantern_*` tables yourself.

**New commands.** `lantern:schema-snapshot`, `lantern:schema-diff`, `lantern:probe`, and `--path`, `--route`, `--status`, `--since` filters on `lantern:trace` and `lantern:issues`. See the [CLI reference](CLI.md).

**Output changes to be aware of.**

- `lantern:diff` matches findings by query shape instead of by call-site line, and adds a `changed[]` list with `improved`, `regressed` and `unchanged` entries and before and after values. The existing keys (`resolved`, `new`, `threshold`, `unchanged`) are kept. Moving a query to another line no longer shows as one resolved and one new finding.
- Findings carry a new `shape` field (a line-independent key for the query or relation and its call-site function). Issue ids are unchanged, so a finding stored before the update keeps its id; `lantern:diff` uses `shape` to match findings across a code change.
- A new finding type, `index_not_chosen`, is reported instead of `missing_index` when an index on an involved table exists but the plan did not use it.

## Applying the schema by hand

`lantern:install --sql` prints the migration SQL for a connection's database driver without changing anything. The output is plain SQL on stdout with a comment naming each migration; problems go to stderr.

```bash
# Everything, for the default (or LANTERN_DB_CONNECTION) connection
php artisan lantern:install --sql > lantern.sql

# Only what an existing 0.3.x install is missing
php artisan lantern:install --sql --since=0.4.0 > lantern-upgrade.sql

# SQL written for another connection's driver
php artisan lantern:install --sql --connection=pgsql
```

`--since=` keeps the migrations first shipped in that version or later. Use the first version you do not have yet: on 0.3.x, `--since=0.4.0` gives the `app` columns, the `lantern_plan_samples` table and its later `sql_hash` column; on 0.4.0, `--since=0.4.1` gives only the `sql_hash` column.

On a full output (no `--since`), the `app` columns are part of the create statements, so the separate `add_app` migration is marked as already included and not repeated. Review the file before running it, and run it with your normal change process. The SQL is generated for the connection's driver; `--connection` must name a connection defined in `config/database.php`.
