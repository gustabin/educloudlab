<?php

/**
 * Shared helpers for scripts/backup.php and scripts/restore.php (CLI only).
 * Database credentials are passed to the MySQL client tools through a temporary --defaults-extra-file (mode 0600,
 * deleted right after), never on the command line, so they do not appear in process listings.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** Tables counted in the manifest and compared after a restore. */
const BACKUP_CHECK_TABLES = ['users', 'tenants', 'memberships', 'workspaces', 'resources', 'datasets', 'dataset_versions',
    'jobs', 'labs', 'lab_attempts', 'lab_task_results', 'courses', 'enrollments', 'audit_logs', 'schema_migrations'];

/** Storage subdirectories that are never backed up (transient). */
const BACKUP_SKIP_DIRS = ['jobs', 'tmp', 'backups'];

/** @var list<string> $GLOBALS['backup_cleanup'] files removed on any exit (incl. backup_fail) */
$GLOBALS['backup_cleanup'] = [];
register_shutdown_function(static function (): void {
    foreach ($GLOBALS['backup_cleanup'] as $file) {
        @unlink($file);
    }
});

function backup_fail(string $message): never
{
    fwrite(STDERR, "ERROR: $message\n");
    exit(1);
}

/** @param array<string, string> $env */
function backup_defaults_file(array $env, string $user, string $pass): string
{
    $file = tempnam(sys_get_temp_dir(), 'ecdb');
    if ($file === false) {
        backup_fail('cannot create a temporary file');
    }
    $GLOBALS['backup_cleanup'][] = $file;
    chmod($file, 0600);
    $quote = static fn (string $v): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
    file_put_contents($file, "[client]\nuser=" . $quote($user) . "\npassword=" . $quote($pass) . "\nhost=" . $quote($env['DB_HOST'] ?? '127.0.0.1')
        . "\nport=" . (int) ($env['DB_PORT'] ?? 3306) . "\ndefault-character-set=utf8mb4\n");
    return $file;
}

/**
 * Runs a client tool without a shell. stdin/stdout can be redirected to files.
 *
 * @param list<string> $command
 */
function backup_run(array $command, ?string $stdinFile = null, ?string $stdoutFile = null): void
{
    $spec = [
        0 => $stdinFile === null ? ['pipe', 'r'] : ['file', $stdinFile, 'r'],
        1 => $stdoutFile === null ? ['pipe', 'w'] : ['file', $stdoutFile, 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $spec, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        backup_fail('cannot start ' . basename($command[0]));
    }
    if ($stdinFile === null) {
        fclose($pipes[0]);
    }
    if ($stdoutFile === null) {
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
    }
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $code = proc_close($process);
    if ($code !== 0) {
        // Client tools never echo passwords; still, keep only the first line.
        backup_fail(basename($command[0]) . " exited with $code: " . strtok(trim($stderr), "\n"));
    }
}

/**
 * @param array<string, string> $env
 * @return array<string, int>
 */
function backup_table_counts(array $env, string $dbName, string $user, string $pass): array
{
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli($env['DB_HOST'] ?? '127.0.0.1', $user, $pass, $dbName, (int) ($env['DB_PORT'] ?? 3306));
    $counts = [];
    foreach (BACKUP_CHECK_TABLES as $table) {
        $counts[$table] = (int) $db->query("SELECT COUNT(*) FROM `$table`")->fetch_row()[0];
    }
    $db->close();
    return $counts;
}

function backup_tool(array $env, string $key, string $default): string
{
    $tool = $env[$key] ?? $default;
    if (!is_file($tool)) {
        backup_fail("$tool not found (set $key in .env)");
    }
    return $tool;
}
