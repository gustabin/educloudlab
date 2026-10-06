<?php

declare(strict_types=1);

namespace EduCloud\Modules\Auth;

use EduCloud\Core\Db;

/**
 * One-time tokens (email verification, password reset) and JWT refresh tokens.
 * Only SHA-256 hashes are stored; the raw token exists only in the email link / client response.
 */
final class TokenRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    public static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token, true);
    }

    /** Creates a one-time token, invalidating previous unused tokens of the same type. Returns the raw token. */
    public function createOneTime(int $userId, string $type, int $ttlSeconds): string
    {
        $this->db->execute(
            'UPDATE auth_tokens SET used_at = UTC_TIMESTAMP(3) WHERE user_id = ? AND type = ? AND used_at IS NULL',
            [$userId, $type]
        );
        $token = self::newToken();
        $this->db->insert(
            'INSERT INTO auth_tokens (user_id, type, token_hash, expires_at) VALUES (?, ?, ?, UTC_TIMESTAMP(3) + INTERVAL ? SECOND)',
            [$userId, $type, self::hash($token), $ttlSeconds]
        );
        return $token;
    }

    /**
     * Valid (unused, unexpired) one-time token of a type, without consuming it.
     *
     * @return array{user_id: int, email: string, password_hash: string}|null
     */
    public function findValidOneTime(string $token, string $type): ?array
    {
        $row = $this->db->selectOne(
            'SELECT u.id AS user_id, u.email, u.password_hash
               FROM auth_tokens t JOIN users u ON u.id = t.user_id
              WHERE t.token_hash = ? AND t.type = ? AND t.used_at IS NULL AND t.expires_at > UTC_TIMESTAMP(3)',
            [self::hash($token), $type]
        );
        return $row === null ? null : [
            'user_id' => (int) $row['user_id'],
            'email' => (string) $row['email'],
            'password_hash' => (string) $row['password_hash'],
        ];
    }

    /**
     * Atomically consumes a valid one-time token. Returns the user id, or null when the token is unknown,
     * expired, already used or of another type.
     */
    public function consumeOneTime(string $token, string $type): ?int
    {
        $hash = self::hash($token);
        $row = $this->db->selectOne(
            'SELECT id, user_id FROM auth_tokens
              WHERE token_hash = ? AND type = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP(3)',
            [$hash, $type]
        );
        if ($row === null) {
            return null;
        }
        // The used_at IS NULL condition makes concurrent double-use impossible: only one UPDATE wins.
        $won = $this->db->execute(
            'UPDATE auth_tokens SET used_at = UTC_TIMESTAMP(3) WHERE id = ? AND used_at IS NULL',
            [(int) $row['id']]
        );
        return $won === 1 ? (int) $row['user_id'] : null;
    }

    /** Stores a refresh token and returns the raw value. */
    public function createRefresh(int $userId, int $tenantId, string $familyId, ?int $rotatedFromId, int $ttlSeconds): string
    {
        $token = self::newToken();
        $this->db->insert(
            'INSERT INTO refresh_tokens (user_id, tenant_id, family_id, token_hash, rotated_from_id, expires_at)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(3) + INTERVAL ? SECOND)',
            [$userId, $tenantId, $familyId, self::hash($token), $rotatedFromId, $ttlSeconds]
        );
        return $token;
    }

    /** @return array<string, mixed>|null refresh token row incl. computed `expired` flag */
    public function findRefresh(string $token): ?array
    {
        return $this->db->selectOne(
            'SELECT id, user_id, tenant_id, family_id, used_at, revoked_at, (expires_at <= UTC_TIMESTAMP(3)) AS expired
               FROM refresh_tokens WHERE token_hash = ?',
            [self::hash($token)]
        );
    }

    /** Marks a refresh token as used; returns false if it was already used/revoked (concurrent reuse). */
    public function markRefreshUsed(int $id): bool
    {
        return $this->db->execute(
            'UPDATE refresh_tokens SET used_at = UTC_TIMESTAMP(3) WHERE id = ? AND used_at IS NULL AND revoked_at IS NULL',
            [$id]
        ) === 1;
    }

    public function revokeFamily(string $familyId): void
    {
        $this->db->execute(
            'UPDATE refresh_tokens SET revoked_at = UTC_TIMESTAMP(3) WHERE family_id = ? AND revoked_at IS NULL',
            [$familyId]
        );
    }

    public function revokeAllForUser(int $userId): void
    {
        $this->db->execute(
            'UPDATE refresh_tokens SET revoked_at = UTC_TIMESTAMP(3) WHERE user_id = ? AND revoked_at IS NULL',
            [$userId]
        );
    }
}
