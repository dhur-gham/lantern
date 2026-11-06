# Lantern

**Find, fix and prove Laravel performance problems, for developers and for AI coding agents.**

Lantern records what your code really does (web requests, Livewire updates, queue jobs, commands, any script), finds the problem, names the exact `file:line`, and proves the fix with a before/after comparison. It is built so an AI agent can run the whole loop on its own.

It is **local-only developer tooling**: off by default, a `require-dev` dependency, and it refuses to run its sign-in features outside `APP_ENV=local`.

| You have | Lantern gives you |
|---|---|
| A slow page or API | The query that is slow, the line that runs it, and why (index not chosen, plan flips, N+1, rows read vs returned) |
| A Sentry slow-query span | `lantern:match`: the code, the finding and the plan behind that exact SQL |
| A page behind a login | `lantern:login`: a local one-time sign-in link, an API token, or app credentials, so an agent can open it |
| A fix | `lantern:diff`: queries and time before and after, and the finding marked resolved only when the fix is real |

---

## Your first 10 minutes

You need PHP 8.2+ and Laravel 11 or 12, on your own machine.

### 1. Install (1 min)

```bash
composer require --dev dhur-gham/lantern
```

> **Keep it on your machine.** Do not commit Lantern's config, migrations or `.env` lines unless your whole team uses it.

### 2. Turn it on (1 min)

Add to your local `.env`:

```dotenv
LANTERN_ENABLED=true
LANTERN_STORAGE_DRIVER=database
LANTERN_KEEP_RATE=1
LANTERN_EXPLAIN=true
LANTERN_DB_CONNECTION=lantern
```

- `LANTERN_KEEP_RATE=1` keeps every request (the default keeps 10% of fast, clean ones).
- `LANTERN_EXPLAIN=true` runs `EXPLAIN` on slow queries so Lantern can say *why* they are slow.
- `LANTERN_DB_CONNECTION=lantern` stores traces in their own SQLite file (`storage/lantern/lantern.sqlite`), so they survive switching your app's database. Without it, Lantern uses your app's default database.

In `phpunit.xml` keep it off for tests:

```xml
<env name="LANTERN_ENABLED" value="false"/>
```

### 3. Create its tables (1 min)

If your project is happy with `php artisan lantern:install && php artisan migrate`, do that.

If you apply schema changes by hand (never run migrations), print the SQL and apply it to Lantern's own SQLite file:

```bash
php artisan lantern:status                      # creates storage/lantern/lantern.sqlite
php artisan lantern:install --sql --connection=lantern 2>/dev/null > /tmp/lantern.sql
sqlite3 storage/lantern/lantern.sqlite < /tmp/lantern.sql
```

Keep Lantern's local files out of git without touching `.gitignore` (add to `.git/info/exclude`):

```
config/lantern.php
config/lantern-access.php
storage/lantern/
```

### 4. Find a problem (2 min)

Open a page or call an endpoint with Lantern on, then:

```bash
php artisan lantern:issues --format=json --limit=5
php artisan lantern:issue <id> --format=json      # the SQL, the file:line, the suggestion
```

Each finding has a type (`n_plus_one`, `duplicate_query`, `slow_query`, `missing_index`, `index_not_chosen`, `plan_flip`, `lazy_load`, `memory_hog`), a severity and the exact call site. Filter with `--path=`, `--route=` (for example `--route='livewire:*EditItem*'`) or `--since=30m`.

### 5. Fix it and prove it (2 min)

Note the trace id of the slow request (`php artisan lantern:trace --last --summary`), fix the code, repeat the **same** request, then:

```bash
php artisan lantern:diff <before-trace-id> last
```

The verdict is `improved`, `same` or `regressed`, with queries and time before and after, which findings are resolved, and which are new. Always `EXPLAIN` the new query too: fewer queries can still mean more rows scanned.

### 6. From a production error to the code (1 min)

Copy the slow query from Sentry (placeholders like `%s`, `?` and `$1`, and IN lists of any length, are fine):

```bash
php artisan lantern:match --sql="select ... where mr_id in (%s, %s, %s)"
```

You get the call site, the finding and the plan for that shape. If nothing matches, reproduce the request locally first, then run it again.

### 7. Let an agent reach pages behind a login (2 min)

```bash
php artisan lantern:init --write                 # proposes ways in, writes config/lantern-access.php
php artisan lantern:login --list                 # what is available
php artisan lantern:login panel --to=/items      # a one-time sign-in link (5 min, single use)
php artisan lantern:login api                    # a short-lived API token and headers
```

