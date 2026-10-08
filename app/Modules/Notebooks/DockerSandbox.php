<?php

declare(strict_types=1);

namespace EduCloud\Modules\Notebooks;

use EduCloud\Core\Config;
use EduCloud\Core\Storage\LocalStorage;
use EduCloud\Core\Ulid;

/**
 * Runs notebook cells in an ephemeral Docker container (M8, ADR-010). Only the dispatcher uses this class; the web
 * server never talks to the Docker daemon.
 *
 * Isolation (flags in self::arguments(), asserted by tests and attacked by tests/Sandbox/NotebookIsolationTest):
 * no network, read-only root filesystem, small tmpfs and /dev/shm, memory/CPU/pids/nofile/core limits, no
 * capabilities, no-new-privileges, unprivileged user, no host environment, and only two read-only mounts: the cells
 * (input.json) and a COPY of the job's workspace lakehouse.
 *
 * Lifetime and output (security gate M8-F1): the container is started DETACHED. Its stdout goes to Docker's
 * json-file log inside the Docker VM, rotated at max-size x max-file (never to a host file), and PID 1 of the image
 * is a `timeout -s KILL` guard, so a run stops by itself even if the dispatcher dies. The dispatcher polls the
 * container, kills it at its own deadline, reads the bounded log once it has stopped, and removes it.
 * reapOrphans() removes containers left behind by a crashed dispatcher.
 */
final class DockerSandbox
{
    public const MARKER = '__EDUCLOUD_NB__';

    public function __construct(private readonly Config $config, private readonly LocalStorage $storage)
    {
    }

    /** True when the daemon answers and the image exists (check-env, mode guard). */
    public function available(int $timeoutS = 20): bool
    {
        $out = $this->docker(['image', 'inspect', '--format', '{{.Id}}', (string) $this->setting('image')], $timeoutS);
        return $out['exit'] === 0 && str_starts_with(trim($out['stdout']), 'sha256:');
    }

    public const LABEL = 'educloud.nb=1';
    /** The executor keeps its result line under 1,000,000 characters (nb_exec.MAX_RESULT_CHARS). */
    public const MAX_RESULT_BYTES = 1_100_000;

