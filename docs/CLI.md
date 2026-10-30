# CLI reference

Requirements for the agent commands (`issues`, `issue`, `trace`, `diff`, `check`): `LANTERN_ENABLED=true`, `LANTERN_STORAGE_DRIVER=database` and migrations run. Otherwise they exit 2 with `config_error`.

## Conventions

- Formats: `--format=table|json|ndjson|markdown`. Default is `table` for `issues`, `issue`, `trace`; `json` for `diff` and `check`. Use `json` for tooling. `ndjson` prints one item per line plus a final `{"_meta": ...}` line. `--pretty` pretty-prints json.
- JSON goes to stdout. Every payload has `schema_version` (1) and `schema`.
- `--since` accepts `30m`, `24h`, `7d`, `2w` (units s, m, h, d, w) or a date.
- Trace arguments accept a trace id, `last` or `latest`.
- `--path=` and `--route=` take `Str::is` patterns (`*` matches anything, everything else is literal; a path may omit its leading slash, so `api/otp/*` matches `/api/otp/verify`). `--status=` takes `404` or a class such as `5xx`.
- `--app=name` and `--all-apps` choose which app's rows are read (`lantern:issues`, `lantern:trace`, `lantern:check`). The default is the current app (`lantern.app`, else `app.name`); rows stored before the `app` column existed always match. Passing both is a usage error.
- Paths are relative to `base_path()`, lines are 1-based. SQL literals and bindings are redacted.

### Exit codes

| Code | Meaning |
|---|---|
| 0 | OK (`lantern:check` passed) |
| 1 | `lantern:check` found violations (only this command) |
| 2 | Usage, config, storage or not-found error, including unknown options and missing arguments |
| 3 | No data recorded (or none for `--route`) |

### Errors (json format)

```json
{"schema_version":1,"error":{"code":"not_found","message":"...","hint":"...","exit_code":2}}
```

`code` is one of `usage_error`, `config_error`, `no_data`, `not_found`, `storage_error`. `hint` may be absent. In table or markdown format the message and hint are printed as text.

## lantern:issues

List detected issues.

Flags: `--type=` (`n_plus_one|lazy_load|duplicate_query|slow_query|missing_index|index_not_chosen|plan_flip|memory_hog`), `--severity=` (`critical|warning|info`; aliases high, error, warn, medium, low), `--sort=total_ms|occurrences|last_seen` (default `total_ms`), `--since=`, `--path=`, `--route=`, `--status=` (match the trace that last recorded each issue), `--app=`, `--all-apps`, `--include-resolved`, `--resolved`, `--limit=10` (1-500), `--offset=0`, `--fields=` (comma list), `--full` (all fields including `evidence`), `--format`, `--pretty`.

Resolved issues: an issue is marked resolved when a later trace runs its query shape (same query and call-site file and function, whatever the line) without producing the finding again (a run with a smaller `IN (...)` list and unchanged SQL does not count), so `lantern:issues` stops listing a problem you have fixed. Resolved issues are hidden by default; `--include-resolved` lists both and `--resolved` lists only them (using both together is a usage error). If the finding fires again the issue reopens. Plan based findings (`missing_index`, `index_not_chosen`, `plan_flip`) resolve only on runs where `explain` is on and the detector examined the query; `duplicate_query`, `lazy_load` and `memory_hog` are never resolved automatically. A trace that hit `thresholds.max_issues_per_trace` resolves nothing. Needs the `…_add_resolved_state_to_lantern_issues_table` migration; before it has run, issues are always open.

Schema `lantern.issues`: `generated_at`; `total` matching; `returned`; `offset`; `truncated`; `next_offset` (when truncated); `total_capped` (total is a lower bound); `next` (command for the first issue); `hint` (empty or truncated results); `issues[]`.

Issue fields: `id` (stable fingerprint), `type`, `severity`, `status` (`open` or `resolved`), `title`, `call_site` (`file`, `line`, optional `function`), `occurrences`, `count_total` (total repeats), `total_ms`, `max_ms`, `suggestion` (a hint, not a patch), `first_seen`, `last_seen`, `resolved_at` (null while open), `related_trace_ids`, `evidence`. Default fields: `id type severity status title call_site occurrences total_ms suggestion`.

```
php artisan lantern:issues --format=json --type=n_plus_one --limit=5
```

## lantern:issue {key}

One issue with evidence. `key` is an id or a unique prefix of at least 6 characters.

Flags: `--with-bindings` (masked values instead of type placeholders; local debugging only), `--fields=`, `--format`, `--pretty`.