Open the link in a browser the agent controls (for example headless Chrome through Playwright), and every request, including each Livewire call, is recorded and named by component and method. If the problem is server work, you do not need a browser at all: use a scenario (next section), or for any other code wrap it:

```bash
php artisan lantern:run probes/edit-item.php --item=123
```

where the script calls `Lantern\Facades\Lantern::record('name', fn () => ...)`. Writes are rolled back on every database connection unless you pass `--commit`.

Fill in `config/lantern-access.php` for anything app-specific (an active tenant in the session, for example). Steps for headless Chrome, custom guards and API-key apps are in the [agents guide](docs/AGENTS_GUIDE.md).

### 8. Repeat an interaction without a browser (2 min)

Describe a Livewire interaction or an API request as data in `lantern/scenarios/orders-page.json`:

```json
{
  "name": "orders-page",
  "surface": "panel",
  "fake": ["queue", "mail", "notification", "http"],
  "steps": [
    { "livewire": "App\\Filament\\Resources\\OrderResource\\Pages\\ListOrders",
      "do": [ {"set": "tableSearch", "value": "10023"}, {"call": "gotoPage", "args": [2, "page"]} ] },
    { "request": "GET", "path": "/api/orders/list-orders", "query": {"type": "all"},
      "headers": {"mr-id": "222"}, "as": {"surface": "api"}, "expect": 200 }
  ]
}
```

```bash
php artisan lantern:scenario run orders-page --repeat=5      # no browser, no login link, rolled back
php artisan lantern:issues --route='record:orders-page*'     # what it triggered
# fix, then prove it against one step of the earlier run
php artisan lantern:scenario run orders-page --diff='orders-page:01:mount'
```

Each step is its own trace, a response of 400 or above fails the run (so an error page is never measured), and only the database is rolled back, so list what to fake for write requests. It runs the server work only: use the browser flow above for Alpine. Uploads (`upload`, `files`), a real API token (`as.via`), a switch for the app's rate limit (`throttle`) and a read-back of every table the run wrote to (`rollback_check`) are in the docs. Format, limits and every key are in [docs/CLI.md](docs/CLI.md#lanternscenario-action-file); the agent guide has the workflow.

---

## Cheat sheet

| Command | Use it to |
|---|---|
| `lantern:status` | Check it is on, which version, where traces are stored |
| `lantern:issues` / `lantern:issue <id>` | List findings / see one with its evidence |
| `lantern:trace <id\|--last>` | Read a recorded request, job or command |
| `lantern:diff <before> <after>` | Prove a fix |
| `lantern:match --sql=...` | Production SQL to code |
| `lantern:probe <issue>` | Re-run `EXPLAIN` at different `IN` list sizes to find where a plan flips |
| `lantern:run <script>` | Record any code without a browser or login |
| `lantern:scenario run <file>` | Replay a Livewire / HTTP interaction from a JSON file, no browser, no login, rolled back |
| `lantern:login <surface>` / `lantern:init` | Give an agent a way into the app, locally |
| `lantern:schema-snapshot` / `lantern:schema-diff` | Compare your local indexes with production's |
| `lantern:check` | A CI-style gate with exit codes |
| `lantern:prune` | Delete old traces |

## Good habits

- **Match production first.** A fast query on a small local database proves nothing. Get production's indexes (`lantern:schema-snapshot --from-csv`) and use a copy with production-like volume before trusting a timing.
- **Reproduce the real input.** Many problems appear only with many rows, many ids in an `IN` list, or a field that is empty locally.
- **Findings by design.** A flagged query is not always a bug (a per-row read can be a deliberate guard). Check the code before you "fix" it.
- **Keep it local.** Never enable it in a shared or production environment.

## More

- [Full documentation](docs/FULL-DOCS.md): everything Lantern does, how it compares with Telescope, Pulse and Debugbar, and its limits
- [Configuration](docs/CONFIG.md) · [Detectors](docs/DETECTORS.md) · [CLI reference](docs/CLI.md) · [How it works](docs/HOW_IT_WORKS.md)
- [Agents guide](docs/AGENTS_GUIDE.md) · [Compatibility](docs/COMPATIBILITY.md) · [Upgrading](docs/UPGRADING.md)

## Contributing

Issues and pull requests are welcome. Then:

```bash
composer install
composer test            # main suite
composer test-stress     # stress and overhead scenarios
```

Open a pull request against `main`. CI runs the tests on PHP 8.3 and 8.4 with Laravel 11 and 12.

## License

MIT. See [LICENSE](LICENSE).
