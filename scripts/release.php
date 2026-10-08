<?php

/**
 * Builds a release archive (M12).
 *
 *   php scripts/release.php 1.3.0              from the tag v1.3.0
 *   php scripts/release.php 1.3.0 --ref=HEAD   from a commit (release candidate / smoke tests)
 *
 * Output in build/: educloud-lab-<version>.tar.gz, .zip and SHA256SUMS.
 * - Content: `git archive` of the ref, so only committed files are shipped. Development-only paths are excluded
 *   through `export-ignore` in .gitattributes: tests, e2e, ci, lab solutions, internal plans.
 * - Dependencies: `composer install --no-dev --classmap-authoritative`. Python packages are installed on the
 *   target from worker/requirements.txt (hash-pinned).
 * - Never shipped: .env, storage, .git.
 * The VERSION file of the ref must match the requested version.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$version = $argv[1] ?? '';
if (preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/D', $version) !== 1) {
    fwrite(STDERR, "usage: php scripts/release.php <semver> [--ref=<git ref>]\n");
    exit(2);
}
$ref = 'v' . $version;
foreach (array_slice($argv, 2) as $arg) {
    if (str_starts_with($arg, '--ref=')) {
        $ref = substr($arg, 6);
    }
}
if (preg_match('/^[A-Za-z0-9._][A-Za-z0-9._\/-]{0,99}$/D', $ref) !== 1) { // never starts with '-' (no option injection)
    fwrite(STDERR, "invalid ref\n");
    exit(2);
}

/** @param list<string> $command */
function run(array $command, string $cwd): string
{
    $proc = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($proc)) {
        throw new RuntimeException('cannot start ' . $command[0]);
    }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($proc) !== 0) {
        throw new RuntimeException(implode(' ', $command) . " failed:\n" . $err);
    }
    return $out;
}

function removeTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

try {
    $commit = trim(run(['git', 'rev-parse', '--verify', $ref . '^{commit}'], $root));
    $fileVersion = trim(run(['git', 'show', $commit . ':VERSION'], $root));
    if ($fileVersion !== $version) {
        throw new RuntimeException("VERSION at $ref is '$fileVersion', expected '$version'");
    }

    $name = 'educloud-lab-' . $version;
    $build = $root . '/build';
    $stage = $build . '/' . $name;
    removeTree($stage);
    @mkdir($build, 0755, true);

    // 1. Committed files of the ref (export-ignore applied by git).
    $zipFile = $build . '/' . $name . '.src.zip';
    run(['git', 'archive', '--format=zip', '--prefix=' . $name . '/', '-o', $zipFile, $commit], $root);
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true || !$zip->extractTo($build)) {
        throw new RuntimeException('cannot extract the source archive');
    }
    $zip->close();
    unlink($zipFile);

    // 2. Production dependencies only.
    // Composer's phar is run with this PHP (no shell, works the same on Windows and Linux).
    $composer = (string) getenv('COMPOSER_PHAR');
    foreach ($composer === '' ? explode(PATH_SEPARATOR, (string) getenv('PATH')) : [] as $pathDir) {
        foreach (['composer.phar', 'composer'] as $candidate) {
            if ($composer === '' && is_file($pathDir . DIRECTORY_SEPARATOR . $candidate)) {
                $composer = $pathDir . DIRECTORY_SEPARATOR . $candidate;
            }
        }
    }
    if ($composer === '') {
        throw new RuntimeException('composer not found on PATH (set COMPOSER_PHAR)');
    }
    $install = ['install', '--no-dev', '--classmap-authoritative', '--no-interaction', '--no-progress', '--no-scripts', '--no-plugins'];
    run([PHP_BINARY, $composer, ...$install], $stage);

    file_put_contents($stage . '/RELEASE', "version=$version\ncommit=$commit\nbuilt_at=" . gmdate('Y-m-d\TH:i:s\Z') . "\n");

    // Guard: nothing secret or development-only may be in the stage.
    foreach (['.env', '.git', 'tests', 'e2e', 'ci', 'node_modules', 'storage/logs', 'vendor/phpunit', 'worker/.venv'] as $forbidden) {
        if (file_exists($stage . '/' . $forbidden)) {
            throw new RuntimeException("forbidden path in release: $forbidden");
        }
    }
    if (glob($stage . '/labs/*/solution') !== []) {
        throw new RuntimeException('lab solutions must not be shipped');
    }

    // 3. Archives + checksums.
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS));
    $tarPath = $build . '/' . $name . '.tar';
    foreach ([$tarPath, $tarPath . '.gz', $build . '/' . $name . '.zip'] as $old) {
        @unlink($old);
    }
    $tar = new PharData($tarPath);
    $zip = new ZipArchive();
    $zip->open($build . '/' . $name . '.zip', ZipArchive::CREATE);
    $count = 0;
    foreach ($files as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $local = $name . '/' . str_replace('\\', '/', substr($file->getPathname(), strlen($stage) + 1));
        $tar->addFile($file->getPathname(), $local);
        $zip->addFile($file->getPathname(), $local);
        $count++;
    }
    $zip->close();
    $tar->compress(Phar::GZ);
    unset($tar);
    unlink($tarPath);

    $sums = '';
    foreach ([$name . '.tar.gz', $name . '.zip'] as $artifact) {
        $sums .= hash_file('sha256', $build . '/' . $artifact) . '  ' . $artifact . "\n";
    }
    file_put_contents($build . '/SHA256SUMS', $sums);
    removeTree($stage);

    echo "release $version ($commit), $count files\n" . $sums;
} catch (Throwable $e) {
    fwrite(STDERR, 'release failed: ' . $e->getMessage() . "\n");
    exit(1);
}
