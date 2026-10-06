<?php

declare(strict_types=1);

use EduCloud\Core\Kernel;
use EduCloud\Core\Request;

// Front controller: the only PHP entry point reachable over HTTP.
try {
    $app = require dirname(__DIR__) . '/app/bootstrap.php';
} catch (Throwable $e) {
    // Configuration/bootstrap failure: never reveal details to the client.
    error_log('EduCloud bootstrap failure: ' . get_class($e) . ': ' . $e->getMessage());
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Service unavailable.';
    exit;
}

// Canonical base path: Windows serves "/EduCloudLab/" from the same folder, but cookies are path- and
// case-sensitive ("/educloudlab/"), so other spellings are redirected to the canonical one (ADR-013).
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$base = \EduCloud\Core\Url::baseHref();
if ($base !== '' && strncasecmp($uri, $base, strlen($base)) === 0 && strncmp($uri, $base, strlen($base)) !== 0) {
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    header('Location: ' . $base . substr($uri, strlen($base)), true, in_array($method, ['GET', 'HEAD'], true) ? 301 : 308);
    exit;
}

(new Kernel($app))->handle(Request::fromGlobals((string) $app->config->get('app.base_path', '')))->send();
