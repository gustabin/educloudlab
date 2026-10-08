<?php

/**
 * Email outbox worker (M2-T04).
 * Usage:
 *   php scripts/mailer.php           run continuously (polls every 5 s; Ctrl+C to stop)
 *   php scripts/mailer.php --once    process the due queue once and exit
 * With MAIL_DRIVER=file, messages are written to {STORAGE_PATH}/mail/*.eml.
 */

declare(strict_types=1);

use EduCloud\Modules\Email\Mailer;
use EduCloud\Modules\Observability\Heartbeat;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** @var \EduCloud\Core\App $app */
$app = require dirname(__DIR__) . '/app/bootstrap.php';
$mailer = new Mailer($app->db(), $app->config, $app->logger);
$once = in_array('--once', $argv, true);
$heartbeat = new Heartbeat($app, 'mailer');

do {
    [$sent, $failed] = $mailer->processQueue();
    $heartbeat->tick(['sent' => $sent, 'failed' => $failed], $once);
    if ($sent + $failed > 0) {
        echo gmdate('c') . " sent=$sent failed=$failed\n";
    }
    if (!$once) {
        sleep(5);
    }
} while (!$once);
