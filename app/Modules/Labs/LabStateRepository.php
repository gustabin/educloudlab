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

    /** @return list<array<string, mixed>> containers of active storage resources of the workspace (M7) */
    public function containers(int $tenantId, int $workspaceId): array
    {
        return $this->db->select(
            "SELECT c.id, c.name, c.lifecycle, r.name AS storage_name
               FROM storage_containers c JOIN resources r ON r.tenant_id = c.tenant_id AND r.id = c.resource_id
              WHERE c.tenant_id = ? AND c.workspace_id = ? AND r.status = 'active'",
            [$tenantId, $workspaceId]
        );
    }

    /** @return list<array<string, mixed>> objects of one container (key, metadata, tier) */
    public function objects(int $tenantId, int $containerId): array
    {
        return $this->db->select(
            'SELECT object_key, metadata, tier FROM storage_objects WHERE tenant_id = ? AND container_id = ? ORDER BY object_key LIMIT 1000',
            [$tenantId, $containerId]
        );
    }

    /**
     * Active pipelines of the workspace with their definition and their latest run (status + output table).
     *
     * @return list<array<string, mixed>>
     */
    public function pipelines(int $tenantId, int $workspaceId): array
    {
        return $this->db->select(
            "SELECT r.name, p.definition, lr.status AS run_status, d.layer AS output_layer, d.table_name AS output_table
               FROM pipelines p
               JOIN resources r ON r.tenant_id = p.tenant_id AND r.id = p.resource_id
               LEFT JOIN pipeline_runs lr ON lr.tenant_id = p.tenant_id AND lr.id = (
                    SELECT MAX(x.id) FROM pipeline_runs x WHERE x.tenant_id = p.tenant_id AND x.pipeline_id = p.id)
               LEFT JOIN datasets d ON d.tenant_id = lr.tenant_id AND d.id = lr.output_dataset_id
              WHERE p.tenant_id = ? AND p.workspace_id = ? AND r.status = 'active'",
            [$tenantId, $workspaceId]
        );
    }

    /** @return list<array<string, mixed>> active semantic models of the workspace (name, definition) (M9) */
    public function semanticModels(int $tenantId, int $workspaceId): array
    {
        return $this->db->select(
            "SELECT r.name, m.definition FROM semantic_models m JOIN resources r ON r.tenant_id = m.tenant_id AND r.id = m.resource_id
              WHERE m.tenant_id = ? AND m.workspace_id = ? AND r.status = 'active'",
            [$tenantId, $workspaceId]
        );
    }

    /** @return list<array<string, mixed>> active dashboards with the definition of their model (M9) */
    public function dashboards(int $tenantId, int $workspaceId): array
    {
        return $this->db->select(
            "SELECT r.name, d.definition, m.definition AS model_definition
               FROM dashboards d
               JOIN resources r ON r.tenant_id = d.tenant_id AND r.id = d.resource_id
               JOIN semantic_models m ON m.tenant_id = d.tenant_id AND m.id = d.model_id
              WHERE d.tenant_id = ? AND d.workspace_id = ? AND r.status = 'active'",
            [$tenantId, $workspaceId]
        );
    }

    /**
     * Active notebooks of the workspace with their latest run status and the artifacts of their latest SUCCESSFUL run.
     *
     * @return list<array<string, mixed>>
     */
    public function notebooks(int $tenantId, int $workspaceId): array
    {
        return $this->db->select(
            "SELECT r.name,
                    (SELECT nr.status FROM notebook_runs nr WHERE nr.tenant_id = n.tenant_id AND nr.notebook_id = n.id
                      ORDER BY nr.id DESC LIMIT 1) AS last_status,
                    (SELECT nr.artifacts FROM notebook_runs nr WHERE nr.tenant_id = n.tenant_id AND nr.notebook_id = n.id
                        AND nr.status = 'succeeded' ORDER BY nr.id DESC LIMIT 1) AS artifacts
               FROM notebooks n JOIN resources r ON r.tenant_id = n.tenant_id AND r.id = n.resource_id
              WHERE n.tenant_id = ? AND n.workspace_id = ? AND r.status = 'active'",
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
