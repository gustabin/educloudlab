<?php

/**
 * Consistent backup of the database and the storage directory.
 *
 *   php scripts/backup.php [--out=C:/educloud-backups]
 *
 * Creates <out>/<UTC timestamp>/ with:
 *   db.sql         mysqldump of DB_NAME (single transaction, utf8mb4, no tablespaces) as DB_MIGRATOR_USER
 *   storage.zip    STORAGE_PATH without transient directories (jobs, tmp, backups)
 *   manifest.json  table counts, applied migrations, sizes and SHA-256 of both files (checked by restore.php)
 *
 * The backup contains personal data and password hashes: store it encrypted and access-restricted (DEPLOYMENT.md).
 * Run the dispatcher-idle variant for a strictly consistent lakehouse copy (DuckDB files are copied as they are).
 */

declare(strict_types=1);

require __DIR__ . '/lib/backup-common.php';
require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$env = Dotenv\Dotenv::createArrayBacked($root)->load();
$opts = getopt('', ['out:']);
$storage = rtrim(str_replace('\\', '/', (string) ($env['STORAGE_PATH'] ?? '')), '/');
if ($storage === '' || !is_dir($storage)) {
    backup_fail('STORAGE_PATH is not a directory');
}
$base = rtrim(str_replace('\\', '/', (string) ($opts['out'] ?? dirname($storage) . '/educloud-backups')), '/');
if (str_starts_with($base . '/', $storage . '/')) {
    backup_fail('the backup directory must be outside STORAGE_PATH');
}
$dir = $base . '/' . gmdate('Ymd-His');
if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
    backup_fail("cannot create $dir");
}
$dbName = (string) ($env['DB_NAME'] ?? '');
$user = (string) ($env['DB_MIGRATOR_USER'] ?? '');
$pass = (string) ($env['DB_MIGRATOR_PASS'] ?? '');
if ($dbName === '' || $user === '') {
    backup_fail('DB_NAME and DB_MIGRATOR_USER are required in .env');
}

// 1. Database (tool resolved before the credentials file exists; the file is also removed on any exit)
$mysqldump = backup_tool($env, 'MYSQLDUMP_PATH', 'C:/xampp/mysql/bin/mysqldump.exe');
$defaults = backup_defaults_file($env, $user, $pass);
try {
    backup_run([
        $mysqldump,
        '--defaults-extra-file=' . $defaults,
        '--single-transaction', '--quick', '--routines', '--triggers', '--hex-blob', '--no-tablespaces',
        '--default-character-set=utf8mb4',
        $dbName,
    ], null, $dir . '/db.sql');
} finally {
    @unlink($defaults);
}

// 2. Storage
$zip = new ZipArchive();
if ($zip->open($dir . '/storage.zip', ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
    backup_fail('cannot create storage.zip');
}
$files = 0;
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($storage, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    /** @var SplFileInfo $file */
    $relative = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($storage)), '/');
    if (!$file->isFile() || in_array(explode('/', $relative)[0], BACKUP_SKIP_DIRS, true)) {
        continue;
    }
    $zip->addFile($file->getPathname(), $relative);
    $files++;
}
$zip->close();

// 3. Manifest
$counts = backup_table_counts($env, $dbName, $user, $pass);
$manifest = [
    'created_at' => gmdate('c'),
    'database' => $dbName,
    'tables' => $counts,
    'storage_files' => $files,
    'files' => [
        'db.sql' => ['bytes' => filesize($dir . '/db.sql'), 'sha256' => hash_file('sha256', $dir . '/db.sql')],
        'storage.zip' => ['bytes' => filesize($dir . '/storage.zip'), 'sha256' => hash_file('sha256', $dir . '/storage.zip')],
    ],
];
file_put_contents($dir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo "backup written to $dir ({$counts['users']} users, {$counts['schema_migrations']} migrations, $files storage files)\n";
