<?php

declare(strict_types=1);

namespace EduCloud\Modules\Notebooks;

use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Db;
use EduCloud\Core\Ulid;

/**
 * Notebooks (resource type 'notebook', API id = resource public id) and their runs (M8). Tenant-scoped; visibility
 * follows the workspace owner unless the caller sees the whole tenant. *ForJob methods serve the trusted dispatcher.
 */
final class NotebookRepository
{
    private const SELECT = "SELECT n.id, n.tenant_id, n.workspace_id, n.resource_id, n.cells, n.version, n.created_at, n.updated_at,
               r.public_id, r.name, w.public_id AS workspace_public_id, w.owner_user_id, w.status AS workspace_status,
               (SELECT nr.status FROM notebook_runs nr WHERE nr.tenant_id = n.tenant_id AND nr.notebook_id = n.id
                 ORDER BY nr.id DESC LIMIT 1) AS last_run_status
          FROM notebooks n
          JOIN resources r ON r.tenant_id = n.tenant_id AND r.id = n.resource_id
          JOIN workspaces w ON w.tenant_id = n.tenant_id AND w.id = n.workspace_id";

    private const RUN = "SELECT nr.*, r.public_id AS notebook_public_id, r.name AS notebook_name, w.public_id AS workspace_public_id,
               w.owner_user_id, n.workspace_id, j.cancel_requested AS job_cancel_requested
          FROM notebook_runs nr
          JOIN notebooks n ON n.tenant_id = nr.tenant_id AND n.id = nr.notebook_id
          JOIN resources r ON r.tenant_id = n.tenant_id AND r.id = n.resource_id
          JOIN workspaces w ON w.tenant_id = n.tenant_id AND w.id = n.workspace_id
          LEFT JOIN jobs j ON j.tenant_id = nr.tenant_id AND j.id = nr.job_id";

    public function __construct(private readonly Db $db)
    {
    }

    /** @param list<array<string, string>> $cells */
    public function create(TenantContext $ctx, int $workspaceId, int $resourceId, array $cells): int
    {
        return $this->db->insert(
            'INSERT INTO notebooks (tenant_id, workspace_id, resource_id, cells) VALUES (?, ?, ?, ?)',
            [$ctx->tenantId, $workspaceId, $resourceId, self::json($cells)]
        );
    }

    /** @return list<array<string, mixed>> */
    public function listForWorkspace(TenantContext $ctx, int $workspaceId): array
    {
        return $this->db->select(
            self::SELECT . " WHERE n.tenant_id = ? AND n.workspace_id = ? AND r.status <> 'deleted' ORDER BY r.name",
            [$ctx->tenantId, $workspaceId]
        );
    }

    /** @return array<string, mixed>|null */
    public function findVisible(TenantContext $ctx, string $publicId, bool $wholeTenant): ?array
    {
        $sql = self::SELECT . " WHERE n.tenant_id = ? AND r.public_id = ? AND r.status <> 'deleted' AND w.status = 'active'";
        return $this->visible($sql, $ctx, $publicId, $wholeTenant);
    }

    /** @param list<array<string, string>> $cells */
    public function updateCells(TenantContext $ctx, int $id, array $cells): void
    {
        $this->db->execute(
            'UPDATE notebooks SET cells = ?, version = version + 1 WHERE tenant_id = ? AND id = ?',
            [self::json($cells), $ctx->tenantId, $id]
        );
    }

    // ------------------------------------------------------------------------------------------------ runs

    /**
     * @param list<array<string, string>> $cells snapshot
     * @return array{id: int, public_id: string}
     */
    public function createRun(TenantContext $ctx, int $notebookId, int $version, array $cells): array
    {
        $publicId = Ulid::generate();
        $id = $this->db->insert(
            'INSERT INTO notebook_runs (public_id, tenant_id, notebook_id, user_id, notebook_version, cells) VALUES (?, ?, ?, ?, ?, ?)',
            [$publicId, $ctx->tenantId, $notebookId, $ctx->userId, $version, self::json($cells)]
        );
        return ['id' => $id, 'public_id' => $publicId];
    }

    public function attachJob(int $tenantId, int $runId, int $jobId): void
    {
        $this->db->execute('UPDATE notebook_runs SET job_id = ? WHERE tenant_id = ? AND id = ?', [$jobId, $tenantId, $runId]);
    }

    /** @return list<array<string, mixed>> */
    public function runs(TenantContext $ctx, int $notebookId, int $limit): array
    {
        return $this->db->select(
            self::RUN . ' WHERE nr.tenant_id = ? AND nr.notebook_id = ? ORDER BY nr.id DESC LIMIT ' . max(1, min(50, $limit)),
            [$ctx->tenantId, $notebookId]
        );
    }

    /** @return array<string, mixed>|null */
    public function findRunVisible(TenantContext $ctx, string $publicId, bool $wholeTenant): ?array
    {
        return $this->visible(self::RUN . " WHERE nr.tenant_id = ? AND nr.public_id = ? AND w.status = 'active'", $ctx, $publicId, $wholeTenant);
    }

    /** @return array<string, mixed>|null */
    public function findRunForJob(int $tenantId, int $runId): ?array
    {
        return $this->db->selectOne(self::RUN . ' WHERE nr.tenant_id = ? AND nr.id = ?', [$tenantId, $runId]);
    }

    public function markRunning(int $tenantId, int $runId): void
    {
        $this->db->execute("UPDATE notebook_runs SET status = 'running' WHERE tenant_id = ? AND id = ? AND status = 'queued'", [$tenantId, $runId]);
    }

    /**
     * @param list<array<string, mixed>>|null $outputs
     * @param array<string, mixed>|null       $artifacts
     */
    public function finishRun(
        int $tenantId,
        int $runId,
        string $status,
        ?array $outputs,
        ?array $artifacts,
        ?string $code,
        ?string $message,
        ?int $durationMs
    ): void {
        $this->db->execute(
            'UPDATE notebook_runs SET status = ?, outputs = ?, artifacts = ?, error_code = ?, error_message = ?, duration_ms = ?,
                    finished_at = UTC_TIMESTAMP(3)
              WHERE tenant_id = ? AND id = ?',
            [
                $status, $outputs === null ? null : self::json($outputs), $artifacts === null ? null : self::json($artifacts),
                $code, $message === null ? null : mb_substr($message, 0, 300), $durationMs, $tenantId, $runId,
            ]
        );
    }

    /** @return array<string, mixed>|null */
    private function visible(string $sql, TenantContext $ctx, string $publicId, bool $wholeTenant): ?array
    {
        $params = [$ctx->tenantId, $publicId];
        if (!$wholeTenant) {
            $sql .= ' AND w.owner_user_id = ?';
            $params[] = $ctx->userId;
        }
        return $this->db->selectOne($sql, $params);
    }

    /** @param array<mixed> $value */
    private static function json(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        return $json === false ? '[]' : $json;
    }
}
