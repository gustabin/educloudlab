<?php

declare(strict_types=1);

namespace EduCloud\Modules\SqlLab\Jobs;

use EduCloud\Core\App;
use EduCloud\Core\Format;
use EduCloud\Modules\Datasets\DatasetRepository;
use EduCloud\Modules\Jobs\JobHandler;
use RuntimeException;

/** transform: CREATE OR REPLACE TABLE <silver|gold>.<table> AS <validated student SELECT>. */
final class TransformHandler implements JobHandler
{
    private DatasetRepository $repo;

    public function __construct(private readonly App $app)
    {
        $this->repo = new DatasetRepository($app->db());
    }

    public function prepare(array $job): array
    {
        [$dataset, $version] = $this->load($job);
        $storage = $this->app->storage();
        $tenant = (string) $job['tenant_public_id'];
        $ws = (string) $job['workspace_public_id'];
        return ['op' => 'transform', 'args' => [
            'lakehouse_path' => $storage->lakehouseFile($tenant, $ws),
            'preview_path' => $storage->previewFile($tenant, $ws, (string) $version['public_id']),
            'layer' => (string) $dataset['layer'],
            'table' => (string) $dataset['table_name'],
            'sql' => (string) (Format::jsonColumn($dataset['resource_config'])['sql'] ?? ''),
        ]];
    }

    public function succeeded(array $job, array $data): array
    {
        [$dataset, $version] = $this->load($job);
        $columns = [];
        foreach ((array) ($data['columns'] ?? []) as $c) {
            $columns[] = ['name' => (string) $c['name'], 'type' => (string) $c['type']];
        }
        $rows = (int) ($data['row_count'] ?? 0);
        $this->repo->markVersionReady((int) $job['tenant_id'], (int) $version['id'], $rows, $columns);
        $this->repo->setResourceStatus((int) $job['tenant_id'], (int) $dataset['resource_id'], 'provisioning', 'active');
        return ['table' => $dataset['layer'] . '.' . $dataset['table_name'], 'row_count' => $rows, 'column_count' => count($columns)];
    }

    public function failed(array $job, string $errorCode, string $safeMessage): void
    {
        [$dataset, $version] = $this->load($job);
        $this->repo->markVersionFailed((int) $job['tenant_id'], (int) $version['id'], $errorCode, $safeMessage);
        $this->repo->setResourceStatus((int) $job['tenant_id'], (int) $dataset['resource_id'], 'provisioning', 'failed');
    }

    /**
     * @param array<string, mixed> $job
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function load(array $job): array
    {
        $payload = json_decode((string) $job['payload'], true);
        $dataset = $this->repo->findForJob((int) $job['tenant_id'], (int) ($payload['dataset_id'] ?? 0));
        $version = $this->repo->findVersionForJob((int) $job['tenant_id'], (int) ($payload['version_id'] ?? 0));
        if (
            $dataset === null || $version === null || $job['workspace_public_id'] === null
            || (int) $version['dataset_id'] !== (int) $dataset['dataset_id']
            || (int) $dataset['workspace_id'] !== (int) $job['workspace_id']
            || !in_array($dataset['layer'], ['silver', 'gold'], true) || $dataset['table_name'] === null
        ) {
            throw new RuntimeException('Transform job references missing data');
        }
        return [$dataset, $version];
    }
}
