<?php

declare(strict_types=1);

/** @var array<string, string> $env */

return [
    // "file": writes .eml files to {storage}/mail (development); "smtp": delivers through SMTP.
    'driver' => $env['MAIL_DRIVER'] ?? 'file',
    'from' => $env['MAIL_FROM'] ?? 'no-reply@educloud.local',
    'from_name' => $env['MAIL_FROM_NAME'] ?? 'EduCloud Lab',
    'smtp' => [
        'host' => $env['SMTP_HOST'] ?? '',
        'port' => (int) ($env['SMTP_PORT'] ?? 587),
        'user' => $env['SMTP_USER'] ?? '',
        'pass' => $env['SMTP_PASS'] ?? '',
        'encryption' => $env['SMTP_ENCRYPTION'] ?? 'tls',
        'timeout' => 15,
    ],
    'max_attempts' => 5,
];
