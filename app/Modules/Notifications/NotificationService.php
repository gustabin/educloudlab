<?php

declare(strict_types=1);

namespace EduCloud\Modules\Notifications;

use EduCloud\Core\App;
use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Exceptions\NotFoundException;
use EduCloud\Core\Format;

/**
 * In-app notifications (M10b): course events (lesson published, lesson/lab due soon). Links are app paths generated
 * here from public ids, never taken from input.
 */
final class NotificationService
{
    public const DUE_WINDOW_HOURS = 24;
    public const PURGE_READ_DAYS = 90;
    public const PURGE_ANY_DAYS = 180;

    private NotificationRepository $repo;

    public function __construct(private readonly App $app)
    {
        $this->repo = new NotificationRepository($app->db());
    }

    /** @return array{items: list<array<string, mixed>>, unread: int} */
    public function list(TenantContext $ctx, bool $unreadOnly): array
    {
        return [
            'items' => array_map([self::class, 'present'], $this->repo->list($ctx, $unreadOnly, 50)),
            'unread' => $this->repo->unreadCount($ctx),
        ];
    }

    public function markRead(TenantContext $ctx, string $publicId): void
    {
        if (!$this->repo->markRead($ctx, $publicId)) {
            throw new NotFoundException('La notificación no existe.');
        }
    }

    public function markAllRead(TenantContext $ctx): int
    {
        return $this->repo->markAllRead($ctx);
    }

    /**
     * A lesson became visible to the students of its course.
     *
     * @param list<int> $studentIds
     */
    public function lessonPublished(int $tenantId, array $studentIds, string $lessonPublicId, string $lessonTitle, string $courseTitle): int
    {
        return $this->repo->notify(
            $tenantId,
            $studentIds,
            'lesson_published',
            $lessonPublicId,
            'Nueva lección: ' . $lessonTitle,
            'Curso: ' . $courseTitle,
            '/app/lessons/' . $lessonPublicId
        );
    }

    /**
     * Scheduler: due-soon reminders (once per user and item) and purge of old read notifications.
     *
     * @return array{lesson_reminders: int, lab_reminders: int, purged: int}
     */
    public function maintenance(): array
    {
        $lessons = 0;
        foreach ($this->repo->lessonsDueSoon(self::DUE_WINDOW_HOURS, 1000) as $r) {
            $lessons += $this->repo->notify(
                (int) $r['tenant_id'],
                [(int) $r['user_id']],
                'lesson_due',
                (string) $r['public_id'],
                'Vence pronto: ' . $r['title'],
                'Fecha límite: ' . Format::isoUtc((string) $r['due_at']),
                '/app/lessons/' . $r['public_id']
            );
        }
        $labs = 0;
        foreach ($this->repo->labsDueSoon(self::DUE_WINDOW_HOURS, 1000) as $r) {
            $labs += $this->repo->notify(
                (int) $r['tenant_id'],
                [(int) $r['user_id']],
                'lab_due',
                (string) $r['ref_key'],
                'Laboratorio por entregar: ' . $r['title'],
                'Curso: ' . $r['course_title'] . ' · fecha límite: ' . Format::isoUtc((string) $r['due_at']),
                '/app/courses/' . $r['course_public_id']
            );
        }
        $purged = $this->repo->purge(self::PURGE_READ_DAYS, self::PURGE_ANY_DAYS);
        return ['lesson_reminders' => $lessons, 'lab_reminders' => $labs, 'purged' => $purged];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function present(array $row): array
    {
        return [
            'id' => (string) $row['public_id'],
            'kind' => (string) $row['kind'],
            'title' => (string) $row['title'],
            'body' => $row['body'] === null ? null : (string) $row['body'],
            'url' => $row['link'] === null ? null : url((string) $row['link']),
            'read' => $row['read_at'] !== null,
            'created_at' => Format::isoUtc((string) $row['created_at']),
        ];
    }
}
