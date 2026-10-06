<?php

declare(strict_types=1);

namespace EduCloud\Modules\Datasets;

use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Db;
use EduCloud\Core\Ulid;

/**
 * Datasets = resources of type 'dataset' + a datasets row (layer, table) + versions.
 * The API id of a dataset is its resource public id. All queries are tenant-scoped; visibility follows the
 * workspace owner unless the caller sees the whole tenant.
 */
final class DatasetRepository
{
    /** Latest version per dataset (highest version_no). */
    private const SELECT = "SELECT d.id AS dataset_id, d.layer, d.table_name, d.workspace_id,
               r.id AS resource_id, r.public_id, r.tenant_id, r.owner_user_id, r.name, r.status,
               r.created_at, r.updated_at, w.public_id AS workspace_public_id, w.owner_user_id AS workspace_owner_id,
               v.id AS version_id, v.public_id AS version_public_id, v.version_no, v.format, v.storage_key,
               v.original_name, v.bytes, v.row_count, v.column_count, v.schema_json, v.status AS version_status,
               v.error_code, v.error_message
          FROM datasets d
          JOIN resources r ON r.tenant_id = d.tenant_id AND r.id = d.resource_id
          JOIN workspaces w ON w.tenant_id = d.tenant_id AND w.id = d.workspace_id
          LEFT JOIN dataset_versions v ON v.tenant_id = d.tenant_id AND v.dataset_id = d.id
               AND v.version_no = (SELECT MAX(v2.version_no) FROM dataset_versions v2 WHERE v2.tenant_id = d.tenant_id AND v2.dataset_id = d.id)";

    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function listForWorkspace(TenantContext $ctx, int $workspaceId): array
    {
        return $this->db->select(
            self::SELECT . " WHERE d.tenant_id = ? AND d.workspace_id = ? AND r.status <> 'deleted'
                             ORDER BY r.name, FIELD(d.layer, 'raw', 'bronze', 'silver', 'gold'), d.id",
            [$ctx->tenantId, $workspaceId]
        );
    }

    /** @return array<string, mixed>|null */
    public function findVisible(TenantContext $ctx, string $resourcePublicId, bool $wholeTenant): ?array
    {
        $sql = self::SELECT . " WHERE d.tenant_id = ? AND r.public_id = ? AND r.status <> 'deleted' AND w.status = 'active'";
        $params = [$ctx->tenantId, $resourcePublicId];
        if (!$wholeTenant) {
            $sql .= ' AND w.owner_user_id = ?';
            $params[] = $ctx->userId;
        }
        return $this->db->selectOne($sql, $params);
    }

    /**
     * Unscoped lookup for the trusted dispatcher (job payloads carry internal ids).
     *
     * @return array<string, mixed>|null
     */
    public function findForJob(int $tenantId, int $datasetId): ?array
    {
        return $this->db->selectOne(self::SELECT . ' WHERE d.tenant_id = ? AND d.id = ?', [$tenantId, $datasetId]);
    }

    /** @return array<string, mixed>|null */
    public function findVersionForJob(int $tenantId, int $versionId): ?array
    {
        return $this->db->selectOne(
            'SELECT id, public_id, dataset_id, version_no, storage_key, status FROM dataset_versions WHERE tenant_id = ? AND id = ?',
            [$tenantId, $versionId]
        );
    }

