# Changelog

All notable changes to `lantern` will be documented in this file.

## 1.0.0 (unreleased)

- **Added `lantern:scenario`** (`run`, `list`): run a Livewire or HTTP interaction described in a JSON file, in the current process, with no browser. Same `lantern.run` output, rollback and `--diff` as `lantern:run`. See [docs/CLI.md](docs/CLI.md#lanternscenario-action-file).
- `fake` list in a scenario and `lantern.scenarios.fake` (queue, bus, mail, notification, http) so a write scenario cannot queue jobs, send mail or call other services for real; the output lists what was faked and warns, naming the channels still real, when write requests or Livewire actions ran without queue, mail, notification and http faked.
- New config key `lantern.scenarios.path` (`LANTERN_SCENARIOS_PATH`), default `lantern/scenarios`.
- **Uploads in scenarios**: a Livewire `upload` action and `files` (with `form`) on a POST request, using generated files (a real picture of the asked size, or an empty file). At most 10 files, 10 MB and 4,000 pixels per side each.
- **`"throttle": false`** (and `lantern.scenarios.throttle`): takes Laravel's `throttle:` middleware out of a scenario, so a long run gets no 429 and uses no quota. The output counts how often it was skipped (`scenario.throttle_skipped`), and the docs say when to keep the limit on.
- **`as.via: "token"`**: a request step can send a real Sanctum token, so token lookup runs as for an API client. Refused with an explanation when the user model and Sanctum use different connections.
- **Rollback check**: every scenario reads back the tables it wrote to after the rollback (row count and a content checksum) and fails if they differ or if a statement commits implicitly (`CREATE`, `ALTER`, `DROP`, `TRUNCATE`). New config key `lantern.scenarios.verify_rollback`; the result is `scenario.rollback_check`.
- **`storage` fake**: `fake` can also take `storage`, which turns every non-local disk into a local test folder so an upload never reaches a remote bucket. A scenario that uploads files without it gets a warning.
- Fixed in the rollback check (from review): it no longer passes when it measured nothing (a table that cannot be read fails the run), a connection prefix is no longer applied twice, `insert or ignore`, `low_priority`, `delayed`, CTE and `MERGE` writes are recognised and the ones it cannot attribute are listed, a Lantern storage connection that is the default connection no longer hides the app's writes, and SQLite and PostgreSQL get a content hash for tables up to 20,000 rows, so a committed update with the same row count is caught there too.
- Fixed: a `fake` list holding an array (`"fake": [[]]`) raised a PHP error instead of a validation error; `as.surface` and `as.user` were not checked to be strings; a query that Lantern's own code ran inside the hook of another query made that query show up as a second, failed span.
