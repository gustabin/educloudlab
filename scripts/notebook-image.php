<?php

/**
 * Builds the notebook sandbox image (M8, ADR-010) from worker/notebook (pinned base digest + hashed requirements).
 * Usage: php scripts/notebook-image.php build | status
 * After building, run the isolation suite before enabling NOTEBOOKS_MODE=docker:
 *   vendor/bin/phpunit --testsuite Sandbox
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$action = $argv[1] ?? 'status';
$context = dirname(__DIR__) . '/worker/notebook';
$command = match ($action) {
    'build' => ['docker', 'build', '--pull=false', '-t', 'educloud-nb:1', $context],
    'status' => ['docker', 'image', 'inspect', '--format', '{{.Id}} {{.Created}} {{.Size}}', 'educloud-nb:1'],
    default => null,
};
if ($command === null) {
    fwrite(STDERR, "Usage: php scripts/notebook-image.php build|status\n");
    exit(2);
}
$process = proc_open($command, [1 => STDOUT, 2 => STDERR], $pipes, null, null, ['bypass_shell' => true]);
exit(is_resource($process) ? proc_close($process) : 1);
