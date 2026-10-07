<?php

declare(strict_types=1);

namespace EduCloud\Modules\Datasets\Jobs;

use EduCloud\Core\App;
use EduCloud\Modules\Datasets\DatasetRepository;
use EduCloud\Modules\Jobs\JobHandler;
use RuntimeException;

/**
 * cleanup: releases a deleted dataset's storage and finishes its lifecycle (deleting → deleted).
 * Raw datasets: files are removed here (no runner needed). Table datasets: the runner drops the table.
 */
final class CleanupHandler implements JobHandler
{
    private DatasetRepository $repo;

    public function __construct(private readonly App $app)
    {
        $this->repo = new DatasetRepository($app->db());
    }

    public function prepare(array $job): ?array
    {
        $dataset = $this->load($job);
        if ($dataset['layer'] === 'raw' || $dataset['table_name'] === null) {
            return null;
        }
        return ['op' => 'drop_table', 'args' => [
            'lakehouse_path' => $this->app->storage()->lakehouseFile((string) $job['tenant_public_id'], (string) $job['workspace_public_id']),
            'layer' => (string) $dataset['layer'],
            'table' => (string) $dataset['table_name'],
        ]];
    }

    public function succeeded(array $job, array $data): array
    {
        $dataset = $this->load($job);
        $storage = $this->app->storage();
        $tenant = (string) $job['tenant_public_id'];
        $ws = (string) $job['workspace_public_id'];
        $removed = 0;
        foreach ($this->repo->versions((int) $job['tenant_id'], (int) $dataset['dataset_id']) as $version) {
            if ($version['storage_key'] !== null) {
                $storage->delete($storage->rawFile($tenant, $ws, (string) $version['storage_key']));
                $removed++;
            }
            $storage->delete($storage->previewFile($tenant, $ws, (string) $version['public_id']));
        }
        $this->repo->setResourceStatus((int) $job['tenant_id'], (int) $dataset['resource_id'], 'deleting', 'deleted');
        // Frees <layer>.<table> for a new dataset (unique per workspace); the deleted dataset keeps its name for history.
        $this->repo->releaseTableName((int) $job['tenant_id'], (int) $dataset['dataset_id']);
        return ['files_removed' => $removed, 'table_dropped' => (bool) ($data['dropped'] ?? false)];
    }

    public function failed(array $job, string $errorCode, string $safeMessage): void
    {
        // The dataset stays in 'deleting' (invisible to users); the scheduler retries workspace-level cleanup.
        $this->app->logger->warning('dataset_cleanup_failed', ['job' => $job['public_id'], 'code' => $errorCode]);
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function load(array $job): array
    {
        $payload = json_decode((string) $job['payload'], true);
        $dataset = $this->repo->findForJob((int) $job['tenant_id'], (int) ($payload['dataset_id'] ?? 0));
        if ($dataset === null || $job['workspace_public_id'] === null || (int) $dataset['workspace_id'] !== (int) $job['workspace_id']) {
            throw new RuntimeException('Cleanup job references missing data');
        }
        return $dataset;
    }
}
