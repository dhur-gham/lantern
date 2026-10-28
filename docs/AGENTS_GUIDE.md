# Using Lantern with AI coding agents

Lantern gives agents machine-readable performance findings and a way to prove a fix worked. The loop is: find, fix, verify.

## The loop

1. Enable Lantern locally (`LANTERN_ENABLED=true`) and reproduce the slow request in a test or with `lantern:replay`.
2. `php artisan lantern:issues --format=json` lists findings. Each has a stable `id`, a `rule` (n_plus_one, lazy_load, duplicate_query, slow_query, missing_index, memory_hog), and `location.file` / `location.line`.
3. The agent opens that location and fixes it (eager load, index, `select()`, chunking).
4. `lantern:diff {before} {after}` shows what resolved or regressed. `lantern:check --no-new-issues` is the pass/fail gate.

## Exit codes

| Code | Meaning |
|---|---|
| 0 | OK |
| 1 | Findings at or above `--fail-on` |
| 2 | Usage or config error |
| 3 | No data (Lantern disabled or empty store) |

## Laravel Boost

If the host app uses Laravel Boost, `php artisan boost:install` or `boost:update` picks up Lantern's guideline and the `lantern-debug` skill automatically from `resources/boost/`. Nothing else to do.

## Without Boost: copy-paste snippet

Add to the host app's `AGENTS.md` or `CLAUDE.md`:

```markdown
## Lantern (performance traces)

Use Lantern when a request is slow or runs many queries. Enable locally with `LANTERN_ENABLED=true`; never in production without sampling.

- Find: `php artisan lantern:issues --format=json --limit=10`; fix the highest-severity open issue at `location.file:line`.
- Inspect: `php artisan lantern:issue {id} --format=json`, `php artisan lantern:trace --latest --summary`.
- Verify after editing: `php artisan lantern:diff {before} {after}` then `php artisan lantern:check --route=<route> --no-new-issues --format=json` (exit 0 = fixed, 1 = still failing, 3 = no data).
- Fix the query (eager load, index, select columns); never ignore an issue or raise a threshold to pass.
- Seed at least 3 related rows when reproducing, or N+1 will not appear. Bindings are redacted; do not try to recover them.
```

## Safety

- Keep Lantern off in production, or use sampling. Traces are stored in the app database.
- Bindings are redacted by default. Opt-in `--with-bindings` exists for local debugging only.
- Prefer gating on query count and issue presence over milliseconds; timing is noisy.
