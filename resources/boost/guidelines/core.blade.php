## Lantern (performance traces)

Lantern records request traces and detects performance issues with `file:line`. Use it when a request is slow, a page runs many queries, or you suspect N+1.

- Enable locally only: `LANTERN_ENABLED=true` and `LANTERN_STORAGE_DRIVER=database` in `.env`, then migrate. Never enable in production without sampling. Bindings are redacted by default; never read `.env` or raw bindings out of traces.
- Reproduce with an in-process feature test or a real request. Seed at least 3 related rows or N+1 will not show.
- Find: `php artisan lantern:issues --format=json --limit=10`. Fix the highest-severity issue first.
- Inspect: `php artisan lantern:issue {id} --format=json`; `php artisan lantern:trace --last --summary --format=json`. Narrow to one endpoint with `--path='api/x/*'`, `--route=`, `--status=5xx`, `--since=1h` on `lantern:trace` and `lantern:issues`; `--all-apps` includes other apps sharing the database.
- Verify after every edit: `php artisan lantern:diff {before} last` and `php artisan lantern:check --route=<route> --no-new-issues`.
- Do not ignore an issue or raise a threshold to pass a check. Fix the query (eager load, index, `select()`).

- Reproduce outside a request: wrap code in `Lantern\Facades\Lantern::record('name', fn () => ..., ['id' => 1])` (own trace with route `record:name`; a plain call when Lantern is off). `php artisan lantern:run probes/x.php [--repeat=5] [--diff=name] [--key=value]` runs a script with Lantern on, local only, rolls back its database writes unless `--commit`, passes extra options as `$args`, and returns the traces as JSON (`rolled_back`, `traces`, `summary`, `diff`). Then `lantern:issues --route='record:*'`. Reproduce the production condition (data shape, fields set in production) or the probe hides the bug. It is not an MCP tool.

- Sign in (local only, for browser flows or authenticated APIs): `php artisan lantern:login <surface> [--user=] [--to=/path] --format=json` prints a one-time link (`url`, 5 minutes, works once; open it in the browser) for session surfaces, or `headers` for sanctum and custom ones. `lantern:login --list` shows surfaces and what is outstanding; `lantern:login --revoke --all` when done. `php artisan lantern:init [--write]` proposes surfaces. In-process code: `Lantern::actingAs('panel')`. It exits 2 outside `APP_ENV=local`. It is not an MCP tool.

Exit codes: 0 ok, 1 `lantern:check` failed, 2 usage/config/storage error (also unknown options), 3 no data. Results carry `next` (suggested next command) and `hint`; follow them.

If the `lantern` MCP server is connected (`php artisan mcp:start lantern`, needs `laravel/mcp`), use its tools instead of the commands: `lantern_issues`, `lantern_issue`, `lantern_trace_summary`, `lantern_diff`, `lantern_check` return the same JSON.

JSON fields (JSON only on stdout; `schema_version` 1, `schema` e.g. `lantern.issues`):
- `issues[].id` stable fingerprint, survives line changes. `type`: n_plus_one, lazy_load, duplicate_query, slow_query, missing_index, index_not_chosen, plan_flip, memory_hog.
- `severity`: critical|warning|info. `status`: open|resolved. An issue whose query ran again without the finding is resolved and hidden from `lantern:issues` unless `--include-resolved` (or `--resolved`); it reopens if the finding fires again. Resolved issues never fail `lantern:check`.
- `call_site.file` relative to base_path, `call_site.line` 1-based: the code to change.
- Starting from a production SQL (APM span, slow-query log): `php artisan lantern:match --sql="..." --format=json` returns `matches[].call_site`, the recorded `issue` and its `plan`; `%s`, `?`, `$1`, `:name` and literal values all work; `match_type: similar` means no stored query has exactly that shape. `lantern:issues --sql="..."` filters issues the same way.
- `occurrences`, `total_ms`, `suggestion` (a hint, not a patch). With `--full` or `lantern:issue`: `evidence.sql_shape` normalized SQL (`sample_sql` is null when identical), `evidence.trace_id` use as BEFORE for `lantern:diff`. Plan findings add `evidence.rows_read`, `evidence.projected` (scan size here vs production) and `evidence.schema_note` (production indexes missing locally) when known. `lantern:schema-snapshot` / `lantern:schema-diff` compare local and production indexes; `lantern:probe {id} --in-sizes=...` re-plans a `plan_flip` query.
- `truncated: true` means more results exist: pass `--offset=<next_offset>` or a higher `--limit`.

For fix recipes per issue type, load the `lantern-debug` skill.
