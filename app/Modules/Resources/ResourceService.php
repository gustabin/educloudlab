<?php

declare(strict_types=1);

namespace EduCloud\Modules\Resources;

use EduCloud\Core\App;
use EduCloud\Core\Auth\Policy;
use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Exceptions\ApiException;
use EduCloud\Core\Exceptions\ConflictException;
use EduCloud\Core\Exceptions\ForbiddenException;
use EduCloud\Core\Exceptions\NotFoundException;
use EduCloud\Core\Exceptions\QuotaExceededException;
use EduCloud\Core\Format;
use EduCloud\Core\Request;
use EduCloud\Modules\Workspaces\WorkspaceService;

/**
 * Educational Resource Manager. Resources belong to a workspace and inherit its visibility.
 * Provisioning is simulated (no real infrastructure): provisioning → active happens immediately,
 * but every transition goes through the lifecycle state machine and is audited.
 */
final class ResourceService
{
    private ResourceRepository $repo;
    private WorkspaceService $workspaces;
    private Policy $policy;

    public function __construct(private readonly App $app)
    {
        $this->repo = new ResourceRepository($app->db());
        $this->workspaces = new WorkspaceService($app);
        $this->policy = new Policy($app->config);
    }

    /** @return list<array<string, mixed>> */
    public function listForWorkspace(TenantContext $ctx, string $workspacePublicId, ?string $type): array
    {
        $ws = $this->workspaces->findOrFail($ctx, $workspacePublicId);
        return array_map([self::class, 'present'], $this->repo->listForWorkspace($ctx, (int) $ws['id'], $type));
    }

    /** @return array<string, mixed> */
    public function findOrFail(TenantContext $ctx, string $publicId): array
    {
        $row = $this->repo->findVisible($ctx, $publicId, $this->policy->seesWholeTenant($ctx));
        if ($row === null) {
            throw new NotFoundException('El recurso no existe.');
        }
        return $row;
    }

