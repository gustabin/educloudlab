<?php

declare(strict_types=1);

namespace EduCloud\Modules\Courses;

use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Db;
use EduCloud\Core\Ulid;

/**
 * Course modules, lessons and lesson progress (M10b). Tenant-scoped; who may see what is decided by ContentService
 * on top of the course visibility (CourseService::findOrFail) — rows are only ever loaded inside the caller's tenant.
 */
final class ContentRepository
{
    private const LESSON = "SELECT l.*, m.public_id AS module_public_id, m.title AS module_title, m.status AS module_status,
               c.public_id AS course_public_id, lab.code AS lab_code, cur.title AS lab_title
          FROM course_lessons l
          JOIN course_modules m ON m.tenant_id = l.tenant_id AND m.id = l.module_id
          JOIN courses c ON c.tenant_id = l.tenant_id AND c.id = l.course_id
          LEFT JOIN labs lab ON lab.id = l.lab_id
          LEFT JOIN labs cur ON cur.code = lab.code AND cur.is_current = 1";

    /** Ordered tables and their parent column (identifier allowlist for move()). */
    private const ORDERED = ['course_modules' => 'course_id', 'course_lessons' => 'module_id'];

    public function __construct(private readonly Db $db)
    {
    }

    /** Serialises content edits of one course (positions, counts). */
    public function lockCourse(TenantContext $ctx, int $courseId): void
    {
        $this->db->select('SELECT id FROM courses WHERE tenant_id = ? AND id = ? FOR UPDATE', [$ctx->tenantId, $courseId]);
    }

    // ------------------------------------------------------------------------------------------------ modules

    /** @return list<array<string, mixed>> */
    public function modules(TenantContext $ctx, int $courseId): array
    {
        return $this->db->select(
            'SELECT * FROM course_modules WHERE tenant_id = ? AND course_id = ? ORDER BY position, id',
            [$ctx->tenantId, $courseId]
        );
    }

    /** @return array<string, mixed>|null module with its course public id (tenant-scoped) */
    public function findModule(TenantContext $ctx, string $publicId): ?array
    {
        return $this->db->selectOne(
            'SELECT m.*, c.public_id AS course_public_id FROM course_modules m JOIN courses c ON c.tenant_id = m.tenant_id AND c.id = m.course_id
              WHERE m.tenant_id = ? AND m.public_id = ?',
            [$ctx->tenantId, $publicId]
        );
    }

