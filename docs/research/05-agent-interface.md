# 05 - Agent interface for Lantern

Status legend: [V] verified in a primary source fetched 2026-10-28; [U] unverified / my inference.

## 1. Verified facts

- laravel/mcp [V]: `Tool` subclass with `#[Description]`, `schema(JsonSchema)`, optional `outputSchema()`, `handle(Request): Response`; `Response::structured([...])`, `Response::error()`; annotations `#[IsReadOnly] #[IsIdempotent] #[IsDestructive(false)] #[IsOpenWorld(false)]`; `shouldRegister()` hides a tool; resources via `#[Uri]` + `HasUriTemplate`; `Response::resourceLink()`; `Mcp::local('name', Server::class)` (stdio via `artisan mcp:start`) vs `Mcp::web()`; `ToolSearch` catalog for large toolsets; validation errors should be clear because "AI clients act on them". (laravel.com/docs/13.x/mcp)
- Boost [V]: third-party packages ship `resources/boost/guidelines/core.blade.php` (loaded upfront, keep short) and `resources/boost/skills/{name}/SKILL.md` (frontmatter `name`, `description`; loaded on demand); `boost:install` / `boost:update` discover them from installed packages. Boost writes into a `<laravel-boost-guidelines>` block of CLAUDE.md/AGENTS.md. Boost's own tools: Application Info, Database Query/Schema, Last Error, Read Log Entries, Search Docs, Record Rule, etc. Boost registers Claude Code as `php artisan boost:mcp` (stdio); Codex via `codex mcp add`. (laravel.com/docs/13.x/boost)
- MCP spec 2025-06-18 [V]: tools have `inputSchema`, optional `outputSchema`; if outputSchema given, server MUST return conforming `structuredContent` and SHOULD also serialize JSON into a text block; `tools/list` supports cursor pagination; tool errors use `isError:true` (agent-visible) vs JSON-RPC errors; tool results can include `resource_link`; annotations from untrusted servers must not be trusted; servers MUST validate input, rate limit, sanitize output. (modelcontextprotocol.io/specification/2025-06-18/server/tools)
- Sentry MCP [V partial]: remote `mcp.sentry.dev/mcp`, OAuth, scoping the URL to org/project hides discovery tools; AI-powered `search_issues/search_errors/search_traces/search_logs` (need an LLM provider); "skills" (`inspect`, `triage`, `seer`, `project-management`) gate tool groups; built for "human-in-the-loop coding agents". Individual tool names such as get_issue_details NOT confirmed [U]. (github.com/getsentry/sentry-mcp README)
- Datadog MCP [V]: toolsets (`core`, `apm`, `error-tracking`) selectable at connect, `omit_tools`; `get_datadog_trace`, `search_datadog_spans`, `aggregate_spans`, `apm_latency_bottleneck_summary`, `search_datadog_error_tracking_issues`, `get_datadog_error_tracking_issue`; responses truncated per tool with a hint on how to request more; `max_tokens` on most tools; large traces may be truncated with no way to fetch all spans (a known weakness to avoid). (docs.datadoghq.com/bits_ai/mcp_server, /mcp_server/tools.md)
- Honeycomb MCP [V partial]: mostly read-only, write tools need `mcp:write` scope; attribute-discovery tools (`find_columns`, `get_dataset_columns`); advice to run agent in the codebase and have it save intermediate results to files. (docs.honeycomb.io/integrations/mcp)
- SARIF 2.1.0 [V]: result = `ruleId` (stable), `level`, `message`, `locations[].physicalLocation.artifactLocation.uri` + `region.startLine` (1-based), `partialFingerprints` (versioned keys, for cross-run matching), `fixes[]`. GitHub code scanning uses `primaryLocationLineHash` for dedupe, `note|warning|error`, caps 25,000 results/run, 10 MB gz. (docs.oasis-open.org/sarif/sarif/v2.1.0; docs.github.com code-scanning sarif-support)
- AGENTS.md [V]: plain Markdown at repo root, no required fields, nearest file wins, read by Codex, Cursor, Copilot, Aider, Gemini CLI, etc.; recommended: build/test commands, style, security. llms.txt [V]: H1 title, blockquote summary, H2 link lists, optional "Optional" section, `.md` variants of pages. (agents.md, llmstxt.org)
- [U] Claude Code reads CLAUDE.md not AGENTS.md natively (Boost writes both, which implies this); not verified directly here.
- [U] No primary source found for "agent-friendly CLI" conventions as a standard; the CLI design below is synthesized from the verified patterns above (SARIF fields, Datadog max_tokens/truncation hints, MCP pagination) plus general practice.

## 2. Ranked recommendations

