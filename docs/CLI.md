# CLI reference

Requirements for the agent commands (`issues`, `issue`, `trace`, `diff`, `check`, `run`, `scenario`): `LANTERN_ENABLED=true`, `LANTERN_STORAGE_DRIVER=database` and migrations run. Otherwise they exit 2 with `config_error`.

## Conventions

- Formats: `--format=table|json|ndjson|markdown`. Default is `table` for `issues`, `issue`, `trace`; `json` for `diff` and `check`. Use `json` for tooling. `ndjson` prints one item per line plus a final `{"_meta": ...}` line. `--pretty` pretty-prints json.
- JSON goes to stdout. Every payload has `schema_version` (1) and `schema`.
- `--since` accepts `30m`, `24h`, `7d`, `2w` (units s, m, h, d, w) or a date.
- Trace arguments accept a trace id, `last` or `latest`.
- `--path=` and `--route=` take `Str::is` patterns (`*` matches anything, everything else is literal; a path may omit its leading slash, so `api/otp/*` matches `/api/otp/verify`). `--status=` takes `404` or a class such as `5xx`. Livewire update requests carry the route `livewire:<Component>::<method>` of the main call (the first one that is not a Filament component such as the database notifications, and not a `__lazyLoad`, which ride along in the same batch), with repeater record keys and list positions generalised to `*` (`livewire:Foo::updated.data.rows.record-*.sizes.*`; Filament `callSchemaComponentMethod` appends its first parameter after a `:`), so the same interaction has the same route on every run and `--route='livewire:*Foo*'` selects it. The exact batched calls are in the trace as `livewire_calls` and `livewire_route`, and every generalised route of the request as `livewire_routes`; a finding recorded under any of them can be resolved by that request.
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