    public function versionBelongsToWorkspace(int $tenantId, int $versionId, int $workspaceId): bool
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM dataset_versions v JOIN datasets d ON d.tenant_id = v.tenant_id AND d.id = v.dataset_id
              WHERE v.tenant_id = ? AND v.id = ? AND d.workspace_id = ?',
            [$tenantId, $versionId, $workspaceId]
        ) > 0;
    }

    public function nameTaken(TenantContext $ctx, int $workspaceId, string $layer, string $name): bool
    {
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM datasets d JOIN resources r ON r.tenant_id = d.tenant_id AND r.id = d.resource_id
              WHERE d.tenant_id = ? AND d.workspace_id = ? AND d.layer = ? AND r.name = ? AND r.status <> 'deleted'",
            [$ctx->tenantId, $workspaceId, $layer, $name]
        ) > 0;
    }

    public function countForWorkspace(TenantContext $ctx, int $workspaceId): int
    {
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM datasets d JOIN resources r ON r.tenant_id = d.tenant_id AND r.id = d.resource_id
              WHERE d.tenant_id = ? AND d.workspace_id = ? AND r.status <> 'deleted'",
            [$ctx->tenantId, $workspaceId]
        );
    }

    /** Bytes of raw files of the user's non-deleted datasets in the tenant (storage quota). */
    public function storageUsedBy(TenantContext $ctx, int $userId): int
    {
        return (int) $this->db->scalar(
            "SELECT COALESCE(SUM(v.bytes), 0) FROM dataset_versions v
               JOIN datasets d ON d.tenant_id = v.tenant_id AND d.id = v.dataset_id
               JOIN resources r ON r.tenant_id = d.tenant_id AND r.id = d.resource_id
              WHERE v.tenant_id = ? AND v.created_by_user_id = ? AND r.status <> 'deleted'",
            [$ctx->tenantId, $userId]
        );
    }

    /** @return array{dataset_id: int, resource_id: int, public_id: string} */
    public function createDataset(TenantContext $ctx, int $workspaceId, int $ownerUserId, string $name, string $layer, ?string $tableName): array
    {
        $publicId = Ulid::generate();
        $resourceId = $this->db->insert(
            "INSERT INTO resources (public_id, tenant_id, workspace_id, owner_user_id, type, name, status, config)
             VALUES (?, ?, ?, ?, 'dataset', ?, 'provisioning', ?)",
            [$publicId, $ctx->tenantId, $workspaceId, $ownerUserId, $name, (string) json_encode(['layer' => $layer])]
        );
        $datasetId = $this->db->insert(
            'INSERT INTO datasets (tenant_id, resource_id, workspace_id, layer, table_name) VALUES (?, ?, ?, ?, ?)',
            [$ctx->tenantId, $resourceId, $workspaceId, $layer, $tableName]
        );
        return ['dataset_id' => $datasetId, 'resource_id' => $resourceId, 'public_id' => $publicId];
    }

    /** @return array{id: int, public_id: string} */
    public function createVersion(
        TenantContext $ctx,
        int $datasetId,
        string $format,
        ?string $storageKey,
        ?string $originalName,
        int $bytes,
        ?string $sha256,
    ): array {
        $publicId = Ulid::generate();
        $next = 1 + (int) $this->db->scalar(
            'SELECT COALESCE(MAX(version_no), 0) FROM dataset_versions WHERE tenant_id = ? AND dataset_id = ?',
            [$ctx->tenantId, $datasetId]
        );
        $id = $this->db->insert(
            "INSERT INTO dataset_versions
                (public_id, tenant_id, dataset_id, version_no, format, storage_key, original_name, bytes, sha256, status, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'processing', ?)",
            [$publicId, $ctx->tenantId, $datasetId, $next, $format, $storageKey, $originalName, $bytes, $sha256, $ctx->userId]
        );
        return ['id' => $id, 'public_id' => $publicId];
    }

    /** @param list<array<string, string>> $columns */
    public function markVersionReady(int $tenantId, int $versionId, int $rows, array $columns): void
    {
        $this->db->execute(
            "UPDATE dataset_versions SET status = 'ready', row_count = ?, column_count = ?, schema_json = ?, error_code = NULL, error_message = NULL
              WHERE tenant_id = ? AND id = ?",
            [$rows, count($columns), (string) json_encode($columns, JSON_UNESCAPED_UNICODE), $tenantId, $versionId]
        );
    }

    public function markVersionFailed(int $tenantId, int $versionId, string $code, string $message): void
    {
        $this->db->execute(
            "UPDATE dataset_versions SET status = 'failed', error_code = ?, error_message = ? WHERE tenant_id = ? AND id = ?",
            [$code, mb_substr($message, 0, 300), $tenantId, $versionId]
        );
    }

    /** Compare-and-set on the dataset's resource status (lifecycle). */
    public function setResourceStatus(int $tenantId, int $resourceId, string $from, string $to): bool
    {
        \EduCloud\Modules\Resources\ResourceLifecycle::assertTransition($from, $to);
        return $this->db->execute(
            'UPDATE resources SET status = ? WHERE tenant_id = ? AND id = ? AND status = ?',
            [$to, $tenantId, $resourceId, $from]
        ) === 1;
    }

    /** @return list<array<string, mixed>> versions of a dataset (for cleanup) */
    public function versions(int $tenantId, int $datasetId): array
    {
        return $this->db->select(
            'SELECT id, public_id, storage_key FROM dataset_versions WHERE tenant_id = ? AND dataset_id = ?',
            [$tenantId, $datasetId]
        );
    }

    public function hasActiveLakehouse(TenantContext $ctx, int $workspaceId): bool
    {
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM resources WHERE tenant_id = ? AND workspace_id = ? AND type = 'lakehouse' AND status = 'active'",
            [$ctx->tenantId, $workspaceId]
        ) > 0;
    }
}
