<?php

/**
 * Job dispatcher (execution plane, ADR-005). One instance per role may run (enforced with a lock file).
 * Usage:
 *   php scripts/dispatcher.php               all job types except notebook runs when they have a dedicated worker
 *   php scripts/dispatcher.php --notebooks   dedicated notebook worker (M8): only notebook_run jobs, so long student
 *                                            notebooks never delay SQL Lab queries or lab grading (gate M8-F6)
 *   add --once to process the queue until empty and exit
 */

declare(strict_types=1);

use EduCloud\Modules\Jobs\Dispatcher;
use EduCloud\Modules\Notebooks\DockerSandbox;
use EduCloud\Modules\Observability\Heartbeat;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** @var \EduCloud\Core\App $app */
$app = require dirname(__DIR__) . '/app/bootstrap.php';
$once = in_array('--once', $argv, true);
$notebooks = in_array('--notebooks', $argv, true);
$dedicated = (bool) $app->config->get('execution.notebooks.dedicated_worker', true);

if ($notebooks && !$dedicated) {
    fwrite(STDERR, "NOTEBOOKS_DEDICATED_WORKER=0: the main dispatcher already runs notebooks; do not start --notebooks.\n");
    exit(1);
}

$storage = $app->storage();
$storage->ensureDir($storage->root() . '/locks');
$lock = fopen($storage->root() . '/locks/' . ($notebooks ? 'dispatcher-notebooks.lock' : 'dispatcher.lock'), 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another dispatcher with this role is already running.\n");
    exit(1);
}

if ($notebooks || !$dedicated) {
    // This process owns the notebook sandbox: containers left by a previous crash or stop cannot be legitimately
    // running, so remove them all before claiming jobs (gate M8-F1).
    $reaped = (new DockerSandbox($app->config, $storage))->reapOrphans(true);
    if ($reaped > 0) {
        echo gmdate('c') . " removed $reaped orphaned notebook container(s)\n";
    }
}

$dispatcher = match (true) {
    $notebooks => new Dispatcher($app, 'notebooks-' . getmypid(), ['notebook_run']),
    $dedicated => new Dispatcher($app, 'dispatcher-' . getmypid(), null, ['notebook_run']),
    default => new Dispatcher($app, 'dispatcher-' . getmypid()),
};
echo gmdate('c') . ' dispatcher started' . ($notebooks ? ' (notebooks)' : '') . "\n";
// Liveness for the admin health view (M11b). The process that owns notebooks also reports whether the sandbox can
// run (Docker daemon + image), checked at most once a minute: the web server never talks to Docker itself.
$heartbeat = new Heartbeat($app, $notebooks ? 'dispatcher_notebooks' : 'dispatcher');
$ownsSandbox = ($notebooks || !$dedicated) && $app->config->get('execution.notebooks.mode') === 'docker';
$docker = null;
$dockerCheckedAt = 0;
$total = 0;
do {
    $processed = $dispatcher->drain(50);
    $total += $processed;
    if ($processed > 0) {
        echo gmdate('c') . " processed=$processed\n";
    }
    if ($ownsSandbox && time() - $dockerCheckedAt >= 60) {
        $dockerCheckedAt = time();
        // Short timeout: a hung daemon must not stall job claiming (gate M11b-F3).
        $docker = (new DockerSandbox($app->config, $storage))->available(5);
    }
    $heartbeat->tick(['processed' => $total] + ($ownsSandbox ? ['docker' => $docker] : []));
    if (!$once) {
        usleep(200_000); // short idle poll: SQL Lab queries are interactive
    }
} while (!$once);

flock($lock, LOCK_UN);
fclose($lock);
