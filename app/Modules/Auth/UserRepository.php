<?php

declare(strict_types=1);

namespace EduCloud\Modules\Auth;

use EduCloud\Core\Db;
use EduCloud\Core\Ulid;

final class UserRepository
{
    private const COLUMNS = 'id, public_id, email, password_hash, display_name, locale, status, is_platform_admin,
        email_verified_at, failed_login_count, locked_until, last_login_at';

    public function __construct(private readonly Db $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return $this->db->selectOne('SELECT ' . self::COLUMNS . ' FROM users WHERE email = ?', [$email]);
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->db->selectOne('SELECT ' . self::COLUMNS . ' FROM users WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findByPublicId(string $publicId): ?array
    {
        return $this->db->selectOne('SELECT ' . self::COLUMNS . ' FROM users WHERE public_id = ?', [$publicId]);
    }

    public function create(string $email, string $passwordHash, string $displayName, string $locale): int
    {
        return $this->db->insert(
            'INSERT INTO users (public_id, email, password_hash, display_name, locale, status) VALUES (?, ?, ?, ?, ?, ?)',
            [Ulid::generate(), $email, $passwordHash, $displayName, $locale, 'pending']
        );
    }

    /** Replaces the credentials of a not-yet-verified account (a newer registration for the same address wins). */
    public function replacePendingCredentials(int $userId, string $passwordHash, string $displayName): void
    {
        $this->db->execute(
            "UPDATE users SET password_hash = ?, display_name = ?, failed_login_count = 0, locked_until = NULL
              WHERE id = ? AND status = 'pending'",
            [$passwordHash, $displayName, $userId]
        );
    }

    public function markEmailVerified(int $userId): void
    {
        $this->db->execute(
            "UPDATE users SET email_verified_at = COALESCE(email_verified_at, UTC_TIMESTAMP(3)),
                    status = IF(status = 'pending', 'active', status)
              WHERE id = ?",
            [$userId]
        );
    }

    public function updatePasswordHash(int $userId, string $hash): void
    {
        $this->db->execute('UPDATE users SET password_hash = ? WHERE id = ?', [$hash, $userId]);
    }

    public function recordSuccessfulLogin(int $userId): void
    {
        $this->db->execute(
            'UPDATE users SET failed_login_count = 0, locked_until = NULL, last_login_at = UTC_TIMESTAMP(3) WHERE id = ?',
            [$userId]
        );
    }

    /** Increments the failure counter and returns the new count. */
    public function recordFailedLogin(int $userId): int
    {
        $this->db->execute('UPDATE users SET failed_login_count = failed_login_count + 1 WHERE id = ?', [$userId]);
        return (int) $this->db->scalar('SELECT failed_login_count FROM users WHERE id = ?', [$userId]);
    }

    public function lockUntil(int $userId, int $seconds): void
    {
        $this->db->execute(
            'UPDATE users SET locked_until = UTC_TIMESTAMP(3) + INTERVAL ? SECOND WHERE id = ?',
            [$seconds, $userId]
        );
    }

    public function clearLockout(int $userId): void
    {
        $this->db->execute('UPDATE users SET failed_login_count = 0, locked_until = NULL WHERE id = ?', [$userId]);
    }

    public function isLocked(int $userId): bool
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM users WHERE id = ? AND locked_until IS NOT NULL AND locked_until > UTC_TIMESTAMP(3)',
            [$userId]
        ) > 0;
    }
}
