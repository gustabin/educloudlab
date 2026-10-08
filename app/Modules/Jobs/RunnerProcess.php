<?php

declare(strict_types=1);

namespace EduCloud\Modules\Jobs;

use EduCloud\Core\Config;
use EduCloud\Core\Logger;
use EduCloud\Core\Storage\LocalStorage;

/**
 * Launches the Python runner for one job and enforces the execution-plane limits (ADR-005):
 *  - no shell (argument array), minimal environment (no secrets, no DB credentials);
 *  - request/response exchanged through files below the storage root (Windows pipes cannot be select()ed);
 *  - wall-clock timeout: the whole process tree is killed (taskkill /T /F on Windows, SIGKILL elsewhere);
 *  - response size cap; I/O files are removed afterwards.
 */
final class RunnerProcess
{
    public function __construct(
        private readonly Config $config,
        private readonly LocalStorage $storage,
        private readonly ?Logger $logger = null
    ) {
    }

    /**
     * @param array<string, mixed> $args
     * @param callable(): bool     $heartbeat called every few seconds while the runner works; true = cancel requested
     * @return array<string, mixed> runner response, or a synthetic error response
     */
    public function run(string $jobPublicId, string $op, array $args, int $timeoutSeconds, callable $heartbeat, ?string $allowedRoot = null): array
    {
        $python = (string) $this->config->get('execution.python');
        $runner = (string) $this->config->get('execution.runner');
        if (!is_file($python) || !is_file($runner)) {
            return self::error('ENGINE_UNAVAILABLE', 'El motor de ejecución no está disponible en este servidor.');
        }

        $this->storage->ensureDir($this->storage->root() . '/jobs');
        $requestFile = $this->storage->jobFile($jobPublicId, 'request.json');
        $responseFile = $this->storage->jobFile($jobPublicId, 'response.json');
        $logFile = $this->storage->jobFile($jobPublicId, 'log');
        file_put_contents($requestFile, json_encode([
            'op' => $op,
            'args' => $args,
            'limits' => (array) $this->config->get('execution.limits', []),
            // The runner (and DuckDB's sandbox) may only touch this directory: the job's workspace when it has one.
            'allowed_root' => $allowedRoot ?? $this->storage->root(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        @unlink($responseFile);

        try {
            $process = proc_open(
                [$python, '-I', $runner, $requestFile, $responseFile],
                [0 => ['pipe', 'r'], 1 => ['file', $logFile, 'w'], 2 => ['file', $logFile, 'a']],
                $pipes,
                (string) $this->config->get('execution.worker_dir'),
                self::environment(),
                ['bypass_shell' => true]
            );
            if (!is_resource($process)) {
                return self::error('ENGINE_UNAVAILABLE', 'No se pudo iniciar el motor de ejecución.');
            }
            fclose($pipes[0]);

            $started = microtime(true);
            $lastBeat = $started;
            $beatEvery = (int) $this->config->get('execution.heartbeat_seconds', 5);
            $timedOut = false;
            $cancelled = false;
            while (($status = proc_get_status($process))['running']) {
                if (microtime(true) - $started > $timeoutSeconds) {
                    self::killTree((int) $status['pid']);
                    $timedOut = true;
                    break;
                }
                if (microtime(true) - $lastBeat >= $beatEvery) {
                    if ($heartbeat() === true) {
                        self::killTree((int) $status['pid']);
                        $cancelled = true;
                        break;
                    }
                    $lastBeat = microtime(true);
                }
                usleep(100_000);
            }
            $exit = proc_close($process);

            if ($cancelled) {
                return self::error('CANCELLED', 'La ejecución se canceló a petición del usuario.');
            }
            if ($timedOut) {
                return self::error('TIMEOUT', "La operación superó el tiempo máximo de {$timeoutSeconds} s y se canceló.");
            }
            if (!is_file($responseFile)) {
                // Operators need the cause (e.g. signal 11/9 = crash or memory cap); never paths or output.
                $this->logger?->error('runner_no_response', [
                    'op' => $op,
                    'exit_code' => $status['signaled'] ? null : ($status['exitcode'] >= 0 ? $status['exitcode'] : $exit),
                    'signal' => $status['signaled'] ? $status['termsig'] : null,
                ]);
                return self::error('RUNNER_ERROR', 'El motor de ejecución terminó sin respuesta.');
            }
            if (filesize($responseFile) > (int) $this->config->get('execution.max_response_bytes', 2_097_152)) {
                return self::error('OUTPUT_TOO_LARGE', 'El resultado es demasiado grande.');
            }
            $response = json_decode((string) file_get_contents($responseFile), true);
            if (!is_array($response) || !is_bool($response['ok'] ?? null)) {
                return self::error('RUNNER_ERROR', 'Respuesta no válida del motor de ejecución.');
            }
            return $response;
        } finally {
            foreach ([$requestFile, $responseFile, $logFile] as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * The only environment variables the runner receives (no DB credentials, keys or other secrets).
     *
     * @return array<string, string>
     */
    public static function environment(): array
    {
        $env = ['PYTHONIOENCODING' => 'utf-8', 'PYTHONDONTWRITEBYTECODE' => '1'];
        if (PHP_OS_FAMILY !== 'Windows') {
            // The runner caps its address space (RLIMIT_AS). glibc reserves a 64 MB malloc arena per thread, and
            // DuckDB crashes (SIGSEGV) instead of failing cleanly when such a reservation is refused: limit the
            // arenas so the cap only bites on real memory use (found by the Linux CI, M12).
            $env['MALLOC_ARENA_MAX'] = '2';
        }
        foreach (['PATH', 'SYSTEMROOT', 'SystemRoot', 'TEMP', 'TMP', 'WINDIR'] as $key) {
            $value = getenv($key);
            if (is_string($value) && $value !== '') {
                $env[$key] = $value;
            }
        }
        return $env;
    }

    private static function killTree(int $pid): void
    {
        if ($pid <= 0) {
            return;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            exec('taskkill /T /F /PID ' . $pid . ' 2>NUL');
        } elseif (function_exists('posix_kill')) {
            posix_kill($pid, 9);
        }
    }

    /** @return array<string, mixed> */
    private static function error(string $code, string $message): array
    {
        return ['ok' => false, 'error_code' => $code, 'safe_message' => $message];
    }
}
