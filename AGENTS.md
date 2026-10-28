# AGENTS.md (Lantern package repo)

Lantern is a Laravel package: request tracing and performance issue detection (N+1, lazy loading, duplicate/slow queries, missing indexes, memory) with `file:line`, a dashboard, a JSON CLI (`lantern:*`) and an optional MCP server.

## Layout

- `src/Trace` span/trace model and recorder.
- `src/Instrumentation/*` listeners that feed the recorder: `Lifecycle` (request, views, sinks, `PersistSink`), `Query`, `Middleware`, `Eloquent` (lazy load), `Livewire`, `Filament`, `Job`, `Command`.
- `src/Detection` analyzer, `Detectors/*`, `Explain`, `Issue`, `QueryFilter`.
- `src/Storage` `TraceStore`, `DatabaseTraceStore`, `TraceQueries`, `Pruner`, payload codec.
- `src/Sampling` keep/drop decisions.
- `src/Agent` JSON output, `Reader`, `Findings`, `Redactor`, `AgentException`, `AgentCommand` base class.
- `src/Console` artisan commands; `src/Mcp` MCP server and tools (wrap the CLI); `src/Http` dashboard and API.
- `config/lantern.php`, `database/` migrations, `routes/`, `resources/` (views, `boost/` guideline and skill).
- `docs/`: `CONFIG.md`, `CLI.md`, `AGENTS_GUIDE.md`, `COMPATIBILITY.md`.

## Tests

PHP 8.4. From the repo root:

- `vendor/bin/phpunit` runs the default suite (excludes Stress).
- `vendor/bin/phpunit --testsuite Stress` runs stress and overhead scenarios.
- The DB matrix (`tests/Feature/Matrix`) is skipped unless `LANTERN_TEST_DB=pgsql|mysql|mariadb` is set. Optional: `LANTERN_TEST_HOST` (default 127.0.0.1), `LANTERN_TEST_PORT` (35432 pgsql, 33306 mysql, 33307 mariadb), `LANTERN_TEST_DATABASE` (`matrix`), `LANTERN_TEST_USERNAME`, `LANTERN_TEST_PASSWORD` (`secret`).

Add or update tests with every behavior change.

## Style

- `declare(strict_types=1)`, `final` classes, sparse comments; match neighbouring code.
- Keep `docs/CLI.md`, `docs/AGENTS_GUIDE.md` and `resources/boost/**` in sync with CLI flags, rule names and JSON fields.

## Safety invariants

- Lantern must never break a request, job or command: instrumentation and storage failures are swallowed.
- Disabled (`LANTERN_ENABLED=false` or kill switch) means zero registered listeners and no overhead.
- Lantern's own queries (storage, dashboard, CLI reads) are never captured as query spans (`QuerySuspend`).
- Bindings are redacted by default; never log or emit raw bindings.
- Off by default; cheap when sampling drops a request.
- JSON on stdout only, human logs on stderr. Every payload carries `schema_version` and `schema`. Exit codes: 0 ok, 1 `lantern:check` failed, 2 usage/config/storage/not-found (unknown options too), 3 no data. MCP tools must return the same JSON as the CLI.
- Issue `id` is a fingerprint independent of line numbers; paths are relative to `base_path()`, lines 1-based.

## Extending

- New detector: implement `Lantern\Detection\Detector` in `src/Detection/Detectors`, register it in `config/lantern.php` `detectors`, add its type to `IssuesCommand::TYPES`, the docs and the Boost skill, plus a test.
- New instrumentation: add a class under `src/Instrumentation/<Area>` with a static `register($app)`, call it from `LanternServiceProvider` only when enabled, and test that disabled registers nothing.
