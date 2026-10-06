<?php

declare(strict_types=1);

/** @var array<string, string> $env */

$appUrl = rtrim($env['APP_URL'] ?? 'http://localhost', '/');
// Sub-directory deployments (e.g. http://localhost/EduCloud%20Lab): decoded path used to match requests.
$basePath = rtrim(rawurldecode((string) parse_url($appUrl, PHP_URL_PATH)), '/');

return [
    'name' => 'EduCloud Lab',
    'version' => '0.1.0',
    'env' => $env['APP_ENV'] ?? 'production',
    'debug' => filter_var($env['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL),
    'url' => $appUrl,
    'base_path' => $basePath,
    'locale' => $env['APP_LOCALE'] ?? 'es',
    'storage_path' => rtrim(str_replace('\\', '/', $env['STORAGE_PATH'] ?? ''), '/'),
    'hash_key' => $env['APP_HASH_KEY'] ?? '',
    // Maximum accepted JSON body size for API requests (uploads use multipart and their own limits).
    'max_json_bytes' => 1_048_576,
];
