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
        \Lantern\Detection\Detectors\LazyLoadDetector::class,
    ],

    // Run EXPLAIN on slow queries to detect missing indexes.
    'explain' => env('LANTERN_EXPLAIN', false),

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

    'storage' => [
        // memory | database
        'driver' => env('LANTERN_STORAGE_DRIVER', 'memory'),
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
