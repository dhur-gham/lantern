## Lantern (performance traces)

Lantern records request traces and detects performance issues with `file:line`. Use it when a request is slow, a page runs many queries, or you suspect N+1.

- Enable locally only: `LANTERN_ENABLED=true` in `.env`. Never enable in production without sampling. Bindings are redacted by default; never read `.env` or raw bindings out of traces.
- Reproduce with an in-process feature test or `php artisan lantern:replay /route`. Seed at least 3 related rows or N+1 will not show.
- Find: `php artisan lantern:issues --format=json --limit=10`. Fix the highest-severity open issue first.
- Inspect: `php artisan lantern:issue {id} --format=json`; `php artisan lantern:trace --latest --summary`.
- Verify after every edit: `php artisan lantern:diff {before} {after}` and `php artisan lantern:check --route=<route> --no-new-issues --format=json`.
- Do not ignore an issue or raise a threshold to pass a check. Fix the query (eager load, index, `select()`).

Exit codes: 0 ok, 1 findings at or above `--fail-on`, 2 usage/config error, 3 no data (Lantern disabled or store empty).

JSON fields (`schema: lantern.issues/v1`, JSON only on stdout):
- `issues[].id` stable fingerprint, survives line changes. `rule`: n_plus_one, lazy_load, duplicate_query, slow_query, missing_index, memory_hog.
- `severity`: note|warning|error. `status`: open|resolved|ignored.
- `location.file` relative to base_path, `location.line` 1-based: the code to change.
- `evidence.count` executions, `total_ms`, `sql_shape` normalized SQL, `example_trace_id` use as BEFORE for `lantern:diff`.
- `suggest.hint` is a hint, not a patch; check `suggest.confidence`.
- `truncated: true` means more results exist: pass `--cursor=<next_cursor>` or a higher `--limit`.

For fix recipes per rule, load the `lantern-debug` skill.
