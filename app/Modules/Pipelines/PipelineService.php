<?php

declare(strict_types=1);

namespace EduCloud\Modules\Pipelines;

use EduCloud\Core\App;
use EduCloud\Core\Auth\Policy;
use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Exceptions\ApiException;
use EduCloud\Core\Exceptions\ConflictException;
use EduCloud\Core\Exceptions\ForbiddenException;
use EduCloud\Core\Exceptions\NotFoundException;
use EduCloud\Core\Exceptions\QuotaExceededException;
use EduCloud\Core\Exceptions\ValidationException;
use EduCloud\Core\Format;
use EduCloud\Core\Request;
use EduCloud\Modules\Datasets\DatasetRepository;
use EduCloud\Modules\Datasets\DatasetService;
use EduCloud\Modules\Jobs\JobRepository;
use EduCloud\Modules\Pipelines\Jobs\PipelineRunHandler;
use EduCloud\Modules\Resources\ResourceRepository;
use EduCloud\Modules\Resources\ResourceTypes;
use EduCloud\Modules\Usage\UsageService;
use EduCloud\Modules\Workspaces\WorkspaceService;

/**
 * Pipelines (M7, ADR-009): CRUD of pipeline resources and on-demand runs executed by the dispatcher.
 * A run writes <silver|gold>.<table>: a table that does not exist yet, or one this same pipeline produced before.
 */
final class PipelineService
{
    private PipelineRepository $repo;
    private DatasetRepository $datasets;
    private WorkspaceService $workspaces;
    private Policy $policy;

