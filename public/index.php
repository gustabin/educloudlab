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

(new Kernel($app))->handle(Request::fromGlobals())->send();