    /**
     * @param list<array{id: string, source: string}> $cells code cells, in order
     * @param callable(): bool                        $heartbeat true = cancel requested
     * @return array<string, mixed> runner-style response {ok, data | error_code + safe_message, stats}
     */
    public function run(array $cells, ?string $lakehouseFile, int $timeoutSeconds, callable $heartbeat): array
    {
        $runId = strtolower(Ulid::generate());
        $dir = $this->storage->root() . '/jobs/nb-' . $runId;
        $this->storage->ensureDir($dir . '/in');
        $name = 'educloud-nb-' . $runId;
        $started = microtime(true);
        try {
            file_put_contents($dir . '/in/input.json', json_encode([
                'cells' => $cells,
                'limits' => ['cell_timeout_s' => (int) $this->setting('cell_timeout_s')],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $lakehouseCopy = null;
            if ($lakehouseFile !== null && is_file($lakehouseFile)) {
                // A copy: the student container can never touch the live lakehouse (nor see other workspaces).
                $lakehouseCopy = $dir . '/lakehouse.duckdb';
                copy($lakehouseFile, $lakehouseCopy);
            }

            $created = $this->docker(self::arguments($this->settings(), $name, $dir . '/in', $lakehouseCopy), 60);
            if ($created['exit'] !== 0) {
                return self::error('ENGINE_UNAVAILABLE', 'No se pudo iniciar el entorno de notebooks.');
            }
            $beatEvery = (int) $this->config->get('execution.heartbeat_seconds', 5);
            $lastBeat = $started;
            $outcome = null;
            $state = ['running' => true, 'exit' => null, 'oom' => false];
            while (true) {
                $state = $this->state($name);
                if (!$state['running']) {
                    break;
                }
                if (microtime(true) - $started > $timeoutSeconds) {
                    $outcome = ['TIMEOUT', "El notebook superó el tiempo máximo de {$timeoutSeconds} s y se detuvo."];
                } elseif (microtime(true) - $lastBeat >= $beatEvery) {
                    $lastBeat = microtime(true);
                    if ($heartbeat() === true) {
                        $outcome = ['CANCELLED', 'La ejecución se canceló a petición del usuario.'];
                    }
                }
                if ($outcome !== null) {
                    $this->docker(['kill', $name], 30);
                    break;
                }
                usleep(400_000);
            }
            $stats = ['duration_ms' => (int) ((microtime(true) - $started) * 1000)];
            if ($outcome !== null) {
                return self::error($outcome[0], $outcome[1]) + ['stats' => $stats];
            }
            $logFile = $dir . '/result.log';
            $this->docker(['logs', $name], 60, $logFile); // bounded by the json-file rotation (max-size x max-file)
            $result = self::parse($logFile, (int) $this->setting('max_log_bytes'));
            if ($result === null) {
                // No (valid) result line: killed from inside (OOM, pids, the PID 1 guard) or the line was too large.
                return ($state['oom'] || $state['exit'] === 137
                    ? self::error('NOTEBOOK_KILLED', 'El notebook se detuvo por superar los límites de memoria, de procesos o de tiempo.')
                    : self::error('NOTEBOOK_ERROR', 'El notebook terminó sin resultado. Revisa el código de las celdas.')) + ['stats' => $stats];
            }
            return ['ok' => true, 'data' => $result, 'stats' => $stats];
        } finally {
            $this->docker(['rm', '-f', $name], 30);
            self::removeTree($dir);
        }
    }

    /**
     * Removes notebook containers left behind (dispatcher crash or stop) and stale run directories.
     * $all = true at dispatcher start (single owner: nothing can be legitimately running); otherwise only containers
     * older than the PID 1 guard (150 s) plus a margin, whatever their state: a container that has just exited may
     * still be waiting for its owner to read the result (gate M8-N1).
     */
    /** Seconds after which a labelled container is an orphan: PID 1 guard (Dockerfile, 150 s) plus a margin. */
    public const ORPHAN_AGE_S = 210;

    public function reapOrphans(bool $all): int
    {
        $list = $this->docker(['ps', '-a', '--filter', 'label=' . self::LABEL, '--format', '{{.Names}}|{{.State}}|{{.CreatedAt}}'], 30);
        $removed = 0;
        foreach (preg_split('/\r?\n/', trim($list['stdout'])) ?: [] as $line) {
            [$name, , $createdAt] = array_pad(explode('|', $line, 3), 3, '');
            if (preg_match('/^educloud-nb-[0-9a-z]{26}$/D', $name) !== 1) {
                continue;
            }
            $created = strtotime(preg_replace('/ [A-Z]{2,5}$/', '', $createdAt) ?? '') ?: time();
            if ($all || time() - $created > self::ORPHAN_AGE_S) {
                $this->docker(['rm', '-f', $name], 30);
                $removed++;
            }
        }
        foreach (glob($this->storage->root() . '/jobs/nb-*', GLOB_ONLYDIR) ?: [] as $dir) {
            if ($all || (int) @filemtime($dir) < time() - 600) {
                self::removeTree($dir);
            }
        }
        return $removed;
    }

    /** @return array{running: bool, exit: int|null, oom: bool} */
    private function state(string $name): array
    {
        $out = $this->docker(['inspect', '--format', '{{.State.Running}}|{{.State.ExitCode}}|{{.State.OOMKilled}}', $name], 30);
        if ($out['exit'] !== 0) {
            return ['running' => false, 'exit' => null, 'oom' => false];
        }
        [$running, $exit, $oom] = array_pad(explode('|', trim($out['stdout'])), 3, '');
        return ['running' => $running === 'true', 'exit' => is_numeric($exit) ? (int) $exit : null, 'oom' => $oom === 'true'];
    }

    /**
     * The exact `docker run` argument list (no shell). Public for the argument tests.
     *
     * @param array<string, mixed> $s execution.notebooks settings
     * @return list<string>
     */
    public static function arguments(array $s, string $name, string $inputDir, ?string $lakehouseCopy): array
    {
        $args = [
            'run', '--detach', '--name', $name, '--label', self::LABEL,
            '--network', 'none',
            '--read-only',
            '--tmpfs', '/tmp:rw,nosuid,nodev,noexec,size=' . (int) $s['tmpfs_mb'] . 'm',
            '--shm-size', (int) $s['shm_mb'] . 'm',
            '--memory', (string) $s['memory'], '--memory-swap', (string) $s['memory'],
            '--cpus', (string) $s['cpus'],
            '--pids-limit', (string) (int) $s['pids'],
            '--ulimit', 'nofile=' . (int) $s['nofile'] . ':' . (int) $s['nofile'],
            '--ulimit', 'core=0:0',
            '--cap-drop', 'ALL',
            '--security-opt', 'no-new-privileges',
            '--user', '10001:10001',
            // stdout/stderr stay inside the Docker VM, rotated: never a growing file on the host (gate M8-F1/F5).
            '--log-driver', 'json-file', '--log-opt', 'max-size=' . (int) $s['log_max_mb'] . 'm', '--log-opt', 'max-file=2',
            '--mount', 'type=bind,source=' . self::hostPath($inputDir) . ',target=/in,readonly',
        ];
        if ($lakehouseCopy !== null) {
            $args[] = '--mount';
            $args[] = 'type=bind,source=' . self::hostPath($lakehouseCopy) . ',target=/data/lakehouse.duckdb,readonly';
        }
        $args[] = (string) $s['image'];
        return $args;
    }

    /**
     * Normalises the executor's result line. Everything in it is student-controlled: strict types, caps, no trust.
     *
     * @return array{status: string, cells: list<array<string, mixed>>, artifacts: array<string, mixed>}|null
     */
    public static function parse(string $logFile, int $limit): ?array
    {
        if (!is_file($logFile) || filesize($logFile) > $limit) {
            return null;
        }
        $line = null;
        $handle = fopen($logFile, 'rb');
        if ($handle === false) {
            return null;
        }
        while (($l = fgets($handle)) !== false) {
            if (str_starts_with($l, self::MARKER)) {
                $line = rtrim(substr($l, strlen(self::MARKER)), "\r\n"); // the last marker wins
            }
        }
        fclose($handle);
        // Bounded decoding (gate M8-F2): a short line and a structural budget before json_decode.
        if ($line === null || strlen($line) > self::MAX_RESULT_BYTES || substr_count($line, '[') + substr_count($line, '{') > 60_000) {
            return null;
        }
        $raw = json_decode($line, true, 16);
        if (!is_array($raw)) {
            return null;
        }
        $text = static fn (mixed $v, int $max): string => mb_substr(is_string($v) ? $v : '', 0, $max);
        $table = static function (mixed $t, int $maxRows): ?array {
            if (!is_array($t) || !is_array($t['columns'] ?? null) || !is_array($t['rows'] ?? null)) {
                return null;
            }
            $columns = array_map(
                static fn (mixed $c): string => mb_substr(is_scalar($c) ? (string) $c : '', 0, 100),
                array_slice(array_values($t['columns']), 0, 100)
            );
            $rows = [];
            foreach (array_slice(array_values($t['rows']), 0, $maxRows) as $row) {
                if (!is_array($row) || count($row) !== count($columns)) {
                    continue; // malformed row (gate M8-F8): every row has exactly one value per column
                }
                $rows[] = array_map(
                    static fn (mixed $v): mixed => $v === null || is_bool($v) || is_int($v) || is_float($v)
                        ? $v
                        : mb_substr(is_scalar($v) ? (string) $v : '', 0, 200),
                    array_values($row)
                );
            }
            return ['columns' => $columns, 'rows' => $rows, 'total_rows' => is_int($t['total_rows'] ?? null) ? $t['total_rows'] : count($rows)];
        };
        $cells = [];
        foreach (array_slice(is_array($raw['cells'] ?? null) ? array_values($raw['cells']) : [], 0, 50) as $c) {
            if (!is_array($c)) {
                continue;
            }
            $error = is_array($c['error'] ?? null) ? [
                'type' => $text($c['error']['type'] ?? '', 60),
                'message' => $text($c['error']['message'] ?? '', 1000),
                'line' => is_int($c['error']['line'] ?? null) ? $c['error']['line'] : null,
            ] : null;
            $id = $c['id'] ?? null;
            if (!is_string($id) || preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/D', $id) !== 1) {
                continue; // cell ids follow the notebook pattern (gate M8-F7)
            }
            $cells[] = [
                'id' => $id,
                'status' => ($c['status'] ?? null) === 'succeeded' ? 'succeeded' : 'failed',
                'stdout' => $text($c['stdout'] ?? '', 20_200), // executor cap (20,000) + its truncation note
                'stderr' => $text($c['stderr'] ?? '', 5_000),
                'value' => isset($c['value']) ? $text($c['value'], 5_000) : null,
                'table' => $table($c['table'] ?? null, 50),
                'error' => $error,
            ];
        }
        $artifacts = [];
        foreach (is_array($raw['artifacts'] ?? null) ? $raw['artifacts'] : [] as $key => $t) {
            if (is_string($key) && preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $key) === 1 && count($artifacts) < 5) {
                $normalised = $table($t, 1000);
                if ($normalised !== null) {
                    $artifacts[$key] = $normalised;
                }
            }
        }
        return ['status' => ($raw['status'] ?? null) === 'succeeded' ? 'succeeded' : 'failed', 'cells' => $cells, 'artifacts' => $artifacts];
    }

    /**
     * @param list<string> $args
     * @return array{exit: int, stdout: string}
     */
    private function docker(array $args, int $timeout, ?string $outputFile = null): array
    {
        $out = $outputFile ?? (string) tempnam(sys_get_temp_dir(), 'ecdk');
        $process = @proc_open(
            [(string) $this->setting('docker'), ...$args],
            [0 => ['file', self::nullDevice(), 'r'], 1 => ['file', $out, 'w'], 2 => ['file', self::nullDevice(), 'w']],
            $pipes,
            null,
            self::environment(),
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            @unlink($out);
            return ['exit' => -1, 'stdout' => ''];
        }
        $started = microtime(true);
        $exit = -1;
        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exit = (int) $status['exitcode']; // only reliable from the first status that sees the exit
                break;
            }
            if (microtime(true) - $started >= $timeout) {
                proc_terminate($process); // a hung CLI never blocks the dispatcher (gate M8-F1)
                break;
            }
            usleep(50_000);
        }
        proc_close($process);
        if ($outputFile !== null) {
            return ['exit' => $exit, 'stdout' => ''];
        }
        $stdout = (string) @file_get_contents($out, false, null, 0, 1_048_576);
        @unlink($out);
        return ['exit' => $exit, 'stdout' => $stdout];
    }