Schema `lantern.issue`: `issue` (all fields above, including `status`) and `next`; a resolved issue has a `hint` instead of `next`. `issue.evidence`: `trace_id` (use as BEFORE for diff), `sample_sql` (null when equal to `sql_shape`), `sql_shape` (normalized SQL), `bindings`, `parent`, `avg_ms`. For `missing_index` and `index_not_chosen` findings it also carries, when known: `rows_read` (estimated rows the scans read), `rows_returned` and `read_ratio` (rows read per row returned; only when the returned count is known; otherwise `rows_returned_why` says why: `budget` when `explain_analyze_max` was used up by slower queries, `no_bindings` when the values were unavailable, `timeout` when the replay hit `explain_analyze_timeout_ms` (raise it), `failed` when it errored or was not allowed, `unsupported` when no replay is available), `tables` (per table: `table rows key type`), `projected` (scan size here against production: `table local_rows production_rows factor`) and `schema_note` (indexes production has that the local schema lacks). Absent fields are omitted.

## lantern:trace {id?}

Show a stored trace (the latest when no id is given).

Filters: `--path=`, `--route=`, `--status=`, `--since=`, `--app=`, `--all-apps` narrow which trace is "latest" (or, with `--slow`, which of the 100 latest is slowest); `--last` combines with them. No match exits 3 with `No traces match these filters.`. Filters together with a trace id (other than `last`) are a usage error.

Flags: `--last`, `--slow` (slowest of the 100 latest), `--summary`, `--tree`, `--spans`, `--min-ms=` (spans), `--depth=6` (tree, 0-50), `--limit=10` (summary groups; spans default 50), `--fields=` (spans: `id parent_id type name start_ms duration_ms self_ms call_site`), `--json` (legacy full trace dump), `--format`, `--pretty`. Use only one of `--summary|--tree|--spans`; an id together with `--last` is a usage error. Without a mode, the table format prints the legacy tree and json prints the summary.

Schemas:
- `lantern.trace.summary`: `trace_id`, `started_at`, `method`, `path`, `route`, `status`, `duration_ms`, `query_count`, `query_ms`, `peak_memory`, `issue_count`, `issues[]` (`id shape type severity title call_site count total_ms`, plus `rows_read` and `read_ratio` when the finding has plan data; `shape` is stable when the call site moves to another line), `query_groups[]` (`sql_shape count total_ms call_site issue_id`, plus `rows_read` and `read_ratio` for a group tied to such a finding; groups with equal time sort by `rows_read`), `truncated`, `next`, `hint`.
- `lantern.trace.spans`: `trace_id`, `total`, `returned`, `truncated`, `hint`, `spans[]` (slowest first).
- `lantern.trace.tree`: `trace_id`, `tree` (nested nodes; repeated sibling queries collapse into `query_group` nodes with `count`, `total_ms`, `sql_shape`; `children_omitted` when depth-limited).

## lantern:diff {before} {after}

Compare two traces.

Flags: `--format=json`, `--pretty`.

Findings are matched across the two traces by shape: the SQL fingerprint (or model and relation) plus the call-site file and function, never the line. Editing lines above a query therefore shows one finding that changed, not one resolved and one new. A shape that appears on one side only is `resolved` or `new`; two findings with the same shape in one trace are merged.

Schema `lantern.diff`: `verdict` (issues weighted by severity, new plus regressed minus resolved minus improved: above zero is `regressed`, below zero `improved`; on a tie the query count decides, else `same`), `dimensions` (`queries`, `query_ms`, `findings {resolved new improved regressed verdict}`, each `improved|same|regressed`), `before` and `after` (`trace_id route path issue_count`), `queries`, `query_ms`, `duration_ms` (each `before after delta`), `resolved[]`, `new[]`, `changed[]` (every finding present on both sides: `id` of the after finding, `before_id`, `type`, `severity`, `title`, `call_site`, `status` `improved|regressed|unchanged`, and `before` / `after` objects with `severity count total_ms call_site`; status follows total time within the tolerance below, then occurrence count, then severity), `threshold[]` (missing_index on a query shape that ran in both traces: not new), `unchanged[]` (the after side of `changed` entries whose status is `unchanged`). Time dimensions (`query_ms`, `duration_ms`) read `same` within max(`diff.tolerance_pct` of before, `diff.tolerance_ms`), default 20% and 5 ms. Exits 0 on success whatever the verdict.

```
php artisan lantern:diff <before-id> last --format=json
```

## lantern:check

Pass/fail gate for CI and agents.

