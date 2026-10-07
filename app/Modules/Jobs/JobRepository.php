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
    public function create(
        TenantContext $ctx,
        ?int $workspaceId,
        string $type,
        array $payload,
        int $timeoutSeconds,
        int $priority = 5,
        int $maxAttempts = 1,
    ): array {
        $publicId = Ulid::generate();
        $id = $this->db->insert(
            'INSERT INTO jobs (public_id, tenant_id, workspace_id, user_id, type, priority, payload, timeout_s, max_attempts)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $publicId, $ctx->tenantId, $workspaceId, $ctx->userId, $type, $priority, (string) json_encode($payload),
                $timeoutSeconds, max(1, $maxAttempts),
            ]
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
     * @param list<string>|null $onlyTypes   claim only these job types (dedicated workers, e.g. notebooks)
     * @param list<string>      $exceptTypes never claim these types
     * @return array<string, mixed>|null job row incl. tenant_public_id and workspace_public_id
     */
    public function claimNext(string $workerId, ?array $onlyTypes = null, array $exceptTypes = []): ?array
    {
        return $this->db->transaction(function () use ($workerId, $onlyTypes, $exceptTypes): ?array {
            $filter = '';
            $params = [];
            if ($onlyTypes !== null) {
                $filter .= ' AND j.type IN (' . implode(', ', array_fill(0, max(1, count($onlyTypes)), '?')) . ')';
                $params = $onlyTypes === [] ? [''] : $onlyTypes;
            }
            if ($exceptTypes !== []) {
                $filter .= ' AND j.type NOT IN (' . implode(', ', array_fill(0, count($exceptTypes), '?')) . ')';
                $params = [...$params, ...$exceptTypes];
            }
            // Fairness: among queued jobs, prefer the user whose most recent job started longest ago, so one user's
            // burst cannot starve everyone else on the single dispatcher.
            $row = $this->db->selectOne(
                "SELECT j.id FROM jobs j
                  WHERE j.status = 'queued'$filter
                  ORDER BY j.priority ASC,
                           COALESCE((SELECT MAX(p.started_at) FROM jobs p WHERE p.user_id = j.user_id), '1970-01-01') ASC,
                           j.queued_at ASC, j.id ASC
                  LIMIT 1 FOR UPDATE",
                $params
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
                'SELECT j.*, t.public_id AS tenant_public_id, w.public_id AS workspace_public_id, w.status AS workspace_status
                   FROM jobs j
                   JOIN tenants t ON t.id = j.tenant_id
                   LEFT JOIN workspaces w ON w.tenant_id = j.tenant_id AND w.id = j.workspace_id
                  WHERE j.id = ?',
                [(int) $row['id']]
            );
        });
    }

    /** Cancels the queued jobs of a workspace that is being released (abandoned/expired lab, M6 gate). */
    public function cancelQueuedForWorkspace(int $tenantId, int $workspaceId): int
    {
        return $this->db->execute(
            "UPDATE jobs SET status = 'cancelled', error_code = 'WORKSPACE_DELETED', finished_at = UTC_TIMESTAMP(3)
              WHERE tenant_id = ? AND workspace_id = ? AND status = 'queued'",
            [$tenantId, $workspaceId]
        );
    }

    /** Keeps a running job alive; returns true when cancellation was requested (the dispatcher then stops the runner). */
    public function heartbeat(int $id): bool
    {
        $this->db->execute("UPDATE jobs SET heartbeat_at = UTC_TIMESTAMP(3) WHERE id = ? AND status = 'running'", [$id]);
        return (int) $this->db->scalar('SELECT cancel_requested FROM jobs WHERE id = ?', [$id]) === 1;
    }

    /**
     * Cancels a job of the tenant: a queued job is cancelled at once (returns 'cancelled'); a running one is flagged
     * and stopped by the dispatcher at its next heartbeat (returns 'requested'). Finished jobs return null.
     */
    public function cancel(int $tenantId, int $id): ?string
    {
        $cancelled = $this->db->execute(
            "UPDATE jobs SET status = 'cancelled', error_code = 'CANCELLED', finished_at = UTC_TIMESTAMP(3)
              WHERE tenant_id = ? AND id = ? AND status = 'queued'",
            [$tenantId, $id]
        );
        if ($cancelled === 1) {
            return 'cancelled';
        }
        return $this->db->execute(
            "UPDATE jobs SET cancel_requested = 1 WHERE tenant_id = ? AND id = ? AND status = 'running'",
            [$tenantId, $id]
        ) === 1 ? 'requested' : null;
    }

    /** Puts a failed attempt back in the queue (transient failure, attempts < max_attempts). */
    public function requeue(int $id): bool
    {
        return $this->db->execute(
            "UPDATE jobs SET status = 'queued', locked_by = NULL, started_at = NULL, heartbeat_at = NULL, queued_at = UTC_TIMESTAMP(3)
              WHERE id = ? AND status = 'running' AND attempts < max_attempts AND cancel_requested = 0",
            [$id]
        ) === 1;
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
