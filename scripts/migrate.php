<?php

/**
 * EduCloud Lab migration runner (M1-T06).
 *
 * Usage:
 *   php scripts/migrate.php status [--test]
 *   php scripts/migrate.php up     [--test]
 *   php scripts/migrate.php down   [--test] [--steps=N]   (default 1; --steps=all rolls back everything)
 *
 * Runs as DB_MIGRATOR_USER against DB_NAME (or DB_TEST_NAME with --test).
 * Migrations: database/migrations/NNNN_name.up.sql + NNNN_name.down.sql.
 * Applied migrations are checksummed; editing an applied file aborts the run.
 * Note: DDL auto-commits in MySQL/MariaDB, so a failed migration is NOT rolled back automatically;
 * fix forward or run its .down.sql manually. Always back up non-test databases first.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$args = array_slice($argv, 1);
$command = $args[0] ?? 'status';
$useTest = in_array('--test', $args, true);
$steps = '1';
foreach ($args as $a) {
    if (str_starts_with($a, '--steps=')) {
        $steps = substr($a, 8);
    }
}

$env = Dotenv\Dotenv::createArrayBacked($root)->load();
$dbName = $useTest ? ($env['DB_TEST_NAME'] ?? '') : ($env['DB_NAME'] ?? '');
$user = $env['DB_MIGRATOR_USER'] ?? '';
$pass = $env['DB_MIGRATOR_PASS'] ?? '';
if ($dbName === '' || $user === '') {
    fwrite(STDERR, "Missing DB_NAME/DB_TEST_NAME or DB_MIGRATOR_USER in .env\n");
    exit(1);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $db = new mysqli($env['DB_HOST'] ?? '127.0.0.1', $user, $pass, $dbName, (int) ($env['DB_PORT'] ?? 3306));
} catch (mysqli_sql_exception $e) {
    fwrite(STDERR, "Cannot connect to $dbName as $user (errno {$e->getCode()}).\n");
    exit(1);
}
$db->set_charset('utf8mb4');
$db->query("SET time_zone = '+00:00'");

$db->query(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        version VARCHAR(150) NOT NULL PRIMARY KEY,
        checksum CHAR(64) NOT NULL,
        applied_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

/** @return array<string, array{up: string, down: string}> */
$discover = static function () use ($root): array {
    $found = [];
    foreach (glob($root . '/database/migrations/*.up.sql') ?: [] as $up) {
        $version = basename($up, '.up.sql');
        if (!preg_match('/^\d{4}_[a-z0-9_]+$/', $version)) {
            fwrite(STDERR, "Invalid migration name: $version\n");
            exit(1);
        }
        $down = substr($up, 0, -7) . '.down.sql';
        if (!is_file($down)) {
            fwrite(STDERR, "Missing down migration for $version\n");
            exit(1);
        }
        $found[$version] = ['up' => $up, 'down' => $down];
    }
    ksort($found, SORT_STRING);
    return $found;
};

/** @return array<string, string> version => checksum */
$applied = static function () use ($db): array {
    $rows = [];
    $res = $db->query('SELECT version, checksum FROM schema_migrations ORDER BY version');
    while ($r = $res->fetch_assoc()) {
        $rows[$r['version']] = $r['checksum'];
    }
    return $rows;
};

$runSql = static function (string $file) use ($db): void {
    $sql = (string) file_get_contents($file);
    $db->multi_query($sql);
    do {
        if ($result = $db->store_result()) {
            $result->free();
        }
    } while ($db->more_results() && $db->next_result());
};

$migrations = $discover();
$done = $applied();

foreach ($done as $version => $checksum) {
    if (isset($migrations[$version]) && hash_file('sha256', $migrations[$version]['up']) !== $checksum) {
        fwrite(STDERR, "Checksum mismatch: $version was modified after being applied. Create a new migration instead.\n");
        exit(1);
    }
}

$target = $dbName;
switch ($command) {
    case 'status':
        foreach ($migrations as $version => $_) {
            printf("[%s] %s\n", isset($done[$version]) ? 'x' : ' ', $version);
        }
        foreach (array_diff_key($done, $migrations) as $version => $_) {
            printf("[?] %s (applied but file missing)\n", $version);
        }
        break;

    case 'up':
        $pending = array_diff_key($migrations, $done);
        if ($pending === []) {
            echo "$target: nothing to migrate.\n";
            break;
        }
        foreach ($pending as $version => $files) {
            try {
                $runSql($files['up']);
            } catch (mysqli_sql_exception $e) {
                fwrite(STDERR, "FAILED $version: {$e->getMessage()}\n");
                exit(1);
            }
            $stmt = $db->prepare('INSERT INTO schema_migrations (version, checksum) VALUES (?, ?)');
            $sum = hash_file('sha256', $files['up']);
            $stmt->bind_param('ss', $version, $sum);
            $stmt->execute();
            echo "$target: applied $version\n";
        }
        break;

    case 'down':
        $toRevert = array_reverse(array_keys($done));
        if ($steps !== 'all') {
            $toRevert = array_slice($toRevert, 0, max(1, (int) $steps));
        }
        foreach ($toRevert as $version) {
            if (!isset($migrations[$version])) {
                fwrite(STDERR, "Cannot revert $version: file missing.\n");
                exit(1);
            }
            try {
                $runSql($migrations[$version]['down']);
            } catch (mysqli_sql_exception $e) {
                fwrite(STDERR, "FAILED reverting $version: {$e->getMessage()}\n");
                exit(1);
            }
            $stmt = $db->prepare('DELETE FROM schema_migrations WHERE version = ?');
            $stmt->bind_param('s', $version);
            $stmt->execute();
            echo "$target: reverted $version\n";
        }
        break;

    default:
        fwrite(STDERR, "Unknown command '$command'. Use status|up|down.\n");
        exit(1);
}
