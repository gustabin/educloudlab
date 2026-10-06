<?php

declare(strict_types=1);

namespace EduCloud\Modules\Resources;

use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Db;
use EduCloud\Core\Ulid;

/**
 * Tenant-scoped resource registry. Visibility of a resource follows its workspace: callers pass
 * $wholeTenant=false to restrict to workspaces owned by the current user.
 */
final class ResourceRepository
{
    private const SELECT = "SELECT r.id, r.public_id, r.tenant_id, r.workspace_id, r.owner_user_id, r.type, r.name, r.status,
               r.region, r.config, r.tags, r.created_at, r.updated_at, w.public_id AS workspace_public_id
          FROM resources r
          JOIN workspaces w ON w.tenant_id = r.tenant_id AND w.id = r.workspace_id";

    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function listForWorkspace(TenantContext $ctx, int $workspaceId, ?string $type): array
    {
        $sql = self::SELECT . " WHERE r.tenant_id = ? AND r.workspace_id = ? AND r.status <> 'deleted'";
        $params = [$ctx->tenantId, $workspaceId];
        if ($type !== null) {
            $sql .= ' AND r.type = ?';
            $params[] = $type;
        }
        return $this->db->select($sql . ' ORDER BY r.type, r.name, r.id', $params);
    }

    /** @return array<string, mixed>|null */
    public function findVisible(TenantContext $ctx, string $publicId, bool $wholeTenant): ?array
    {
        $sql = self::SELECT . " WHERE r.tenant_id = ? AND r.public_id = ? AND r.status <> 'deleted' AND w.status <> 'deleted'";
        $params = [$ctx->tenantId, $publicId];
        if (!$wholeTenant) {
            $sql .= ' AND w.owner_user_id = ?';
            $params[] = $ctx->userId;
        }
        return $this->db->selectOne($sql, $params);
    }

    public function countActive(TenantContext $ctx, int $workspaceId): int
    {
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM resources WHERE tenant_id = ? AND workspace_id = ? AND status <> 'deleted'",
            [$ctx->tenantId, $workspaceId]
        );
    }

    public function nameTaken(TenantContext $ctx, int $workspaceId, string $type, string $name, int $exceptId = 0): bool
    {
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM resources
              WHERE tenant_id = ? AND workspace_id = ? AND type = ? AND name = ? AND status <> 'deleted' AND id <> ?",
            [$ctx->tenantId, $workspaceId, $type, $name, $exceptId]
        ) > 0;
    }

    /**
     * @param array<string, mixed>  $config
     * @param array<string, string> $tags
     * @return array{id: int, public_id: string}
     */
    public function create(
        TenantContext $ctx,
        int $workspaceId,
        int $ownerUserId,
        string $type,
        string $name,
        string $region,
        array $config,
        array $tags,
    ): array {
        $publicId = Ulid::generate();
        $id = $this->db->insert(
            'INSERT INTO resources (public_id, tenant_id, workspace_id, owner_user_id, type, name, status, region, config, tags)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$publicId, $ctx->tenantId, $workspaceId, $ownerUserId, $type, $name, 'provisioning', $region, self::json($config), self::json($tags)]
        );
        return ['id' => $id, 'public_id' => $publicId];
    }

    /**
     * Compare-and-set status change: only succeeds if the current status is $from.
     */
    public function transition(TenantContext $ctx, int $id, string $from, string $to): bool
    {
        ResourceLifecycle::assertTransition($from, $to);
        return $this->db->execute(
            'UPDATE resources SET status = ? WHERE tenant_id = ? AND id = ? AND status = ?',
            [$to, $ctx->tenantId, $id, $from]
        ) === 1;
    }

    /**
     * @param array<string, mixed>  $config
     * @param array<string, string> $tags
     */
    public function update(TenantContext $ctx, int $id, string $name, array $config, array $tags): void
    {
        $this->db->execute(
            "UPDATE resources SET name = ?, config = ?, tags = ? WHERE tenant_id = ? AND id = ? AND status = 'active'",
            [$name, self::json($config), self::json($tags), $ctx->tenantId, $id]
        );
    }

    /** @param array<mixed> $value */
    private static function json(array $value): string
    {
        return (string) json_encode($value === [] ? new \stdClass() : $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
