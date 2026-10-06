<?php

/**
 * Execution plane (ADR-005): dispatcher + Python/DuckDB runner limits.
 */

declare(strict_types=1);

/** @var array<string, string> $env */

$root = dirname(__DIR__);
$python = $env['WORKER_PYTHON'] ?? 'worker/.venv/Scripts/python.exe';

return [
    'python' => preg_match('~^([a-zA-Z]:)?[\\\\/]~', $python) === 1 ? $python : $root . '/' . $python,
    'runner' => $root . '/worker/runner.py',
    'worker_dir' => $root . '/worker',
    // Wall-clock timeout per job type (seconds); the dispatcher kills the runner's process tree when exceeded.
    'timeouts' => [
        'profile' => 60,
        'ingest' => 120,
        'cleanup' => 60,
        'sql_query' => 20,   // hard kill; the runner interrupts the query itself after limits.sql_timeout_s
        'transform' => 90,
    ],
    'limits' => [
        'threads' => 2,
        'memory_mb' => 512,
        'max_rows' => 500_000,
        'max_columns' => 100,
        'preview_rows' => 50,
        // SQL Lab (student SQL)
        'sql_max_length' => 20_000,
        'sql_max_rows' => 1000,
        'sql_max_bytes' => 1_500_000,
        'sql_timeout_s' => 10,
        'transform_timeout_s' => 60,
        'sql_max_cell_chars' => 1000,
        'sql_max_columns' => 200,
        'lakehouse_max_mb' => 200,        // per workspace (ingest + transforms)
        'process_memory_mb' => 1280,      // OS cap on the whole runner process (Job Object / RLIMIT_AS)
    ],
    'max_response_bytes' => 2 * 1024 * 1024,
    'heartbeat_seconds' => 5,
];
