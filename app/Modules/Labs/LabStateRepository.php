<?php

declare(strict_types=1);

namespace EduCloud\Modules\Labs;

use EduCloud\Core\Db;

/** Read-only view of a lab workspace's actual state, used by metadata checks (trusted dispatcher context). */
final class LabStateRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<array<string, mixed>> infrastructure resources of the workspace (any status) */
    public function resources(int $tenantId, int $workspaceId): array
    {
        return $this->db->select(
            "SELECT type, name, status, config, tags FROM resources
              WHERE tenant_id = ? AND workspace_id = ? AND type IN ('storage', 'lakehouse')",
            [$tenantId, $workspaceId]
        );
    }

    /** @return list<array<string, mixed>> datasets of the workspace with their latest version status */
    public function datasets(int $tenantId, int $workspaceId): array
    {
        return $this->db->select(
            "SELECT d.layer, d.table_name, r.name, r.status,
                    (SELECT v.status FROM dataset_versions v WHERE v.tenant_id = d.tenant_id AND v.dataset_id = d.id
                      ORDER BY v.version_no DESC LIMIT 1) AS version_status
               FROM datasets d JOIN resources r ON r.tenant_id = d.tenant_id AND r.id = d.resource_id
              WHERE d.tenant_id = ? AND d.workspace_id = ?",
            [$tenantId, $workspaceId]
        );
    }

    public function activeJobs(int $tenantId, int $workspaceId, ?string $type = null): int
    {
        $sql = "SELECT COUNT(*) FROM jobs WHERE tenant_id = ? AND workspace_id = ? AND status IN ('queued', 'running')";
        $params = [$tenantId, $workspaceId];
        if ($type !== null) {
            $sql .= ' AND type = ?';
            $params[] = $type;
        }
        return (int) $this->db->scalar($sql, $params);
    }
}
