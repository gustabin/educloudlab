<?php

/**
 * Email outbox worker (M2-T04).
 * Usage:
 *   php scripts/mailer.php                       run continuously (polls every 5 s; Ctrl+C to stop)
 *   php scripts/mailer.php --once                process the due queue once and exit (cron)
 *   php scripts/mailer.php --test=you@example.org [--verbose]
 *                                                send ONE test message now (outside the queue) and print why it
 *                                                failed; --verbose also prints the SMTP dialogue (password masked)
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

$options = getopt('', ['once', 'test:', 'verbose']);
if (isset($options['test'])) {
    $to = (string) $options['test'];
    if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
        fwrite(STDERR, "Usage: php scripts/mailer.php --test=you@example.org [--verbose]\n");
        exit(2);
    }
    $driver = (string) $app->config->get('mail.driver', 'file');
    echo "driver=$driver host=" . (string) $app->config->get('mail.smtp.host') . ' port=' . (int) $app->config->get('mail.smtp.port')
        . ' encryption=' . ((string) $app->config->get('mail.smtp.encryption') ?: 'none') . ' from=' . (string) $app->config->get('mail.from') . "\n";
    $result = $mailer->sendTest($to, isset($options['verbose']), static function (string $line): void {
        echo $line, "\n";
    });
    echo $result['ok'] ? "OK: message handed to the " . ($driver === 'smtp' ? 'SMTP server' : 'file driver (STORAGE_PATH/mail)') . "\n" : "FAILED\n";
    exit($result['ok'] ? 0 : 1);
}

$once = isset($options['once']);
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
