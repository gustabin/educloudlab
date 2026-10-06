<?php

/**
 * Job dispatcher (execution plane, ADR-005). Exactly one instance may run (enforced with a lock file).
 * Usage:
 *   php scripts/dispatcher.php           run continuously (polls every second; Ctrl+C to stop)
 *   php scripts/dispatcher.php --once    process the queue until empty and exit
 */

declare(strict_types=1);

use EduCloud\Modules\Jobs\Dispatcher;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** @var \EduCloud\Core\App $app */
$app = require dirname(__DIR__) . '/app/bootstrap.php';
$once = in_array('--once', $argv, true);

$storage = $app->storage();
$storage->ensureDir($storage->root() . '/locks');
$lock = fopen($storage->root() . '/locks/dispatcher.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another dispatcher is already running.\n");
    exit(1);
}

$dispatcher = new Dispatcher($app, 'dispatcher-' . getmypid());
echo gmdate('c') . " dispatcher started\n";
do {
    $processed = $dispatcher->drain(50);
    if ($processed > 0) {
        echo gmdate('c') . " processed=$processed\n";
    }
    if (!$once) {
        usleep(200_000); // short idle poll: SQL Lab queries are interactive
    }
} while (!$once);

flock($lock, LOCK_UN);
fclose($lock);
