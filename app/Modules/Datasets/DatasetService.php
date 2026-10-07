<?php

declare(strict_types=1);

namespace EduCloud\Modules\Datasets;

use EduCloud\Core\App;
use EduCloud\Core\Auth\Policy;
use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Exceptions\ApiException;
use EduCloud\Core\Exceptions\ConflictException;
use EduCloud\Core\Exceptions\NotFoundException;
use EduCloud\Core\Exceptions\QuotaExceededException;
use EduCloud\Core\Format;
use EduCloud\Core\Request;
use EduCloud\Core\Ulid;
use EduCloud\Core\UploadedFile;
use EduCloud\Modules\Jobs\JobController;
use EduCloud\Modules\Jobs\JobRepository;
use EduCloud\Modules\Usage\UsageService;
use EduCloud\Modules\Workspaces\WorkspaceService;
use Throwable;

/**
 * Datasets and the medallion flow of M4:
 *   upload CSV/JSON/Parquet → raw dataset (file) → profile job (schema, rows, preview)
 *   ingest     → bronze dataset (table in the workspace lakehouse) via ingest job
 * Heavy work always runs in the execution plane; this service only validates, records and enqueues.
 */
final class DatasetService
{
    public const TABLE_NAME = '/^[a-z][a-z0-9_]{0,62}$/D';

    private DatasetRepository $repo;
    private JobRepository $jobs;
    private WorkspaceService $workspaces;
    private Policy $policy;

    public function __construct(private readonly App $app)
    {
        $this->repo = new DatasetRepository($app->db());
        $this->jobs = new JobRepository($app->db());
        $this->workspaces = new WorkspaceService($app);
        $this->policy = new Policy($app->config);
    }

    /** @return list<array<string, mixed>> */
    public function list(TenantContext $ctx, string $workspacePublicId): array
    {
        $ws = $this->workspaces->findOrFail($ctx, $workspacePublicId);
        return array_map([self::class, 'present'], $this->repo->listForWorkspace($ctx, (int) $ws['id']));
    }

    /** @return array<string, mixed> */
    public function findOrFail(TenantContext $ctx, string $publicId): array
    {
        $row = $this->repo->findVisible($ctx, $publicId, $this->policy->seesWholeTenant($ctx));
        if ($row === null) {
            throw new NotFoundException('El dataset no existe.');
        }
        return $row;
    }

    /**
     * @param callable(): string $validName validates the request input after visibility has been resolved
     * @return array{dataset: array<string, mixed>, job: array<string, mixed>}
     */
    public function upload(Request $request, TenantContext $ctx, string $workspacePublicId, callable $validName, ?UploadedFile $file): array
    {
        $ws = $this->workspaces->findOrFail($ctx, $workspacePublicId);
        $this->workspaces->assertCan($request, $ctx, $ws, 'create');
        $name = $validName();
        $format = (new DataUploadValidator((int) $this->app->config->get('quotas.upload_max_bytes')))->validate($file);
        /** @var UploadedFile $file */
        $size = (int) filesize($file->tmpPath);
        $this->assertQuotas($ctx, (int) $ws['id'], $size); // early, friendly rejection (re-checked under lock)

        $storage = $this->app->storage();
        $storageKey = Ulid::generate();
        $target = $storage->rawFile((string) $this->tenantPublicId($ctx), (string) $ws['public_id'], $storageKey);
        $storage->ensureDir(dirname($target));
        if (!$file->moveTo($target)) {
            throw new ApiException(400, 'UPLOAD_FAILED', 'No se pudo guardar el archivo. Inténtalo de nuevo.');
        }

        try {
            $sha = (string) hash_file('sha256', $target, true);
            $created = $this->app->db()->transaction(function () use ($ctx, $ws, $name, $storageKey, $file, $size, $sha, $format): array {
                $this->lockUser($ctx);
                $this->lockActiveWorkspace($ctx, (int) $ws['id']);
                $this->assertQuotas($ctx, (int) $ws['id'], $size);
                if ($this->repo->nameTaken($ctx, (int) $ws['id'], 'raw', $name)) {
                    throw new ConflictException('Ya existe un dataset raw con ese nombre en el workspace.');
                }
                $dataset = $this->repo->createDataset($ctx, (int) $ws['id'], (int) $ws['owner_user_id'], $name, 'raw', null);
                $version = $this->repo->createVersion($ctx, $dataset['dataset_id'], $format, $storageKey, $file->safeClientName(), $size, $sha);
                $job = $this->jobs->create($ctx, (int) $ws['id'], 'profile', [
                    'dataset_id' => $dataset['dataset_id'],
                    'version_id' => $version['id'],
                ], (int) $this->app->config->get('execution.timeouts.profile', 60));
                return ['dataset' => $dataset, 'job' => $job];
            });
        } catch (Throwable $e) {
            $storage->delete($target);
            throw $e;
        }

        $datasetId = (string) $created['dataset']['public_id'];
        $this->app->audit()->record($request, 'dataset.upload', 'success', $ctx->tenantId, $ctx->userId, 'dataset', $datasetId, [
            'workspace' => $workspacePublicId,
            'bytes' => $size,
        ]);
        return $this->result($ctx, $created['dataset']['public_id'], $created['job']['public_id']);
    }

