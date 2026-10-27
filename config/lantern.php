<?php

return [
    // Master switch. Off by default.
    'enabled' => env('LANTERN_ENABLED', false),

    // Hard kill switch: when true, nothing is recorded even if enabled.
    'kill_switch' => env('LANTERN_KILL_SWITCH', false),

    // dev: capture call sites for every query. profile: only slow/repeated ones.
    'mode' => env('LANTERN_MODE', 'dev'),

    // Fraction of executions to trace (0.0 - 1.0). Slow/error/issue traces are always kept.
    'sampling_rate' => (float) env('LANTERN_SAMPLING_RATE', 1.0),

    'thresholds' => [
        'n_plus_one_min_repeats' => 5,
        'slow_query_ms' => 100,
        'memory_delta_mb' => 8,
    ],

    // Request paths that are never traced (fnmatch patterns).
    'ignore_paths' => [
        'lantern*',
        'up',
        '_debugbar*',
        'telescope*',
        'horizon*',
    ],

    'storage' => [
        'connection' => env('LANTERN_DB_CONNECTION'),
    ],

    'retention' => [
        'days' => (int) env('LANTERN_RETENTION_DAYS', 7),
        'issues_days' => (int) env('LANTERN_ISSUES_RETENTION_DAYS', 30),
    ],
];
