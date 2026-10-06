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
    ],
    'limits' => [
        'threads' => 2,
        'memory_mb' => 512,
        'max_rows' => 500_000,
        'max_columns' => 100,
        'preview_rows' => 50,
    ],
    'max_response_bytes' => 2 * 1024 * 1024,
    'heartbeat_seconds' => 5,
];
