## Lantern (performance traces)

Lantern records request traces and detects performance issues with `file:line`. Use it when a request is slow, a page runs many queries, or you suspect N+1.

- Enable locally only: `LANTERN_ENABLED=true` and `LANTERN_STORAGE_DRIVER=database` in `.env`, then migrate. Never enable in production without sampling. Bindings are redacted by default; never read `.env` or raw bindings out of traces.
- Reproduce with an in-process feature test or a real request. Seed at least 3 related rows or N+1 will not show.
- Find: `php artisan lantern:issues --format=json --limit=10`. Fix the highest-severity issue first.
- Inspect: `php artisan lantern:issue {id} --format=json`; `php artisan lantern:trace --last --summary --format=json`.
- Verify after every edit: `php artisan lantern:diff {before} {after}` and `php artisan lantern:check --route=<route> --no-new-issues`.
- Do not ignore an issue or raise a threshold to pass a check. Fix the query (eager load, index, `select()`).

Exit codes: 0 ok, 1 `lantern:check` failed, 2 usage/config/storage error (also unknown options), 3 no data. Results carry `next` (suggested next command) and `hint`; follow them.

If the `lantern` MCP server is connected (`php artisan mcp:start lantern`, needs `laravel/mcp`), use its tools instead of the commands: `lantern_issues`, `lantern_issue`, `lantern_trace_summary`, `lantern_diff`, `lantern_check` return the same JSON.

JSON fields (JSON only on stdout; `schema_version` 1, `schema` e.g. `lantern.issues`):
- `issues[].id` stable fingerprint, survives line changes. `type`: n_plus_one, lazy_load, duplicate_query, slow_query, missing_index, memory_hog.
- `severity`: critical|warning|info.
- `call_site.file` relative to base_path, `call_site.line` 1-based: the code to change.
- `occurrences`, `total_ms`, `suggestion` (a hint, not a patch). With `--full` or `lantern:issue`: `evidence.sql_shape` normalized SQL (`sample_sql` is null when identical), `evidence.trace_id` use as BEFORE for `lantern:diff`.
- `truncated: true` means more results exist: pass `--offset=<next_offset>` or a higher `--limit`.

For fix recipes per issue type, load the `lantern-debug` skill.
