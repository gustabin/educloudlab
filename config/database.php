<?php

declare(strict_types=1);

/** @var array<string, string> $env */

return [
    'host' => $env['DB_HOST'] ?? '127.0.0.1',
    'port' => (int) ($env['DB_PORT'] ?? 3306),
    'name' => $env['DB_NAME'] ?? 'educloud',
    'user' => $env['DB_USER'] ?? '',
    'pass' => $env['DB_PASS'] ?? '',
    'test' => [
        'name' => $env['DB_TEST_NAME'] ?? 'educloud_test',
        'user' => $env['DB_TEST_USER'] ?? '',
        'pass' => $env['DB_TEST_PASS'] ?? '',
    ],
];
