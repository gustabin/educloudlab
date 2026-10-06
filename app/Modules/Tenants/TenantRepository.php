<?php

declare(strict_types=1);

namespace EduCloud\Modules\Tenants;

use EduCloud\Core\Db;
use EduCloud\Core\Ulid;

/** Tenants and memberships. Membership is the only source of tenant access (never client input). */
final class TenantRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** Creates the personal tenant of a new user and makes the user its org_admin. Returns the tenant id. */
    public function createPersonalTenant(int $userId, string $displayName): int
    {
        $publicId = Ulid::generate();
        $tenantId = $this->db->insert(
            'INSERT INTO tenants (public_id, type, name, slug) VALUES (?, ?, ?, ?)',
            [$publicId, 'personal', mb_substr('Espacio de ' . $displayName, 0, 120), 'u-' . strtolower($publicId)]
        );
        $this->db->insert(
            'INSERT INTO memberships (tenant_id, user_id, role) VALUES (?, ?, ?)',
            [$tenantId, $userId, 'org_admin']
        );
        return $tenantId;
    }

    /**
     * Active membership of a user in an active tenant, or null.
     *
     * @return array{tenant_id: int, tenant_public_id: string, tenant_type: string, tenant_name: string, role: string}|null
     */
    public function findActiveMembership(int $userId, int $tenantId): ?array
    {
        $row = $this->db->selectOne(
            "SELECT t.id AS tenant_id, t.public_id AS tenant_public_id, t.type AS tenant_type, t.name AS tenant_name, m.role
               FROM memberships m
               JOIN tenants t ON t.id = m.tenant_id
              WHERE m.user_id = ? AND m.tenant_id = ? AND m.status = 'active' AND t.status = 'active'",
            [$userId, $tenantId]
        );
        return $row === null ? null : $this->membership($row);
    }

    /** @return array{tenant_id: int, tenant_public_id: string, tenant_type: string, tenant_name: string, role: string}|null */
    public function findActiveMembershipByPublicId(int $userId, string $tenantPublicId): ?array
    {
        $row = $this->db->selectOne(
            "SELECT t.id AS tenant_id, t.public_id AS tenant_public_id, t.type AS tenant_type, t.name AS tenant_name, m.role
               FROM memberships m
               JOIN tenants t ON t.id = m.tenant_id
              WHERE m.user_id = ? AND t.public_id = ? AND m.status = 'active' AND t.status = 'active'",
            [$userId, $tenantPublicId]
        );
        return $row === null ? null : $this->membership($row);
    }

    /**
     * Default tenant for a new session: the personal tenant, else the oldest active membership.
     *
     * @return array{tenant_id: int, tenant_public_id: string, tenant_type: string, tenant_name: string, role: string}|null
     */
    public function findDefaultMembership(int $userId): ?array
    {
        $row = $this->db->selectOne(
            "SELECT t.id AS tenant_id, t.public_id AS tenant_public_id, t.type AS tenant_type, t.name AS tenant_name, m.role
               FROM memberships m
               JOIN tenants t ON t.id = m.tenant_id
              WHERE m.user_id = ? AND m.status = 'active' AND t.status = 'active'
              ORDER BY (t.type = 'personal') DESC, m.created_at ASC
              LIMIT 1",
            [$userId]
        );
        return $row === null ? null : $this->membership($row);
    }

    /** @return list<array{tenant_id: int, tenant_public_id: string, tenant_type: string, tenant_name: string, role: string}> */
    public function listActiveMemberships(int $userId): array
    {
        $rows = $this->db->select(
            "SELECT t.id AS tenant_id, t.public_id AS tenant_public_id, t.type AS tenant_type, t.name AS tenant_name, m.role
               FROM memberships m
               JOIN tenants t ON t.id = m.tenant_id
              WHERE m.user_id = ? AND m.status = 'active' AND t.status = 'active'
              ORDER BY (t.type = 'personal') DESC, t.name ASC",
            [$userId]
        );
        return array_map(fn (array $r): array => $this->membership($r), $rows);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{tenant_id: int, tenant_public_id: string, tenant_type: string, tenant_name: string, role: string}
     */
    private function membership(array $row): array
    {
        return [
            'tenant_id' => (int) $row['tenant_id'],
            'tenant_public_id' => (string) $row['tenant_public_id'],
            'tenant_type' => (string) $row['tenant_type'],
            'tenant_name' => (string) $row['tenant_name'],
            'role' => (string) $row['role'],
        ];
    }
}
