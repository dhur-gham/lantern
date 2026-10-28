# CLI reference

Requirements for the agent commands (`issues`, `issue`, `trace`, `diff`, `check`): `LANTERN_ENABLED=true`, `LANTERN_STORAGE_DRIVER=database` and migrations run. Otherwise they exit 2 with `config_error`.

## Conventions

- Formats: `--format=table|json|ndjson|markdown`. Default is `table` for `issues`, `issue`, `trace`; `json` for `diff` and `check`. Use `json` for tooling. `ndjson` prints one item per line plus a final `{"_meta": ...}` line. `--pretty` pretty-prints json.
- JSON goes to stdout. Every payload has `schema_version` (1) and `schema`.
- `--since` accepts `30m`, `24h`, `7d`, `2w` (units s, m, h, d, w) or a date.
- Trace arguments accept a trace id, `last` or `latest`.
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

Flags: `--type=` (`n_plus_one|lazy_load|duplicate_query|slow_query|missing_index|memory_hog`), `--severity=` (`critical|warning|info`; aliases high, error, warn, medium, low), `--sort=total_ms|occurrences|last_seen` (default `total_ms`), `--since=`, `--limit=10` (1-500), `--offset=0`, `--fields=` (comma list), `--full` (all fields including `evidence`), `--format`, `--pretty`.

Schema `lantern.issues`: `generated_at`; `total` matching; `returned`; `offset`; `truncated`; `next_offset` (when truncated); `total_capped` (total is a lower bound); `next` (command for the first issue); `hint` (empty or truncated results); `issues[]`.

Issue fields: `id` (stable fingerprint), `type`, `severity`, `title`, `call_site` (`file`, `line`, optional `function`), `occurrences`, `count_total` (total repeats), `total_ms`, `max_ms`, `suggestion` (a hint, not a patch), `first_seen`, `last_seen`, `related_trace_ids`, `evidence`. Default fields: `id type severity title call_site occurrences total_ms suggestion`.

```
php artisan lantern:issues --format=json --type=n_plus_one --limit=5
```

## lantern:issue {key}

One issue with evidence. `key` is an id or a unique prefix of at least 6 characters.

Flags: `--with-bindings` (masked values instead of type placeholders; local debugging only), `--fields=`, `--format`, `--pretty`.

Schema `lantern.issue`: `issue` (all fields above) and `next`. `issue.evidence`: `trace_id` (use as BEFORE for diff), `sample_sql` (null when equal to `sql_shape`), `sql_shape` (normalized SQL), `bindings`, `parent`, `avg_ms`.

## lantern:trace {id?}

Show a stored trace (the latest when no id is given).

Flags: `--last`, `--slow` (slowest of the 100 latest), `--summary`, `--tree`, `--spans`, `--min-ms=` (spans), `--depth=6` (tree, 0-50), `--limit=10` (summary groups; spans default 50), `--fields=` (spans: `id parent_id type name start_ms duration_ms self_ms call_site`), `--json` (legacy full trace dump), `--format`, `--pretty`. Use only one of `--summary|--tree|--spans`; an id together with `--last` is a usage error. Without a mode, the table format prints the legacy tree and json prints the summary.

Schemas:
- `lantern.trace.summary`: `trace_id`, `started_at`, `method`, `path`, `route`, `status`, `duration_ms`, `query_count`, `query_ms`, `peak_memory`, `issue_count`, `issues[]` (`id type severity title call_site count total_ms`), `query_groups[]` (`sql_shape count total_ms call_site issue_id`), `truncated`, `next`, `hint`.
- `lantern.trace.spans`: `trace_id`, `total`, `returned`, `truncated`, `hint`, `spans[]` (slowest first).
- `lantern.trace.tree`: `trace_id`, `tree` (nested nodes; repeated sibling queries collapse into `query_group` nodes with `count`, `total_ms`, `sql_shape`; `children_omitted` when depth-limited).

## lantern:diff {before} {after}

Compare two traces.

Flags: `--format=json`, `--pretty`.

Schema `lantern.diff`: `verdict` (issues weighted by severity, new minus resolved: above zero is `regressed`, below zero `improved`; on a tie the query count decides, else `same`), `dimensions` (`queries`, `query_ms`, `findings {resolved new verdict}`, each `improved|same|regressed`), `before` and `after` (`trace_id route path issue_count`), `queries`, `query_ms`, `duration_ms` (each `before after delta`), `resolved[]`, `new[]`, `unchanged[]` (issues). Exits 0 on success whatever the verdict.

```
php artisan lantern:diff <before-id> last --format=json
```

## lantern:check

Pass/fail gate for CI and agents.

Flags: `--max-queries=`, `--max-ms=` (per trace), `--fail-on=critical|warning|info` (default critical), `--no-new-issues` (any issue not in the baseline fails; any issue at all when there is no baseline), `--baseline=file`, `--write-baseline=file` (records current ids, exits 0), `--route=` (route name or path), `--since=`, `--traces=50` (recent traces inspected), `--limit=20` (violations listed, 1-500), `--format=json`, `--pretty`. Defaults for max-queries, max-ms, fail-on and traces come from `lantern.check.*`.

Baseline file: `{"schema_version":1,"issues":["<id>"]}`.

Schema `lantern.check`: `pass`, `exit_code`, `thresholds`, `scope` (`route since traces_checked issues_checked`), `violation_count`, `truncated`, `violations[]`, `next` (when failing), `hint`. A violation is `{rule: max_queries|max_ms, limit, actual, trace_id, route}` or `{rule: issue|new_issue, issue_id, type, severity, title, call_site}`.

Exit 0 pass, 1 violations, 2 usage/config (bad flag, missing baseline), 3 nothing recorded.

```
php artisan lantern:check --route=/posts --no-new-issues --max-queries=20
php artisan lantern:check --write-baseline=lantern-baseline.json
php artisan lantern:check --baseline=lantern-baseline.json --no-new-issues
```

## Maintenance commands

Human-readable output, no JSON.

- `lantern:install [--force] [--local]` publishes config and migrations; `--local` also writes `LANTERN_ENABLED=true`, `LANTERN_STORAGE_DRIVER=database` and `LANTERN_KEEP_RATE=1` to `.env`.
- `lantern:status` prints enabled state, version, mode, storage driver, keep rate, Laravel and Livewire versions, and warns when traces would be missing (non-database storage, keep rate below 1 locally, enabled under `testing`).
- `lantern:prune [--days=] [--issues-days=] [--dry-run]` deletes traces and issues past retention (defaults `lantern.retention.days` 7 and `issues_days` 30) and prints the counts.
- `lantern:trace:dump [--last]` prints the latest in-process trace or `storage/lantern/last-trace.json` (needs `LANTERN_DEBUG_WRITE_LAST_TRACE=true`); exits 1 when none is found.