    public function __construct(private readonly App $app)
    {
        $this->repo = new PipelineRepository($app->db());
        $this->datasets = new DatasetRepository($app->db());
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
            throw new NotFoundException('El pipeline no existe.');
        }
        return $row;
    }

    /**
     * @param callable(): array{name: string, definition: mixed} $validInput
     * @return array<string, mixed>
     */
    public function create(Request $request, TenantContext $ctx, string $workspacePublicId, callable $validInput): array
    {
        $ws = $this->workspaces->findOrFail($ctx, $workspacePublicId);
        $this->workspaces->assertCan($request, $ctx, $ws, 'create');
        $input = $validInput();
        $definition = PipelineDefinition::validate($input['definition']);
        $resources = new ResourceRepository($this->app->db());
        $limit = (int) $this->app->config->get('quotas.resources_per_workspace', 20);
        $created = $this->app->db()->transaction(function () use ($ctx, $ws, $input, $definition, $resources, $limit): array {
            $this->lockActiveWorkspace($ctx, (int) $ws['id']);
            if ($resources->countActive($ctx, (int) $ws['id']) >= $limit) {
                throw new QuotaExceededException("Este workspace ya tiene el máximo de $limit recursos.");
            }
            if ($resources->nameTaken($ctx, (int) $ws['id'], 'pipeline', $input['name'])) {
                throw new ConflictException('Ya existe un pipeline con ese nombre en el workspace.');
            }
            $owner = (int) $ws['owner_user_id'];
            $resource = $resources->create($ctx, (int) $ws['id'], $owner, 'pipeline', $input['name'], ResourceTypes::REGIONS[0], [], []);
            $resources->transition($ctx, $resource['id'], 'provisioning', 'active');
            $this->repo->create($ctx, (int) $ws['id'], $resource['id'], $definition);
            return $resource;
        });
        $this->app->audit()->record($request, 'pipeline.create', 'success', $ctx->tenantId, $ctx->userId, 'pipeline', $created['public_id']);
        return self::present($this->findOrFail($ctx, $created['public_id']));
    }

    /**
     * @param callable(): array{name?: string, definition?: mixed} $validInput
     * @return array<string, mixed>
     */
    public function update(Request $request, TenantContext $ctx, string $publicId, callable $validInput): array
    {
        $row = $this->findOrFail($ctx, $publicId);
        $this->assertCan($request, $ctx, $row, 'update');
        $input = $validInput();
        $definition = array_key_exists('definition', $input) ? PipelineDefinition::validate($input['definition']) : null;
        $resources = new ResourceRepository($this->app->db());
        $this->app->db()->transaction(function () use ($ctx, $row, $input, $definition, $resources): void {
            $this->lockActiveWorkspace($ctx, (int) $row['workspace_id']);
            if (isset($input['name']) && $input['name'] !== $row['name']) {
                if ($resources->nameTaken($ctx, (int) $row['workspace_id'], 'pipeline', $input['name'], (int) $row['resource_id'])) {
                    throw new ConflictException('Ya existe un pipeline con ese nombre en el workspace.');
                }
                $resources->update($ctx, (int) $row['resource_id'], $input['name'], [], []);
            }
            if ($definition !== null) {
                $this->repo->updateDefinition($ctx, (int) $row['id'], $definition);
            }
        });
        $this->app->audit()->record($request, 'pipeline.update', 'success', $ctx->tenantId, $ctx->userId, 'pipeline', $publicId, [
            'fields' => array_keys($input),
        ]);
        return self::present($this->findOrFail($ctx, $publicId));
    }

    public function delete(Request $request, TenantContext $ctx, string $publicId): void
    {
        $row = $this->findOrFail($ctx, $publicId);
        $this->assertCan($request, $ctx, $row, 'delete');
        if (in_array($row['last_run_status'], ['queued', 'running'], true)) {
            throw new ApiException(409, 'PIPELINE_RUNNING', 'El pipeline se está ejecutando. Cancélalo o espera a que termine.');
        }
        $resources = new ResourceRepository($this->app->db());
        $this->app->db()->transaction(function () use ($ctx, $row, $resources): void {
            $resources->transition($ctx, (int) $row['resource_id'], 'active', 'deleting');
            $resources->transition($ctx, (int) $row['resource_id'], 'deleting', 'deleted');
        });
        $this->app->audit()->record($request, 'pipeline.delete', 'success', $ctx->tenantId, $ctx->userId, 'pipeline', $publicId);
    }

    /**
     * Enqueues a run of the current definition (202). Validates everything that can be known up front.
     *
     * @return array<string, mixed> the run
     */
    public function run(Request $request, TenantContext $ctx, string $publicId): array
    {
        $row = $this->findOrFail($ctx, $publicId);
        $this->assertCan($request, $ctx, $row, 'execute');
        $definition = PipelineDefinition::validate(Format::jsonColumn($row['definition']));
        $wsId = (int) $row['workspace_id'];
        if (!$this->datasets->hasActiveLakehouse($ctx, $wsId)) {
            throw new ApiException(409, 'LAKEHOUSE_REQUIRED', 'Crea un recurso lakehouse en este workspace para ejecutar pipelines.');
        }
        (new DatasetService($this->app))->assertLakehouseHasRoom($ctx, (string) $row['workspace_public_id']);
        (new UsageService($this->app))->assertRoom($ctx, 0);
        $output = PipelineDefinition::output($definition);
        $jobs = new JobRepository($this->app->db());

        $created = $this->app->db()->transaction(function () use ($ctx, $row, $definition, $wsId, $output, $jobs, $publicId): array {
            $this->app->db()->select('SELECT id FROM memberships WHERE tenant_id = ? AND user_id = ? FOR UPDATE', [$ctx->tenantId, $ctx->userId]);
            $this->lockActiveWorkspace($ctx, $wsId);
            $max = (int) $this->app->config->get('quotas.active_jobs_per_user', 3);
            if ($jobs->countActiveForUser($ctx, $ctx->userId) >= $max) {
                throw new QuotaExceededException('Tienes demasiados trabajos en curso. Espera a que terminen.');
            }
            if (in_array($row['last_run_status'], ['queued', 'running'], true)) {
                throw new ApiException(409, 'PIPELINE_RUNNING', 'Este pipeline ya se está ejecutando.');
            }
            $raw = [];
            foreach (PipelineDefinition::rawInputs($definition) as $name) {
                $source = $this->datasets->findRaw($ctx, $wsId, $name);
                if ($source === null || $source['version_status'] !== 'ready') {
                    throw new ValidationException([
                        ['field' => 'definition', 'code' => 'raw_missing', 'message' => "El dataset raw «{$name}» no existe o aún no está listo."],
                    ]);
                }
                $raw[$name] = (int) $source['version_id'];
            }
            $existing = $this->datasets->findTable($ctx, $wsId, $output['layer'], $output['table']);
            $target = "{$output['layer']}.{$output['table']}";
            if ($existing !== null) {
                if ((Format::jsonColumn($existing['resource_config'])['pipeline_id'] ?? null) !== $publicId) {
                    throw new ConflictException("La tabla $target ya existe y no la creó este pipeline. Usa otro nombre de salida.");
                }
                if ($existing['status'] !== 'active') {
                    throw new ConflictException("La tabla $target se está procesando o eliminando.");
                }
                $datasetId = (int) $existing['dataset_id'];
                $new = false;
            } else {
                $max = (int) $this->app->config->get('quotas.datasets_per_workspace', 50);
                if ($this->datasets->countForWorkspace($ctx, $wsId) >= $max) {
                    throw new QuotaExceededException("Este workspace ya tiene el máximo de $max datasets.");
                }
                $owner = (int) $row['owner_user_id'];
                $config = ['pipeline_id' => $publicId];
                $dataset = $this->datasets->createDataset($ctx, $wsId, $owner, $output['table'], $output['layer'], $output['table'], $config);
                $datasetId = $dataset['dataset_id'];
                $new = true;
            }
            $version = $this->datasets->createVersion($ctx, $datasetId, 'table', null, null, 0, null);
            $run = $this->repo->createRun($ctx, (int) $row['id'], (int) $row['version'], $definition);
            $job = $jobs->create($ctx, $wsId, 'pipeline_run', [
                'run_id' => $run['id'],
                'dataset_id' => $datasetId,
                'version_id' => $version['id'],
                'new_dataset' => $new,
                'raw' => $raw === [] ? new \stdClass() : $raw,
            ], (int) $this->app->config->get('execution.timeouts.pipeline_run', 300), 5, 1 + (int) ($definition['retries'] ?? 0));
            $this->repo->attachJob($ctx->tenantId, $run['id'], $job['id']);
            return $run;
        });
        $this->app->audit()->record($request, 'pipeline.run', 'success', $ctx->tenantId, $ctx->userId, 'pipeline', $publicId, [
            'run' => $created['public_id'],
        ]);
        return $this->getRun($ctx, $created['public_id']);
    }

    /** @return list<array<string, mixed>> */
    public function runs(TenantContext $ctx, string $publicId): array
    {
        $row = $this->findOrFail($ctx, $publicId);
        return array_map([self::class, 'presentRun'], $this->repo->runs($ctx, (int) $row['id'], 20));
    }

    /** @return array<string, mixed> */
    public function getRun(TenantContext $ctx, string $runPublicId): array
    {
        return self::presentRun($this->findRunOrFail($ctx, $runPublicId));
    }

    /**
     * Cancels a queued run at once, or asks the dispatcher to stop a running one.
     *
     * @return array<string, mixed>
     */
    public function cancel(Request $request, TenantContext $ctx, string $runPublicId): array
    {
        $run = $this->findRunOrFail($ctx, $runPublicId);
        if (!$this->policy->canModify($ctx, (int) $run['owner_user_id'], 'execute')) {
            throw new ForbiddenException();
        }
        $outcome = $run['job_id'] === null ? null : (new JobRepository($this->app->db()))->cancel($ctx->tenantId, (int) $run['job_id']);
        if ($outcome === null) {
            throw new ApiException(409, 'RUN_FINISHED', 'Esta ejecución ya terminó.');
        }
        if ($outcome === 'cancelled') {
            // Never reached the dispatcher: finish it here with the same cleanup as a failed run.
            $job = $this->app->db()->selectOne(
                'SELECT j.*, t.public_id AS tenant_public_id, w.public_id AS workspace_public_id FROM jobs j
                   JOIN tenants t ON t.id = j.tenant_id LEFT JOIN workspaces w ON w.tenant_id = j.tenant_id AND w.id = j.workspace_id
                  WHERE j.tenant_id = ? AND j.id = ?',
                [$ctx->tenantId, (int) $run['job_id']]
            );
            if ($job !== null) {
                (new PipelineRunHandler($this->app))->failed($job, 'CANCELLED', 'La ejecución se canceló antes de empezar.');
            }
        }
        $this->app->audit()->record($request, 'pipeline.cancel', 'success', $ctx->tenantId, $ctx->userId, 'pipeline_run', $runPublicId, [
            'outcome' => $outcome,
        ]);
        return $this->getRun($ctx, $runPublicId);
    }

    /** @return array<string, mixed> */
    private function findRunOrFail(TenantContext $ctx, string $runPublicId): array
    {
        $run = $this->repo->findRunVisible($ctx, $runPublicId, $this->policy->seesWholeTenant($ctx));
        if ($run === null) {
            throw new NotFoundException('La ejecución no existe.');
        }
        return $run;
    }

    /** @param array<string, mixed> $row */
    private function assertCan(Request $request, TenantContext $ctx, array $row, string $permission): void
    {
        if (!$this->policy->canModify($ctx, (int) $row['owner_user_id'], $permission)) {
            $id = (string) $row['public_id'];
            $this->app->audit()->record($request, 'pipeline.' . $permission, 'denied', $ctx->tenantId, $ctx->userId, 'pipeline', $id);
            throw new ForbiddenException();
        }
        if ($row['workspace_status'] !== 'active') {
            throw new ApiException(409, 'CONFLICT', 'El workspace no está activo.');
        }
    }

    private function lockActiveWorkspace(TenantContext $ctx, int $workspaceId): void
    {
        $status = $this->app->db()->scalar('SELECT status FROM workspaces WHERE tenant_id = ? AND id = ? FOR UPDATE', [$ctx->tenantId, $workspaceId]);
        if ($status !== 'active') {
            throw new NotFoundException('El workspace no existe.');
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function present(array $row): array
    {
        return [
            'id' => (string) $row['public_id'],
            'workspace_id' => (string) $row['workspace_public_id'],
            'name' => (string) $row['name'],
            'version' => (int) $row['version'],
            'definition' => Format::jsonColumn($row['definition']),
            'last_run_status' => $row['last_run_status'] === null ? null : (string) $row['last_run_status'],
            'created_at' => Format::isoUtc((string) $row['created_at']),
            'updated_at' => Format::isoUtc((string) $row['updated_at']),
        ];
    }

    /**
     * @param array<string, mixed> $run
     * @return array<string, mixed>
     */
    public static function presentRun(array $run): array
    {
        $definition = Format::jsonColumn($run['definition']);
        $last = $definition['nodes'][count($definition['nodes'] ?? []) - 1] ?? [];
        return [
            'id' => (string) $run['public_id'],
            'pipeline' => ['id' => (string) $run['pipeline_public_id'], 'name' => (string) $run['pipeline_name']],
            'workspace_id' => (string) $run['workspace_public_id'],
            'status' => (string) $run['status'],
            'cancel_requested' => (int) ($run['job_cancel_requested'] ?? 0) === 1 && $run['status'] === 'running',
            'definition_version' => (int) $run['definition_version'],
            'output' => isset($last['layer'], $last['table']) ? $last['layer'] . '.' . $last['table'] : null,
            'steps' => Format::jsonColumn($run['steps']),
            'error' => $run['error_code'] === null ? null : ['code' => (string) $run['error_code'], 'message' => (string) $run['error_message']],
            'attempts' => $run['job_attempts'] === null ? 0 : (int) $run['job_attempts'],
            'triggered_by' => ['id' => (string) $run['user_public_id'], 'display_name' => (string) $run['user_name']],
            'created_at' => Format::isoUtc((string) $run['created_at']),
            'started_at' => Format::isoUtc($run['started_at'] === null ? null : (string) $run['started_at']),
            'finished_at' => Format::isoUtc($run['finished_at'] === null ? null : (string) $run['finished_at']),
        ];
    }
}