Flags: `--max-queries=`, `--max-ms=` (per trace), `--fail-on=critical|warning|info` (default critical), `--no-new-issues` (any issue not in the baseline fails; any issue at all when there is no baseline), `--baseline=file`, `--write-baseline=file` (records current ids, exits 0), `--route=` (route name or path), `--path=`, `--status=`, `--app=`, `--all-apps`, `--since=`, `--traces=50` (recent traces inspected), `--limit=20` (violations listed, 1-500), `--format=json`, `--pretty`. Defaults for max-queries, max-ms, fail-on and traces come from `lantern.check.*`.

Baseline file: `{"schema_version":1,"issues":["<id>"]}`.

Resolved issues never fail the gate, including when an older inspected trace still lists the finding and a later clean run resolved it (see [lantern:issues](#lanternissues)).

Schema `lantern.check`: `pass`, `exit_code`, `thresholds`, `scope` (`route since traces_checked issues_checked`), `violation_count`, `truncated`, `violations[]`, `next` (when failing), `hint`. A violation is `{rule: max_queries|max_ms, limit, actual, trace_id, route}` or `{rule: issue|new_issue, issue_id, type, severity, title, call_site}`.

Exit 0 pass, 1 violations, 2 usage/config (bad flag, missing baseline), 3 nothing recorded.

```
php artisan lantern:check --route=/posts --no-new-issues --max-queries=20
php artisan lantern:check --write-baseline=lantern-baseline.json
php artisan lantern:check --baseline=lantern-baseline.json --no-new-issues
```

## lantern:probe {key}

Re-run EXPLAIN for an issue's query with its longest `IN (...)` list resized, to see where the optimizer changes plan. The lists are filled with the most frequent values of the filtered column (one grouped, read-only read ordered by row count, `NULL` skipped), so even a list of one holds a value that has plenty of rows; each smaller list is a prefix of the larger ones. A value with few or no rows gets a different plan from a typical one and would report a change that does not exist. When the column or table cannot be resolved, or has no rows, the numbers 1..N are used instead (`ids_source: synthetic`) and the output says so in `ids_note`. The query itself is never executed, only EXPLAIN.

Flags: `--in-sizes=1,7,20,41,100` (1 to 12 integers between 1 and 5000), `--format=json`, `--pretty`. `key` is an id or a unique prefix of at least 6 characters; the issue needs a stored trace whose query has an `IN (...)` list (a `plan_flip`, `slow_query` or `n_plus_one` finding, for example).

Schema `lantern.probe`: `issue_id`, `sql_shape`, `connection`, `table`, `column`, `ids_source` (`frequent_values|synthetic`), `verdict` (`stable|plan_changes`), `plan_changes[]` (`from_size`, `to_size`, `from`, `to`), `sizes[]` (`in_size` actually used, `requested`, `plan {table key rows full_scan possible_keys summary}`), `hint`, and `ids_note` (only with `synthetic`). When the table has fewer distinct values than requested, `in_size` is lower than `requested`. Bindings and values are not printed.

Exit 0 on success, 2 for a bad `--in-sizes`, an unknown issue, a query with no `IN (...)` list, or a connection where EXPLAIN is unavailable.

```
php artisan lantern:probe 3f9c2a41b7d0 --in-sizes=1,7,20,41,100
```

## lantern:match

Find the code behind a SQL statement seen outside the app, such as an APM span description or a database log line. The statement is normalised with the same rules Lantern uses for its own query spans, after rewriting `%s`, `%d`, `%(name)s` and `:name` placeholders to `?` (`?` and `$1` already work). Literals, case, whitespace, quoting, comments and hints, IN lists of any length and trailing `LIMIT`/`OFFSET` values do not affect the match. Lantern then searches the stored query spans of the most recent traces and groups what it finds by call site, with the issues recorded on those spans.

Flags: `--sql="select ..."` (when omitted and input is piped, the SQL is read from stdin), `--traces=200` (recent traces searched, 1 to 500), `--since=`, `--path=`, `--route=`, `--status=`, `--app=`, `--all-apps` (the same scope as `lantern:issues`), `--include-resolved`, `--limit=5` (1 to 50), `--format=json` (default), `--pretty`.

Schema `lantern.match`: `shape` (the redacted normalised SQL), `fingerprint`, `match_type` (`exact|similar`, of the best match), `traces_scanned`, `total_matches`, `returned`, `truncated`, `matches[]`, `hidden_resolved` (only when resolved issues were left out), `next`, `hint`. Each match has `match_type`, `sql_shape`, `call_site {file, line, function?}`, `occurrences`, `total_ms`, `max_ms`, `avg_ms`, `last_trace_id`, `trace_ids[]` (up to 5), `score` (`similar` only: word overlap from 0 to 1, at least 0.6) and, when an issue was recorded on the query, `issue {id, type, severity, status, title, suggestion}`, `issue_ids[]` and `plan {tables, rows_read, projected, schema_note, ...}` where known. `similar` matches are other stored queries that share most of their tables and columns (for example the production statement adds a condition). Literals from the input are never printed.

Exit 0 on a match, 2 for no SQL, SQL that does not start with `select`, `insert`, `update`, `delete`, `with` and similar, or an unknown option, 3 when nothing matches (the hint says how to widen the search).

```
php artisan lantern:match --sql="select * from users where email = %s and id in (%s, %s)"
echo "select * from users where id = $1" | php artisan lantern:match
```

`lantern:issues --sql="..."` lists only the issues recorded on queries with exactly this shape.

## lantern:schema-snapshot

Capture the index layout of a database as JSON, to compare elsewhere. A local database often lacks indexes production has, so local EXPLAIN plans and timings can describe a plan production never uses. These two commands read the application's database, not Lantern storage, so they work with any `LANTERN_STORAGE_DRIVER`. Supported drivers: MySQL, MariaDB, PostgreSQL, SQLite.

Flags: `--connection=` (default connection when omitted), `--tables=a,b` (default all), `--from-csv=file` (build from a saved `SHOW INDEX` result instead of a connection; the CSV needs its header row with `Table`, `Key_name`, `Seq_in_index`, `Column_name`, `Non_unique`), `--row-counts=orders:838776,cart:2686282` (production row counts to store), `--format=json` (default), `--pretty`.

Schema `lantern.schema-snapshot`: `driver`, `database`, `captured_at`, `table_count`, `tables` (table to `[{name, columns[], unique}]`, columns in index order), `row_counts`. Exit 0, 2 (usage, unreadable CSV, unsupported driver) or 3 (no indexes found for the request).

```
php artisan lantern:schema-snapshot --tables=order_items,orders --row-counts=orders:838776 > prod-schema.json
php artisan lantern:schema-snapshot --from-csv=show-index.csv > prod-schema.json
```

## lantern:schema-diff {snapshot}

List the indexes the snapshot has that the local database lacks, and the reverse. Indexes are compared by table and ordered column list, so the same columns under a different name match.

Flags: `--connection=`, `--format=json` (default), `--pretty`.

Schema `lantern.schema-diff`: `snapshot` (`driver database captured_at tables`), `local` (`driver database`), `in_sync`, `tables_missing_locally[]`, `missing_locally[]` and `extra_locally[]` (`table name columns unique`), `hint`. Only tables present in the snapshot are compared. Exit 0 whatever the result, 2 for a missing or invalid file, 3 for an empty snapshot.

```
php artisan lantern:schema-diff prod-schema.json
```

Set `schema_snapshot` (`LANTERN_SCHEMA_SNAPSHOT`) to the snapshot path to have findings on affected tables note the difference; see [Configuration](CONFIG.md).

## Maintenance commands

Human-readable output, no JSON.

- `lantern:install [--force] [--local]` publishes config and migrations; `--local` also writes `LANTERN_ENABLED=true`, `LANTERN_STORAGE_DRIVER=database` and `LANTERN_KEEP_RATE=1` to `.env`.
- `lantern:install --sql [--connection=] [--since=]` prints the migration SQL for a connection's database driver instead of installing, so teams that apply schema changes by hand can review and run it. It touches nothing: plain SQL on stdout with a comment naming each migration, problems on stderr. `--since=0.4.0` keeps only the migrations first shipped in that version or later (what an upgrading install is missing); exit 2 on a bad `--since` or an unknown connection, 1 when a migration's SQL could not be generated. See [Upgrading](UPGRADING.md).
- `lantern:status` prints enabled state, version, mode, storage driver, storage connection and app name (database driver), keep rate, Laravel and Livewire versions, and warns when traces would be missing (non-database storage, keep rate below 1 locally, enabled under `testing`) or could end up in the wrong database (`LANTERN_DB_CONNECTION` unset).
- `lantern:prune [--days=] [--issues-days=] [--dry-run]` deletes traces and issues past retention (defaults `lantern.retention.days` 7 and `issues_days` 30) and prints the counts.
- `lantern:trace:dump [--last]` prints the latest in-process trace or `storage/lantern/last-trace.json` (needs `LANTERN_DEBUG_WRITE_LAST_TRACE=true`); exits 1 when none is found.
