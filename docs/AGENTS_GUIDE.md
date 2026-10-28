# Using Lantern with AI coding agents

Lantern gives agents machine-readable performance findings and a way to prove a fix worked. The loop is: find, fix, verify.

Requires `LANTERN_ENABLED=true` and `LANTERN_STORAGE_DRIVER=database` (plus `php artisan migrate`). Without the database driver every agent command exits 2.

## The loop

1. Enable Lantern locally and reproduce the slow request (a feature test that hits the route, or a browser request). Seed at least 3 related rows or N+1 will not appear.
2. `php artisan lantern:issues --format=json --limit=10` lists findings. Each has a stable `id`, a `type` (`n_plus_one`, `lazy_load`, `duplicate_query`, `slow_query`, `missing_index`, `memory_hog`), a `severity` (`critical`, `warning`, `info`) and `call_site.file` / `call_site.line`.
3. `php artisan lantern:issue {id} --format=json` adds `evidence` (`trace_id`, `sample_sql`, `sql_shape`, `bindings`, `parent`, `avg_ms`). `evidence.trace_id` is the BEFORE trace for `lantern:diff`. `evidence.sample_sql` is null when it equals `evidence.sql_shape`. Measured output sizes and sample runs: `docs/AGENT_WORKFLOW_RESULTS.md`.
4. The agent opens the call site and fixes it (eager load, index, `select()`, chunking).
5. Re-run the reproduction, then `php artisan lantern:diff {before} {after}` (`last` is accepted) shows `resolved`, `new`, `unchanged` and query deltas, with verdict `improved`, `regressed` or `same`.
6. `php artisan lantern:check --route=<route name or path> --no-new-issues` is the pass/fail gate.

## Exit codes

| Code | Meaning |
|---|---|
| 0 | OK (`lantern:check` passed) |
| 1 | `lantern:check` found violations |
| 2 | Usage, config, storage or not-found error (including non-database storage) |
| 3 | No data (nothing recorded yet, or none for `--route`) |

Only `lantern:check` returns 1; unknown options and missing arguments exit 2 too. Always pass `--format=json`: the default table is built for humans and is several times larger. JSON goes to stdout; errors in json format are `{"schema_version":1,"error":{"code","message","hint","exit_code"}}`.

## Command flags

- `lantern:issues`: `--type= --severity= --sort=total_ms|occurrences|last_seen --since= --limit=10 --offset=0 --fields= --full --format=table --pretty`. Paging: when `truncated` is true use `next_offset` (`--offset`). Results carry `next` (the suggested next command) and, when empty or truncated, `hint`. `--type` and `--severity` are validated.
- `lantern:issue {key}`: `--with-bindings --fields= --format=table --pretty`; `key` may be a unique prefix of 6+ characters.
- `lantern:trace {id?}`: `--last --slow --summary --tree --spans --min-ms= --depth=6 --limit=10 --fields= --json --format=table --pretty`.
- `lantern:diff {before} {after}`: `--format=json --pretty`.
- `lantern:check`: `--max-queries= --max-ms= --fail-on=critical|warning|info --no-new-issues --baseline= --write-baseline= --route= --since= --traces=50 --limit=20 --format=json --pretty`. With `--baseline=file` only issues missing from the baseline count; `--write-baseline=file` records the current ids and exits 0.
- `--since` accepts `30m`, `24h`, `7d`, `2w` or a date.

## Laravel Boost

If the host app uses Laravel Boost, `php artisan boost:install` or `boost:update` picks up Lantern's guideline (`resources/boost/guidelines/core.blade.php`) and the `lantern-debug` skill (`resources/boost/skills/lantern-debug/SKILL.md`). Agents can also use the MCP server below.

## MCP server

```bash
composer require laravel/mcp --dev
```

Lantern registers a local server named `lantern` on its own once `laravel/mcp` is installed (`Lantern\Mcp\Server::register()`, called from the service provider; nothing to publish). Point the MCP client at it, for example in the project's `.mcp.json`:

```json
{
  "mcpServers": {
    "lantern": { "command": "php", "args": ["artisan", "mcp:start", "lantern"] }
  }
}
```

Run it from the host app's root with `LANTERN_ENABLED=true` and `LANTERN_STORAGE_DRIVER=database` in `.env` (the server reads the same storage as the CLI). Tools, all read-only: `lantern_issues` (`type`, `severity`, `sort`, `since`, `limit`, `offset`, `full`), `lantern_issue` (`id`), `lantern_trace_summary` (`trace_id`, `slow`, `limit`), `lantern_diff` (`before`, `after`), `lantern_check` (`route`, `max_queries`, `max_ms`, `fail_on`, `no_new_issues`, `baseline`, `since`, `limit`). Each returns the same JSON as the matching `--format=json` command (as text and as `structuredContent`); errors come back with `isError: true` and the JSON error object, and a failing `lantern_check` is a normal result with `pass: false`. `next` and `hint` name the matching tool instead of an artisan command.

## Without Boost: copy-paste snippet

Add to the host app's `AGENTS.md` or `CLAUDE.md`:

```markdown
## Lantern (performance traces)

Use Lantern when a request is slow or runs many queries. Enable locally with `LANTERN_ENABLED=true` and `LANTERN_STORAGE_DRIVER=database`; never in production without sampling.

- Find: `php artisan lantern:issues --format=json --limit=10`; fix the highest-severity issue at `call_site.file:line`.
- Inspect: `php artisan lantern:issue {id} --format=json`, `php artisan lantern:trace --last --summary`.
- Verify after editing: `php artisan lantern:diff {before} {after}` then `php artisan lantern:check --route=<route> --no-new-issues` (exit 0 = fixed, 1 = still failing, 3 = no data).
- Fix the query (eager load, index, select columns); never ignore an issue or raise a threshold to pass.
- Seed at least 3 related rows when reproducing, or N+1 will not appear. Bindings are redacted; do not try to recover them.
```

## Safety

- Keep Lantern off in production, or use sampling (`docs/CONFIG.md`). Traces are stored in the app database.
- Bindings and SQL literals are redacted in CLI output. `lantern:issue --with-bindings` shows masked values and is for local debugging only.
- Prefer gating on query count and issue presence over milliseconds; timing is noisy.
