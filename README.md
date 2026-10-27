# Lantern (working name)

Laravel package that traces a request end-to-end and detects N+1, slow and duplicate queries and memory growth, with file:line call sites. Status: skeleton only (no tracing yet). See `docs/PLAN.md`.

Requires PHP 8.2+, Laravel 11 or 12.

## Install (path repository)

In the host app's `composer.json`:

```json
"repositories": [
    { "type": "path", "url": "../lantern", "options": { "symlink": true } }
]
```

```
composer require dhur-gham/lantern:@dev
php artisan lantern:install
php artisan lantern:status
```

## Configuration

Published to `config/lantern.php`. Disabled by default; set `LANTERN_ENABLED=true` to enable. `LANTERN_KILL_SWITCH=true` disables it regardless. Other keys: `mode`, `sampling_rate`, `thresholds` (`n_plus_one_min_repeats`=5, `slow_query_ms`=100, `memory_delta_mb`), `ignore_paths`, `storage.connection`, `retention.days`.

## License

MIT
