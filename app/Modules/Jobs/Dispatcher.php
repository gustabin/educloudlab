<?php

declare(strict_types=1);

namespace EduCloud\Modules\Jobs;

use EduCloud\Core\App;
use EduCloud\Modules\Analytics\Jobs\SemanticQueryHandler;
use EduCloud\Modules\Datasets\Jobs\CleanupHandler;
use EduCloud\Modules\Datasets\Jobs\IngestHandler;
use EduCloud\Modules\Datasets\Jobs\ProfileHandler;
use EduCloud\Modules\Labs\Jobs\ValidateHandler;
use EduCloud\Modules\Pipelines\Jobs\PipelineRunHandler;
use EduCloud\Modules\SqlLab\Jobs\QueryHandler;
use EduCloud\Modules\SqlLab\Jobs\TransformHandler;
use EduCloud\Modules\Usage\UsageService;
use Throwable;

/**
 * Single-process job dispatcher (ADR-005). Claims one job at a time, which also serialises all writes to each
 * workspace lakehouse (DuckDB allows a single writer). Run by scripts/dispatcher.php; tests call runOnce().
 */
final class Dispatcher
{
    /** Error codes worth another attempt (never deterministic failures such as SQL, validation or quality errors). */
    public const TRANSIENT = ['RUNNER_ERROR', 'ENGINE_UNAVAILABLE'];

    private JobRepository $jobs;

    public function __construct(private readonly App $app, private readonly string $workerId = 'dispatcher')
    {
        $this->jobs = new JobRepository($app->db());
    }

    public function handlerFor(string $type): ?JobHandler
    {
        return match ($type) {
            'profile' => new ProfileHandler($this->app),
            'ingest' => new IngestHandler($this->app),
            'cleanup' => new CleanupHandler($this->app),
            'sql_query' => new QueryHandler($this->app),
            'transform' => new TransformHandler($this->app),
            'validate' => new ValidateHandler($this->app),
            'pipeline_run' => new PipelineRunHandler($this->app),
            'semantic_query' => new SemanticQueryHandler($this->app),
            default => null,
        };
    }

    /** Processes one queued job. Returns false when the queue is empty. */
    public function runOnce(): bool
    {
        $job = $this->jobs->claimNext($this->workerId);
        if ($job === null) {
            return false;
        }
        $id = (int) $job['id'];
        if (($job['workspace_status'] ?? null) === 'deleted') {
            // Nothing to do for a released workspace (e.g. setup jobs of an abandoned lab); its storage is purged.
            $this->jobs->finish($id, 'cancelled', null, 'WORKSPACE_DELETED', 'El workspace ya no existe.');
            return true;
        }
        $handler = $this->handlerFor((string) $job['type']);
        if ($handler === null) {
            $this->jobs->finish($id, 'failed', null, 'UNSUPPORTED_JOB', 'Tipo de trabajo no soportado.');
            return true;
        }

        $durationMs = null;
        try {
            $request = $handler->prepare($job);
            $allowedRoot = null;
            if ($job['workspace_public_id'] !== null) {
                $storage = $this->app->storage();
                $allowedRoot = $storage->workspaceDir((string) $job['tenant_public_id'], (string) $job['workspace_public_id']);
                $storage->ensureDir($allowedRoot);
            }
            $response = $request === null
                ? ['ok' => true, 'data' => []]
                : (new RunnerProcess($this->app->config, $this->app->storage()))->run(
                    (string) $job['public_id'],
                    $request['op'],
                    $request['args'],
                    (int) $job['timeout_s'],
                    fn () => $this->jobs->heartbeat($id),
                    $allowedRoot,
                );

            $durationMs = isset($response['stats']['duration_ms']) ? (int) $response['stats']['duration_ms'] : null;
            if ($response['ok'] === true) {
                $summary = $handler->succeeded($job, is_array($response['data'] ?? null) ? $response['data'] : []);
                $this->jobs->finish($id, 'succeeded', ($summary ?? []) + ['duration_ms' => $response['stats']['duration_ms'] ?? null]);
            } else {
                $code = (string) ($response['error_code'] ?? 'RUNNER_ERROR');
                $message = (string) ($response['safe_message'] ?? 'La operación falló.');
                // Transient failures (runner crashed or unavailable) are retried when the job allows it (pipelines).
                if (in_array($code, self::TRANSIENT, true) && $this->jobs->requeue($id)) {
                    $this->app->logger->info('job_requeued', ['job' => $job['public_id'], 'code' => $code]);
                    return true;
                }
                if ($handler instanceof PartialResultHandler && is_array($response['data'] ?? null)) {
                    $handler->partial($job, $response['data']);
                }
                $handler->failed($job, $code, $message);
                $status = match (true) {
                    in_array($code, ['TIMEOUT', 'QUERY_TIMEOUT'], true) => 'timed_out',
                    $code === 'CANCELLED' => 'cancelled',
                    default => 'failed',
                };
                $this->jobs->finish($id, $status, null, $code, $message);
            }
        } catch (Throwable $e) {
            $this->app->logger->error('job_failed', [
                'job' => $job['public_id'],
                'type' => $job['type'],
                // Class and code only: driver/IO messages may contain SQL fragments or filesystem paths.
                'exception' => get_class($e),
                'code' => $e->getCode(),
            ]);
            $message = 'Error interno al procesar el trabajo.';
            try {
                $handler->failed($job, 'INTERNAL_ERROR', $message);
            } catch (Throwable) {
                // The job is still marked failed below.
            }
            $this->jobs->finish($id, 'failed', null, 'INTERNAL_ERROR', $message);
        }
        $usage = new UsageService($this->app);
        $usage->recordJob($job, $durationMs);
        if (in_array($job['type'], ['ingest', 'transform', 'cleanup'], true)) {
            $usage->refreshStorage((int) $job['tenant_id'], (string) $job['tenant_public_id'], (int) $job['user_id']);
        }
        $this->app->logger->info('job_processed', ['job' => $job['public_id'], 'type' => $job['type']]);
        return true;
    }

    /** Processes jobs until the queue is empty or $max jobs ran. Returns the number processed. */
    public function drain(int $max = 100): int
    {
        $n = 0;
        while ($n < $max && $this->runOnce()) {
            $n++;
        }
        return $n;
    }

    /** Fails jobs whose dispatcher died mid-run (no heartbeat for timeout + grace). */
    public function failStaleJobs(int $graceSeconds = 60): int
    {
        $count = 0;
        foreach ($this->jobs->findStale($graceSeconds) as $job) {
            $message = 'El trabajo se interrumpió. Vuelve a intentarlo.';
            $this->handlerFor((string) $job['type'])?->failed($job, 'INTERRUPTED', $message);
            $this->jobs->finish((int) $job['id'], 'failed', null, 'INTERRUPTED', $message);
            $count++;
        }
        return $count;
    }
}
