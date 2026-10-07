<?php

declare(strict_types=1);

namespace EduCloud\Modules\Admin;

use EduCloud\Core\Db;
use EduCloud\Core\Format;

/**
 * Platform-wide, read-mostly queries for the admin monitor (platform admins only, enforced by the route permission
 * 'platform_admin' that no tenant role holds). Deliberately NOT tenant-scoped. Never selects secrets: no password
 * hashes, token hashes, job payloads or IP hashes.
 */
final class AdminRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $count = fn (string $sql, string $key): array => array_map('intval', array_column($this->db->select($sql), 'n', $key));
        return [
            'users' => $count('SELECT status, COUNT(*) AS n FROM users GROUP BY status', 'status'),
            'tenants' => $count("SELECT type, COUNT(*) AS n FROM tenants WHERE status = 'active' GROUP BY type", 'type'),
            'jobs_24h' => $count(
                'SELECT status, COUNT(*) AS n FROM jobs WHERE queued_at >= UTC_TIMESTAMP(3) - INTERVAL 1 DAY GROUP BY status',
                'status'
            ),
            'queue' => [
                'queued' => (int) $this->db->scalar("SELECT COUNT(*) FROM jobs WHERE status = 'queued'"),
                'running' => (int) $this->db->scalar("SELECT COUNT(*) FROM jobs WHERE status = 'running'"),
                'oldest_queued_at' => Format::isoUtc(self::nullableString(
                    $this->db->scalar("SELECT MIN(queued_at) FROM jobs WHERE status = 'queued'")
                )),
            ],
            'storage' => [
                'total_bytes' => (int) $this->db->scalar(
                    "SELECT COALESCE(SUM(value), 0) FROM usage_counters WHERE metric = 'storage_bytes' AND period = 'total'"
                ),
                'top' => array_map(static fn (array $r): array => [
                    'user' => ['id' => (string) $r['user_public_id'], 'display_name' => (string) $r['display_name']],
                    'tenant' => (string) $r['tenant_name'],
                    'bytes' => (int) $r['value'],
                ], $this->db->select(
                    "SELECT u.public_id AS user_public_id, u.display_name, t.name AS tenant_name, c.value
                       FROM usage_counters c JOIN users u ON u.id = c.user_id JOIN tenants t ON t.id = c.tenant_id
                      WHERE c.metric = 'storage_bytes' AND c.period = 'total' AND c.value > 0
                      ORDER BY c.value DESC LIMIT 5"
                )),
            ],
            'labs' => [
                'attempts_in_progress' => (int) $this->db->scalar("SELECT COUNT(*) FROM lab_attempts WHERE status IN ('in_progress', 'validating')"),
                'attempts_completed' => (int) $this->db->scalar("SELECT COUNT(*) FROM lab_attempts WHERE status = 'completed'"),
                'courses' => (int) $this->db->scalar("SELECT COUNT(*) FROM courses WHERE status <> 'archived'"),
            ],
            'denied_24h' => (int) $this->db->scalar(
                "SELECT COUNT(*) FROM audit_logs WHERE outcome IN ('denied', 'failure') AND occurred_at >= UTC_TIMESTAMP(3) - INTERVAL 1 DAY"
            ),
        ];
    }

    /**
     * @param array{status?: string, type?: string} $filters values already allowlisted by the controller
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function jobs(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = self::where(['j.status' => $filters['status'] ?? null, 'j.type' => $filters['type'] ?? null]);
        $rows = $this->db->select(
            "SELECT j.public_id, j.type, j.status, j.priority, j.attempts, j.error_code, j.safe_message, j.result_summary,
                    j.queued_at, j.started_at, j.finished_at, t.public_id AS tenant_public_id, t.name AS tenant_name,
                    w.public_id AS workspace_public_id, u.public_id AS user_public_id, u.display_name
               FROM jobs j JOIN tenants t ON t.id = j.tenant_id JOIN users u ON u.id = j.user_id
               LEFT JOIN workspaces w ON w.tenant_id = j.tenant_id AND w.id = j.workspace_id
              WHERE $where ORDER BY j.id DESC LIMIT ? OFFSET ?",
            [...$params, $limit, $offset]
        );
        return ['rows' => $rows, 'total' => (int) $this->db->scalar("SELECT COUNT(*) FROM jobs j WHERE $where", $params)];
    }

    /**
     * @param array{action?: string, outcome?: string} $filters action is a validated prefix, outcome allowlisted
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function audit(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = self::where(['a.outcome' => $filters['outcome'] ?? null]);
        if (($filters['action'] ?? '') !== '') {
            $where .= " AND a.action LIKE ? ESCAPE '\\\\'";
            $params[] = addcslashes((string) $filters['action'], '%_\\') . '%';
        }
        $rows = $this->db->select(
            "SELECT a.id, a.occurred_at, a.request_id, a.action, a.outcome, a.resource_type, a.resource_public_id, a.meta,
                    t.public_id AS tenant_public_id, t.name AS tenant_name, u.public_id AS user_public_id, u.display_name
               FROM audit_logs a
               LEFT JOIN tenants t ON t.id = a.tenant_id
               LEFT JOIN users u ON u.id = a.actor_user_id
              WHERE $where ORDER BY a.id DESC LIMIT ? OFFSET ?",
            [...$params, $limit, $offset]
        );
        return ['rows' => $rows, 'total' => (int) $this->db->scalar("SELECT COUNT(*) FROM audit_logs a WHERE $where", $params)];
    }

    /**
     * @param array{q?: string, status?: string, id?: string} $filters id: exact internal id (re-read after a change)
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function users(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = self::where(['u.status' => $filters['status'] ?? null, 'u.id' => $filters['id'] ?? null]);
        if (($filters['q'] ?? '') !== '') {
            $where .= " AND (u.email LIKE ? ESCAPE '\\\\' OR u.display_name LIKE ? ESCAPE '\\\\')";
            $like = Format::likeContains((string) $filters['q']);
            array_push($params, $like, $like);
        }
        $rows = $this->db->select(
            "SELECT u.id, u.public_id, u.email, u.display_name, u.status, u.is_platform_admin, u.email_verified_at,
                    u.last_login_at, u.created_at,
                    (SELECT COUNT(*) FROM memberships m WHERE m.user_id = u.id AND m.status = 'active') AS memberships,
                    (SELECT COALESCE(SUM(c.value), 0) FROM usage_counters c
                      WHERE c.user_id = u.id AND c.metric = 'storage_bytes' AND c.period = 'total') AS storage_bytes
               FROM users u WHERE $where ORDER BY u.id DESC LIMIT ? OFFSET ?",
            [...$params, $limit, $offset]
        );
        return ['rows' => $rows, 'total' => (int) $this->db->scalar("SELECT COUNT(*) FROM users u WHERE $where", $params)];
    }

    /** @return array<string, mixed>|null */
    public function findUser(string $publicId): ?array
    {
        return $this->db->selectOne('SELECT id, public_id, email, status, is_platform_admin FROM users WHERE public_id = ?', [$publicId]);
    }

    public function setUserStatus(int $userId, string $status): void
    {
        $this->db->execute('UPDATE users SET status = ? WHERE id = ? AND is_platform_admin = 0', [$status, $userId]);
    }

    /**
     * Equality filters on allowlisted columns (the column names are constants of this class, values are bound).
     *
     * @param array<string, string|null> $filters
     * @return array{0: string, 1: list<string>}
     */
    private static function where(array $filters): array
    {
        $where = '1 = 1';
        $params = [];
        foreach ($filters as $column => $value) {
            if ($value !== null && $value !== '') {
                $where .= " AND $column = ?";
                $params[] = $value;
            }
        }
        return [$where, $params];
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
