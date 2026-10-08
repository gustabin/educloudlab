<?php

declare(strict_types=1);

/** @var array<string, string> $env */

$appUrl = rtrim($env['APP_URL'] ?? 'http://localhost', '/');
// Sub-directory deployments (e.g. http://localhost/educloudlab): decoded path used to match requests.
$basePath = rtrim(rawurldecode((string) parse_url($appUrl, PHP_URL_PATH)), '/');

return [
    'name' => 'EduCloud Lab',
    // Release version: the VERSION file at the repository root (written into every release archive).
    'version' => is_file(dirname(__DIR__) . '/VERSION') ? trim((string) file_get_contents(dirname(__DIR__) . '/VERSION')) : '0.0.0-dev',
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
