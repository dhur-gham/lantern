# AGENTS.md (Lantern package repo)

Lantern is a Laravel package: request tracing and performance issue detection (N+1, slow queries) with file:line, a dashboard, and agent-friendly CLI output (`lantern:issues|issue|trace|diff|check`, database storage required) and an optional MCP server (`src/Mcp`, needs `laravel/mcp`).

## Setup and tests

PHP 8.4 required. On the dev machine set the env first (see `docs/PROMPT_CONTEXT.md`).

- Package tests: `php vendor/bin/phpunit`
- Demo app tests: `cd demo-app && php artisan test`
- Demo runs in-process only; never `artisan serve`.

## Structure

- `src/` package code (finding model, detectors, CLI commands, store)
- `config/lantern.php` config (reference: `docs/CONFIG.md`); `README.md` user docs
- `resources/boost/` Laravel Boost guideline (`guidelines/core.blade.php`) and skill (`skills/lantern-debug/SKILL.md`)
- `tests/` PHPUnit tests
- `docs/PLAN.md` plan; `docs/research/` verified framework facts; `docs/AGENTS_GUIDE.md` how host apps use Lantern with agents

## Rules

- `declare(strict_types=1)`, `final` classes, sparse comments; match neighbouring code.
- JSON on stdout only, human logs on stderr. Every JSON payload carries `schema`. Keep exit codes: 0 ok, 1 `lantern:check` failed, 2 usage/config/storage/not-found (including unknown options), 3 no data. MCP tools wrap the CLI commands and must return the same JSON.
- Issue `id` is a fingerprint independent of line numbers. Paths relative to base_path, lines 1-based.
- Bindings are redacted by default; never log or emit raw bindings.
- Lantern must be off by default in production and cheap when disabled.
- Add or update tests with every behavior change. Keep `resources/boost/**` in sync with CLI flags and rule names.
- Do not commit, push, or deploy unless the user asks. No AI tool credits in code, comments, or commits.