1. CLI with stable JSON first (works for every agent, no setup, Codex/Cursor/Claude all run shell). `lantern:issues --format=json`, `lantern:trace`, `lantern:check`. Highest value per effort.
2. Boost package guideline + skill shipped in the package (`resources/boost/guidelines/core.blade.php`, `resources/boost/skills/lantern-performance/SKILL.md`). Zero-config discovery for Laravel users, tiny. Guideline stays under ~25 lines; the skill carries the verify loop.
3. `lantern:check` as the single verification primitive and CI gate (exit codes + thresholds + baseline compare). This is what makes agent fixes provable.
4. MCP server via laravel/mcp, `Mcp::local('lantern', ...)` (stdio; same transport Boost uses), 5 read-only tools returning `structuredContent`. Adds typed schemas and pagination; but duplicates CLI, so after 1-3. Do not ship web MCP by default (auth surface; MCP spec requires access control + rate limits).
5. SARIF export (`--format=sarif`) so GitHub code scanning / IDEs show N+1 findings inline. Cheap once the finding model exists; fingerprint = hash(rule_id, file, normalized sql shape), not line number.
6. Docs `llms.txt` / `AGENTS.md` for the Lantern repo itself (contributors' agents), low priority.

## 3. Finding model (shared by CLI, MCP, SARIF)

```json
{
  "schema": "lantern.issues/v1",
  "generated_at": "2026-10-28T12:00:00Z",
  "total": 14, "returned": 10, "next_cursor": "eyJvIjoxMH0",
  "truncated": false,
  "issues": [{
    "id": "iss_9f2c",                      // stable fingerprint: hash(rule, file, query_shape)
    "rule": "n_plus_one",                  // n_plus_one | slow_query | duplicate_query | slow_request | memory
    "severity": "error",                   // note|warning|error (SARIF levels)
    "status": "open",                      // open|resolved|ignored
    "title": "N+1: select * from comments where post_id = ?",
    "location": {"file": "app/Http/Controllers/PostController.php", "line": 42, "function": "PostController@index"},
    "evidence": {"count": 51, "total_ms": 83.4, "p95_ms": 2.1, "sql_shape": "select * from `comments` where `post_id` = ?",
                 "example_trace_id": "trc_01h...", "requests_seen": 37, "first_seen": "...", "last_seen": "..."},
    "explain": "Query runs once per post inside a loop; 51 executions in one request.",
    "suggest": {"kind": "eager_load", "hint": "Post::with('comments')", "confidence": "medium"}
  }]
}
```
Rules: paths relative to base_path, 1-based lines (SARIF), `id` independent of line numbers so it survives edits, `suggest` is a hint not a patch (confidence labelled), no raw bindings by default (PII; opt-in `--with-bindings`).

## 4. CLI design

| Command | Flags | Purpose |
|---|---|---|
| `lantern:issues` | `--format=table\|json\|ndjson\|sarif` `--rule=` `--severity=` `--status=open` `--since=24h` `--route=` `--limit=10` `--cursor=` `--fields=` `--compact` | list findings; default limit small (token budget) |
| `lantern:issue {id}` | `--format` | one finding with full evidence + sample queries |
| `lantern:trace {id\|--latest\|--route=}` | `--format` `--depth=` `--min-ms=` `--max-spans=200` `--summary` | trace as span tree; `--summary` returns top-N slow spans + query groups only |
| `lantern:diff {before} {after}` | `--format` | compare two traces/runs: query count, total ms, per-issue resolved/new/regressed |
| `lantern:check` | `--max-queries=` `--max-ms=` `--fail-on=error` `--no-new-issues` `--baseline=file` `--route=` `--format=json\|sarif` | CI/agent gate |
| `lantern:replay` (optional, later) | `{route}` `--method=` | fire a request in-process under Lantern, print trace id |

Conventions:
- JSON only on stdout; human logs on stderr; `--format=json` never emits ANSI; every payload carries `schema` version.
- Exit codes: 0 ok, 1 findings at/above `--fail-on` (check/issues `--exit-code`), 2 usage/config error, 3 no data (Lantern disabled/empty store). Distinct codes let agents branch without parsing.
- Token efficiency: default `--limit=10`, `next_cursor`, `truncated:true` + `hint` string telling the agent which flag to add (mirrors Datadog's truncation hint [V]), `--fields` projection, ndjson for streaming large sets, `--compact` strips `explain`/`suggest`.
- Trace trees: collapse repeated sibling queries into `{"sql_shape":..., "count":51, "total_ms":..}` nodes rather than 51 spans (avoids Datadog-style truncation problem [V]).

## 5. MCP design (laravel/mcp, `Mcp::local('lantern')`, all `#[IsReadOnly] #[IsIdempotent] #[IsOpenWorld(false)]`)

Server `#[Instructions]`: "Lantern: Laravel request traces and performance issues with file:line. Start with lantern_issues; fix; verify with lantern_check."

| Tool | Input | Output (structuredContent) |
|---|---|---|
| `lantern_issues` | `rule?` enum, `severity?` enum, `status?` (default open), `route?` string, `since?` string, `limit?` int 1-50 (default 10), `cursor?` | the section 3 payload |
| `lantern_issue` | `id` required | one issue + `samples[]` (3 trace ids) + `source_snippet` (file lines +-5, so agent avoids a read) |
| `lantern_trace` | `trace_id?` or `latest?:bool` or `route?`; `summary?:bool` default true; `min_ms?`; `max_spans?` | `{trace_id, route, status, duration_ms, query_count, query_ms, groups:[{sql_shape,count,total_ms,location}], spans?:[...]}` |
| `lantern_diff` | `before` trace/run id, `after` trace/run id | `{queries:{before,after,delta}, ms:{...}, resolved:[id], new:[id], unchanged:[id], verdict:"improved\|regressed\|same"}` |
| `lantern_check` | `route?`, `max_queries?`, `max_ms?`, `fail_on?`, `baseline?` | `{pass:bool, violations:[{rule,limit,actual,issue_id}], exit_code}` |

Resources (template): `lantern://trace/{id}`, `lantern://issue/{id}`; tools return `resource_link` items so large detail is fetched on demand [V mechanism]. No write tools in v1 (ignore/resolve stay CLI/UI) - Honeycomb gates writes behind a separate scope [V]. Optionally `shouldRegister()` returns false when `app()->isProduction()` unless `LANTERN_MCP_ENABLED`. Validation messages must name the fix ("`limit` max is 50"). Each tool also returns the JSON as text for clients ignoring structuredContent [V requirement].

## 6. Guideline snippet (`resources/boost/guidelines/core.blade.php`; same text works in AGENTS.md/CLAUDE.md)

```markdown
## Lantern (performance traces)

Lantern records request traces and detects N+1 / slow queries with file:line, stored in the app DB.

- Find problems: `php artisan lantern:issues --format=json --limit=10` (or MCP `lantern_issues`). Fix the highest-severity open issue first; `location` is the code to change.
- Inspect: `php artisan lantern:issue {id} --format=json`; traces: `lantern:trace {id} --summary`.
- After editing, ALWAYS verify: re-run the failing test/request, then `php artisan lantern:check --route=<route> --no-new-issues --format=json`. Exit 0 = fixed; 1 = still failing.
- Do not silence an issue (ignore/threshold bump) to make a check pass; fix the query (eager load, index, select columns).
- Never read `.env`/bindings out of traces; bindings are redacted by default.
```
Skill `lantern-performance/SKILL.md` (frontmatter name/description per Boost [V]) holds the detailed loop (section 7) plus fix recipes per rule (eager load, `withCount`, `chunk`, index migration, `select()`), loaded on demand.

## 7. Before/after verification loop

1. `lantern:issues --format=json --limit=5` -> pick issue `iss_x`, note `evidence.example_trace_id` = BEFORE.
2. `lantern:issue iss_x` -> read `location` + snippet; edit code.
3. Reproduce: run the relevant feature test (Lantern records during tests when `LANTERN_ENABLED=true`; each test run gets a `run_id`) or `lantern:replay /posts`. Prefer in-process tests over a running server.
4. `lantern:diff <before_trace> <after_trace>` -> expect `resolved:[iss_x]`, `new:[]`, `queries.delta < 0`.
5. `lantern:check --route=/posts --no-new-issues --max-queries=15` -> exit 0.
6. CI: same `lantern:check --baseline=lantern-baseline.json --format=sarif > lantern.sarif`; fail on `new` issues only so legacy debt does not block (baseline file committed). Thresholds in `config/lantern.php` (`check.max_queries`, `check.max_ms`, `check.fail_on`) so agent and CI share one definition.

Pitfalls: timing is noisy -> gate on query count and issue presence (deterministic) and use ms only as a loose ceiling or p95 over N runs; tests with seeded data too small to trigger N+1 give false passes -> skill tells agent to seed >=3 related rows.

## 8. Build order

1. Finding model + stable fingerprint + `lantern:issues` / `lantern:issue` JSON (schema v1, exit codes, cursor/limit).
2. `lantern:trace --summary` with collapsed query groups; `lantern:diff`.
3. `lantern:check` (+ baseline, thresholds, exit codes).
4. Boost guideline + SKILL.md (test with a real `boost:install` in demo-app).
5. MCP local server with the five tools (thin wrappers over the same query classes used by the CLI, so one code path).
6. SARIF output; then optional `lantern:replay`, web MCP with auth.

## 9. Sources

- https://laravel.com/docs/13.x/mcp
- https://laravel.com/docs/13.x/boost
- https://modelcontextprotocol.io/specification/2025-06-18/server/tools
- https://github.com/getsentry/sentry-mcp (README), https://mcp.sentry.dev/
- https://docs.datadoghq.com/bits_ai/mcp_server/ , https://docs.datadoghq.com/mcp_server/tools.md
- https://docs.honeycomb.io/integrations/mcp/
- https://docs.oasis-open.org/sarif/sarif/v2.1.0/sarif-v2.1.0.html
- https://docs.github.com/en/code-security/code-scanning/integrating-with-code-scanning/sarif-support-for-code-scanning
- https://agents.md/ , https://llmstxt.org/
- https://agentskills.io (referenced by Boost docs; not fetched)
