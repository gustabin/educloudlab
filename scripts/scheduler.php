<?php

/**
 * Housekeeping (stale jobs, storage of deleted workspaces, expired sessions/rate limits, temp files).
 * Run every 5 minutes, e.g. Windows Task Scheduler:  php C:\xampp\htdocs\educloudlab\scripts\scheduler.php
 */

declare(strict_types=1);

use EduCloud\Modules\Jobs\Maintenance;
use EduCloud\Modules\Observability\Heartbeat;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** @var \EduCloud\Core\App $app */
$app = require dirname(__DIR__) . '/app/bootstrap.php';
$result = (new Maintenance($app))->run();
$app->logger->info('scheduler_run', $result);
(new Heartbeat($app, 'scheduler'))->tick($result, true);
echo gmdate('c') . ' ' . json_encode($result) . "\n";
