# Upgrading

Each section lists what changes in your application when you move to that release. Run `composer update dhur-gham/lantern` first, then follow the steps for every release you are crossing.

Lantern loads its migrations from the package, so `php artisan migrate` is enough after an update. You do not need to publish them again, even if you published them earlier: a published copy only overrides the package file with the same name.

If your team applies schema changes by hand, print the SQL instead of migrating (see [Applying the schema by hand](#applying-the-schema-by-hand)).

## 0.4.1

**Schema.** One new migration, `2024_01_01_000005_add_sql_hash_to_lantern_plan_samples_table`, adds a nullable `sql_hash` column to `lantern_plan_samples`. Run `php artisan migrate`. Until it has run, plan-flip samples keep working but cannot tell that a query's SQL text changed.

**Detectors added after your published config are now picked up.** A config published on 0.3.x keeps its own `detectors` list, which replaced the package default, so detectors added since (currently `PlanFlipDetector`) never ran and nothing said so. From 0.4.1 any detector the package ships that is missing from your list is merged in automatically. To keep one off, list it in the new `disabled_detectors` key:

```php
'disabled_detectors' => [\Lantern\Detection\Detectors\PlanFlipDetector::class],
```

`php artisan lantern:status` lists the active detectors and warns about package detectors that are off and not listed in `disabled_detectors`.

**Behavior changes.**

- `plan_flip` samples now remember a hash of the exact SQL text, hints and comments included. A sample taken from different SQL is discarded, so adding an optimizer hint no longer leaves a stale `plan_flip` finding behind. Samples are also re-explained sooner when a run's time moves far from the stored average (`plan_flip.time_deviation`).
- `missing_index` suggestions keep flag-style columns out of the lead position even when no index covers them and their distinct count is unknown. A column counts as a flag by its name (`is_`, `has_`, `can_`, `should_`, `was_`, `did_`, `need_` prefixes or a `_flag` suffix) or its type (`tinyint`, `bit`, `boolean`, short `enum`). A real distinct count still decides when one is available.
- New opt-in `explain_analyze` (`LANTERN_EXPLAIN_ANALYZE`): runs a flagged query a second time after the request to record `rows_returned`, which `lantern:issue` uses for the rows read against rows returned ratio. It executes SQL, so leave it off in environments where that is not acceptable. See [Configuration](CONFIG.md).

## 0.4.0

**Schema.** Two migrations:

| Migration | Change |
|---|---|
| `2024_01_01_000003_add_app_to_lantern_tables` | Adds a nullable, indexed `app` column to `lantern_traces` and `lantern_issues`. |
| `2024_01_01_000004_create_lantern_plan_samples_table` | Creates `lantern_plan_samples`, which stores one row per query shape and `IN (...)` list size. |

Run `php artisan migrate`. A store that has not been migrated yet falls back to writing traces without the `app` tag.

**Detectors.** `PlanFlipDetector` is new. If you published `config/lantern.php` before 0.4.0, add it to your `detectors` list, or move to 0.4.1 where it is merged in for you. It needs `explain` on.

**The `app` tag.** Traces and issues are tagged with `lantern.app` (`LANTERN_APP`, default `APP_NAME`). The CLI now shows only the current app, plus older rows that have no tag; pass `--all-apps` to see everything or `--app=` to pick one. On issues the tag is last-writer-wins: the issue key does not include the app, so two apps that hit an identical query shape share one row.

**Storage connection.** `LANTERN_DB_CONNECTION=lantern` stores traces in `storage/lantern/lantern.sqlite`, independent of `DB_DATABASE`. The default is unchanged. Moving existing traces is not automatic; run `php artisan migrate` after switching and start fresh, or copy the three `lantern_*` tables yourself.

**New commands.** `lantern:schema-snapshot`, `lantern:schema-diff`, `lantern:probe`, and `--path`, `--route`, `--status`, `--since` filters on `lantern:trace` and `lantern:issues`. See the [CLI reference](CLI.md).

**Output changes to be aware of.**

- `lantern:diff` matches findings by query shape instead of by call-site line, and adds a `changed[]` list with `improved`, `regressed` and `unchanged` entries and before and after values. The existing keys (`resolved`, `new`, `threshold`, `unchanged`) are kept. Moving a query to another line no longer shows as one resolved and one new finding.
- Findings carry a new `shape` field (a line-independent key for the query or relation and its call-site function). Issue ids are unchanged, so a finding stored before the update keeps its id; `lantern:diff` uses `shape` to match findings across a code change.
- A new finding type, `index_not_chosen`, is reported instead of `missing_index` when an index on an involved table exists but the plan did not use it.

## Applying the schema by hand

`lantern:install --sql` prints the migration SQL for a connection's database driver without changing anything. The output is plain SQL on stdout with a comment naming each migration; problems go to stderr.

```bash
# Everything, for the default (or LANTERN_DB_CONNECTION) connection
php artisan lantern:install --sql > lantern.sql

# Only what an existing 0.3.x install is missing
php artisan lantern:install --sql --since=0.4.0 > lantern-upgrade.sql

# SQL written for another connection's driver
php artisan lantern:install --sql --connection=pgsql
```

`--since=` keeps the migrations first shipped in that version or later. Use the first version you do not have yet: on 0.3.x, `--since=0.4.0` gives the `app` columns, the `lantern_plan_samples` table and its later `sql_hash` column; on 0.4.0, `--since=0.4.1` gives only the `sql_hash` column.

On a full output (no `--since`), the `app` columns are part of the create statements, so the separate `add_app` migration is marked as already included and not repeated. Review the file before running it, and run it with your normal change process. The SQL is generated for the connection's driver; `--connection` must name a connection defined in `config/database.php`.
