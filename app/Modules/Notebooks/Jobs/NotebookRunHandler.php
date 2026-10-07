<?php

declare(strict_types=1);

namespace EduCloud\Modules\Notebooks\Jobs;

use EduCloud\Core\App;
use EduCloud\Core\Format;
use EduCloud\Modules\Jobs\CustomExecutor;
use EduCloud\Modules\Jobs\JobHandler;
use EduCloud\Modules\Notebooks\DockerSandbox;
use EduCloud\Modules\Notebooks\NotebookRepository;
use RuntimeException;

/**
 * notebook_run (M8, ADR-010): runs the code cells of a run snapshot in the Docker sandbox (never on the host) and
 * stores the normalised outputs and artifacts. A cell error is a finished run with status "failed".
 */
final class NotebookRunHandler implements JobHandler, CustomExecutor
{
    private NotebookRepository $repo;
    private ?int $durationMs = null;

    public function __construct(private readonly App $app)
    {
        $this->repo = new NotebookRepository($app->db());
    }

    public function prepare(array $job): array
    {
        $run = $this->load($job);
        $this->repo->markRunning((int) $job['tenant_id'], (int) $run['id']);
        $cells = [];
        foreach (Format::jsonColumn($run['cells']) as $cell) {
            if (($cell['type'] ?? null) === 'code' && trim((string) ($cell['source'] ?? '')) !== '') {
                $cells[] = ['id' => (string) $cell['id'], 'source' => (string) $cell['source']];
            }
        }
        return ['op' => 'notebook', 'args' => ['cells' => $cells]];
    }

    public function execute(array $job, array $request, callable $heartbeat): array
    {
        if ($this->app->config->get('execution.notebooks.mode') !== 'docker') {
            $message = 'La ejecución de notebooks no está activada en este servidor.';
            return ['ok' => false, 'error_code' => 'NOTEBOOKS_DISABLED', 'safe_message' => $message];
        }
        $cells = (array) ($request['args']['cells'] ?? []);
        if ($cells === []) {
            return ['ok' => true, 'data' => ['status' => 'succeeded', 'cells' => [], 'artifacts' => []], 'stats' => ['duration_ms' => 0]];
        }
        $lakehouse = $this->app->storage()->lakehouseFile((string) $job['tenant_public_id'], (string) $job['workspace_public_id']);
        $sandbox = new DockerSandbox($this->app->config, $this->app->storage());
        $response = $sandbox->run(array_values($cells), is_file($lakehouse) ? $lakehouse : null, (int) $job['timeout_s'], $heartbeat);
        $this->durationMs = isset($response['stats']['duration_ms']) ? (int) $response['stats']['duration_ms'] : null;
        return $response;
    }

    public function succeeded(array $job, array $data): array
    {
        $run = $this->load($job);
        $status = ($data['status'] ?? null) === 'succeeded' ? 'succeeded' : 'failed';
        $cells = array_values((array) ($data['cells'] ?? []));
        $failed = array_values(array_filter($cells, static fn (mixed $c): bool => is_array($c) && ($c['status'] ?? null) === 'failed'));
        $this->repo->finishRun(
            (int) $job['tenant_id'],
            (int) $run['id'],
            $status,
            $cells,
            (array) ($data['artifacts'] ?? []),
            $status === 'failed' ? 'CELL_ERROR' : null,
            $status === 'failed' ? 'Una celda falló: revisa su salida.' : null,
            $this->durationMs
        );
        return ['status' => $status, 'cells' => count($cells), 'failed_cell' => $failed[0]['id'] ?? null];
    }

    public function failed(array $job, string $errorCode, string $safeMessage): void
    {
        $run = $this->load($job);
        $status = match ($errorCode) {
            'TIMEOUT' => 'timed_out',
            'CANCELLED' => 'cancelled',
            default => 'failed',
        };
        $this->repo->finishRun((int) $job['tenant_id'], (int) $run['id'], $status, null, null, $errorCode, $safeMessage, $this->durationMs);
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function load(array $job): array
    {
        $payload = json_decode((string) $job['payload'], true);
        $run = $this->repo->findRunForJob((int) $job['tenant_id'], (int) ($payload['run_id'] ?? 0));
        if ($run === null || $job['workspace_public_id'] === null || (int) $run['workspace_id'] !== (int) $job['workspace_id']) {
            throw new RuntimeException('Notebook job references missing data');
        }
        return $run;
    }
}
