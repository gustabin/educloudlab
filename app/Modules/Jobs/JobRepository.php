<?php

declare(strict_types=1);

namespace EduCloud\Modules\Jobs;

use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Db;
use EduCloud\Core\Ulid;

/** The jobs queue. Tenant-scoped reads for the API; unscoped claim/finish for the (trusted) dispatcher only. */
final class JobRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * @param array<string, mixed> $payload internal ids only (never paths or user-supplied text)
     * @return array{id: int, public_id: string}
     */
    public function create(TenantContext $ctx, ?int $workspaceId, string $type, array $payload, int $timeoutSeconds, int $priority = 5): array
    {
        $publicId = Ulid::generate();
        $id = $this->db->insert(
            'INSERT INTO jobs (public_id, tenant_id, workspace_id, user_id, type, priority, payload, timeout_s) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$publicId, $ctx->tenantId, $workspaceId, $ctx->userId, $type, $priority, (string) json_encode($payload), $timeoutSeconds]
        );
        return ['id' => $id, 'public_id' => $publicId];
    }

    public function countActiveForUser(TenantContext $ctx, int $userId): int
    {
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM jobs WHERE tenant_id = ? AND user_id = ? AND status IN ('queued', 'running')",
            [$ctx->tenantId, $userId]
        );
    }

    /** @return array<string, mixed>|null */
    public function findVisible(TenantContext $ctx, string $publicId, bool $wholeTenant): ?array
    {
        $sql = 'SELECT j.*, w.public_id AS workspace_public_id FROM jobs j
                  LEFT JOIN workspaces w ON w.tenant_id = j.tenant_id AND w.id = j.workspace_id
                 WHERE j.tenant_id = ? AND j.public_id = ?';
        $params = [$ctx->tenantId, $publicId];
        if (!$wholeTenant) {
            $sql .= ' AND j.user_id = ?';
            $params[] = $ctx->userId;
        }
        return $this->db->selectOne($sql, $params);
    }

    /**
     * Claims the next queued job (single dispatcher, ADR-005) and marks it running.
     *
     * @return array<string, mixed>|null job row incl. tenant_public_id and workspace_public_id
     */
    public function claimNext(string $workerId): ?array
    {
        return $this->db->transaction(function () use ($workerId): ?array {
            // Fairness: among queued jobs, prefer the user whose most recent job started longest ago, so one user's
            // burst cannot starve everyone else on the single dispatcher.
            $row = $this->db->selectOne(
                "SELECT j.id FROM jobs j
                  WHERE j.status = 'queued'
                  ORDER BY j.priority ASC,
                           COALESCE((SELECT MAX(p.started_at) FROM jobs p WHERE p.user_id = j.user_id), '1970-01-01') ASC,
                           j.queued_at ASC, j.id ASC
                  LIMIT 1 FOR UPDATE"
            );
            if ($row === null) {
                return null;
            }
            $this->db->execute(
                "UPDATE jobs SET status = 'running', locked_by = ?, attempts = attempts + 1,
                        started_at = UTC_TIMESTAMP(3), heartbeat_at = UTC_TIMESTAMP(3)
                  WHERE id = ? AND status = 'queued'",
                [$workerId, (int) $row['id']]
            );
            return $this->db->selectOne(
                'SELECT j.*, t.public_id AS tenant_public_id, w.public_id AS workspace_public_id
                   FROM jobs j
                   JOIN tenants t ON t.id = j.tenant_id
                   LEFT JOIN workspaces w ON w.tenant_id = j.tenant_id AND w.id = j.workspace_id
                  WHERE j.id = ?',
                [(int) $row['id']]
            );
        });
    }

    public function heartbeat(int $id): void
    {
        $this->db->execute("UPDATE jobs SET heartbeat_at = UTC_TIMESTAMP(3) WHERE id = ? AND status = 'running'", [$id]);
    }

    /** @param array<string, mixed>|null $summary */
    public function finish(int $id, string $status, ?array $summary, ?string $errorCode = null, ?string $safeMessage = null): void
    {
        $this->db->execute(
            "UPDATE jobs SET status = ?, result_summary = ?, error_code = ?, safe_message = ?, finished_at = UTC_TIMESTAMP(3)
              WHERE id = ? AND status = 'running'",
            [$status, $summary === null ? null : (string) json_encode($summary, JSON_UNESCAPED_UNICODE), $errorCode,
                $safeMessage === null ? null : mb_substr($safeMessage, 0, 500), $id]
        );
    }

    /**
     * Running jobs whose heartbeat stopped (dispatcher crash). Returned so their handlers can fail them cleanly.
     *
     * @return list<array<string, mixed>>
     */
    public function findStale(int $graceSeconds): array
    {
        return $this->db->select(
            "SELECT j.*, t.public_id AS tenant_public_id, w.public_id AS workspace_public_id
               FROM jobs j
               JOIN tenants t ON t.id = j.tenant_id
               LEFT JOIN workspaces w ON w.tenant_id = j.tenant_id AND w.id = j.workspace_id
              WHERE j.status = 'running' AND j.heartbeat_at < UTC_TIMESTAMP(3) - INTERVAL (j.timeout_s + ?) SECOND",
            [$graceSeconds]
        );
    }
}
