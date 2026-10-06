<?php

declare(strict_types=1);

/** @var array<string, string> $env */

return [
    'name' => 'EduCloud Lab',
    'version' => '0.1.0',
    'env' => $env['APP_ENV'] ?? 'production',
    'debug' => filter_var($env['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL),
    'url' => rtrim($env['APP_URL'] ?? 'http://localhost', '/'),
    'locale' => $env['APP_LOCALE'] ?? 'es',
    'storage_path' => rtrim(str_replace('\\', '/', $env['STORAGE_PATH'] ?? ''), '/'),
    'hash_key' => $env['APP_HASH_KEY'] ?? '',
    // Maximum accepted JSON body size for API requests (uploads use multipart and their own limits).
    'max_json_bytes' => 1_048_576,
];