    /**
     * @param array<string, mixed>|null $config
     * @return array<string, mixed>
     */
    public function create(
        Request $request,
        TenantContext $ctx,
        string $workspacePublicId,
        string $type,
        string $name,
        string $region,
        ?array $config,
        mixed $tags,
    ): array {
        $ws = $this->workspaces->findOrFail($ctx, $workspacePublicId);
        $this->workspaces->assertCan($request, $ctx, $ws, 'create');

        if (!in_array($type, ResourceTypes::CREATABLE, true)) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'Este tipo de recurso se crea desde su propio módulo.', [
                ['field' => 'type', 'code' => 'not_creatable', 'message' => 'Tipo no disponible aquí.'],
            ]);
        }
        $config = ResourceTypes::normaliseConfig($type, $config);
        $tags = ResourceTypes::normaliseTags($tags, (int) $this->app->config->get('quotas.tags_per_resource', 10));
        $limit = (int) $this->app->config->get('quotas.resources_per_workspace', 20);
        $wsId = (int) $ws['id'];

        $created = $this->app->db()->transaction(function () use ($ctx, $wsId, $ws, $type, $name, $region, $config, $tags, $limit): array {
            // Serialise writes per workspace (quota + name uniqueness) and re-check it is still active under the lock.
            $this->lockActiveWorkspace($ctx, $wsId);
            if ($this->repo->countActive($ctx, $wsId) >= $limit) {
                throw new QuotaExceededException("Este workspace ya tiene el máximo de $limit recursos.");
            }
            if ($this->repo->nameTaken($ctx, $wsId, $type, $name)) {
                throw new ConflictException('Ya existe un recurso de ese tipo con ese nombre en el workspace.');
            }
            $created = $this->repo->create($ctx, $wsId, (int) $ws['owner_user_id'], $type, $name, $region, $config, $tags);
            // Simulated provisioning: no external infrastructure is involved.
            $this->repo->transition($ctx, $created['id'], 'provisioning', 'active');
            return $created;
        });

        $this->app->audit()->record($request, 'resource.create', 'success', $ctx->tenantId, $ctx->userId, $type, $created['public_id'], [
            'workspace' => $workspacePublicId,
            'region' => $region,
        ]);
        return self::present($this->findOrFail($ctx, $created['public_id']));
    }

    /**
     * @param array{name?: string, config?: array<string, mixed>, tags?: mixed} $changes
     * @return array<string, mixed>
     */
    public function update(Request $request, TenantContext $ctx, string $publicId, array $changes): array
    {
        $row = $this->findOrFail($ctx, $publicId);
        $this->assertCan($request, $ctx, $row, 'update');
        if ($row['status'] !== 'active') {
            throw new ApiException(409, 'INVALID_STATE', 'Solo se pueden modificar recursos activos.');
        }
        $name = $changes['name'] ?? (string) $row['name'];
        $config = ResourceTypes::normaliseConfig((string) $row['type'], $changes['config'] ?? null, Format::jsonColumn($row['config']));
        $tags = array_key_exists('tags', $changes)
            ? ResourceTypes::normaliseTags($changes['tags'], (int) $this->app->config->get('quotas.tags_per_resource', 10))
            : Format::jsonColumn($row['tags']);

        $this->app->db()->transaction(function () use ($ctx, $row, $name, $config, $tags): void {
            $wsId = (int) $row['workspace_id'];
            $this->lockActiveWorkspace($ctx, $wsId);
            if ($name !== $row['name'] && $this->repo->nameTaken($ctx, $wsId, (string) $row['type'], $name, (int) $row['id'])) {
                throw new ConflictException('Ya existe un recurso de ese tipo con ese nombre en el workspace.');
            }
            $this->repo->update($ctx, (int) $row['id'], $name, $config, $tags);
        });
        $this->app->audit()->record($request, 'resource.update', 'success', $ctx->tenantId, $ctx->userId, (string) $row['type'], $publicId, [
            'fields' => array_keys($changes),
        ]);
        return self::present($this->findOrFail($ctx, $publicId));
    }

    public function delete(Request $request, TenantContext $ctx, string $publicId): void
    {
        $row = $this->findOrFail($ctx, $publicId);
        $this->assertCan($request, $ctx, $row, 'delete');
        $from = (string) $row['status'];
        if (!ResourceLifecycle::canTransition($from, 'deleting')) {
            throw new ApiException(409, 'INVALID_STATE', 'El recurso no se puede eliminar en su estado actual.');
        }
        $this->app->db()->transaction(function () use ($ctx, $row, $from): void {
            if ($row['type'] === 'storage') {
                // Under the resource row lock that container creation also takes.
                $containers = new \EduCloud\Modules\ObjectStorage\ObjectStorageRepository($this->app->db());
                $containers->lockStorage($ctx, (int) $row['id']);
                if ($containers->countContainers($ctx, (int) $row['id']) > 0) {
                    throw new ApiException(409, 'STORAGE_NOT_EMPTY', 'Elimina primero los contenedores de este almacenamiento.');
                }
            }
            if (!$this->repo->transition($ctx, (int) $row['id'], $from, 'deleting')) {
                throw new ConflictException('El recurso cambió mientras se eliminaba. Inténtalo de nuevo.');
            }
            // Simulated teardown. Data-bearing types (datasets, M4) will release storage before this step.
            $this->repo->transition($ctx, (int) $row['id'], 'deleting', 'deleted');
        });
        $this->app->audit()->record($request, 'resource.delete', 'success', $ctx->tenantId, $ctx->userId, (string) $row['type'], $publicId);
    }

    /** Locks the workspace row and fails if it was deleted meanwhile (race with workspace deletion). */
    private function lockActiveWorkspace(TenantContext $ctx, int $workspaceId): void
    {
        $status = $this->app->db()->scalar(
            'SELECT status FROM workspaces WHERE tenant_id = ? AND id = ? FOR UPDATE',
            [$ctx->tenantId, $workspaceId]
        );
        if ($status !== 'active') {
            throw new NotFoundException('El workspace no existe.');
        }
    }

    /** @param array<string, mixed> $row */
    private function assertCan(Request $request, TenantContext $ctx, array $row, string $permission): void
    {
        if ($row['type'] === 'dataset') {
            // Datasets own files/tables; their lifecycle is driven by the datasets API (cleanup jobs).
            throw new ApiException(409, 'MANAGED_RESOURCE', 'Los datasets se gestionan desde la API de datasets.');
        }
        if ($row['type'] === 'pipeline') {
            throw new ApiException(409, 'MANAGED_RESOURCE', 'Los pipelines se gestionan desde su propia sección del workspace.');
        }
        if ($row['type'] === 'notebook') {
            throw new ApiException(409, 'MANAGED_RESOURCE', 'Los notebooks se gestionan desde su propia sección del workspace.');
        }
        if (in_array($row['type'], ['semantic_model', 'dashboard'], true)) {
            throw new ApiException(409, 'MANAGED_RESOURCE', 'Los modelos semánticos y dashboards se gestionan desde la sección Analítica.');
        }
        if (!$this->policy->canModify($ctx, (int) $row['owner_user_id'], $permission)) {
            $this->app->audit()->record(
                $request,
                'resource.' . $permission,
                'denied',
                $ctx->tenantId,
                $ctx->userId,
                (string) $row['type'],
                (string) $row['public_id']
            );
            throw new ForbiddenException();
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function present(array $row): array
    {
        $config = Format::jsonColumn($row['config']);
        $tags = Format::jsonColumn($row['tags']);
        return [
            'id' => (string) $row['public_id'],
            'workspace_id' => (string) $row['workspace_public_id'],
            'type' => (string) $row['type'],
            'name' => (string) $row['name'],
            'status' => (string) $row['status'],
            'region' => (string) $row['region'],
            'config' => $config === [] ? new \stdClass() : $config,
            'tags' => $tags === [] ? new \stdClass() : $tags,
            'created_at' => Format::isoUtc((string) $row['created_at']),
            'updated_at' => Format::isoUtc((string) $row['updated_at']),
        ];
    }
}