    /**
     * @param callable(): string $validTable validates the request input after visibility has been resolved
     * @return array{dataset: array<string, mixed>, job: array<string, mixed>}
     */
    public function ingest(Request $request, TenantContext $ctx, string $sourcePublicId, callable $validTable): array
    {
        $source = $this->findOrFail($ctx, $sourcePublicId);
        $ws = $this->workspaces->findOrFail($ctx, (string) $source['workspace_public_id']);
        $this->workspaces->assertCan($request, $ctx, $ws, 'create');
        $table = $validTable();
        if ($source['layer'] !== 'raw') {
            throw new ApiException(409, 'INVALID_STATE', 'Solo se pueden ingerir datasets de la capa raw.');
        }
        if ($source['status'] !== 'active' || $source['version_status'] !== 'ready') {
            throw new ApiException(409, 'INVALID_STATE', 'El dataset raw aún no está listo.');
        }
        if (!$this->repo->hasActiveLakehouse($ctx, (int) $ws['id'])) {
            throw new ApiException(409, 'LAKEHOUSE_REQUIRED', 'Crea un recurso lakehouse en este workspace antes de ingerir datos.');
        }
        $this->assertLakehouseHasRoom($ctx, (string) $ws['public_id']);
        $this->assertQuotas($ctx, (int) $ws['id'], 0);

        $created = $this->app->db()->transaction(function () use ($ctx, $ws, $source, $table): array {
            $this->lockUser($ctx);
            $this->lockActiveWorkspace($ctx, (int) $ws['id']);
            $this->assertQuotas($ctx, (int) $ws['id'], 0);
            if ($this->repo->nameTaken($ctx, (int) $ws['id'], 'bronze', $table)) {
                throw new ConflictException("Ya existe la tabla bronze.$table en este workspace.");
            }
            $dataset = $this->repo->createDataset($ctx, (int) $ws['id'], (int) $ws['owner_user_id'], $table, 'bronze', $table);
            $version = $this->repo->createVersion($ctx, $dataset['dataset_id'], 'table', null, null, 0, null);
            $job = $this->jobs->create($ctx, (int) $ws['id'], 'ingest', [
                'source_version_id' => (int) $source['version_id'],
                'target_dataset_id' => $dataset['dataset_id'],
                'target_version_id' => $version['id'],
            ], (int) $this->app->config->get('execution.timeouts.ingest', 120));
            return ['dataset' => $dataset, 'job' => $job];
        });

        $datasetId = (string) $created['dataset']['public_id'];
        $this->app->audit()->record($request, 'dataset.ingest', 'success', $ctx->tenantId, $ctx->userId, 'dataset', $datasetId, [
            'source' => $sourcePublicId,
            'target' => "bronze.$table",
        ]);
        return $this->result($ctx, $created['dataset']['public_id'], $created['job']['public_id']);
    }