Schema `lantern.issue`: `issue` (all fields above, including `status`) and `next`; a resolved issue has a `hint` instead of `next`. `issue.evidence`: `trace_id` (use as BEFORE for diff), `sample_sql` (null when equal to `sql_shape`), `sql_shape` (normalized SQL), `bindings`, `parent`, `avg_ms`. For `missing_index` and `index_not_chosen` findings it also carries, when known: `rows_read` (estimated rows the scans read), `rows_returned` and `read_ratio` (rows read per row returned; only when the returned count is known; otherwise `rows_returned_why` says why: `budget` when `explain_analyze_max` was used up by slower queries, `no_bindings` when the values were unavailable, `timeout` when the replay hit `explain_analyze_timeout_ms` (a `rows_returned_hint` then says to raise `LANTERN_EXPLAIN_ANALYZE_TIMEOUT_MS`), `parse_failed` when the engine's answer could not be read, `failed` when it errored or was not allowed, `unsupported` when no replay is available), `tables` (per table: `table rows key type`), `projected` (scan size here against production: `table local_rows production_rows factor`) and `schema_note` (indexes production has that the local schema lacks). Absent fields are omitted.

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

## Lantern::record() and lantern:run

`Lantern\Facades\Lantern::record(string $name, Closure $fn, array $context = [])` records any code as a trace and returns the closure's result. Outside a request, job or command it starts its own trace with the route `record:<name>`; that trace is always kept, whatever the sampling says. Inside a request, job or command it adds a child span and keeps the enclosing trace. When Lantern is off it just calls the closure, so probes can stay in the code. `$context` (ids and small values) is stored on the trace; strings are truncated and values under keys such as `password` or `token` are redacted.

```php
use Lantern\Facades\Lantern;

Lantern::record('invoice-export', fn () => $exporter->run($invoice), ['invoice' => $invoice->id]);
```

### lantern:run {script}

Runs a PHP file with the application booted and Lantern forced on for the process; every `Lantern::record()` block in it becomes a stored trace, analysed like a request. The file runs with `$args` (array) and `$lantern` in scope. Local only: it exits 2 unless `APP_ENV=local`. It is not exposed over MCP because it executes code.

Writes made by the script are rolled back by default, so a probe can create production-shaped data and leave the database as it was. Pass `--commit` to keep them.

Flags: `--repeat=N` (1 to 1000; runs the script N times and adds a median `summary`), `--commit`, `--diff=<trace id or record name>` (compares the first trace of this run against that stored trace, or the latest stored `record:<name>` trace), `--arg=key=value` (any other `--key=value` or `--flag` is passed to the script as `$args['key']`), `--format`, `--pretty`.

Schema `lantern.run`: `script`, `args`, `repeat`, `rolled_back` (true unless `--commit` or a connection could not be rolled back), `connections` (connection name => whether its writes were rolled back, for every connection the script touched, including ones it opened itself; Lantern's own storage connection is left alone), `status` (`ok` or `failed`; failed when the script threw or a block threw), `traces[]` (`trace_id name status duration_ms query_count query_ms issue_count issues[]`; each issue has `id key type severity title call_site count`), `summary[]` (only with `--repeat` above 1: `name runs median_ms min_ms max_ms median_queries`), `diff` (a `lantern.diff` payload, only with `--diff`), `error` (`class message`, when the script threw; the traces recorded before it are still listed), `next`, `hint`. Exit 0 ok, 1 when the script or a block failed, 2 for usage errors, a missing script, or a non-local environment, 3 when `--diff` names nothing stored.

```
php artisan lantern:run probes/export.php --repeat=5 --customer=42
php artisan lantern:run probes/export.php --diff=invoice-export
php artisan lantern:issues --route='record:*' --format=json
```

## lantern:scenario {action} {file?}

Runs an interaction described as data, inside this process: no browser, no web server, no sign-in link. Local only (`APP_ENV=local`), and every database connection is rolled back unless you pass `--commit`. It is `lantern:run` with a generated script, so the output is the same `lantern.run` JSON (one trace per step, `--repeat` medians, `--diff`).

- `lantern:scenario list` shows the scenario files in `lantern/scenarios/` (change it with `lantern.scenarios.path` / `LANTERN_SCENARIOS_PATH`) and whether each one is valid.
- `lantern:scenario run <file|name> [--repeat=N] [--diff=<trace-id|record-name>] [--commit]` runs one. A bare name resolves to `<directory>/<name>.json`.

A scenario is a JSON object. Unknown keys are rejected, so a typo fails instead of being skipped.

```json
{
  "name": "orders-page",
  "surface": "panel",
  "session": {"current_merchant_id": 222},
  "fake": ["queue", "mail", "notification", "http"],
  "steps": [
    { "livewire": "App\\Filament\\Resources\\OrderResource\\Pages\\ListOrders",
      "mount": {},
      "do": [
        { "set": "tableSearch", "value": "10023" },
        { "call": "gotoPage", "args": [2, "page"] }
      ] },
    { "request": "GET", "path": "/api/orders/list-orders", "query": {"type": "all"},
      "headers": {"mr-id": "222"}, "as": {"surface": "api"}, "expect": 200 }
  ]
}
```

- `surface` / `user` (top level, or `as` on a request step) pick a `lantern.access` surface and sign its user in with `Lantern::actingAs()`. Credentials never go in a scenario: a header whose name contains `auth`, `token`, `secret`, `api-key`, `password`, `cookie`, `csrf`, `xsrf`, `session` or `credential` is refused, and no other part of the file (`json`, `query`, `session`) should hold a secret, because the file lives in your project. `session` values are put in the session before the step.
- A `livewire` step runs `Livewire::test($class, $mount)`, then each `do` item in order: `set` a property, `call` a method with `args`, or `upload` a file into a property (`{"upload": "photo", "file": {"name": "photo.jpg", "width": 2550, "height": 2550, "kb": 3500}}`, or `files` with a list for an array property).
- A `request` step goes through the HTTP kernel (middleware, form requests, tenant checks), as `GET|POST|PUT|PATCH|DELETE` on a path of this app (never a URL). `json` is a JSON body, `form` a form body (strings, as a browser sends it), `query` the query string, `headers` extra headers, such as a tenant header. `files` (POST only) sends a multipart upload: `{"images": [{"name": "a.jpg", "width": 1200, "height": 800}]}`, a field to a file or a list of files, together with the `form` fields; it cannot be combined with `json`.
- A file is generated, never read from disk, with Laravel's fake files. `kind` is `image` (default for `.jpg`, `.jpeg`, `.png`, `.gif`, `.webp`, `.bmp`; a real picture of `width` x `height`, default 640 x 480, needs the PHP `gd` extension) or `file` (empty content, with a `mime`). `kb` (default 100) is the size reported to validation rules, and `name` a plain file name. Limits: 10 files per step or request, 10 MB (`kb` 10,240) and 4,000 pixels per side per file. An upload counts as a write for the warning below.
- `as.via: "token"` (on a request step, with a `sanctum` surface) sends a real Sanctum token and the surface's own headers instead of signing the user in directly, so token lookup and `last_used_at` run as they do for an API client (without it a step skips them and shows about 3 queries fewer than HTTP). The token is deleted after the step, also with `--commit`. A header of the step wins over the surface's.
- Each step starts signed out and with an empty session, so an earlier step's sign-in cannot carry into the next.
- A request that answers 400 or above **fails the run** (with the status and the start of the body), unless the step says `"expect": <status>`. A run that only measured a 422 or 403 would report numbers for an error page.
- Traces are named `<scenario>:<step>:<what>` (`orders-page:01:mount`, `orders-page:01:call gotoPage`, `list-orders:02:GET /api/orders/list-orders`), so `--diff` and `lantern:issues --route='record:orders-page*'` match the same step before and after a change.

Limits and things to know:

- A file is at most 2 MB, a scenario at most 500 steps and a `livewire` step at most 200 `do` items; a longer one is refused so a mistake cannot run for hours.
- The database is rolled back, **nothing else is**. Events, queued jobs, mail, notifications and HTTP calls to other services that a step triggers really happen (a queued job lands in Redis; an outgoing call leaves the machine). Name what to fake with `"fake": [...]` in the scenario, or for every scenario in `lantern.scenarios.fake`; the two lists are added together. Allowed: `queue`, `bus`, `mail`, `notification`, `http`, `storage` (Laravel's own fakes: jobs are recorded and not run, mail and notifications are not sent, every outgoing HTTP call gets an empty 200, and every disk that is not a local one (S3, FTP, SFTP...) becomes a local folder under `storage/framework/testing`, so an uploaded file never reaches a remote bucket; local disks stay real, because code that builds a path from `storage_path('app')` does not find its files under another root, so files a step writes to a local disk stay there). Events are deliberately not fakeable: faking them would also stop model observers and change the query counts. A run with a write request (anything but GET), or a Livewire step that has `set` or `call` actions, adds a single warning to `scenario.warnings` naming the channels among `queue`, `mail`, `notification` and `http` (and `storage`, when the scenario uploads files) that were not faked (`bus` is optional: it only stops synchronously dispatched jobs from running). The output always lists what was faked (`scenario.faked`). Fakes last for the process, which is one command run.
- The app's own rate limiter applies by default, because the request goes through its middleware, and its counters live in the cache, outside the rollback. A scenario that sends more requests than the limit gets a 429 and fails (merchant-portal: a 300-step run stopped at its 121st request), and it uses up the quota of the dev user for a minute. `"throttle": false` (or `lantern.scenarios.throttle` set to `false` for every scenario; the scenario's own value wins) replaces Laravel's `throttle:` middleware, including its Redis variant, with a pass-through for that run: no 429, no quota used, every other middleware still runs. A rate limiter you wrote yourself still applies. The output shows `scenario.throttle`. When to use it: only for a run (or `--repeat`) longer than the app's limit. Keep it on when what you are investigating is login, OTP, 429 responses or rate limiting itself, or when the cache store is the database (the limiter's own reads and writes would then be missing from the query counts). Compare a before and after with the same throttle setting. With it off, `scenario.throttle_skipped` counts how many times the middleware was skipped: 0 means the switch changed nothing.
- Every run reads back what it wrote: before the first write to a table, on any connection, the run records its row count and a checksum of its contents, and after the rollback it reads them again (`scenario.rollback_check`: `ok`, `checked`, `problems`, `unattributed`). The checksum is `CHECKSUM TABLE` on MySQL and MariaDB (tables up to 200,000 rows) and a hash computed in PHP on every other driver (tables up to 20,000 rows); a bigger table gets row counts only and says `not checked`. The run fails (status `failed`, exit 1) when a table differs, when a table cannot be read (a failed read is never counted as a pass; set `lantern.scenarios.verify_rollback` to `false` if a table really cannot be read), or when a statement cannot be rolled back (`CREATE`, `ALTER`, `DROP`, `TRUNCATE` and `RENAME` commit implicitly on MySQL). The tables come from the SQL, so a connection prefix is handled, Lantern's own tables are left out, and no connection is skipped: writes on a connection that Lantern's storage shares with the app are checked too. A write that cannot be attributed to a table (a MySQL multi-table `DELETE t1 FROM t1 JOIN ...`, for example) is listed in `unattributed` and in `scenario.warnings`: the check does not cover it. A row changed by another process during the run would show up too. It is skipped with `--commit` or `lantern.scenarios.verify_rollback` set to `false` (`rollback_check` is then `null`), and it reads the tables with queries that are not recorded, so the traces are the same with or without it. It reads each table twice, once before its first write and once after the rollback (merchant-portal's biggest, `op_items` with 86,000 rows: about 70 ms for the count and 260 ms for the checksum).
- Step failures stop the run and the traces recorded so far are kept; exit code 1. Bad files, unknown surfaces and non-local environments exit 2.

What it does not reproduce: Alpine and other browser JavaScript (including requests it fires, `wire:poll` and `wire:init`), the HTTP round trips of Livewire's browser uploader (the file is set on the component directly), and the content of an uploaded file (a picture is generated, a plain file is empty). A step signed in with `as` skips token validation unless it uses `via: "token"` (measured 21 against 24 queries on a merchant-portal route). Use the browser path for problems that only exist in the browser.

## lantern:login {surface?}

Local-only way into the app for an agent or a developer: it creates the end state of a login (a session, a token, app credentials) without driving the login screen. Available only with `APP_ENV=local`, `LANTERN_ENABLED=true` and `lantern.access.enabled` on; otherwise the sign-in route does not exist and the command exits 2. Surfaces are defined under `lantern.access.surfaces` (see [Configuration](CONFIG.md#access)); `php artisan lantern:init` proposes them. It does not need database storage. It is not an MCP tool, because it mints credentials.

Flags: `--user=` (id or email instead of the surface's default), `--to=` (session surfaces: relative path to open after signing in, for example `/admin/items/1/edit`; absolute URLs are refused), `--list`, `--revoke`, `--all` (with `--revoke`), `--format=json`, `--pretty`.

Surface types:
- `session`: prints a one-time link `/_lantern/access/{token}`. The link is valid for 5 minutes and works once; only a hash of the token is stored. Opening it signs the user in on the surface's guard, starts a fresh session, applies the surface's `session` values and redirects to `--to` (or the surface's `home`). It does not fire the `Login` event unless `lantern.access.fire_login_event` is on, because listeners may send mail or write audit rows. The route refuses requests carrying `X-Forwarded-*`, `Forwarded` or `X-Real-IP` headers, and unknown, used and expired links all answer 404.
- `sanctum`: creates a short-lived Sanctum personal access token (`ttl_minutes`, default 30) and prints the headers to send.
- `custom`: calls your class implementing `Lantern\Access\ProvidesCredentials` (`issue(array $subject)` returns headers, optional query parameters and an optional expiry; `revoke(array $issued)` deletes them) and prints what it returned.

`APP_URL` must have a host; the link is built from it and checked against the host of the last request Lantern recorded (database storage). A mismatch exits 2 with the host to use, because the session cookie would not be sent to the other host. When no recorded request has a host yet there is nothing to compare with: the link is still printed, with `host_verified: false` and a `hint` to open the app in the browser once (or to set `APP_URL` to the address the browser uses), because a wrong `APP_URL` would produce a link whose cookie is never sent. The link works once; the one-time guarantee rests on the cache store reading and deleting the token in one step, which Redis does not do atomically, so in theory two requests at the same instant could both use a link.

Schemas:
- `lantern.login`: `surface`, `type`, `use`, `next`, and by type `url`, `expires_at`, `user {id label}` (session); `headers`, `expires_at`, `user` (sanctum); `headers`, `query`, `expires_at`, `subject` (custom). `host_verified` (session; `false` when no recorded request could confirm the host of `APP_URL`) and `hint` when it is `false`.
- `lantern.login.list` (`--list`): `surfaces[]` (`name type guard usable problem`), `issued[]` (`id surface type user expires_at expired used`), `cache_store`, `hint`.
- `lantern.login.revoke` (`--revoke [surface]` or `--revoke --all`): `revoked` (count). Unused links are invalidated, sessions started by a link are destroyed, Sanctum tokens are deleted and custom credentials are passed to `revoke()`.

Tokens and the list of what was issued live in the cache store (`lantern.access.cache_store`, default store). The `array` store loses them between processes, so use `file`, `redis` or `database`; `lantern:status` warns when it is `array`. Exit 0 ok, 2 for an unknown surface, a missing or unknown user, a bad `--to`, an `APP_URL` problem, or access not available.

```
php artisan lantern:login panel --to=/admin/items/1/edit
php artisan lantern:login api
php artisan lantern:login --list
php artisan lantern:login --revoke --all
```

### Lantern::actingAs()

`Lantern\Facades\Lantern::actingAs('panel')` signs a surface's user in for code running in the same process, from the same recipe, for example before `Livewire::test()` in a probe or a recorded block. Pass a second argument to choose the user. For a `custom` surface the credentials class must also implement `Lantern\Access\ProvidesUser` (`user(array $subject)`). Local only.

## lantern:init

Proposes surfaces for this app from `config/auth.php` and installed packages: a `session` surface for the first session guard (with a default user and, with Filament, the panel path as `home`), a `sanctum` surface when the user model uses `HasApiTokens`, and a `custom` skeleton for any guard Lantern cannot log into (nothing is guessed there). Prints JSON (`lantern.init`: `surfaces`, `php`, `notes`, `written`, `next`). `--write` saves them to `config/lantern-access.php` (an existing file needs `--force`), which Lantern merges into `lantern.access.surfaces`; the main config wins on a clash. Keep that file out of git if it names real accounts. Review the output: app-specific session keys (an active tenant, for example) are yours to fill in.

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
- `lantern:status` prints enabled state, version, mode, storage driver, storage connection and app name (database driver), keep rate, Laravel and Livewire versions, and includes an `access` object (`available`, `surfaces`, `cache_store`, `host_verified`, `warnings`) in `--json`, warns when traces would be missing (non-database storage, keep rate below 1 locally, enabled under `testing`) or could end up in the wrong database (`LANTERN_DB_CONNECTION` unset). `--json` prints `schema`, `enabled`, `version` and `migration_skip` (`null`, `tests-other-connection` or `connection-unverified`). A `connection-unverified` skip means a Lantern migration did nothing because its connection could not be checked, and status warns about it.
- `lantern:prune [--days=] [--issues-days=] [--dry-run]` deletes traces and issues past retention (defaults `lantern.retention.days` 7 and `issues_days` 30) and prints the counts.
- `lantern:trace:dump [--last]` prints the latest in-process trace or `storage/lantern/last-trace.json` (needs `LANTERN_DEBUG_WRITE_LAST_TRACE=true`); exits 1 when none is found.
