<?php

declare(strict_types=1);

namespace EduCloud\Modules\Analytics;

use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Db;
use EduCloud\Core\Ulid;

/**
 * Semantic models (resource type 'semantic_model'), dashboards (type 'dashboard') and semantic queries (M9).
 * Tenant-scoped; visibility follows the workspace owner unless the caller sees the whole tenant. *ForJob methods
 * serve the trusted dispatcher.
 */
final class AnalyticsRepository
{
    private const MODEL = "SELECT m.id, m.tenant_id, m.workspace_id, m.resource_id, m.definition, m.version, m.created_at, m.updated_at,
               r.public_id, r.name, w.public_id AS workspace_public_id, w.owner_user_id, w.status AS workspace_status,
               (SELECT count(*) FROM dashboards d JOIN resources dr ON dr.tenant_id = d.tenant_id AND dr.id = d.resource_id
                 WHERE d.tenant_id = m.tenant_id AND d.model_id = m.id AND dr.status <> 'deleted') AS dashboard_count
          FROM semantic_models m
          JOIN resources r ON r.tenant_id = m.tenant_id AND r.id = m.resource_id
          JOIN workspaces w ON w.tenant_id = m.tenant_id AND w.id = m.workspace_id";

    private const DASHBOARD = "SELECT d.id, d.tenant_id, d.workspace_id, d.resource_id, d.model_id, d.definition, d.version, d.created_at,
               d.updated_at, r.public_id, r.name, w.public_id AS workspace_public_id, w.owner_user_id, w.status AS workspace_status,
               mr.public_id AS model_public_id, mr.name AS model_name, m.definition AS model_definition
          FROM dashboards d
          JOIN resources r ON r.tenant_id = d.tenant_id AND r.id = d.resource_id
          JOIN workspaces w ON w.tenant_id = d.tenant_id AND w.id = d.workspace_id
          JOIN semantic_models m ON m.tenant_id = d.tenant_id AND m.id = d.model_id
          JOIN resources mr ON mr.tenant_id = m.tenant_id AND mr.id = m.resource_id";

    private const QUERY = "SELECT q.*, w.public_id AS workspace_public_id, w.owner_user_id, mr.public_id AS model_public_id,
               dr.public_id AS dashboard_public_id
          FROM semantic_queries q
          JOIN workspaces w ON w.tenant_id = q.tenant_id AND w.id = q.workspace_id
          JOIN semantic_models m ON m.tenant_id = q.tenant_id AND m.id = q.model_id
          JOIN resources mr ON mr.tenant_id = m.tenant_id AND mr.id = m.resource_id
          LEFT JOIN dashboards d ON d.tenant_id = q.tenant_id AND d.id = q.dashboard_id
          LEFT JOIN resources dr ON dr.tenant_id = d.tenant_id AND dr.id = d.resource_id";

    public function __construct(private readonly Db $db)
    {
    }

    // ------------------------------------------------------------------------------------------------ models

    /** @param array<string, mixed> $definition */
    public function createModel(TenantContext $ctx, int $workspaceId, int $resourceId, array $definition): int
    {
        return $this->db->insert(
            'INSERT INTO semantic_models (tenant_id, workspace_id, resource_id, definition) VALUES (?, ?, ?, ?)',
            [$ctx->tenantId, $workspaceId, $resourceId, self::json($definition)]
        );
    }

    /** @return list<array<string, mixed>> */
    public function models(TenantContext $ctx, int $workspaceId): array
    {
        return $this->db->select(
            self::MODEL . " WHERE m.tenant_id = ? AND m.workspace_id = ? AND r.status <> 'deleted' ORDER BY r.name",
            [$ctx->tenantId, $workspaceId]
        );
    }

    /** @return array<string, mixed>|null */
    public function findModel(TenantContext $ctx, string $publicId, bool $wholeTenant): ?array
    {
        $sql = self::MODEL . " WHERE m.tenant_id = ? AND r.public_id = ? AND r.status <> 'deleted' AND w.status = 'active'";
        return $this->visible($sql, $ctx, $publicId, $wholeTenant);
    }

    /** @param array<string, mixed> $definition */
    public function updateModel(TenantContext $ctx, int $id, array $definition): void
    {
        $this->db->execute(
            'UPDATE semantic_models SET definition = ?, version = version + 1 WHERE tenant_id = ? AND id = ?',
            [self::json($definition), $ctx->tenantId, $id]
        );
    }

