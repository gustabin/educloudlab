<?php

// Router for PHP's built-in server (development/tests only):
//   php -S 127.0.0.1:8099 -t public scripts/dev-router.php
// Serves existing static files under public/ directly; everything else goes to the front controller.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = realpath(__DIR__ . '/../public' . $path);
$public = realpath(__DIR__ . '/../public');
if ($file !== false && is_file($file) && str_starts_with($file, $public . DIRECTORY_SEPARATOR) && !str_ends_with($file, '.php')) {
    return false;
}
require __DIR__ . '/../public/index.php';
