<?php

return [
    // Master switch. Off by default.
    'enabled' => env('LANTERN_ENABLED', false),

    // Hard kill switch: when true, nothing is recorded even if enabled.
    'kill_switch' => env('LANTERN_KILL_SWITCH', false),

    // dev: capture call sites for every query. profile: only slow/repeated ones.
    'mode' => env('LANTERN_MODE', 'dev'),

    // all: resolve every query's call site. lazy: only repeated or slow queries (backfilled on repeat).
    'call_site_mode' => env('LANTERN_CALL_SITE_MODE', env('LANTERN_MODE', 'dev') === 'dev' ? 'all' : 'lazy'),

    // Fraction of executions to trace (0.0 - 1.0). Slow/error/issue traces are always kept.
    'sampling_rate' => (float) env('LANTERN_SAMPLING_RATE', 1.0),

    'thresholds' => [
        'n_plus_one_min_repeats' => (int) env('LANTERN_N_PLUS_ONE_MIN_REPEATS', 5),
        'duplicate_min_repeats' => (int) env('LANTERN_DUPLICATE_MIN_REPEATS', 2),
        'slow_query_ms' => (int) env('LANTERN_SLOW_QUERY_MS', 100),
        'memory_delta_mb' => (float) env('LANTERN_MEMORY_DELTA_MB', 32),
        // Queries slower than this are candidates for EXPLAIN (when 'explain' is on).
        'explain_min_ms' => (float) env('LANTERN_EXPLAIN_MIN_MS', 0),
        'max_issues_per_trace' => 50,
        // A full scan projected (from production_row_counts or the schema snapshot) to read at least this many rows in
        // production is reported as critical. 0 turns the escalation off.
        'production_scan_rows' => (int) env('LANTERN_PRODUCTION_SCAN_ROWS', 500000),
    ],

    // Framework plumbing that N+1, duplicate, slow-query and missing-index detection skips. A query is skipped when
    // its primary table (after FROM / INTO / UPDATE) is listed, or its SQL matches a pattern (a plain substring, or a
    // regex when it starts with '/').
    'ignore_tables' => ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'migrations', 'password_reset_tokens'],
    'ignore_sql_patterns' => [],

    // Detector classes run against each finished trace; missing classes are skipped.
    'detectors' => [
        \Lantern\Detection\Detectors\NPlusOneDetector::class,
        \Lantern\Detection\Detectors\DuplicateQueryDetector::class,
        \Lantern\Detection\Detectors\SlowQueryDetector::class,
        \Lantern\Detection\Detectors\MemoryHogDetector::class,
        \Lantern\Detection\Detectors\MissingIndexDetector::class,
        \Lantern\Detection\Detectors\PlanFlipDetector::class,
        \Lantern\Detection\Detectors\LazyLoadDetector::class,
    ],

    // Detector classes to turn off. Detectors added in a release newer than your published config are merged into
    // `detectors` automatically; list one here to opt out of it (this also works for the older detectors).
    'disabled_detectors' => [],

    // Run EXPLAIN on slow queries to detect missing indexes.
    'explain' => env('LANTERN_EXPLAIN', false),

    // Also measure how many rows a flagged query returns (so findings show "read 780k to return 41"). This runs the
    // query a second time after the request, in a read-only, time-limited statement, for at most explain_analyze_max
    // queries per trace; queries with locks or other side effects are never replayed. Needs 'explain'. The replay uses
    // the real bindings, held in memory only until the trace is analysed (never stored), so it works with
    // capture_bindings off.
    'explain_analyze' => env('LANTERN_EXPLAIN_ANALYZE', false),
    'explain_analyze_max' => (int) env('LANTERN_EXPLAIN_ANALYZE_MAX', 3),
    'explain_analyze_timeout_ms' => (int) env('LANTERN_EXPLAIN_ANALYZE_TIMEOUT_MS', 2000),

    // plan_flip (needs 'explain'): a plan change or cost growth between IN-list sizes is reported when the larger size
    // takes at least min_ms or reads at least min_rows, and cost grows more than `factor` times faster than the list.
    // Each size is re-EXPLAINed at most every refresh_minutes, or sooner (at most every 5 minutes) when its time moves
    // time_deviation times above or below the stored average; 0 turns that off. Samples taken from a different SQL text
    // (for example after adding an optimizer hint) are discarded.
    'plan_flip' => [
        'factor' => (float) env('LANTERN_PLAN_FLIP_FACTOR', 3),
        'min_ms' => (float) env('LANTERN_PLAN_FLIP_MIN_MS', 10),
        'min_rows' => (int) env('LANTERN_PLAN_FLIP_MIN_ROWS', 1000),
        'refresh_minutes' => (int) env('LANTERN_PLAN_FLIP_REFRESH_MINUTES', 60),
        'time_deviation' => (float) env('LANTERN_PLAN_FLIP_TIME_DEVIATION', 4),
    ],

    // Path to a production index snapshot (php artisan lantern:schema-snapshot > prod-schema.json). When set,
    // findings on tables whose indexes differ locally say so.
    'schema_snapshot' => env('LANTERN_SCHEMA_SNAPSHOT'),

    // Production row counts per table, used to project scans, e.g. ['orders' => 838776]. Overrides the snapshot's.
    'production_row_counts' => [],

    // Record bound values on query spans. null = on in dev mode, off otherwise.
    // Named bindings called password/token/secret are always redacted.
    'capture_bindings' => env('LANTERN_CAPTURE_BINDINGS'),

    // Longer string bindings are truncated to this many characters.
    'binding_max_length' => 200,

    // Also store the normalized SQL on each query span.
    'capture_normalized_sql' => true,

    // Frames inspected when resolving a query's call site (verified: 40-50 needed under lazy loading/Livewire).
    'backtrace_limit' => 50,

    // Directories whose files count as application code for call sites. Views and routes are included so
    // queries made while rendering Blade or inside route closures point at the template/route line.
    'app_paths' => [app_path(), resource_path('views'), base_path('routes')],

    // Number of finished traces kept by the in-memory sink.
    'memory_sink_limit' => (int) env('LANTERN_MEMORY_SINK_LIMIT', 20),

    // Write each finished trace to storage_path('lantern/last-trace.json') for CLI inspection.
    'debug_write_last_trace' => env('LANTERN_DEBUG_WRITE_LAST_TRACE', false),

    // Request paths that are never traced (Str::is patterns). Livewire update requests are traced.
    'ignore_paths' => [
        'lantern*',
        'up',
        '_debugbar*',
        'telescope*',
        'horizon*',
    ],

    // Spans nested deeper than this are dropped from stored/rendered trees (counted in `children_omitted`).
    'max_tree_depth' => (int) env('LANTERN_MAX_TREE_DEPTH', 100),

    // Defaults for `lantern:check` (CI / agent verification gate); CLI flags override. null = no limit.
    'check' => [
        'max_queries' => null,
        'max_ms' => null,
        'fail_on' => 'critical', // lowest severity that fails the gate: info | warning | critical
        'traces' => 50,          // how many recent traces are examined
    ],

    // Queue jobs and artisan commands are traced as their own traces. Loop wrappers (queue:work, schedule:work,
    // serve, ...) are always skipped. Add an `ignore_commands` array (Str::is patterns) to replace the built-in
    // list (lantern:*, migrate*, db:*, make:*, ...); it is left out here so the built-in list applies.
    'trace_jobs' => (bool) env('LANTERN_TRACE_JOBS', true),
    'trace_commands' => (bool) env('LANTERN_TRACE_COMMANDS', true),
    'ignore_jobs' => [],

    // Tail sampling (applied when a trace finishes): slow, 5xx, failed and issue-bearing traces are always kept.
    'keep_slow_ms' => (int) env('LANTERN_KEEP_SLOW_MS', 1000),
    // lantern:diff counts a time change as better or worse only beyond max(pct of before, ms).
    'diff' => [
        'tolerance_pct' => (float) env('LANTERN_DIFF_TOLERANCE_PCT', 20),
        'tolerance_ms' => (float) env('LANTERN_DIFF_TOLERANCE_MS', 5),
    ],

    // null: keep every clean trace in the local environment, 0.1 elsewhere.
    'keep_rate' => env('LANTERN_KEEP_RATE'),

    // Name stored on every trace and issue so apps sharing one database can be told apart; the CLI shows only the
    // current app unless --all-apps is passed. null = config('app.name').
    'app' => env('LANTERN_APP'),

    'storage' => [
        // memory | database
        'driver' => env('LANTERN_STORAGE_DRIVER', 'memory'),
        // Connection for the lantern_* tables. null follows the application's default connection (and so DB_DATABASE).
        // Set LANTERN_DB_CONNECTION=lantern to use a dedicated SQLite file at storage/lantern/lantern.sqlite
        // (created on demand; run php artisan migrate afterwards), or name any connection from config/database.php.
        'connection' => env('LANTERN_DB_CONNECTION'),
        'compress' => env('LANTERN_STORAGE_COMPRESS', true),
        // Traces buffered before a write (flushed at the end of the request regardless).
        'batch_size' => (int) env('LANTERN_STORAGE_BATCH_SIZE', 1),
    ],

    // Developer UI and JSON API (registered only while Lantern is enabled). Gate with Lantern::auth(); the default
    // allows the local environment only.
    'path' => env('LANTERN_PATH', 'lantern'),
    'domain' => env('LANTERN_DOMAIN'),
    'middleware' => ['web'],

    'retention' => [
        'days' => (int) env('LANTERN_RETENTION_DAYS', 7),
        'issues_days' => (int) env('LANTERN_ISSUES_RETENTION_DAYS', 30),
    ],
];
