<?php

/**
 * EduCloud Lab environment checker (M0-T05).
 * Usage: php scripts/check-env.php
 * Exit code 1 if any FAIL. WARN items describe degraded or optional capabilities.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$results = [];

$add = static function (string $status, string $check, string $detail) use (&$results): void {
    $results[] = [$status, $check, $detail];
};

// --- PHP ---------------------------------------------------------------------
// @phpstan-ignore if.alwaysFalse (runtime check: the script may run under a different PHP binary)
if (version_compare(PHP_VERSION, '8.1.6', '<')) {
    $add('FAIL', 'PHP version', PHP_VERSION . ' (need >= 8.1.6)');
} elseif (version_compare(PHP_VERSION, '8.2.0', '<')) {
    $add('WARN', 'PHP version', PHP_VERSION . ' - PHP 8.1 is end-of-life; never expose this install publicly (ADR-001)');
} else {
    $add('OK', 'PHP version', PHP_VERSION);
}

foreach (['mysqli', 'mbstring', 'fileinfo', 'openssl', 'json', 'session', 'intl'] as $ext) {
    $add(extension_loaded($ext) ? 'OK' : 'FAIL', "ext-$ext", extension_loaded($ext) ? 'loaded' : 'missing');
}
if (extension_loaded('xdebug')) {
    $add('WARN', 'xdebug', 'loaded - disable in php.ini for normal development speed');
}

// --- Composer ----------------------------------------------------------------
$autoload = $root . '/vendor/autoload.php';
$hasVendor = is_file($autoload);
$add($hasVendor ? 'OK' : 'FAIL', 'Composer dependencies', $hasVendor ? 'vendor/ present' : 'run: composer install');

// --- .env --------------------------------------------------------------------
$env = [];
if (is_file($root . '/.env') && $hasVendor) {
    require $autoload;
    try {
        $env = Dotenv\Dotenv::createArrayBacked($root)->load();
        $add('OK', '.env', 'loaded');
    } catch (Throwable $e) {
        $add('FAIL', '.env', 'could not be parsed: ' . get_class($e));
    }
} else {
    $add('WARN', '.env', 'not found - copy .env.example to .env (required from M1)');
}

// --- Database ----------------------------------------------------------------
if ($env !== []) {
    mysqli_report(MYSQLI_REPORT_OFF);
    $db = @new mysqli(
        $env['DB_HOST'] ?? '127.0.0.1',
        $env['DB_USER'] ?? '',
        $env['DB_PASS'] ?? '',
        $env['DB_NAME'] ?? '',
        (int) ($env['DB_PORT'] ?? 3306)
    );
    if ($db->connect_errno) {
        $add('FAIL', 'Database', 'connection failed (errno ' . $db->connect_errno . ') - is MySQL running and the app user created?');
    } else {
        $add('OK', 'Database', 'connected, server ' . $db->server_info);
        if (strtolower((string) ($env['DB_USER'] ?? '')) === 'root') {
            $add('FAIL', 'Database user', 'the application must not run as root');
        }
        $labs = @$db->query("SELECT COUNT(*) FROM labs WHERE status = 'published' AND is_current = 1");
        $count = $labs instanceof mysqli_result ? (int) $labs->fetch_row()[0] : -1;
        if ($count > 0) {
            $add('OK', 'Lab catalog', "$count published labs");
        } else {
            $add('WARN', 'Lab catalog', 'no labs imported - run php scripts/labs-import.php');
        }
        $db->close();
    }
} else {
    $add('WARN', 'Database', 'skipped (no .env)');
}

// --- Storage -----------------------------------------------------------------
$storage = $env['STORAGE_PATH'] ?? '';
if ($storage === '') {
    $add('WARN', 'Storage path', 'STORAGE_PATH not set');
} else {
    $realRoot = realpath($root) ?: $root;
    $realStorage = realpath($storage);
    if ($realStorage === false) {
        $add('FAIL', 'Storage path', "$storage does not exist");
    } elseif (!is_writable($realStorage)) {
        $add('FAIL', 'Storage path', "$realStorage is not writable");
    } elseif (stripos($realStorage, realpath($root . '/public') ?: "\0") === 0) {
        $add('FAIL', 'Storage path', 'must not be inside public/');
    } elseif (stripos($realStorage, $realRoot) === 0) {
        $add('WARN', 'Storage path', 'inside the project (denied by .htaccess); prefer a path outside htdocs');
    } else {
        $add('OK', 'Storage path', $realStorage);
    }
}

// --- Execution plane ---------------------------------------------------------
$run = static function (string $cmd): ?string {
    $out = [];
    $code = 1;
    @exec($cmd . ' 2>&1', $out, $code);
    return $code === 0 ? trim(implode("\n", $out)) : null;
};

$python = $env['WORKER_PYTHON'] ?? 'worker/.venv/Scripts/python.exe';
$pythonPath = preg_match('~^([a-zA-Z]:)?[\\\\/]~', $python) ? $python : $root . '/' . $python;
if (is_file($pythonPath)) {
    $ver = $run(escapeshellarg($pythonPath) . ' -c "import duckdb,sys;print(sys.version.split()[0], duckdb.__version__)"');
    $add($ver !== null ? 'OK' : 'WARN', 'Python runner', $ver !== null ? "python/duckdb $ver" : 'venv found but duckdb not importable');
} else {
    $add('WARN', 'Python runner', 'worker venv not found (needed from M4; SQL Lab/ingestion run in degraded mode)');
}

$docker = $run('docker info --format "{{.ServerVersion}}"');
$add(
    $docker !== null ? 'OK' : 'WARN',
    'Docker (optional, M8)',
    $docker !== null ? "daemon $docker" : 'daemon not running - notebooks stay in demo mode'
);

// --- Report ------------------------------------------------------------------
$fail = 0;
foreach ($results as [$status, $check, $detail]) {
    $fail += $status === 'FAIL' ? 1 : 0;
    printf("[%-4s] %-24s %s\n", $status, $check, $detail);
}
echo $fail === 0 ? "\nEnvironment OK (see WARN items).\n" : "\n$fail check(s) failed.\n";
exit($fail === 0 ? 0 : 1);