    /** @return array<string, mixed> cleanup job */
    public function delete(Request $request, TenantContext $ctx, string $publicId): array
    {
        $row = $this->findOrFail($ctx, $publicId);
        $ws = $this->workspaces->findOrFail($ctx, (string) $row['workspace_public_id']);
        $this->workspaces->assertCan($request, $ctx, $ws, 'delete');
        if ((Format::jsonColumn($row['resource_config'])['lab_setup'] ?? false) === true) {
            throw new ApiException(409, 'LAB_MANAGED', 'Este dataset forma parte del laboratorio y no se puede eliminar.');
        }
        if (!in_array($row['status'], ['active', 'failed'], true)) {
            throw new ApiException(409, 'INVALID_STATE', 'El dataset se está procesando; espera a que termine.');
        }
        $job = $this->app->db()->transaction(function () use ($ctx, $row): array {
            if (!$this->repo->setResourceStatus($ctx->tenantId, (int) $row['resource_id'], (string) $row['status'], 'deleting')) {
                throw new ConflictException('El dataset cambió mientras se eliminaba. Inténtalo de nuevo.');
            }
            $timeout = (int) $this->app->config->get('execution.timeouts.cleanup', 60);
            return $this->jobs->create($ctx, (int) $row['workspace_id'], 'cleanup', ['dataset_id' => (int) $row['dataset_id']], $timeout);
        });
        $this->app->audit()->record($request, 'dataset.delete', 'success', $ctx->tenantId, $ctx->userId, 'dataset', $publicId);
        return $this->presentJob($ctx, $job['public_id']);
    }