    /**
     * Environment of the docker CLI process only (the container gets nothing from the host: no -e/--env flags).
     *
     * @return array<string, string>
     */
    private static function environment(): array
    {
        $env = [];
        $keys = ['PATH', 'SYSTEMROOT', 'SystemRoot', 'WINDIR', 'TEMP', 'TMP', 'USERPROFILE', 'APPDATA', 'LOCALAPPDATA', 'ProgramData',
            'ProgramFiles', 'HOMEDRIVE', 'HOMEPATH', 'HOME', 'DOCKER_HOST', 'DOCKER_CONFIG', 'DOCKER_CONTEXT'];
        foreach ($keys as $key) {
            $value = getenv($key);
            if (is_string($value) && $value !== '') {
                $env[$key] = $value;
            }
        }
        return $env;
    }

    /** @return array<string, mixed> */
    private function settings(): array
    {
        return (array) $this->config->get('execution.notebooks', []);
    }

    private function setting(string $key): mixed
    {
        return $this->settings()[$key] ?? null;
    }

    private static function hostPath(string $path): string
    {
        $real = (string) realpath($path);
        if ($real === '' || str_contains($real, ',') || str_contains($real, '"')) {
            throw new \RuntimeException('Unsupported sandbox mount path');
        }
        return $real;
    }

    private static function nullDevice(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    /** @return array<string, mixed> */
    private static function error(string $code, string $message): array
    {
        return ['ok' => false, 'error_code' => $code, 'safe_message' => $message];
    }
}
