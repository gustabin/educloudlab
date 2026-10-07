<?php

declare(strict_types=1);

namespace EduCloud\Modules\Usage;

use EduCloud\Core\Db;

/**
 * Usage metering (usage_counters) and the inputs of the storage quota. Methods take explicit tenant/user ids because
 * they are also used by the trusted dispatcher and scheduler (no request context); callers pass TenantContext values.
 */
final class UsageRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** Bytes of raw files of the user's non-deleted datasets in the tenant. */
    public function rawBytes(int $tenantId, int $userId): int
    {
        return (int) $this->db->scalar(
            "SELECT COALESCE(SUM(v.bytes), 0) FROM dataset_versions v
               JOIN datasets d ON d.tenant_id = v.tenant_id AND d.id = v.dataset_id
               JOIN resources r ON r.tenant_id = d.tenant_id AND r.id = d.resource_id
              WHERE v.tenant_id = ? AND v.created_by_user_id = ? AND r.status <> 'deleted'",
            [$tenantId, $userId]
        );
    }

    /** @return list<string> public ids of the user's non-deleted workspaces in the tenant */
    public function workspacePublicIds(int $tenantId, int $userId): array
    {
        return array_map(static fn (array $r): string => (string) $r['public_id'], $this->db->select(
            "SELECT public_id FROM workspaces WHERE tenant_id = ? AND owner_user_id = ? AND status <> 'deleted'",
            [$tenantId, $userId]
        ));
    }

    public function setGauge(int $tenantId, int $userId, string $metric, int $value): void
    {
        $this->db->execute(
            "INSERT INTO usage_counters (tenant_id, user_id, metric, period, value) VALUES (?, ?, ?, 'total', ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)",
            [$tenantId, $userId, $metric, $value]
        );
    }

    public function increment(int $tenantId, int $userId, string $metric, string $period, int $delta): void
    {
        $this->db->execute(
            'INSERT INTO usage_counters (tenant_id, user_id, metric, period, value) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE value = value + VALUES(value)',
            [$tenantId, $userId, $metric, $period, $delta]
        );
    }

    /** @return array<string, int> metric => value for one period */
    public function period(int $tenantId, int $userId, string $period): array
    {
        $out = [];
        $rows = $this->db->select(
            'SELECT metric, value FROM usage_counters WHERE tenant_id = ? AND user_id = ? AND period = ?',
            [$tenantId, $userId, $period]
        );
        foreach ($rows as $row) {
            $out[(string) $row['metric']] = (int) $row['value'];
        }
        return $out;
    }

    /**
     * (tenant, user) pairs that own non-deleted workspaces - scheduler refresh of the storage gauge.
     *
     * @return list<array{tenant_id: int, tenant_public_id: string, user_id: int}>
     */
    public function owners(int $limit): array
    {
        return array_map(static fn (array $r): array => [
            'tenant_id' => (int) $r['tenant_id'],
            'tenant_public_id' => (string) $r['tenant_public_id'],
            'user_id' => (int) $r['owner_user_id'],
        ], $this->db->select(
            "SELECT DISTINCT w.tenant_id, t.public_id AS tenant_public_id, w.owner_user_id
               FROM workspaces w JOIN tenants t ON t.id = w.tenant_id
              WHERE w.status <> 'deleted'
              ORDER BY w.tenant_id, w.owner_user_id
              LIMIT " . max(1, $limit)
        ));
    }
}
