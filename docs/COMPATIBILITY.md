# Compatibility matrix

Verified 2026-10-28 with the full package suite (scratch copies, nothing committed).

| Combination | PHP | Result |
|---|---|---|
| Laravel 12.70 + testbench 10.12 + Livewire 4.4 + PHPUnit 11 | 8.4 | pass (220 tests, SQLite) |
| Laravel 11.57 + testbench 9.18 + Livewire 3.8 + PHPUnit 11 | 8.3 | pass (227 tests, 7 DB-matrix skipped) |
| Laravel 12.70 + Filament 4.16 + Livewire 3.8 | 8.4 | pass (236 tests, 7 DB-matrix skipped) |
| PostgreSQL 16 (Docker) | 8.4 and 8.3 | pass |
| MySQL 8.4 (Docker) | 8.4 and 8.3 | pass |
| MariaDB 10.11 (Docker) | 8.4 and 8.3 | pass |
| SQLite (in-memory) | 8.4 and 8.3 | pass |

PHP 8.2 was not run (no 8.2 binary locally). `src/`, `tests/`, `config/` and `database/` were searched for
8.3+/8.4-only syntax (readonly classes, typed class constants, `#[\Override]`, `json_validate`, `array_find`,
`array_any`/`array_all`, `mb_trim`, property hooks, asymmetric visibility): none found.

Laravel 11 resolves only to versions flagged by Composer security advisories; the scratch copy ran with
`audit.block-insecure=false`.

## Database tests

`tests/Feature/Matrix/DatabaseMatrixTest.php` covers migrations up/down, `DatabaseTraceStore` (plain and gzip),
issue upsert, `TraceQueries`, `Pruner` (dry run and delete), `$1` placeholder fingerprints and `ExplainRunner`
plan parsing (full scan versus primary-key lookup, write statements and missing tables rejected). It skips
unless `LANTERN_TEST_DB` is `pgsql`, `mysql` or `mariadb`.

```
docker run -d --name lantern-pg -e POSTGRES_PASSWORD=secret -p 127.0.0.1:35432:5432 postgres:16
docker run -d --name lantern-mysql -e MYSQL_ROOT_PASSWORD=secret -p 127.0.0.1:33306:3306 mysql:8.4
docker run -d --name lantern-maria -e MARIADB_ROOT_PASSWORD=secret -p 127.0.0.1:33307:3306 mariadb:10.11
# create database "matrix" in each, then:
LANTERN_TEST_DB=pgsql php vendor/bin/phpunit tests/Feature/Matrix
```

Defaults: host 127.0.0.1, database `matrix`, password `secret`, ports 35432 / 33306 / 33307. Override with
`LANTERN_TEST_HOST`, `_PORT`, `_DATABASE`, `_USERNAME`, `_PASSWORD`. PostgreSQL needs `pdo_pgsql` enabled.

## Notes

- Livewire 3 and 4 both pass the instrumentation tests; the `call` listener reads only its first two arguments, so
  the differing trailing arguments between the two majors do not matter.
- The PostgreSQL planner chooses a sequential scan for tiny tables, so the explain test seeds 5000 rows and runs
  `ANALYZE` first.
- No source bugs were found in the owned paths.