    public function countModules(TenantContext $ctx, int $courseId): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM course_modules WHERE tenant_id = ? AND course_id = ?', [$ctx->tenantId, $courseId]);
    }

    /** @return array{id: int, public_id: string} */
    public function createModule(TenantContext $ctx, int $courseId, string $title, ?string $summary): array
    {
        $publicId = Ulid::generate();
        $position = 1 + (int) $this->db->scalar(
            'SELECT COALESCE(MAX(position), 0) FROM course_modules WHERE tenant_id = ? AND course_id = ?',
            [$ctx->tenantId, $courseId]
        );
        $id = $this->db->insert(
            'INSERT INTO course_modules (public_id, tenant_id, course_id, title, summary, position) VALUES (?, ?, ?, ?, ?, ?)',
            [$publicId, $ctx->tenantId, $courseId, $title, $summary, $position]
        );
        return ['id' => $id, 'public_id' => $publicId];
    }

    /** @param array{title: string, summary: string|null, status: string} $values */
    public function updateModule(TenantContext $ctx, int $id, array $values): void
    {
        $this->db->execute(
            'UPDATE course_modules SET title = ?, summary = ?, status = ? WHERE tenant_id = ? AND id = ?',
            [$values['title'], $values['summary'], $values['status'], $ctx->tenantId, $id]
        );
    }

    public function deleteModule(TenantContext $ctx, int $id): void
    {
        $this->db->execute('DELETE FROM course_modules WHERE tenant_id = ? AND id = ?', [$ctx->tenantId, $id]);
    }

    // ------------------------------------------------------------------------------------------------ lessons

    /** @return list<array<string, mixed>> lessons of a course ordered by module and position, with the caller's completion */
    public function courseLessons(TenantContext $ctx, int $courseId): array
    {
        return $this->db->select(
            "SELECT l.id, l.public_id, l.module_id, l.title, l.estimated_minutes, l.due_at, l.position, l.status, lab.code AS lab_code,
                    (SELECT p.completed_at FROM lesson_progress p WHERE p.tenant_id = l.tenant_id AND p.lesson_id = l.id AND p.user_id = ?)
                    AS my_completed_at
               FROM course_lessons l
               JOIN course_modules m ON m.tenant_id = l.tenant_id AND m.id = l.module_id
               LEFT JOIN labs lab ON lab.id = l.lab_id
              WHERE l.tenant_id = ? AND l.course_id = ?
              ORDER BY m.position, m.id, l.position, l.id",
            [$ctx->userId, $ctx->tenantId, $courseId]
        );
    }

    /** @return array<string, mixed>|null lesson with module/course data and the caller's completion (tenant-scoped) */
    public function findLesson(TenantContext $ctx, string $publicId): ?array
    {
        $row = $this->db->selectOne(self::LESSON . ' WHERE l.tenant_id = ? AND l.public_id = ?', [$ctx->tenantId, $publicId]);
        if ($row !== null) {
            $row['my_completed_at'] = $this->db->scalar(
                'SELECT completed_at FROM lesson_progress WHERE tenant_id = ? AND lesson_id = ? AND user_id = ?',
                [$ctx->tenantId, (int) $row['id'], $ctx->userId]
            );
        }
        return $row;
    }

    public function countLessons(TenantContext $ctx, int $moduleId): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM course_lessons WHERE tenant_id = ? AND module_id = ?', [$ctx->tenantId, $moduleId]);
    }

    /**
     * @param array{title: string, body_md: string, estimated_minutes: int|null, due_at: string|null, lab_id: int|null} $values
     * @return array{id: int, public_id: string}
     */
    public function createLesson(TenantContext $ctx, int $courseId, int $moduleId, array $values): array
    {
        $publicId = Ulid::generate();
        $position = 1 + (int) $this->db->scalar(
            'SELECT COALESCE(MAX(position), 0) FROM course_lessons WHERE tenant_id = ? AND module_id = ?',
            [$ctx->tenantId, $moduleId]
        );
        $id = $this->db->insert(
            'INSERT INTO course_lessons (public_id, tenant_id, course_id, module_id, title, body_md, estimated_minutes, due_at, lab_id, position)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $publicId, $ctx->tenantId, $courseId, $moduleId, $values['title'], $values['body_md'],
                $values['estimated_minutes'], $values['due_at'], $values['lab_id'], $position,
            ]
        );
        return ['id' => $id, 'public_id' => $publicId];
    }

    /** @param array{title: string, body_md: string, estimated_minutes: int|null, due_at: string|null, lab_id: int|null, status: string} $values */
    public function updateLesson(TenantContext $ctx, int $id, array $values): void
    {
        $this->db->execute(
            "UPDATE course_lessons SET title = ?, body_md = ?, estimated_minutes = ?, due_at = ?, lab_id = ?, status = ?,
                    published_at = CASE WHEN ? = 'published' THEN COALESCE(published_at, UTC_TIMESTAMP(3)) ELSE published_at END
              WHERE tenant_id = ? AND id = ?",
            [
                $values['title'], $values['body_md'], $values['estimated_minutes'], $values['due_at'], $values['lab_id'],
                $values['status'], $values['status'], $ctx->tenantId, $id,
            ]
        );
    }

    public function deleteLesson(TenantContext $ctx, int $id): void
    {
        $this->db->execute('DELETE FROM course_lessons WHERE tenant_id = ? AND id = ?', [$ctx->tenantId, $id]);
    }

    /**
     * Moves an item to a 1-based position among its siblings and renumbers them (call inside the course lock).
     * $table/$parentColumn must be a pair of self::ORDERED (identifiers are never taken from input).
     */
    public function move(TenantContext $ctx, string $table, string $parentColumn, int $parentId, int $id, int $position): void
    {
        if ((self::ORDERED[$table] ?? null) !== $parentColumn) {
            throw new \LogicException('Unsupported ordered table');
        }
        $ids = array_map(
            static fn (array $r): int => (int) $r['id'],
            $this->db->select("SELECT id FROM $table WHERE tenant_id = ? AND $parentColumn = ? ORDER BY position, id", [$ctx->tenantId, $parentId])
        );
        $ids = array_values(array_filter($ids, static fn (int $i): bool => $i !== $id));
        array_splice($ids, max(0, min(count($ids), $position - 1)), 0, [$id]);
        foreach ($ids as $i => $rowId) {
            $this->db->execute("UPDATE $table SET position = ? WHERE tenant_id = ? AND id = ?", [$i + 1, $ctx->tenantId, $rowId]);
        }
    }

    // ------------------------------------------------------------------------------------------------ progress

    public function complete(TenantContext $ctx, int $lessonId): void
    {
        $this->db->execute(
            'INSERT IGNORE INTO lesson_progress (tenant_id, lesson_id, user_id) VALUES (?, ?, ?)',
            [$ctx->tenantId, $lessonId, $ctx->userId]
        );
    }

    public function uncomplete(TenantContext $ctx, int $lessonId): void
    {
        $this->db->execute(
            'DELETE FROM lesson_progress WHERE tenant_id = ? AND lesson_id = ? AND user_id = ?',
            [$ctx->tenantId, $lessonId, $ctx->userId]
        );
    }

    /**
     * Completed published lessons per student of a course (progress grid).
     *
     * @return array<int, int> user id => completed visible lessons
     */
    public function completedByStudent(TenantContext $ctx, int $courseId): array
    {
        $rows = $this->db->select(
            "SELECT p.user_id, COUNT(*) AS n FROM lesson_progress p
               JOIN course_lessons l ON l.tenant_id = p.tenant_id AND l.id = p.lesson_id
               JOIN course_modules m ON m.tenant_id = l.tenant_id AND m.id = l.module_id
              WHERE p.tenant_id = ? AND l.course_id = ? AND l.status = 'published' AND m.status = 'published'
              GROUP BY p.user_id",
            [$ctx->tenantId, $courseId]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['user_id']] = (int) $r['n'];
        }
        return $out;
    }

    /**
     * Lessons visible to students (published, in a published module) of a course, optionally narrowed.
     *
     * @return list<array<string, mixed>>
     */
    public function visibleLessons(int $tenantId, int $courseId, ?int $moduleId = null, ?int $lessonId = null): array
    {
        $sql = "SELECT l.id, l.public_id, l.title FROM course_lessons l
                  JOIN course_modules m ON m.tenant_id = l.tenant_id AND m.id = l.module_id
                 WHERE l.tenant_id = ? AND l.course_id = ? AND l.status = 'published' AND m.status = 'published'";
        $params = [$tenantId, $courseId];
        if ($moduleId !== null) {
            $sql .= ' AND l.module_id = ?';
            $params[] = $moduleId;
        }
        if ($lessonId !== null) {
            $sql .= ' AND l.id = ?';
            $params[] = $lessonId;
        }
        return $this->db->select($sql, $params);
    }

    /** @return list<int> active students of a course */
    public function studentIds(int $tenantId, int $courseId): array
    {
        return array_map(static fn (array $r): int => (int) $r['user_id'], $this->db->select(
            "SELECT user_id FROM enrollments WHERE tenant_id = ? AND course_id = ? AND role = 'student' AND status = 'active'",
            [$tenantId, $courseId]
        ));
    }
}