    /** @return array{columns: list<string>, rows: list<list<mixed>>} */
    public function preview(TenantContext $ctx, string $publicId): array
    {
        $row = $this->findOrFail($ctx, $publicId);
        if ($row['version_status'] !== 'ready') {
            throw new ApiException(409, 'INVALID_STATE', 'La vista previa estará disponible cuando el dataset esté listo.');
        }
        $file = $this->app->storage()->previewFile(
            $this->tenantPublicId($ctx),
            (string) $row['workspace_public_id'],
            (string) $row['version_public_id']
        );
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($data) || !is_array($data['columns'] ?? null) || !is_array($data['rows'] ?? null)) {
            throw new NotFoundException('La vista previa no está disponible.');
        }
        return ['columns' => $data['columns'], 'rows' => $data['rows']];
    }

    /**
     * Upstream sources and downstream consumers of a dataset (one level each way), for the "Linaje" view.
     *
     * @return array{dataset: array<string, mixed>, upstream: list<array<string, mixed>>, downstream: list<array<string, mixed>>}
     */
    public function lineage(TenantContext $ctx, string $publicId): array
    {
        $row = $this->findOrFail($ctx, $publicId);
        $edges = (new LineageRepository($this->app->db()))->neighbours($ctx, (int) $row['dataset_id']);
        $present = static fn (array $e): array => [
            'id' => (string) $e['public_id'],
            'name' => (string) $e['name'],
            'layer' => (string) $e['layer'],
            'table' => $e['table_name'] === null ? null : $e['layer'] . '.' . $e['table_name'],
            'status' => (string) $e['status'],
            'via' => (string) $e['via'],
            'pipeline' => $e['pipeline_public_id'] === null
                ? null
                : ['id' => (string) $e['pipeline_public_id'], 'name' => (string) $e['pipeline_name']],
        ];
        return [
            'dataset' => ['id' => (string) $row['public_id'], 'name' => (string) $row['name'], 'layer' => (string) $row['layer']],
            'upstream' => array_map($present, $edges['upstream']),
            'downstream' => array_map($present, $edges['downstream']),
        ];
    }

    /** Early, friendly check; the runner enforces the same cap authoritatively after writing (LAKEHOUSE_FULL). */
    public function assertLakehouseHasRoom(TenantContext $ctx, string $workspacePublicId): void
    {
        $file = $this->app->storage()->lakehouseFile($ctx->tenantPublicId, $workspacePublicId);
        $maxMb = (int) $this->app->config->get('execution.limits.lakehouse_max_mb', 200);
        if (is_file($file) && filesize($file) >= $maxMb * 1048576) {
            throw new QuotaExceededException("El lakehouse de este workspace alcanzó su límite de $maxMb MB. Elimina tablas que no uses.");
        }
    }

    private function assertQuotas(TenantContext $ctx, int $workspaceId, int $newBytes): void
    {
        $maxDatasets = (int) $this->app->config->get('quotas.datasets_per_workspace', 50);
        if ($this->repo->countForWorkspace($ctx, $workspaceId) >= $maxDatasets) {
            throw new QuotaExceededException("Este workspace ya tiene el máximo de $maxDatasets datasets.");
        }
        // Raw files + lakehouse tables (M11a); ingests ($newBytes = 0) need the user to still be under the quota.
        (new UsageService($this->app))->assertRoom($ctx, $newBytes);
        $maxJobs = (int) $this->app->config->get('quotas.active_jobs_per_user', 3);
        if ($this->jobs->countActiveForUser($ctx, $ctx->userId) >= $maxJobs) {
            throw new QuotaExceededException('Tienes demasiados trabajos en curso. Espera a que terminen.');
        }
    }

    /** Serialises one user's quota-relevant writes (lock order: membership, then workspace). */
    private function lockUser(TenantContext $ctx): void
    {
        $this->app->db()->select('SELECT id FROM memberships WHERE tenant_id = ? AND user_id = ? FOR UPDATE', [$ctx->tenantId, $ctx->userId]);
    }

    private function lockActiveWorkspace(TenantContext $ctx, int $workspaceId): void
    {
        $status = $this->app->db()->scalar('SELECT status FROM workspaces WHERE tenant_id = ? AND id = ? FOR UPDATE', [$ctx->tenantId, $workspaceId]);
        if ($status !== 'active') {
            throw new NotFoundException('El workspace no existe.');
        }
    }

    private function tenantPublicId(TenantContext $ctx): string
    {
        return $ctx->tenantPublicId;
    }

    /** @return array{dataset: array<string, mixed>, job: array<string, mixed>} */
    private function result(TenantContext $ctx, string $datasetPublicId, string $jobPublicId): array
    {
        return ['dataset' => self::present($this->findOrFail($ctx, $datasetPublicId)), 'job' => $this->presentJob($ctx, $jobPublicId)];
    }

    /** @return array<string, mixed> */
    private function presentJob(TenantContext $ctx, string $jobPublicId): array
    {
        $job = $this->jobs->findVisible($ctx, $jobPublicId, true);
        return $job === null ? ['id' => $jobPublicId] : JobController::present($job);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function present(array $row): array
    {
        $columns = Format::jsonColumn($row['schema_json'] ?? null);
        return [
            'id' => (string) $row['public_id'],
            'workspace_id' => (string) $row['workspace_public_id'],
            'name' => (string) $row['name'],
            'layer' => (string) $row['layer'],
            'table' => $row['table_name'] === null ? null : $row['layer'] . '.' . $row['table_name'],
            'status' => (string) $row['status'],
            'version' => $row['version_id'] === null ? null : [
                'id' => (string) $row['version_public_id'],
                'number' => (int) $row['version_no'],
                'format' => (string) $row['format'],
                'original_name' => $row['original_name'],
                'bytes' => (int) $row['bytes'],
                'row_count' => $row['row_count'] === null ? null : (int) $row['row_count'],
                'column_count' => $row['column_count'] === null ? null : (int) $row['column_count'],
                'status' => (string) $row['version_status'],
            ],
            'columns' => $columns,
            'sql' => Format::jsonColumn($row['resource_config'] ?? null)['sql'] ?? null,
            'error' => $row['error_code'] === null ? null : ['code' => (string) $row['error_code'], 'message' => (string) $row['error_message']],
            'created_at' => Format::isoUtc((string) $row['created_at']),
            'updated_at' => Format::isoUtc((string) $row['updated_at']),
        ];
    }
}
