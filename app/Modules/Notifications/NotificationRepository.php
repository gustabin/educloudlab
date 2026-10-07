<?php

declare(strict_types=1);

namespace EduCloud\Modules\Notifications;

use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Db;
use EduCloud\Core\Ulid;

/**
 * In-app notifications (M10b). One row per user and event: (user_id, kind, ref_key) is unique, so re-triggering an
 * event never duplicates it. Reads are scoped to the caller's user AND active tenant.
 */
final class NotificationRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Notifies every listed user once (INSERT IGNORE on the dedupe key). Returns the number of new rows.
     *
     * @param list<int> $userIds
     */
    public function notify(int $tenantId, array $userIds, string $kind, string $refKey, string $title, ?string $body, ?string $link): int
    {
        $created = 0;
        $title = mb_substr($title, 0, 200);
        $body = $body === null ? null : mb_substr($body, 0, 300);
        foreach (array_chunk(array_values(array_unique($userIds)), 200) as $chunk) {
            $params = [];
            foreach ($chunk as $userId) {
                array_push($params, Ulid::generate(), $tenantId, $userId, $kind, $refKey, $title, $body, $link);
            }
            $created += $this->db->execute(
                'INSERT IGNORE INTO notifications (public_id, tenant_id, user_id, kind, ref_key, title, body, link) VALUES '
                    . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?)')),
                $params
            );
        }
        return $created;
    }

    /** @return list<array<string, mixed>> newest first */
    public function list(TenantContext $ctx, bool $unreadOnly, int $limit): array
    {
        return $this->db->select(
            'SELECT public_id, kind, title, body, link, read_at, created_at FROM notifications
              WHERE tenant_id = ? AND user_id = ?' . ($unreadOnly ? ' AND read_at IS NULL' : '') . '
              ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min(50, $limit)),
            [$ctx->tenantId, $ctx->userId]
        );
    }

    public function unreadCount(TenantContext $ctx): int
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM notifications WHERE tenant_id = ? AND user_id = ? AND read_at IS NULL',
            [$ctx->tenantId, $ctx->userId]
        );
    }

    /** @return bool false when the notification is not the caller's (404) */
    public function markRead(TenantContext $ctx, string $publicId): bool
    {
        $exists = $this->db->scalar(
            'SELECT id FROM notifications WHERE tenant_id = ? AND user_id = ? AND public_id = ?',
            [$ctx->tenantId, $ctx->userId, $publicId]
        );
        if ($exists === null) {
            return false;
        }
        $this->db->execute(
            'UPDATE notifications SET read_at = COALESCE(read_at, UTC_TIMESTAMP(3)) WHERE tenant_id = ? AND id = ?',
            [$ctx->tenantId, (int) $exists]
        );
        return true;
    }

    public function markAllRead(TenantContext $ctx): int
    {
        return $this->db->execute(
            'UPDATE notifications SET read_at = UTC_TIMESTAMP(3) WHERE tenant_id = ? AND user_id = ? AND read_at IS NULL',
            [$ctx->tenantId, $ctx->userId]
        );
    }

    /** Read notifications after $readDays; any notification after $anyDays (unread ones of inactive users too). */
    public function purge(int $readDays, int $anyDays): int
    {
        return $this->db->execute(
            'DELETE FROM notifications WHERE (read_at IS NOT NULL AND read_at < UTC_TIMESTAMP(3) - INTERVAL ? DAY)
                OR created_at < UTC_TIMESTAMP(3) - INTERVAL ? DAY',
            [$readDays, $anyDays]
        );
    }

    /**
     * Visible lessons due within the window, with the active students that have not completed them.
     *
     * @return list<array<string, mixed>>
     */
    public function lessonsDueSoon(int $hours, int $limit): array
    {
        return $this->db->select(
            "SELECT l.tenant_id, l.public_id, l.title, l.due_at, e.user_id
               FROM course_lessons l
               JOIN course_modules m ON m.tenant_id = l.tenant_id AND m.id = l.module_id
               JOIN courses c ON c.tenant_id = l.tenant_id AND c.id = l.course_id
               JOIN enrollments e ON e.tenant_id = c.tenant_id AND e.course_id = c.id AND e.role = 'student' AND e.status = 'active'
              WHERE l.status = 'published' AND m.status = 'published' AND c.status = 'published'
                AND l.due_at > UTC_TIMESTAMP(3) AND l.due_at <= UTC_TIMESTAMP(3) + INTERVAL ? HOUR
                AND NOT EXISTS (SELECT 1 FROM lesson_progress p WHERE p.tenant_id = l.tenant_id AND p.lesson_id = l.id AND p.user_id = e.user_id)
                AND NOT EXISTS (SELECT 1 FROM notifications n WHERE n.user_id = e.user_id AND n.kind = 'lesson_due' AND n.ref_key = l.public_id)
              LIMIT " . max(1, $limit),
            [$hours]
        );
    }

    /**
     * Course labs due within the window, with the active students that have not completed them in that course.
     *
     * @return list<array<string, mixed>>
     */
    public function labsDueSoon(int $hours, int $limit): array
    {
        return $this->db->select(
            "SELECT cl.tenant_id, c.public_id AS course_public_id, c.title AS course_title, l.code, cur.title, cl.due_at, e.user_id,
                    CONCAT(c.public_id, ':', l.code) AS ref_key
               FROM course_labs cl
               JOIN courses c ON c.tenant_id = cl.tenant_id AND c.id = cl.course_id
               JOIN labs l ON l.id = cl.lab_id
               JOIN labs cur ON cur.code = l.code AND cur.is_current = 1
               JOIN enrollments e ON e.tenant_id = c.tenant_id AND e.course_id = c.id AND e.role = 'student' AND e.status = 'active'
              WHERE c.status = 'published'
                AND cl.due_at > UTC_TIMESTAMP(3) AND cl.due_at <= UTC_TIMESTAMP(3) + INTERVAL ? HOUR
                AND NOT EXISTS (SELECT 1 FROM lab_attempts a JOIN labs al ON al.id = a.lab_id
                                 WHERE a.tenant_id = c.tenant_id AND a.course_id = c.id AND a.user_id = e.user_id
                                   AND al.code = l.code AND a.status = 'completed')
                AND NOT EXISTS (SELECT 1 FROM notifications n WHERE n.user_id = e.user_id AND n.kind = 'lab_due'
                                 AND n.ref_key = CONCAT(c.public_id, ':', l.code))
              LIMIT " . max(1, $limit),
            [$hours]
        );
    }
}
