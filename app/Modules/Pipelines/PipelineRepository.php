<?php

declare(strict_types=1);

namespace EduCloud\Modules\Pipelines;

use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Db;
use EduCloud\Core\Ulid;

/**
 * Pipelines (resource type 'pipeline', API id = resource public id) and their runs. Tenant-scoped; visibility follows
 * the workspace owner unless the caller sees the whole tenant. *ForJob methods serve the trusted dispatcher.
 */
final class PipelineRepository
{
    private const SELECT = "SELECT p.id, p.tenant_id, p.workspace_id, p.resource_id, p.definition, p.version, p.created_at, p.updated_at,
               r.public_id, r.name, r.status, w.public_id AS workspace_public_id, w.owner_user_id, w.status AS workspace_status,
               (SELECT pr.status FROM pipeline_runs pr WHERE pr.tenant_id = p.tenant_id AND pr.pipeline_id = p.id
                 ORDER BY pr.id DESC LIMIT 1) AS last_run_status
          FROM pipelines p
          JOIN resources r ON r.tenant_id = p.tenant_id AND r.id = p.resource_id
          JOIN workspaces w ON w.tenant_id = p.tenant_id AND w.id = p.workspace_id";

    private const RUN_SELECT = "SELECT pr.*, r.public_id AS pipeline_public_id, r.name AS pipeline_name, w.public_id AS workspace_public_id,
               w.owner_user_id, u.public_id AS user_public_id, u.display_name AS user_name, j.status AS job_status,
               j.public_id AS job_public_id, j.attempts AS job_attempts, j.cancel_requested AS job_cancel_requested
          FROM pipeline_runs pr
          JOIN pipelines p ON p.tenant_id = pr.tenant_id AND p.id = pr.pipeline_id
          JOIN resources r ON r.tenant_id = p.tenant_id AND r.id = p.resource_id
          JOIN workspaces w ON w.tenant_id = p.tenant_id AND w.id = p.workspace_id
          JOIN users u ON u.id = pr.user_id
          LEFT JOIN jobs j ON j.tenant_id = pr.tenant_id AND j.id = pr.job_id";

    public function __construct(private readonly Db $db)
    {
    }

    /** @param array<string, mixed> $definition */
    public function create(TenantContext $ctx, int $workspaceId, int $resourceId, array $definition): int
    {
        return $this->db->insert(
            'INSERT INTO pipelines (tenant_id, workspace_id, resource_id, definition) VALUES (?, ?, ?, ?)',
            [$ctx->tenantId, $workspaceId, $resourceId, self::json($definition)]
        );
    }

    /** @return list<array<string, mixed>> */
    public function listForWorkspace(TenantContext $ctx, int $workspaceId): array
    {
        return $this->db->select(
            self::SELECT . " WHERE p.tenant_id = ? AND p.workspace_id = ? AND r.status <> 'deleted' ORDER BY r.name",
            [$ctx->tenantId, $workspaceId]
        );
    }

    /** @return array<string, mixed>|null */
    public function findVisible(TenantContext $ctx, string $publicId, bool $wholeTenant): ?array
    {
        $sql = self::SELECT . " WHERE p.tenant_id = ? AND r.public_id = ? AND r.status <> 'deleted' AND w.status = 'active'";
        $params = [$ctx->tenantId, $publicId];
        if (!$wholeTenant) {
            $sql .= ' AND w.owner_user_id = ?';
            $params[] = $ctx->userId;
        }
        return $this->db->selectOne($sql, $params);
    }

    /** @param array<string, mixed> $definition */
    public function updateDefinition(TenantContext $ctx, int $id, array $definition): void
    {
        $this->db->execute(
            'UPDATE pipelines SET definition = ?, version = version + 1 WHERE tenant_id = ? AND id = ?',
            [self::json($definition), $ctx->tenantId, $id]
        );
    }

    // ------------------------------------------------------------------------------------------------ runs

    /**
     * @param array<string, mixed> $definition snapshot
     * @return array{id: int, public_id: string}
     */
    public function createRun(TenantContext $ctx, int $pipelineId, int $version, array $definition): array
    {
        $publicId = Ulid::generate();
        $id = $this->db->insert(
            'INSERT INTO pipeline_runs (public_id, tenant_id, pipeline_id, user_id, definition_version, definition) VALUES (?, ?, ?, ?, ?, ?)',
            [$publicId, $ctx->tenantId, $pipelineId, $ctx->userId, $version, self::json($definition)]
        );
        return ['id' => $id, 'public_id' => $publicId];
    }

    public function attachJob(int $tenantId, int $runId, int $jobId): void
    {
        $this->db->execute('UPDATE pipeline_runs SET job_id = ? WHERE tenant_id = ? AND id = ?', [$jobId, $tenantId, $runId]);
    }

    /** @return list<array<string, mixed>> */
    public function runs(TenantContext $ctx, int $pipelineId, int $limit): array
    {
        return $this->db->select(
            self::RUN_SELECT . ' WHERE pr.tenant_id = ? AND pr.pipeline_id = ? ORDER BY pr.id DESC LIMIT ' . max(1, $limit),
            [$ctx->tenantId, $pipelineId]
        );
    }

    /** @return array<string, mixed>|null */
    public function findRunVisible(TenantContext $ctx, string $publicId, bool $wholeTenant): ?array
    {
        $sql = self::RUN_SELECT . ' WHERE pr.tenant_id = ? AND pr.public_id = ?';
        $params = [$ctx->tenantId, $publicId];
        if (!$wholeTenant) {
            $sql .= ' AND w.owner_user_id = ?';
            $params[] = $ctx->userId;
        }
        return $this->db->selectOne($sql, $params);
    }

    /** @return array<string, mixed>|null */
    public function findRunForJob(int $tenantId, int $runId): ?array
    {
        return $this->db->selectOne(self::RUN_SELECT . ' WHERE pr.tenant_id = ? AND pr.id = ?', [$tenantId, $runId]);
    }

    public function markRunning(int $tenantId, int $runId): void
    {
        $this->db->execute(
            "UPDATE pipeline_runs SET status = 'running', started_at = COALESCE(started_at, UTC_TIMESTAMP(3)) WHERE tenant_id = ? AND id = ?",
            [$tenantId, $runId]
        );
    }

    /** @param list<array<string, mixed>> $steps */
    public function finishRun(int $tenantId, int $runId, string $status, array $steps, ?int $outputDatasetId, ?string $code, ?string $message): void
    {
        $this->db->execute(
            'UPDATE pipeline_runs SET status = ?, steps = ?, output_dataset_id = ?, error_code = ?, error_message = ?, finished_at = UTC_TIMESTAMP(3)
              WHERE tenant_id = ? AND id = ?',
            [
                $status, $steps === [] ? null : self::json($steps), $outputDatasetId, $code,
                $message === null ? null : mb_substr($message, 0, 300), $tenantId, $runId,
            ]
        );
    }

    /** @param array<mixed> $value */
    private static function json(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
