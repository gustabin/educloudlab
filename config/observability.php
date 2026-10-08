<?php

/**
 * Observability (M11b): request metrics, component heartbeats, retention. Platform admins only.
 */

declare(strict_types=1);

/** @var array<string, string> $env */

return [
    // One upsert per request into request_metrics (by route name, never URL). Set OBSERVABILITY_REQUEST_METRICS=0 to disable.
    'request_metrics' => ($env['OBSERVABILITY_REQUEST_METRICS'] ?? '1') !== '0',
    // GET /metrics (Prometheus, M12): disabled unless this holds a random token of at least 32 characters.
    'metrics_token' => (string) ($env['METRICS_TOKEN'] ?? ''),
    // Requests slower than this log a `slow_request` warning (with route name and duration only).
    'slow_request_ms' => 2000,
    'metrics_retention_days' => 14,
    'log_retention_days' => max(1, (int) ($env['LOG_RETENTION_DAYS'] ?? 30)),
    // Workers write a heartbeat at most this often.
    'heartbeat_interval_s' => 15,
    // A component is "down" when its last heartbeat is older than this (scheduler runs every 5 minutes).
    'stale_after_s' => [
        'dispatcher' => 60,
        'dispatcher_notebooks' => 60,
        'mailer' => 120,
        'scheduler' => 900,
    ],
    // Warnings.
    'queue_wait_warning_s' => 60,
    'outbox_warning_s' => 600,
    'disk_free_warning_bytes' => 1_073_741_824,
];
