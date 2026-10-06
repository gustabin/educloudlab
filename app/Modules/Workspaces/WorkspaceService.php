<?php

declare(strict_types=1);

namespace EduCloud\Modules\Workspaces;

use EduCloud\Core\App;
use EduCloud\Core\Auth\Policy;
use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Exceptions\ApiException;
use EduCloud\Core\Exceptions\ConflictException;
use EduCloud\Core\Exceptions\ForbiddenException;
use EduCloud\Core\Exceptions\NotFoundException;
use EduCloud\Core\Exceptions\QuotaExceededException;
use EduCloud\Core\Format;
use EduCloud\Core\Pagination;
use EduCloud\Core\Request;

/** Workspace use cases. Tenant comes from TenantContext only; invisible workspaces are reported as 404. */
final class WorkspaceService
{
    private WorkspaceRepository $repo;
    private Policy $policy;

    public function __construct(private readonly App $app)
    {
        $this->repo = new WorkspaceRepository($app->db());
        $this->policy = new Policy($app->config);
    }

    /** @return array{items: list<array<string, mixed>>, meta: array<string, int>} */
    public function list(TenantContext $ctx, Pagination $page, string $search, string $sort, string $dir): array
    {
        $whole = $this->policy->seesWholeTenant($ctx);
        $rows = $this->repo->list($ctx, $whole, $search, $sort, $dir, $page->perPage, $page->offset());
        return [
            'items' => array_map([self::class, 'present'], $rows),
            'meta' => $page->meta($this->repo->count($ctx, $whole, $search)),
        ];
    }

    /**
     * Loads a visible workspace row or throws 404.
     *
     * @return array<string, mixed>
     */
    public function findOrFail(TenantContext $ctx, string $publicId): array
    {
        $row = $this->repo->findVisible($ctx, $publicId, $this->policy->seesWholeTenant($ctx));
        if ($row === null) {
            throw new NotFoundException('El workspace no existe.');
        }
        return $row;
    }

    /** @return array<string, mixed> */
    public function create(Request $request, TenantContext $ctx, string $name, ?string $description): array
    {
        $limit = (int) $this->app->config->get('quotas.workspaces_per_user', 5);
        $created = $this->app->db()->transaction(function () use ($ctx, $name, $description, $limit): array {
            // Serialise this owner's workspace writes (quota + name uniqueness) on a row that always exists.
            $this->lockOwner($ctx, $ctx->userId);
            if ($this->repo->countActiveOwned($ctx, $ctx->userId) >= $limit) {
                throw new QuotaExceededException("Alcanzaste el máximo de $limit workspaces. Elimina alguno para crear otro.");
            }
            if ($this->repo->nameTaken($ctx, $ctx->userId, $name)) {
                throw new ConflictException('Ya tienes un workspace con ese nombre.');
            }
            return $this->repo->create($ctx, $name, $description);
        });

        $this->app->audit()->record($request, 'workspace.create', 'success', $ctx->tenantId, $ctx->userId, 'workspace', $created['public_id']);
        return self::present($this->findOrFail($ctx, $created['public_id']));
    }

    /**
     * @param array{name?: string, description?: string|null} $changes
     * @return array<string, mixed>
     */
    public function update(Request $request, TenantContext $ctx, string $publicId, array $changes): array
    {
        $row = $this->findOrFail($ctx, $publicId);
        $this->assertCan($request, $ctx, $row, 'update');

        $name = $changes['name'] ?? (string) $row['name'];
        $description = array_key_exists('description', $changes) ? $changes['description'] : $row['description'];
        $this->app->db()->transaction(function () use ($ctx, $row, $name, $description): void {
            $this->lockOwner($ctx, (int) $row['owner_user_id']);
            if ($name !== $row['name'] && $this->repo->nameTaken($ctx, (int) $row['owner_user_id'], $name, (int) $row['id'])) {
                throw new ConflictException('Ya existe un workspace con ese nombre.');
            }
            $this->repo->update($ctx, (int) $row['id'], $name, $description === '' ? null : $description);
        });
        $this->app->audit()->record($request, 'workspace.update', 'success', $ctx->tenantId, $ctx->userId, 'workspace', $publicId, [
            'fields' => array_keys($changes),
        ]);
        return self::present($this->findOrFail($ctx, $publicId));
    }

    public function delete(Request $request, TenantContext $ctx, string $publicId): void
    {
        $row = $this->findOrFail($ctx, $publicId);
        $this->assertCan($request, $ctx, $row, 'delete');
        $released = $this->app->db()->transaction(fn (): int => $this->repo->markDeleted($ctx, (int) $row['id']));
        $this->app->audit()->record($request, 'workspace.delete', 'success', $ctx->tenantId, $ctx->userId, 'workspace', $publicId, [
            'resources_released' => $released,
        ]);
    }

    /**
     * Row lock that serialises an owner's workspace writes. The membership row always exists, so this never
     * degrades into a gap lock (which could deadlock two concurrent "first" creates).
     */
    private function lockOwner(TenantContext $ctx, int $ownerUserId): void
    {
        $this->app->db()->select(
            'SELECT id FROM memberships WHERE tenant_id = ? AND user_id = ? FOR UPDATE',
            [$ctx->tenantId, $ownerUserId]
        );
    }

    /** @param array<string, mixed> $row */
    public function assertCan(Request $request, TenantContext $ctx, array $row, string $permission): void
    {
        if (!$this->policy->canModify($ctx, (int) $row['owner_user_id'], $permission)) {
            $publicId = (string) $row['public_id'];
            $this->app->audit()->record($request, 'workspace.' . $permission, 'denied', $ctx->tenantId, $ctx->userId, 'workspace', $publicId);
            throw new ForbiddenException();
        }
        if ($row['status'] !== 'active') {
            throw new ApiException(409, 'CONFLICT', 'El workspace no está activo.');
        }
    }

    /**
     * Public representation (no internal ids).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function present(array $row): array
    {
        return [
            'id' => (string) $row['public_id'],
            'name' => (string) $row['name'],
            'description' => $row['description'] === null ? null : (string) $row['description'],
            'purpose' => (string) $row['purpose'],
            'status' => (string) $row['status'],
            'owner' => ['id' => (string) $row['owner_public_id'], 'display_name' => (string) $row['owner_name']],
            'resource_count' => (int) $row['resource_count'],
            'created_at' => Format::isoUtc((string) $row['created_at']),
            'updated_at' => Format::isoUtc((string) $row['updated_at']),
        ];
    }
}
