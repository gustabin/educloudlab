<?php

declare(strict_types=1);

namespace EduCloud\Modules\Datasets\Jobs;

use EduCloud\Core\App;
use EduCloud\Modules\Datasets\DatasetRepository;
use EduCloud\Modules\Jobs\JobHandler;
use RuntimeException;

/** ingest: raw CSV → bronze.<table> in the workspace lakehouse (normalised column names). */
final class IngestHandler implements JobHandler
{
    private DatasetRepository $repo;

    public function __construct(private readonly App $app)
    {
        $this->repo = new DatasetRepository($app->db());
    }

    public function prepare(array $job): array
    {
        [$source, $target, $targetVersion] = $this->load($job);
        $tenant = (string) $job['tenant_public_id'];
        $ws = (string) $job['workspace_public_id'];
        $storage = $this->app->storage();
        return ['op' => 'ingest', 'args' => [
            'file_path' => $storage->rawFile($tenant, $ws, (string) $source['storage_key']),
            'format' => (string) $source['format'],
            'lakehouse_path' => $storage->lakehouseFile($tenant, $ws),
            'layer' => (string) $target['layer'],
            'table' => (string) $target['table_name'],
            'preview_path' => $storage->previewFile($tenant, $ws, (string) $targetVersion['public_id']),
        ]];
    }

    public function succeeded(array $job, array $data): array
    {
        [$source, $target, $targetVersion] = $this->load($job);
        $columns = [];
        foreach ((array) ($data['columns'] ?? []) as $c) {
            $columns[] = ['name' => (string) $c['name'], 'type' => (string) $c['type'], 'source_name' => (string) ($c['source_name'] ?? '')];
        }
        $rows = (int) ($data['row_count'] ?? 0);
        $this->repo->markVersionReady((int) $job['tenant_id'], (int) $targetVersion['id'], $rows, $columns);
        $this->repo->setResourceStatus((int) $job['tenant_id'], (int) $target['resource_id'], 'provisioning', 'active');
        (new \EduCloud\Modules\Datasets\LineageRepository($this->app->db()))
            ->record((int) $job['tenant_id'], (int) $job['workspace_id'], (int) $target['dataset_id'], [(int) $source['dataset_id']], 'ingest');
        return ['table' => $target['layer'] . '.' . $target['table_name'], 'row_count' => $rows, 'column_count' => count($columns)];
    }

    public function failed(array $job, string $errorCode, string $safeMessage): void
    {
        [, $target, $targetVersion] = $this->load($job);
        $this->repo->markVersionFailed((int) $job['tenant_id'], (int) $targetVersion['id'], $errorCode, $safeMessage);
        $this->repo->setResourceStatus((int) $job['tenant_id'], (int) $target['resource_id'], 'provisioning', 'failed');
    }

    /**
     * @param array<string, mixed> $job
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function load(array $job): array
    {
        $payload = json_decode((string) $job['payload'], true);
        $tenantId = (int) $job['tenant_id'];
        $source = $this->repo->findVersionForJob($tenantId, (int) ($payload['source_version_id'] ?? 0));
        $target = $this->repo->findForJob($tenantId, (int) ($payload['target_dataset_id'] ?? 0));
        $targetVersion = $this->repo->findVersionForJob($tenantId, (int) ($payload['target_version_id'] ?? 0));
        if (
            $source === null || $target === null || $targetVersion === null || $job['workspace_public_id'] === null
            || $source['storage_key'] === null || $target['table_name'] === null
            || (int) $targetVersion['dataset_id'] !== (int) $target['dataset_id']
            || (int) $target['workspace_id'] !== (int) $job['workspace_id']
            || !$this->repo->versionBelongsToWorkspace($tenantId, (int) $source['id'], (int) $job['workspace_id'])
        ) {
            throw new RuntimeException('Ingest job references missing data');
        }
        return [$source, $target, $targetVersion];
    }
}
