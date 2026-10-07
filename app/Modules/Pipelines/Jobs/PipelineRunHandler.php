<?php

declare(strict_types=1);

namespace EduCloud\Modules\Pipelines\Jobs;

use EduCloud\Core\App;
use EduCloud\Core\Format;
use EduCloud\Modules\Datasets\DatasetRepository;
use EduCloud\Modules\Datasets\LineageRepository;
use EduCloud\Modules\Jobs\JobHandler;
use EduCloud\Modules\Jobs\PartialResultHandler;
use EduCloud\Modules\Pipelines\PipelineRepository;
use RuntimeException;

/**
 * pipeline_run: executes the definition snapshot of a run in the runner (op "pipeline") and records the per-step
 * report, the output dataset version and lineage edges (sources → output). Payload: internal ids only.
 */
final class PipelineRunHandler implements JobHandler, PartialResultHandler
{
    private PipelineRepository $pipelines;
    private DatasetRepository $datasets;
    /** @var list<array<string, mixed>> */
    private array $steps = [];

    public function __construct(private readonly App $app)
    {
        $this->pipelines = new PipelineRepository($app->db());
        $this->datasets = new DatasetRepository($app->db());
    }

    public function prepare(array $job): array
    {
        [$run, $payload, $version] = $this->load($job);
        $tenantId = (int) $job['tenant_id'];
        $this->pipelines->markRunning($tenantId, (int) $run['id']);
        $storage = $this->app->storage();
        $tenant = (string) $job['tenant_public_id'];
        $ws = (string) $job['workspace_public_id'];
        $raw = [];
        foreach ((array) ($payload['raw'] ?? []) as $name => $versionId) {
            $source = $this->datasets->findVersionForJob($tenantId, (int) $versionId);
            $inWorkspace = $source !== null && $this->datasets->versionBelongsToWorkspace($tenantId, (int) $versionId, (int) $job['workspace_id']);
            if ($source === null || $source['storage_key'] === null || !$inWorkspace) {
                throw new RuntimeException('Pipeline raw input is missing');
            }
            $raw[(string) $name] = [
                'path' => $storage->rawFile($tenant, $ws, (string) $source['storage_key']),
                'format' => (string) $source['format'],
            ];
        }
        return ['op' => 'pipeline', 'args' => [
            'lakehouse_path' => $storage->lakehouseFile($tenant, $ws),
            'preview_path' => $storage->previewFile($tenant, $ws, (string) $version['public_id']),
            'nodes' => Format::jsonColumn($run['definition'])['nodes'] ?? [],
            'raw_inputs' => (object) $raw,
        ]];
    }

    public function succeeded(array $job, array $data): array
    {
        [$run, $payload, $version] = $this->load($job);
        $tenantId = (int) $job['tenant_id'];
        $columns = [];
        foreach ((array) ($data['output']['columns'] ?? []) as $c) {
            $columns[] = ['name' => (string) $c['name'], 'type' => (string) $c['type']];
        }
        $rows = (int) ($data['output']['row_count'] ?? 0);
        $datasetId = (int) $payload['dataset_id'];
        $this->datasets->markVersionReady($tenantId, (int) $version['id'], $rows, $columns);
        if (($payload['new_dataset'] ?? false) === true) {
            $dataset = $this->datasets->findForJob($tenantId, $datasetId);
            if ($dataset !== null) {
                $this->datasets->setResourceStatus($tenantId, (int) $dataset['resource_id'], 'provisioning', 'active');
            }
        }
        $lineage = new LineageRepository($this->app->db());
        $keys = array_merge(
            array_map('strval', (array) ($data['sources'] ?? [])),
            array_map(static fn ($n): string => 'raw:' . $n, (array) ($data['raw_sources'] ?? []))
        );
        $sources = $lineage->resolve($tenantId, (int) $job['workspace_id'], $keys);
        $lineage->record($tenantId, (int) $job['workspace_id'], $datasetId, $sources, 'pipeline', (int) $run['pipeline_id']);
        $this->pipelines->finishRun($tenantId, (int) $run['id'], 'succeeded', self::steps($data), $datasetId, null, null);
        return ['run' => (string) $run['public_id'], 'rows' => $rows, 'steps' => count((array) ($data['steps'] ?? []))];
    }

    public function partial(array $job, array $data): void
    {
        $this->steps = self::steps($data);
    }

    public function failed(array $job, string $errorCode, string $safeMessage): void
    {
        [$run, $payload, $version] = $this->load($job);
        $tenantId = (int) $job['tenant_id'];
        $status = match ($errorCode) {
            'CANCELLED' => 'cancelled',
            'TIMEOUT' => 'timed_out',
            default => 'failed',
        };
        // The output table is only written by the last step: a failed run leaves the previous table intact.
        if (($payload['new_dataset'] ?? false) === true) {
            $this->datasets->markVersionFailed($tenantId, (int) $version['id'], $errorCode, $safeMessage);
            $dataset = $this->datasets->findForJob($tenantId, (int) $payload['dataset_id']);
            if ($dataset !== null) {
                $this->datasets->setResourceStatus($tenantId, (int) $dataset['resource_id'], 'provisioning', 'failed');
                $this->datasets->setResourceStatus($tenantId, (int) $dataset['resource_id'], 'failed', 'deleting');
                $this->datasets->setResourceStatus($tenantId, (int) $dataset['resource_id'], 'deleting', 'deleted');
                $this->datasets->releaseTableName($tenantId, (int) $payload['dataset_id']);
            }
        } else {
            $this->datasets->deleteVersion($tenantId, (int) $version['id']);
        }
        $this->pipelines->finishRun($tenantId, (int) $run['id'], $status, $this->steps, null, $errorCode, $safeMessage);
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    private static function steps(array $data): array
    {
        $out = [];
        foreach (array_slice((array) ($data['steps'] ?? []), 0, 25) as $s) {
            if (!is_array($s)) {
                continue;
            }
            $out[] = [
                'id' => mb_substr((string) ($s['id'] ?? ''), 0, 40),
                'type' => mb_substr((string) ($s['type'] ?? ''), 0, 20),
                'status' => in_array($s['status'] ?? null, ['succeeded', 'failed', 'warning', 'skipped', 'running'], true) ? $s['status'] : 'failed',
                'rows' => isset($s['rows']) ? (int) $s['rows'] : null,
                'duration_ms' => isset($s['duration_ms']) ? (int) $s['duration_ms'] : null,
                'message' => isset($s['message']) ? mb_substr((string) $s['message'], 0, 300) : null,
            ];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $job
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function load(array $job): array
    {
        $payload = json_decode((string) $job['payload'], true);
        $tenantId = (int) $job['tenant_id'];
        $run = $this->pipelines->findRunForJob($tenantId, (int) ($payload['run_id'] ?? 0));
        $version = $this->datasets->findVersionForJob($tenantId, (int) ($payload['version_id'] ?? 0));
        $dataset = $this->datasets->findForJob($tenantId, (int) ($payload['dataset_id'] ?? 0));
        if (
            $run === null || $version === null || $dataset === null || $job['workspace_public_id'] === null
            || (int) $version['dataset_id'] !== (int) $dataset['dataset_id']
            || (int) $dataset['workspace_id'] !== (int) $job['workspace_id']
            || $run['workspace_public_id'] !== $job['workspace_public_id']
        ) {
            throw new RuntimeException('Pipeline job references missing data');
        }
        return [$run, $payload, $version];
    }
}
