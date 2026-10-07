<?php

declare(strict_types=1);

namespace EduCloud\Modules\Datasets\Jobs;

use EduCloud\Core\App;
use EduCloud\Modules\Datasets\DatasetRepository;
use EduCloud\Modules\Jobs\JobHandler;
use RuntimeException;

/** profile: schema discovery, row count and preview of a raw CSV upload. */
final class ProfileHandler implements JobHandler
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
        return ['op' => 'profile', 'args' => [
            'file_path' => $storage->rawFile($tenant, $ws, (string) $version['storage_key']),
            'format' => (string) $version['format'],
            'preview_path' => $storage->previewFile($tenant, $ws, (string) $version['public_id']),
        ]];
    }

    public function succeeded(array $job, array $data): array
    {
        [$dataset, $version] = $this->load($job);
        $columns = [];
        foreach ((array) ($data['columns'] ?? []) as $c) {
            $columns[] = ['name' => (string) $c['name'], 'type' => (string) $c['type'], 'suggested_name' => (string) ($c['suggested_name'] ?? '')];
        }
        $rows = (int) ($data['row_count'] ?? 0);
        $this->repo->markVersionReady((int) $job['tenant_id'], (int) $version['id'], $rows, $columns);
        $this->repo->setResourceStatus((int) $job['tenant_id'], (int) $dataset['resource_id'], 'provisioning', 'active');
        return ['row_count' => $rows, 'column_count' => count($columns)];
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
        ) {
            throw new RuntimeException('Profile job references missing data');
        }
        return [$dataset, $version];
    }
}
