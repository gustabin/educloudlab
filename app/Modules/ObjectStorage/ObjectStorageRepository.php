<?php

declare(strict_types=1);

namespace EduCloud\Modules\ObjectStorage;

use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Db;
use EduCloud\Core\Ulid;

/**
 * Containers and objects of storage resources (M7). Tenant-scoped; visibility follows the workspace owner unless the
 * caller sees the whole tenant. Containers/objects of deleted resources or workspaces are invisible.
 */
final class ObjectStorageRepository
{
    private const CONTAINER = "SELECT c.*, r.public_id AS resource_public_id, r.name AS resource_name, r.status AS resource_status,
               w.public_id AS workspace_public_id, w.owner_user_id,
               (SELECT COUNT(*) FROM storage_objects o WHERE o.tenant_id = c.tenant_id AND o.container_id = c.id) AS object_count,
               (SELECT COALESCE(SUM(o.bytes), 0) FROM storage_objects o WHERE o.tenant_id = c.tenant_id AND o.container_id = c.id) AS total_bytes
          FROM storage_containers c
          JOIN resources r ON r.tenant_id = c.tenant_id AND r.id = c.resource_id
          JOIN workspaces w ON w.tenant_id = c.tenant_id AND w.id = c.workspace_id";

    private const OBJECT = "SELECT o.*, c.public_id AS container_public_id, c.name AS container_name, c.workspace_id,
               w.public_id AS workspace_public_id, w.owner_user_id
          FROM storage_objects o
          JOIN storage_containers c ON c.tenant_id = o.tenant_id AND c.id = o.container_id
          JOIN resources r ON r.tenant_id = c.tenant_id AND r.id = c.resource_id
          JOIN workspaces w ON w.tenant_id = c.tenant_id AND w.id = c.workspace_id";

    private const VISIBLE = " AND r.status = 'active' AND w.status = 'active'";

    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function containers(TenantContext $ctx, int $resourceId): array
    {
        return $this->db->select(self::CONTAINER . ' WHERE c.tenant_id = ? AND c.resource_id = ? ORDER BY c.name', [$ctx->tenantId, $resourceId]);
    }

    /** @return array<string, mixed>|null */
    public function findContainer(TenantContext $ctx, string $publicId, bool $wholeTenant): ?array
    {
        [$sql, $params] = self::scoped(
            self::CONTAINER . ' WHERE c.tenant_id = ? AND c.public_id = ?' . self::VISIBLE,
            [$ctx->tenantId, $publicId],
            $ctx,
            $wholeTenant
        );
        return $this->db->selectOne($sql, $params);
    }

