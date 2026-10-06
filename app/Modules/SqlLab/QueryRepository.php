<?php

declare(strict_types=1);

namespace EduCloud\Modules\SqlLab;

use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Db;
use EduCloud\Core\Ulid;

/** SQL Lab query history (query_history). History is personal: users see their own queries (org_admin: any). */
final class QueryRepository
{
    private const SELECT = "SELECT q.id, q.public_id, q.tenant_id, q.workspace_id, q.user_id, q.job_id, q.sql_text, q.status,
               q.duration_ms, q.row_count, q.error_code, q.created_at, w.public_id AS workspace_public_id,
               j.status AS job_status, j.safe_message, j.result_summary
          FROM query_history q
          JOIN workspaces w ON w.tenant_id = q.tenant_id AND w.id = q.workspace_id
          LEFT JOIN jobs j ON j.tenant_id = q.tenant_id AND j.id = q.job_id";

    public function __construct(private readonly Db $db)
    {
    }

    /** @return array{id: int, public_id: string} */
    public function create(TenantContext $ctx, int $workspaceId, string $sql): array
    {
        $publicId = Ulid::generate();
        $id = $this->db->insert(
            'INSERT INTO query_history (public_id, tenant_id, workspace_id, user_id, sql_text) VALUES (?, ?, ?, ?, ?)',
            [$publicId, $ctx->tenantId, $workspaceId, $ctx->userId, $sql]
        );
        return ['id' => $id, 'public_id' => $publicId];
    }

    public function attachJob(int $tenantId, int $queryId, int $jobId): void
    {
        $this->db->execute('UPDATE query_history SET job_id = ? WHERE tenant_id = ? AND id = ?', [$jobId, $tenantId, $queryId]);
    }

    /** @return array<string, mixed>|null */
    public function findVisible(TenantContext $ctx, string $publicId, bool $wholeTenant): ?array
    {
        $sql = self::SELECT . " WHERE q.tenant_id = ? AND q.public_id = ? AND w.status = 'active'";
        $params = [$ctx->tenantId, $publicId];
        if (!$wholeTenant) {
            $sql .= ' AND q.user_id = ?';
            $params[] = $ctx->userId;
        }
        return $this->db->selectOne($sql, $params);
    }

    /** @return array<string, mixed>|null unscoped lookup for the dispatcher */
    public function findForJob(int $tenantId, int $queryId): ?array
    {
        return $this->db->selectOne(self::SELECT . ' WHERE q.tenant_id = ? AND q.id = ?', [$tenantId, $queryId]);
    }

    /** @return list<array<string, mixed>> */
    public function history(TenantContext $ctx, int $workspaceId, int $limit, int $offset): array
    {
        return $this->db->select(
            self::SELECT . ' WHERE q.tenant_id = ? AND q.workspace_id = ? AND q.user_id = ? ORDER BY q.id DESC LIMIT ? OFFSET ?',
            [$ctx->tenantId, $workspaceId, $ctx->userId, $limit, $offset]
        );
    }

    public function countHistory(TenantContext $ctx, int $workspaceId): int
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM query_history WHERE tenant_id = ? AND workspace_id = ? AND user_id = ?',
            [$ctx->tenantId, $workspaceId, $ctx->userId]
        );
    }

    public function markResult(int $tenantId, int $queryId, string $status, ?int $durationMs, ?int $rowCount, ?string $errorCode): void
    {
        $this->db->execute(
            'UPDATE query_history SET status = ?, duration_ms = ?, row_count = ?, error_code = ? WHERE tenant_id = ? AND id = ?',
            [$status, $durationMs, $rowCount, $errorCode, $tenantId, $queryId]
        );
    }
}
