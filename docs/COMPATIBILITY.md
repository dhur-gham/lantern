# Compatibility

"Tested" means the package test suite was run on that combination. "Reviewed" means the code path was checked against the framework source and covered by unit tests, but not run against a live install.

## Framework and PHP

| Component | Supported | Status |
|---|---|---|
| Laravel | 11.x, 12.x | Tested on both |
| PHP | 8.2 to 8.4 | 8.3 and 8.4 tested. 8.2 is declared in `composer.json` and the code uses no 8.3+ syntax, but the suite was not run on it |
| Livewire | 3.x, 4.x | Tested on both |
| Filament | 4.x, 5.x | 4.x tested in the package suite. 5.x (5.11 with Livewire 4) exercised end to end in a separate seeded application: panel, resource and column attribution, Livewire update requests. Not part of the package's own matrix run |
| Laravel Octane | Supported | Reviewed: per-request state is reset on Octane request, task and tick events. Not run under a live Octane server |
| Queues | Any queue driver | Job events are framework-level, so the driver does not matter. Tested with the sync and database drivers |
| Laravel MCP | Optional (`laravel/mcp` ^1.1) | Tested |

Tested combinations:

| Laravel | PHP | Livewire | Filament |
|---|---|---|---|
| 12 | 8.4 | 4 | none |
| 12 | 8.4 | 3 | 4 |
| 11 | 8.3 | 3 | none |

## Databases

| Database | Status |
|---|---|
| SQLite | Tested on PHP 8.3 and 8.4 |
| MySQL 8.4 | Tested on PHP 8.3 and 8.4 |
| MariaDB 10.11 | Tested on PHP 8.3 and 8.4 |
| PostgreSQL 16 | Tested on PHP 8.3 and 8.4 (needs `pdo_pgsql`) |
| SQL Server | Reviewed only. Query tracing uses Laravel's connection events; `missing_index` (EXPLAIN) is not supported |

Exercised on each tested database: migrations up and down, trace and issue storage (plain and gzip), issue upserts, trace queries, pruning, fingerprinting of the driver's placeholder style (`?` and `$1`), and EXPLAIN plan parsing for `missing_index`.

`missing_index` runs `EXPLAIN` on MySQL, MariaDB and PostgreSQL and `EXPLAIN QUERY PLAN` on SQLite. Only SELECT statements are explained.

## Notes

- Livewire 3 and 4 differ in the trailing arguments of their `call` hook. Lantern reads only the first two, so both behave the same.
- Traces can be stored on any connection Laravel supports (`storage.connection`); `LANTERN_DB_CONNECTION=lantern` uses a dedicated SQLite file and needs `pdo_sqlite`. The dashboard and agent CLI need `storage.driver` set to `database`.
- Laravel 11 currently resolves only to releases that Composer's security audit flags. Use the latest 11.x patch release, or Laravel 12.
