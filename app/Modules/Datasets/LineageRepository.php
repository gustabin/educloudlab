<?php

declare(strict_types=1);

namespace EduCloud\Modules\Datasets;

use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Db;

/**
 * Dataset lineage (M7): provenance edges between datasets of one workspace. Edges are written only by the trusted
 * dispatcher from server-side facts (ingest source, tables parsed from a transform's SELECT, pipeline sources).
 */
final class LineageRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Records target ← source edges (idempotent). Sources must live in the same tenant and workspace.
     *
     * @param list<int> $sourceDatasetIds
     */
    public function record(int $tenantId, int $workspaceId, int $targetDatasetId, array $sourceDatasetIds, string $via, ?int $pipelineId = null): void
    {
        foreach (array_unique(array_map('intval', $sourceDatasetIds)) as $source) {
            if ($source === $targetDatasetId) {
                continue;
            }
            $this->db->execute(
                'INSERT IGNORE INTO dataset_lineage (tenant_id, workspace_id, target_dataset_id, source_dataset_id, via, pipeline_id)
                 SELECT ?, ?, ?, d.id, ?, ? FROM datasets d WHERE d.tenant_id = ? AND d.workspace_id = ? AND d.id = ?',
                [$tenantId, $workspaceId, $targetDatasetId, $via, $pipelineId, $tenantId, $workspaceId, $source]
            );
        }
    }

    /**
     * Dataset ids of a workspace by "layer.table" (tables) or "raw:name" (raw datasets), for resolving sources.
     *
     * @param list<string> $keys
     * @return list<int>
     */
    public function resolve(int $tenantId, int $workspaceId, array $keys): array
    {
        $ids = [];
        foreach ($keys as $key) {
            if (str_starts_with($key, 'raw:')) {
                $id = $this->db->scalar(
                    "SELECT d.id FROM datasets d JOIN resources r ON r.tenant_id = d.tenant_id AND r.id = d.resource_id
                      WHERE d.tenant_id = ? AND d.workspace_id = ? AND d.layer = 'raw' AND r.name = ? AND r.status <> 'deleted'",
                    [$tenantId, $workspaceId, substr($key, 4)]
                );
            } elseif (preg_match('/^(bronze|silver|gold)\.([a-z][a-z0-9_]{0,62})$/D', $key, $m) === 1) {
                $id = $this->db->scalar(
                    "SELECT d.id FROM datasets d JOIN resources r ON r.tenant_id = d.tenant_id AND r.id = d.resource_id
                      WHERE d.tenant_id = ? AND d.workspace_id = ? AND d.layer = ? AND d.table_name = ? AND r.status <> 'deleted'",
                    [$tenantId, $workspaceId, $m[1], $m[2]]
                );
            } else {
                $id = null;
            }
            if ($id !== null) {
                $ids[] = (int) $id;
            }
        }
        return $ids;
    }

    /**
     * Upstream (sources) and downstream (targets) of a dataset, one level, with each neighbour's display data.
     *
     * @return array{upstream: list<array<string, mixed>>, downstream: list<array<string, mixed>>}
     */
    public function neighbours(TenantContext $ctx, int $datasetId): array
    {
        $select = "SELECT r.public_id, r.name, r.status, d.layer, d.table_name, l.via, pr.public_id AS pipeline_public_id, pr.name AS pipeline_name
                     FROM dataset_lineage l
                     JOIN datasets d ON d.tenant_id = l.tenant_id AND d.id = %s
                     JOIN resources r ON r.tenant_id = d.tenant_id AND r.id = d.resource_id
                     LEFT JOIN pipelines p ON p.tenant_id = l.tenant_id AND p.id = l.pipeline_id
                     LEFT JOIN resources pr ON pr.tenant_id = p.tenant_id AND pr.id = p.resource_id
                    WHERE l.tenant_id = ? AND %s = ?
                    ORDER BY d.layer, r.name";
        return [
            'upstream' => $this->db->select(sprintf($select, 'l.source_dataset_id', 'l.target_dataset_id'), [$ctx->tenantId, $datasetId]),
            'downstream' => $this->db->select(sprintf($select, 'l.target_dataset_id', 'l.source_dataset_id'), [$ctx->tenantId, $datasetId]),
        ];
    }
}
