# Configuration reference

Published to `config/lantern.php` by `php artisan lantern:install`. Source of truth: `config/lantern.php`.

| Key | Default | Env | Meaning |
|---|---|---|---|
| `enabled` | `false` | `LANTERN_ENABLED` | Master switch. |
| `kill_switch` | `false` | `LANTERN_KILL_SWITCH` | When true nothing is recorded, even if enabled. |
| `mode` | `dev` | `LANTERN_MODE` | `dev`: call sites for every query. `profile`: only slow or repeated queries. |
| `call_site_mode` | `all` in dev, else `lazy` | `LANTERN_CALL_SITE_MODE` | `all`: resolve every query's call site. `lazy`: only repeated or slow queries (backfilled on repeat). |
| `sampling_rate` | `1.0` | `LANTERN_SAMPLING_RATE` | Fraction (0.0 to 1.0) of executions traced; decided once per execution. |
| `thresholds.n_plus_one_min_repeats` | `5` | `LANTERN_N_PLUS_ONE_MIN_REPEATS` | Same query shape at one call site this many times is an N+1 (critical at 4x). |
| `thresholds.duplicate_min_repeats` | `2` | `LANTERN_DUPLICATE_MIN_REPEATS` | Identical SQL and bindings repeats before a duplicate issue. |
| `thresholds.slow_query_ms` | `100` | `LANTERN_SLOW_QUERY_MS` | Slow query threshold. |
| `thresholds.memory_delta_mb` | `32` | `LANTERN_MEMORY_DELTA_MB` | Per-span memory growth that flags a memory hog. |
| `thresholds.explain_min_ms` | `0` | `LANTERN_EXPLAIN_MIN_MS` | Query shapes slower than this are EXPLAIN candidates (when `explain` is on). The default `0` decides by plan, not by one run's timing, so a finding does not come and go between runs. |
| `thresholds.max_issues_per_trace` | `50` | none | Cap on issues per trace. |
| `thresholds.production_scan_rows` | `500000` | `LANTERN_PRODUCTION_SCAN_ROWS` | A full scan projected to read at least this many rows in production (from `production_row_counts` or the schema snapshot) is reported as `critical`. `0` turns the escalation off. |
| `ignore_tables` | sessions, cache, cache_locks, jobs, job_batches, failed_jobs, migrations, password_reset_tokens | none | Queries whose primary table is listed are skipped by query detectors. |
| `ignore_sql_patterns` | `[]` | none | Substring, or regex when starting with `/`; matching SQL is skipped. |
| `detectors` | all seven detector classes | none | Detector classes run on each finished trace (`NPlusOne`, `DuplicateQuery`, `SlowQuery`, `MemoryHog`, `MissingIndex`, `PlanFlip`, `LazyLoad`). A config published before a detector existed does not list it, so detectors added since 0.3 (currently `PlanFlip`) are merged in automatically. |
| `disabled_detectors` | `[]` | none | Detector classes to turn off. Use it to opt out of an automatically merged detector; it also works for any other. `lantern:status` lists the active detectors and warns about package detectors that are off and not listed here. |
| `explain` | `false` | `LANTERN_EXPLAIN` | Run EXPLAIN on slow queries to detect missing indexes. |
| `explain_analyze` | `false` | `LANTERN_EXPLAIN_ANALYZE` | Also measure how many rows a flagged query returns (`rows_returned`, which gives `read_ratio` in `lantern:issue`). **Runs the query a second time** after the request, under a time limit and read-only on PostgreSQL; queries with locks or side effects are never replayed. Needs `explain`. |
| `explain_analyze_max` / `explain_analyze_timeout_ms` | `3` / `2000` | `LANTERN_EXPLAIN_ANALYZE_MAX` / `LANTERN_EXPLAIN_ANALYZE_TIMEOUT_MS` | At most this many queries per trace are replayed, each limited to this many milliseconds. |
| `explain_min_rows` | not set (`100`) | none | Optional, not in the published file. With `explain` on, scans estimated at this many rows or fewer are not reported as missing indexes. |
| `plan_flip.factor` | `3` | `LANTERN_PLAN_FLIP_FACTOR` | `plan_flip` reports cost (time or estimated rows) that grows more than this many times faster than the `IN (...)` list. |
| `plan_flip.min_ms` / `plan_flip.min_rows` | `10` / `1000` | `LANTERN_PLAN_FLIP_MIN_MS` / `LANTERN_PLAN_FLIP_MIN_ROWS` | The larger list size must take at least this long, or read at least this many estimated rows, to be reported. |
| `plan_flip.refresh_minutes` | `60` | `LANTERN_PLAN_FLIP_REFRESH_MINUTES` | A shape and list size is re-EXPLAINed at most this often (needs `explain` and the database driver). |
| `plan_flip.time_deviation` | `4` | `LANTERN_PLAN_FLIP_TIME_DEVIATION` | A sample is re-EXPLAINed early (at most every 5 minutes) when a run takes this many times more or less than its stored average, so a fix that changes no SQL text is picked up. `0` turns it off. Samples from a different SQL text (an added optimizer hint) are always discarded. |
| `schema_snapshot` | `null` | `LANTERN_SCHEMA_SNAPSHOT` | Path to a production index snapshot made with `lantern:schema-snapshot`. Findings on tables whose indexes differ locally note it. |
| `production_row_counts` | `[]` | none | Production row counts per table, for example `['orders' => 838776]`. Takes precedence over the snapshot's `row_counts`. |
| `capture_bindings` | `null` (on only when `APP_ENV=local` in dev mode, off otherwise) | `LANTERN_CAPTURE_BINDINGS` | Record bound values on query spans. Bindings named password/token/secret, and values that look like emails, JWTs or API tokens, are always redacted. |
| `binding_max_length` | `200` | none | Longer string bindings are truncated. |
| `capture_normalized_sql` | `true` | none | Store the normalized SQL on query spans. |
| `backtrace_limit` | `50` | none | Frames inspected to resolve a call site. |
| `app_paths` | `app_path()`, `resource_path('views')`, `base_path('routes')` | none | Directories counted as application code for call sites. |
| `memory_sink_limit` | `20` | `LANTERN_MEMORY_SINK_LIMIT` | Finished traces kept by the in-memory sink. |
| `debug_write_last_trace` | `false` | `LANTERN_DEBUG_WRITE_LAST_TRACE` | Write each trace to `storage/lantern/last-trace.json` (read by `lantern:trace:dump`). |
| `ignore_paths` | `lantern*`, `up`, `_debugbar*`, `telescope*`, `horizon*` | none | `Str::is` patterns never traced. The dashboard path is added automatically. |
| `max_tree_depth` | `100` | `LANTERN_MAX_TREE_DEPTH` | Spans nested deeper are dropped from stored trees (counted in `children_omitted`). |
| `check.max_queries` | `null` | none | Default `--max-queries` for `lantern:check`. |
| `check.max_ms` | `null` | none | Default `--max-ms`. |
| `check.fail_on` | `critical` | none | Default `--fail-on`: `critical`, `warning` or `info`. |
| `check.traces` | `50` | none | Default `--traces`. |
| `trace_jobs` | `true` | `LANTERN_TRACE_JOBS` | Trace queue jobs as their own traces. |
| `trace_commands` | `true` | `LANTERN_TRACE_COMMANDS` | Trace artisan commands. Loop wrappers (`queue:work`, `schedule:work`, `serve`, ...) are always skipped. An optional `ignore_commands` array (`Str::is` patterns) replaces the built-in skip list (`lantern:*`, `migrate*`, `db:*`, `make:*`, ...). |
| `ignore_jobs` | `[]` | none | Job classes or patterns not traced. |
| `keep_slow_ms` | `1000` | `LANTERN_KEEP_SLOW_MS` | Traces slower than this are always kept (tail sampling). |
| `diff.tolerance_pct` / `diff.tolerance_ms` | `20` / `5` | `LANTERN_DIFF_TOLERANCE_PCT` / `LANTERN_DIFF_TOLERANCE_MS` | `lantern:diff` calls a time change better or worse only beyond max(pct of before, ms). |
| `keep_rate` | unset: `1` when `APP_ENV=local`, else `0.1` | `LANTERN_KEEP_RATE` | Probability of keeping a clean trace. Slow, 5xx, failed and issue-bearing traces are always kept. |
| `storage.driver` | `memory` | `LANTERN_STORAGE_DRIVER` | `memory` or `database`. The dashboard and agent CLI need `database`. |
| `storage.connection` | `null` | `LANTERN_DB_CONNECTION` | Connection for the `lantern_*` tables. `null` follows the application's default connection, so pointing the app at another database (`DB_DATABASE=...`) moves Lantern with it and splits your traces. Set it explicitly to keep them in one place: `lantern` creates a dedicated SQLite file at `storage/lantern/lantern.sqlite` (run `php artisan migrate` afterwards), or name any connection from `config/database.php`. |
| `app` | `null` | `LANTERN_APP` | Name stored on every trace and issue (`config('app.name')` when null). `lantern:issues`, `lantern:trace` and `lantern:check` show only the current app's rows (plus untagged older rows) unless `--app=` or `--all-apps` is passed. Run `php artisan migrate` after upgrading to add the column. |
| `storage.compress` | `true` | `LANTERN_STORAGE_COMPRESS` | Gzip stored trace payloads. |
| `storage.batch_size` | `1` | `LANTERN_STORAGE_BATCH_SIZE` | Traces buffered before a write (always flushed at request end). |
| `path` | `lantern` | `LANTERN_PATH` | Dashboard URL prefix. |
| `domain` | `null` | `LANTERN_DOMAIN` | Optional dashboard domain. |
| `middleware` | `['web']` | none | Dashboard middleware; the `Authorize` gate is appended. |
| `retention.days` | `7` | `LANTERN_RETENTION_DAYS` | Trace retention for `lantern:prune`. |
| `retention.issues_days` | `30` | `LANTERN_ISSUES_RETENTION_DAYS` | Issue retention for `lantern:prune`. |

The dashboard gate is code, not config: `Lantern::auth(fn ($request) => ...)`. Without a gate, only `APP_ENV=local` requests from a loopback peer (127.0.0.1, ::1) are allowed; every other environment or client gets 403. Behind Docker or a remote dev host, set `Lantern::auth`.
