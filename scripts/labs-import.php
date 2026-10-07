<?php

/**
 * Imports lab definitions into the catalog (ADR-008).
 *
 *   php scripts/labs-import.php                      validate + import every labs/LAB-xxx
 *   php scripts/labs-import.php --dry-run [dirs...]  validate only (no database writes)
 *   php scripts/labs-import.php labs/LAB-004         import selected labs
 *
 * Exit code 1 when any lab is invalid or conflicts with an already imported version.
 */

declare(strict_types=1);

use EduCloud\Modules\Labs\LabImporter;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** @var \EduCloud\Core\App $app */
$app = require dirname(__DIR__) . '/app/bootstrap.php';

$args = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args, true);
$dirs = array_values(array_filter($args, static fn (string $a): bool => !str_starts_with($a, '--')));
$dirs = $dirs === [] ? null : array_map(static fn (string $d): string => realpath($d) ?: $d, $dirs);

$failed = false;
foreach ((new LabImporter($app))->import($dirs, $dryRun) as $row) {
    echo str_pad($row['status'], 10) . ' ' . $row['lab'] . "\n";
    foreach ($row['errors'] as $error) {
        echo '           - ' . $error . "\n";
    }
    $failed = $failed || in_array($row['status'], ['invalid', 'conflict'], true);
}
exit($failed ? 1 : 0);
