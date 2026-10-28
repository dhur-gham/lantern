# Agent workflow results

Live run on 2026-10-28 of the find, fix, verify loop as a fresh agent that only knows `docs/AGENTS_GUIDE.md`. Setup: demo-app served with `php -S` against MySQL 8.4 (Docker), `LANTERN_ENABLED=true LANTERN_STORAGE_DRIVER=database LANTERN_KEEP_RATE=1`, then `/api/posts/n-plus-one`, `/api/posts/fixed`, `/blog` and `/api/posts/slow` requested once each (the fixed route twice). 7 issues recorded. MCP was driven over stdio JSON-RPC (`php artisan mcp:start lantern`, demo-app has no `laravel/mcp`, so the package's own Testbench app served it against the same database).

Token figures are bytes / 4.

## Default output sizes (CLI)

| Command | Bytes | ~Tokens |
|---|---|---|
| `lantern:issues --format=json` (7 issues) | 3365 | 840 |
| `lantern:issues --format=json --fields=id,type,severity,call_site` | 1467 | 370 |
| `lantern:issues --format=json --full` | 6790 | 1700 |
| `lantern:issues --format=markdown` | 2739 | 685 |
| `lantern:issues` (table, default) | 5724 | 1430 |
| `lantern:issue <id> --format=json` | 1170 | 290 |
| `lantern:issue <id>` (table, default) | 6031 | 1510 |
| `lantern:trace <id> --summary --format=json` (N+1 trace, 152 queries) | 2623 | 655 |
| `lantern:trace --last --summary --format=json` (clean trace) | 606 | 150 |
| `lantern:trace --last --tree --format=json` | 1261 | 315 |
| `lantern:trace --last --spans --format=json` | 1755 | 440 |
| `lantern:diff <before> last` | 1382 | 345 |
| `lantern:check` failing (3 violations) | 1248 | 310 |
| `lantern:check` passing | 259 | 65 |

A 7-issue list costs about 120 tokens per issue; the whole find, inspect, fix, diff, check loop is under 2,500 tokens of tool output. The default table format is 2 to 5 times larger than JSON, so the docs now say to always pass `--format=json`.

## MCP sizes (stdio JSON-RPC frames)

| Call | Bytes | ~Tokens |
|---|---|---|
| `initialize` | 512 | 130 |
| `tools/list` (5 tools) | 4150 | 1040 |
| `lantern_issues` | 7291 | 1820 |
| `lantern_issue` | 2691 | 670 |
| `lantern_trace_summary` | 1493 | 370 |
| `lantern_diff` | 3185 | 800 |
| `lantern_check` (pass) | 775 | 195 |
| `lantern_check` (fail) | 2890 | 720 |

Each result is sent twice, as `content[0].text` and as `structuredContent` (what `Response::structured()` does and what the MCP spec recommends), so a frame is about twice the CLI JSON. A client normally reads one of them.

## Sample run

```
$ php artisan lantern:issues --format=json          # trimmed to the first issue
{"schema_version":1,"schema":"lantern.issues","total":7,"returned":7,"offset":0,"truncated":false,
 "next":"php artisan lantern:issue 7045df52a062fa6a1eb22e18112e65a6 --format=json",
 "issues":[{"id":"7045df52...","type":"slow_query","severity":"warning","call_site":{"file":"app/Http/Controllers/Api/PostController.php","line":32,"function":"slow"},"total_ms":227.09,...}, ...]}

$ php artisan lantern:issue 042bce --format=json    # evidence trimmed
{"issue":{"id":"042bce3e...","type":"n_plus_one","severity":"critical","title":"N+1: Post::tags lazy-loaded x50 at app/Http/Resources/PostResource.php:16",
 "call_site":{"file":"app/Http/Resources/PostResource.php","line":16},"evidence":{"trace_id":"db034b8d-...","sample_sql":null,"sql_shape":"select `tags`.*, ... where `post_tag`.`post_id` = ?","bindings":null,"avg_ms":1.35}},
 "next":"Fix call_site, re-run the request, then: php artisan lantern:diff db034b8d-... last"}

$ php artisan lantern:diff db034b8d-0fc7-4bdf-af96-bacb0b492555 last
{"verdict":"improved","before":{"path":"/api/posts/n-plus-one","issue_count":3},"after":{"path":"/api/posts/fixed","issue_count":0},
 "queries":{"before":152,"after":4,"delta":-148},"query_ms":{"before":213.16,"after":10.42,"delta":-202.74},
 "resolved":[3 n_plus_one issues],"new":[],"unchanged":[]}

$ php artisan lantern:check --route=/api/posts/fixed --no-new-issues      # exit 0
{"schema":"lantern.check","pass":true,"exit_code":0,"scope":{"route":"/api/posts/fixed","traces_checked":2,"issues_checked":0},"violation_count":0,...}

$ php artisan lantern:check --route=/api/posts/n-plus-one --no-new-issues # exit 1
{"pass":false,"exit_code":1,"violation_count":3,"violations":[{"rule":"new_issue","issue_id":"042bce3e...","call_site":{...}}, ...],
 "next":"Fix the violations at their call_site, re-run the request, then run this check again."}
```

## Error behaviour (exit codes checked)

| Situation | Exit | Result |
|---|---|---|
| Empty store, `lantern:issues --format=json` | 0 | `total: 0` and a `hint` saying how to record |
| Empty store, `lantern:check --route=/nope` | 3 | `no_data`, hint to exercise the code with storage enabled |
| Memory driver, any agent command | 2 | `config_error`, hint: set `LANTERN_STORAGE_DRIVER=database`, migrate |
| Unknown issue id, trace id | 2 | `not_found` with a hint naming where ids come from |
| Issue id prefix under 6 characters | 2 | `not_found`, hint says a prefix needs 6+ characters |
| `--type=nplus1`, `--severity=bad`, `--fail-on=bad`, `--format=xml`, `--limit=0`, `--fields=zzz` | 2 | `usage_error` listing the valid values |
| Unknown option (`--bogus`), missing argument (`lantern:diff x`) | 2 | `usage_error`, hint `php artisan <command> --help` |
| Lantern tables missing | 2 | `storage_error`, one-line message, hint to migrate |
| MCP: bad input (`limit` 999, `severity` bogus) | tool error | `isError: true`, validation message |
| MCP: unknown tool | JSON-RPC error | `Tool [nope] not found.` |

## Friction found and fixed

- Unknown options and missing arguments exited 1 (Symfony default), indistinguishable from a failed `lantern:check`. They now exit 2 with a JSON `usage_error` in json format.
- A missing table returned the full SQL statement and connection details. The message is now one line and the hint says to run `php artisan migrate` (and what to do when migrate says nothing to migrate because the tables were dropped).
- `--type` and `--severity` accepted any value and returned an empty list; they are validated now.
- No pointer to the next step. `lantern:issues`, `lantern:issue`, `lantern:trace --summary` and a failing `lantern:check` carry `next`; empty results carry `hint`.
- `evidence.sample_sql` repeated `evidence.sql_shape` (about 250 bytes per issue); it is now null when identical.
- Short issue id prefixes and trace-not-found errors did not say why; both hints are explicit now.
- MCP error text and `next` told agents to run `php artisan ...`; they now name the matching `lantern_*` tool.

## Not changed

- The default `table` format stays for human use; agents are told to pass `--format=json`.
- There is no command that lists traces: the BEFORE trace id comes from `lantern:issue` (`evidence.trace_id`), the AFTER is `last`.
- MCP frames carry the payload twice (`text` and `structuredContent`).