    /**
     * Definition of an active model, read under the caller's workspace lock (model writes all take that lock).
     *
     * @return array<string, mixed>|null
     */
    public function activeModelDefinition(TenantContext $ctx, int $modelId): ?array
    {
        $definition = $this->db->scalar(
            "SELECT m.definition FROM semantic_models m JOIN resources r ON r.tenant_id = m.tenant_id AND r.id = m.resource_id
              WHERE m.tenant_id = ? AND m.id = ? AND r.status = 'active'",
            [$ctx->tenantId, $modelId]
        );
        if ($definition === null) {
            return null;
        }
        $decoded = json_decode((string) $definition, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @return list<array<string, mixed>> non-deleted dashboards built on a model (to re-validate them on model changes) */
    public function dashboardsOfModel(TenantContext $ctx, int $modelId): array
    {
        return $this->db->select(
            "SELECT d.id, d.definition, r.name FROM dashboards d JOIN resources r ON r.tenant_id = d.tenant_id AND r.id = d.resource_id
              WHERE d.tenant_id = ? AND d.model_id = ? AND r.status <> 'deleted'",
            [$ctx->tenantId, $modelId]
        );
    }

    // ------------------------------------------------------------------------------------------------ dashboards

    /** @param array<string, mixed> $definition */
    public function createDashboard(TenantContext $ctx, int $workspaceId, int $resourceId, int $modelId, array $definition): int
    {
        return $this->db->insert(
            'INSERT INTO dashboards (tenant_id, workspace_id, resource_id, model_id, definition) VALUES (?, ?, ?, ?, ?)',
            [$ctx->tenantId, $workspaceId, $resourceId, $modelId, self::json($definition)]
        );
    }

    /** @return list<array<string, mixed>> */
    public function dashboards(TenantContext $ctx, int $workspaceId): array
    {
        return $this->db->select(
            self::DASHBOARD . " WHERE d.tenant_id = ? AND d.workspace_id = ? AND r.status <> 'deleted' ORDER BY r.name",
            [$ctx->tenantId, $workspaceId]
        );
    }

    /** @return array<string, mixed>|null */
    public function findDashboard(TenantContext $ctx, string $publicId, bool $wholeTenant): ?array
    {
        $sql = self::DASHBOARD . " WHERE d.tenant_id = ? AND r.public_id = ? AND r.status <> 'deleted' AND mr.status <> 'deleted'
              AND w.status = 'active'";
        return $this->visible($sql, $ctx, $publicId, $wholeTenant);
    }

    /** @param array<string, mixed> $definition */
    public function updateDashboard(TenantContext $ctx, int $id, int $modelId, array $definition): void
    {
        $this->db->execute(
            'UPDATE dashboards SET model_id = ?, definition = ?, version = version + 1 WHERE tenant_id = ? AND id = ?',
            [$modelId, self::json($definition), $ctx->tenantId, $id]
        );
    }

    // ------------------------------------------------------------------------------------------------ queries

    /**
     * @param array<string, mixed> $request snapshot: {model, queries}
     * @return array{id: int, public_id: string}
     */
    public function createQuery(TenantContext $ctx, int $workspaceId, int $modelId, ?int $dashboardId, string $kind, array $request): array
    {
        $publicId = Ulid::generate();
        $id = $this->db->insert(
            'INSERT INTO semantic_queries (public_id, tenant_id, workspace_id, model_id, dashboard_id, user_id, kind, request)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$publicId, $ctx->tenantId, $workspaceId, $modelId, $dashboardId, $ctx->userId, $kind, self::json($request)]
        );
        return ['id' => $id, 'public_id' => $publicId];
    }

    public function attachJob(int $tenantId, int $queryId, int $jobId): void
    {
        $this->db->execute('UPDATE semantic_queries SET job_id = ? WHERE tenant_id = ? AND id = ?', [$jobId, $tenantId, $queryId]);
    }

    /** @return array<string, mixed>|null */
    public function findQuery(TenantContext $ctx, string $publicId, bool $wholeTenant): ?array
    {
        $sql = self::QUERY . " WHERE q.tenant_id = ? AND q.public_id = ? AND w.status = 'active' AND mr.status <> 'deleted'";
        return $this->visible($sql, $ctx, $publicId, $wholeTenant);
    }

    /** @return array<string, mixed>|null */
    public function findQueryForJob(int $tenantId, int $queryId): ?array
    {
        return $this->db->selectOne(self::QUERY . ' WHERE q.tenant_id = ? AND q.id = ?', [$tenantId, $queryId]);
    }

    public function finishQuery(int $tenantId, int $queryId, string $status, ?int $durationMs, ?string $code, ?string $message): void
    {
        $this->db->execute(
            'UPDATE semantic_queries SET status = ?, duration_ms = ?, error_code = ?, error_message = ?, finished_at = UTC_TIMESTAMP(3)
              WHERE tenant_id = ? AND id = ?',
            [$status, $durationMs, $code, $message === null ? null : mb_substr($message, 0, 300), $tenantId, $queryId]
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

    /** @param array<string, mixed> $value */
    private static function json(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