    public function containerNameTaken(TenantContext $ctx, int $resourceId, string $name): bool
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM storage_containers WHERE tenant_id = ? AND resource_id = ? AND name = ?',
            [$ctx->tenantId, $resourceId, $name]
        ) > 0;
    }

    public function countObjects(TenantContext $ctx, int $containerId): int
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM storage_objects WHERE tenant_id = ? AND container_id = ?',
            [$ctx->tenantId, $containerId]
        );
    }

    public function countContainers(TenantContext $ctx, int $resourceId): int
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM storage_containers WHERE tenant_id = ? AND resource_id = ?',
            [$ctx->tenantId, $resourceId]
        );
    }

    /**
     * @param array<string, int>|null $lifecycle
     * @return array{id: int, public_id: string}
     */
    public function createContainer(TenantContext $ctx, int $workspaceId, int $resourceId, string $name, ?array $lifecycle): array
    {
        $publicId = Ulid::generate();
        $id = $this->db->insert(
            'INSERT INTO storage_containers (public_id, tenant_id, workspace_id, resource_id, name, lifecycle, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$publicId, $ctx->tenantId, $workspaceId, $resourceId, $name, $lifecycle === null ? null : json_encode($lifecycle), $ctx->userId]
        );
        return ['id' => $id, 'public_id' => $publicId];
    }

    /** @param array<string, int>|null $lifecycle */
    public function setLifecycle(TenantContext $ctx, int $containerId, ?array $lifecycle): void
    {
        $this->db->execute(
            'UPDATE storage_containers SET lifecycle = ? WHERE tenant_id = ? AND id = ?',
            [$lifecycle === null ? null : json_encode($lifecycle), $ctx->tenantId, $containerId]
        );
    }

    /** Row lock on the container (serialises uploads and deletion); false when it no longer exists. */
    public function lockContainer(TenantContext $ctx, int $containerId): bool
    {
        return $this->db->scalar(
            'SELECT id FROM storage_containers WHERE tenant_id = ? AND id = ? FOR UPDATE',
            [$ctx->tenantId, $containerId]
        ) !== null;
    }

    /** Row lock on a storage resource; returns its status (null when missing). */
    public function lockStorage(TenantContext $ctx, int $resourceId): ?string
    {
        $status = $this->db->scalar(
            "SELECT status FROM resources WHERE tenant_id = ? AND id = ? AND type = 'storage' FOR UPDATE",
            [$ctx->tenantId, $resourceId]
        );
        return $status === null ? null : (string) $status;
    }

    public function deleteContainer(TenantContext $ctx, int $containerId): void
    {
        $this->db->execute('DELETE FROM storage_containers WHERE tenant_id = ? AND id = ?', [$ctx->tenantId, $containerId]);
    }

    // ------------------------------------------------------------------------------------------------ objects

    /** @return list<array<string, mixed>> */
    public function objects(TenantContext $ctx, int $containerId, string $prefix, int $limit): array
    {
        return $this->db->select(
            self::OBJECT . " WHERE o.tenant_id = ? AND o.container_id = ? AND o.object_key LIKE ? ESCAPE '\\\\'
              ORDER BY o.object_key LIMIT " . max(1, $limit),
            [$ctx->tenantId, $containerId, addcslashes($prefix, '%_\\') . '%']
        );
    }

    /** @return array<string, mixed>|null */
    public function findObject(TenantContext $ctx, string $publicId, bool $wholeTenant): ?array
    {
        [$sql, $params] = self::scoped(
            self::OBJECT . ' WHERE o.tenant_id = ? AND o.public_id = ?' . self::VISIBLE,
            [$ctx->tenantId, $publicId],
            $ctx,
            $wholeTenant
        );
        return $this->db->selectOne($sql, $params);
    }

    /** @return array<string, mixed>|null */
    public function findByKey(TenantContext $ctx, int $containerId, string $key): ?array
    {
        return $this->db->selectOne(
            self::OBJECT . ' WHERE o.tenant_id = ? AND o.container_id = ? AND o.object_key = ?',
            [$ctx->tenantId, $containerId, $key]
        );
    }

    /**
     * Inserts or replaces (same key) an object. Returns the previous storage key when an object was replaced.
     *
     * @param array<string, string> $metadata
     */
    public function putObject(
        TenantContext $ctx,
        int $containerId,
        string $key,
        string $storageKey,
        int $bytes,
        string $contentType,
        string $sha256,
        array $metadata,
        string $tier
    ): ?string {
        $existing = $this->findByKey($ctx, $containerId, $key);
        $meta = $metadata === [] ? null : (string) json_encode($metadata, JSON_UNESCAPED_UNICODE);
        if ($existing !== null) {
            $this->db->execute(
                'UPDATE storage_objects SET storage_key = ?, bytes = ?, content_type = ?, sha256 = ?, metadata = ?, tier = ?, uploaded_by = ?,
                        created_at = UTC_TIMESTAMP(3)
                  WHERE tenant_id = ? AND id = ?',
                [$storageKey, $bytes, $contentType, $sha256, $meta, $tier, $ctx->userId, $ctx->tenantId, (int) $existing['id']]
            );
            return (string) $existing['storage_key'];
        }
        $this->db->insert(
            'INSERT INTO storage_objects
                    (public_id, tenant_id, container_id, object_key, storage_key, bytes, content_type, sha256, metadata, tier, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [Ulid::generate(), $ctx->tenantId, $containerId, $key, $storageKey, $bytes, $contentType, $sha256, $meta, $tier, $ctx->userId]
        );
        return null;
    }

    /** @param array<string, string> $metadata */
    public function updateObject(TenantContext $ctx, int $objectId, array $metadata, string $tier): void
    {
        $this->db->execute(
            'UPDATE storage_objects SET metadata = ?, tier = ? WHERE tenant_id = ? AND id = ?',
            [$metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE), $tier, $ctx->tenantId, $objectId]
        );
    }

    public function deleteObject(int $tenantId, int $objectId): void
    {
        $this->db->execute('DELETE FROM storage_objects WHERE tenant_id = ? AND id = ?', [$tenantId, $objectId]);
    }

    // ------------------------------------------------------------------------------------------------ lifecycle (scheduler)

    /**
     * Objects whose container lifecycle says they must be archived or deleted now (bounded batch).
     *
     * @return list<array<string, mixed>>
     */
    public function lifecycleDue(int $limit): array
    {
        return $this->db->select(
            "SELECT o.id, o.tenant_id, o.storage_key, o.tier, t.public_id AS tenant_public_id, w.public_id AS workspace_public_id,
                    CASE WHEN JSON_EXTRACT(c.lifecycle, '$.delete_after_days') IS NOT NULL
                          AND o.created_at < UTC_TIMESTAMP(3)
                              - INTERVAL CAST(JSON_UNQUOTE(JSON_EXTRACT(c.lifecycle, '$.delete_after_days')) AS UNSIGNED) DAY
                         THEN 'delete'
                         ELSE 'archive' END AS action
               FROM storage_objects o
               JOIN storage_containers c ON c.tenant_id = o.tenant_id AND c.id = o.container_id
               JOIN workspaces w ON w.tenant_id = c.tenant_id AND w.id = c.workspace_id
               JOIN tenants t ON t.id = o.tenant_id
              WHERE c.lifecycle IS NOT NULL AND w.status = 'active' AND (
                    (JSON_EXTRACT(c.lifecycle, '$.delete_after_days') IS NOT NULL
                      AND o.created_at < UTC_TIMESTAMP(3)
                          - INTERVAL CAST(JSON_UNQUOTE(JSON_EXTRACT(c.lifecycle, '$.delete_after_days')) AS UNSIGNED) DAY)
                 OR (JSON_EXTRACT(c.lifecycle, '$.archive_after_days') IS NOT NULL AND o.tier <> 'archive'
                      AND o.created_at < UTC_TIMESTAMP(3)
                          - INTERVAL CAST(JSON_UNQUOTE(JSON_EXTRACT(c.lifecycle, '$.archive_after_days')) AS UNSIGNED) DAY))
              LIMIT " . max(1, $limit)
        );
    }

    public function archive(int $tenantId, int $objectId): void
    {
        $this->db->execute("UPDATE storage_objects SET tier = 'archive' WHERE tenant_id = ? AND id = ?", [$tenantId, $objectId]);
    }

    /**
     * @param list<mixed> $params
     * @return array{0: string, 1: list<mixed>}
     */
    private static function scoped(string $sql, array $params, TenantContext $ctx, bool $wholeTenant): array
    {
        if (!$wholeTenant) {
            $sql .= ' AND w.owner_user_id = ?';
            $params[] = $ctx->userId;
        }
        return [$sql, $params];
    }
}
