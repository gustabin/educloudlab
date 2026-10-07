<?php

declare(strict_types=1);

namespace EduCloud\Modules\Workspaces;

use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Db;
use EduCloud\Core\Format;
use EduCloud\Core\Ulid;

/**
 * Tenant-scoped workspace data access. Every query filters by $ctx->tenantId; when $wholeTenant is false
 * it also filters by owner. Deleted workspaces are invisible.
 */
final class WorkspaceRepository
{
    /** Allowlisted sort keys → SQL columns (never interpolate user input). */
    public const SORTS = ['name' => 'w.name', 'created_at' => 'w.created_at', 'updated_at' => 'w.updated_at'];

    private const SELECT = "SELECT w.id, w.public_id, w.tenant_id, w.owner_user_id, w.name, w.description, w.purpose, w.status, w.expires_at,
               w.created_at, w.updated_at, u.public_id AS owner_public_id, u.display_name AS owner_name,
               (SELECT COUNT(*) FROM resources r
                 WHERE r.tenant_id = w.tenant_id AND r.workspace_id = w.id AND r.status <> 'deleted') AS resource_count
          FROM workspaces w JOIN users u ON u.id = w.owner_user_id";

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(TenantContext $ctx, bool $wholeTenant, string $search, string $sort, string $dir, int $limit, int $offset): array
    {
        [$where, $params] = $this->visibility($ctx, $wholeTenant, $search);
        $orderBy = (self::SORTS[$sort] ?? 'w.created_at') . ($dir === 'asc' ? ' ASC' : ' DESC') . ', w.id DESC';
        return $this->db->select(self::SELECT . " WHERE $where ORDER BY $orderBy LIMIT ? OFFSET ?", [...$params, $limit, $offset]);
    }

    public function count(TenantContext $ctx, bool $wholeTenant, string $search): int
    {
        [$where, $params] = $this->visibility($ctx, $wholeTenant, $search);
        return (int) $this->db->scalar("SELECT COUNT(*) FROM workspaces w WHERE $where", $params);
    }

    /** @return array<string, mixed>|null */
    public function findVisible(TenantContext $ctx, string $publicId, bool $wholeTenant): ?array
    {
        $sql = self::SELECT . " WHERE w.tenant_id = ? AND w.public_id = ? AND w.status <> 'deleted'";
        $params = [$ctx->tenantId, $publicId];
        if (!$wholeTenant) {
            $sql .= ' AND w.owner_user_id = ?';
            $params[] = $ctx->userId;
        }
        return $this->db->selectOne($sql, $params);
    }

    /** General-purpose workspaces only: lab workspaces have their own cap (active lab attempts, M6). */
    public function countActiveOwned(TenantContext $ctx, int $ownerUserId): int
    {
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM workspaces WHERE tenant_id = ? AND owner_user_id = ? AND status <> 'deleted' AND purpose = 'general'",
            [$ctx->tenantId, $ownerUserId]
        );
    }

    public function nameTaken(TenantContext $ctx, int $ownerUserId, string $name, ?int $exceptId = null): bool
    {
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM workspaces
              WHERE tenant_id = ? AND owner_user_id = ? AND name = ? AND status <> 'deleted' AND id <> ?",
            [$ctx->tenantId, $ownerUserId, $name, $exceptId ?? 0]
        ) > 0;
    }

    /**
     * @param string $purpose general | lab (lab workspaces are created by the Lab Engine and expire after inactivity)
     * @return array{id: int, public_id: string}
     */
    public function create(TenantContext $ctx, string $name, ?string $description, string $purpose = 'general', ?int $ttlDays = null): array
    {
        $publicId = Ulid::generate();
        $id = $this->db->insert(
            'INSERT INTO workspaces (public_id, tenant_id, owner_user_id, name, description, purpose, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, IF(? IS NULL, NULL, UTC_TIMESTAMP(3) + INTERVAL ? DAY))',
            [$publicId, $ctx->tenantId, $ctx->userId, $name, $description, $purpose, $ttlDays, $ttlDays ?? 0]
        );
        return ['id' => $id, 'public_id' => $publicId];
    }

    /** Pushes the expiry of an expiring (lab) workspace to now + $ttlDays (activity keeps it alive). */
    public function extendExpiry(TenantContext $ctx, int $id, int $ttlDays): void
    {
        $this->db->execute(
            "UPDATE workspaces SET expires_at = UTC_TIMESTAMP(3) + INTERVAL ? DAY, last_activity_at = UTC_TIMESTAMP(3)
              WHERE tenant_id = ? AND id = ? AND status = 'active' AND expires_at IS NOT NULL",
            [$ttlDays, $ctx->tenantId, $id]
        );
    }

    public function update(TenantContext $ctx, int $id, string $name, ?string $description): void
    {
        $this->db->execute(
            "UPDATE workspaces SET name = ?, description = ?, last_activity_at = UTC_TIMESTAMP(3)
              WHERE tenant_id = ? AND id = ? AND status <> 'deleted'",
            [$name, $description, $ctx->tenantId, $id]
        );
    }

    /**
     * Soft-deletes the workspace. Its live resources follow the lifecycle into 'deleting' (provisioning|active|failed
     * → deleting); the cleanup job releases their storage and moves them to 'deleted' (M4/M11).
     * Locks the workspace row first so concurrent resource creation cannot slip in. Returns the resources released.
     */
    public function markDeleted(TenantContext $ctx, int $id): int
    {
        return self::softDelete($this->db, $ctx->tenantId, $id);
    }

    /** Shared by markDeleted() and the scheduler (expired lab workspaces), which has no TenantContext. */
    public static function softDelete(Db $db, int $tenantId, int $id): int
    {
        $db->select('SELECT id FROM workspaces WHERE tenant_id = ? AND id = ? FOR UPDATE', [$tenantId, $id]);
        $released = $db->execute(
            "UPDATE resources SET status = 'deleting'
              WHERE tenant_id = ? AND workspace_id = ? AND status IN ('provisioning', 'active', 'failed')",
            [$tenantId, $id]
        );
        $db->execute("UPDATE workspaces SET status = 'deleted' WHERE tenant_id = ? AND id = ?", [$tenantId, $id]);
        return $released;
    }

    /** @return array{0: string, 1: list<mixed>} */
    private function visibility(TenantContext $ctx, bool $wholeTenant, string $search): array
    {
        $where = "w.tenant_id = ? AND w.status <> 'deleted'";
        $params = [$ctx->tenantId];
        if (!$wholeTenant) {
            $where .= ' AND w.owner_user_id = ?';
            $params[] = $ctx->userId;
        }
        if ($search !== '') {
            $where .= " AND w.name LIKE ? ESCAPE '\\\\'";
            $params[] = Format::likeContains($search);
        }
        return [$where, $params];
    }
}
