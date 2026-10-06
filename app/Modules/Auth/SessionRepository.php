<?php

declare(strict_types=1);

namespace EduCloud\Modules\Auth;

use EduCloud\Core\Db;

/**
 * Server-side browser sessions. The cookie carries a random 256-bit id; the table stores only SHA-256(id).
 */
final class SessionRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** Creates a session and returns the raw session id (cookie value). */
    public function create(int $userId, int $tenantId, ?string $ipHash, ?string $userAgent, int $absoluteLifetime): string
    {
        $id = TokenRepository::newToken();
        $this->db->insert(
            'INSERT INTO sessions (id_hash, user_id, tenant_id, ip_hash, user_agent, expires_at)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(3) + INTERVAL ? SECOND)',
            [TokenRepository::hash($id), $userId, $tenantId, $ipHash, $userAgent === null ? null : mb_substr($userAgent, 0, 255), $absoluteLifetime]
        );
        return $id;
    }

    /**
     * Returns a live session (not expired and active within the idle timeout) or null.
     * Expired/idle sessions are deleted on sight.
     *
     * @return array{id: int, user_id: int, tenant_id: int, idle_seconds: int}|null
     */
    public function findLive(string $rawId, int $idleTimeout): ?array
    {
        $row = $this->db->selectOne(
            'SELECT id, user_id, tenant_id, TIMESTAMPDIFF(SECOND, last_activity_at, UTC_TIMESTAMP(3)) AS idle_seconds,
                    (expires_at <= UTC_TIMESTAMP(3)) AS expired
               FROM sessions WHERE id_hash = ?',
            [TokenRepository::hash($rawId)]
        );
        if ($row === null) {
            return null;
        }
        if ((int) $row['expired'] === 1 || (int) $row['idle_seconds'] >= $idleTimeout) {
            $this->db->execute('DELETE FROM sessions WHERE id = ?', [(int) $row['id']]);
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'tenant_id' => (int) $row['tenant_id'],
            'idle_seconds' => (int) $row['idle_seconds'],
        ];
    }

    public function touch(int $sessionId): void
    {
        $this->db->execute('UPDATE sessions SET last_activity_at = UTC_TIMESTAMP(3) WHERE id = ?', [$sessionId]);
    }

    public function setTenant(int $sessionId, int $tenantId): void
    {
        $this->db->execute('UPDATE sessions SET tenant_id = ? WHERE id = ?', [$tenantId, $sessionId]);
    }

    public function delete(int $sessionId): void
    {
        $this->db->execute('DELETE FROM sessions WHERE id = ?', [$sessionId]);
    }

    public function deleteAllForUser(int $userId): int
    {
        return $this->db->execute('DELETE FROM sessions WHERE user_id = ?', [$userId]);
    }

    public function purgeExpired(int $idleTimeout): int
    {
        return $this->db->execute(
            'DELETE FROM sessions WHERE expires_at <= UTC_TIMESTAMP(3) OR last_activity_at < UTC_TIMESTAMP(3) - INTERVAL ? SECOND',
            [$idleTimeout]
        );
    }
}
