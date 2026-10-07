<?php

/**
 * Restores a backup made by scripts/backup.php.
 *
 *   php scripts/restore.php <backup-dir> --dry-run
 *       Verifies checksums, imports db.sql into DB_TEST_NAME and extracts storage.zip into a temporary directory,
 *       then compares table counts and file counts with the manifest. Touches neither the real database nor storage.
 *       (The test database is wiped by the test suite anyway.)
 *
 *   php scripts/restore.php <backup-dir> --confirm=<DB_NAME>
 *       DESTRUCTIVE: replaces DB_NAME and STORAGE_PATH with the backup. Stop Apache, the dispatcher, the scheduler and
 *       the mailer first. The current storage directory is renamed to <STORAGE_PATH>.before-restore-<timestamp>.
 */

declare(strict_types=1);

require __DIR__ . '/lib/backup-common.php';
require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$env = Dotenv\Dotenv::createArrayBacked($root)->load();
$args = array_slice($argv, 1);
$dir = rtrim(str_replace('\\', '/', (string) ($args[0] ?? '')), '/');
$dryRun = in_array('--dry-run', $args, true);
$confirm = '';
foreach ($args as $a) {
    if (str_starts_with($a, '--confirm=')) {
        $confirm = substr($a, 10);
    }
}
if ($dir === '' || !is_file($dir . '/manifest.json')) {
    backup_fail('usage: php scripts/restore.php <backup-dir> --dry-run | --confirm=<DB_NAME>');
}
$manifest = json_decode((string) file_get_contents($dir . '/manifest.json'), true);
if (!is_array($manifest) || !isset($manifest['files'], $manifest['tables'])) {
    backup_fail('invalid manifest.json');
}

// 1. Integrity
foreach ($manifest['files'] as $name => $meta) {
    if (!is_file("$dir/$name") || !hash_equals((string) $meta['sha256'], (string) hash_file('sha256', "$dir/$name"))) {
        backup_fail("$name is missing or its checksum does not match the manifest");
    }
}
echo "checksums OK\n";

$user = (string) ($env['DB_MIGRATOR_USER'] ?? '');
$pass = (string) ($env['DB_MIGRATOR_PASS'] ?? '');
if ($dryRun) {
    $target = (string) ($env['DB_TEST_NAME'] ?? '');
    // The dry run DROPs and recreates every table of the target: it must never be the real database.
    if ($target === '' || $target === ($env['DB_NAME'] ?? '') || ($env['APP_ENV'] ?? '') === 'production') {
        backup_fail('dry run needs a separate DB_TEST_NAME (different from DB_NAME) and a non-production APP_ENV');
    }
} elseif ($confirm !== '' && $confirm === ($env['DB_NAME'] ?? null)) {
    $target = $confirm;
} else {
    backup_fail('refusing to restore: pass --dry-run, or --confirm=<DB_NAME> to overwrite the real database');
}
if ($target === '' || $user === '') {
    backup_fail('DB_TEST_NAME/DB_NAME and DB_MIGRATOR_USER are required in .env');
}

// 2. Validate the storage archive BEFORE touching anything (database or storage).
$zip = new ZipArchive();
if ($zip->open($dir . '/storage.zip') !== true) {
    backup_fail('cannot open storage.zip');
}
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = (string) $zip->getNameIndex($i);
    if (str_contains($name, '..') || str_contains($name, '\\') || str_starts_with($name, '/') || preg_match('#^[A-Za-z]:#', $name) === 1) {
        backup_fail("unsafe path in storage.zip: $name");
    }
}
if ($zip->numFiles !== (int) $manifest['storage_files']) {
    backup_fail("storage.zip has {$zip->numFiles} files; the manifest says {$manifest['storage_files']}");
}

// 3. Database (mysqldump output has DROP TABLE IF EXISTS + CREATE TABLE for every table)
$mysql = backup_tool($env, 'MYSQL_PATH', 'C:/xampp/mysql/bin/mysql.exe');
$defaults = backup_defaults_file($env, $user, $pass);
try {
    backup_run([$mysql, '--defaults-extra-file=' . $defaults, $target], $dir . '/db.sql');
} finally {
    @unlink($defaults);
}
$counts = backup_table_counts($env, $target, $user, $pass);
if ($counts !== $manifest['tables']) {
    backup_fail('table counts differ from the manifest: ' . json_encode(array_diff_assoc($counts, $manifest['tables'])));
}
echo "database restored into $target, table counts match (" . count($counts) . " tables)\n";

// 4. Storage (the live directory is moved aside only now, and moved back if extraction fails)
$storage = rtrim(str_replace('\\', '/', (string) ($env['STORAGE_PATH'] ?? '')), '/');
$destination = $dryRun ? sys_get_temp_dir() . '/educloud-restore-' . bin2hex(random_bytes(4)) : $storage;
$aside = null;
if (!$dryRun && is_dir($storage)) {
    $aside = $storage . '.before-restore-' . gmdate('Ymd-His');
    if (!rename($storage, $aside)) {
        backup_fail('cannot move the current storage directory aside');
    }
    echo "previous storage kept at $aside\n";
}
$extracted = $zip->numFiles;
if (!$zip->extractTo($destination)) {
    if ($aside !== null && !is_dir($storage)) {
        rename($aside, $storage);
        echo "extraction failed: previous storage put back\n";
    }
    backup_fail('cannot extract storage.zip');
}
$zip->close();
echo "storage restored to $destination ($extracted files)\n";

if ($dryRun) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($destination, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($destination);
    echo "DRY RUN OK: the backup is complete and restorable (temporary files removed)\n";
}
