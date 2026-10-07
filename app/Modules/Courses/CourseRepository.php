<?php

declare(strict_types=1);

namespace EduCloud\Modules\Courses;

use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Db;
use EduCloud\Core\Ulid;

/**
 * Courses, enrollments and lab assignments (tenant-owned). Visibility inside the active tenant:
 *  - tenant-wide roles (org_admin, platform admin): every course;
 *  - staff: the course owner and instructors enrolled as 'instructor';
 *  - students: courses they are actively enrolled in (published or archived).
 */
final class CourseRepository
{
    private const SELECT = "SELECT c.*, u.public_id AS owner_public_id, u.display_name AS owner_name,
               (SELECT COUNT(*) FROM enrollments e WHERE e.tenant_id = c.tenant_id AND e.course_id = c.id
                  AND e.role = 'student' AND e.status = 'active') AS student_count,
               (SELECT COUNT(*) FROM course_labs cl WHERE cl.tenant_id = c.tenant_id AND cl.course_id = c.id) AS lab_count,
               me.role AS my_enrollment_role, me.status AS my_enrollment_status
          FROM courses c
          JOIN users u ON u.id = c.owner_user_id
          LEFT JOIN enrollments me ON me.tenant_id = c.tenant_id AND me.course_id = c.id AND me.user_id = ?";

    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function listVisible(TenantContext $ctx, bool $wholeTenant): array
    {
        [$where, $params] = self::visibility($ctx, $wholeTenant);
        return $this->db->select(self::SELECT . " WHERE $where ORDER BY c.status = 'archived', c.created_at DESC, c.id DESC", $params);
    }

    /** @return array<string, mixed>|null */
    public function findVisible(TenantContext $ctx, string $publicId, bool $wholeTenant): ?array
    {
        [$where, $params] = self::visibility($ctx, $wholeTenant);
        return $this->db->selectOne(self::SELECT . " WHERE $where AND c.public_id = ?", [...$params, $publicId]);
    }

    /** @return array{0: string, 1: list<mixed>} */
    private static function visibility(TenantContext $ctx, bool $wholeTenant): array
    {
        $where = 'c.tenant_id = ?';
        $params = [$ctx->userId, $ctx->tenantId];
        if (!$wholeTenant) {
            $where .= " AND (c.owner_user_id = ?
                         OR (me.role = 'instructor' AND me.status = 'active')
                         OR (me.role = 'student' AND me.status = 'active' AND c.status <> 'draft'))";
            $params[] = $ctx->userId;
        }
        return [$where, $params];
    }

    public function codeTaken(TenantContext $ctx, string $code, int $exceptId = 0): bool
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM courses WHERE tenant_id = ? AND code = ? AND id <> ?',
            [$ctx->tenantId, $code, $exceptId]
        ) > 0;
    }

    /** @return array{id: int, public_id: string} */
    public function create(TenantContext $ctx, string $code, string $title, ?string $description): array
    {
        $publicId = Ulid::generate();
        $slug = self::slug($title) . '-' . strtolower(substr($publicId, -6));
        $id = $this->db->insert(
            'INSERT INTO courses (public_id, tenant_id, owner_user_id, code, title, slug, description) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$publicId, $ctx->tenantId, $ctx->userId, $code, $title, $slug, $description]
        );
        return ['id' => $id, 'public_id' => $publicId];
    }

    /** @param array{title: string, description: string|null, status: string, visibility: string} $values */
    public function update(TenantContext $ctx, int $id, array $values): void
    {
        $this->db->execute(
            'UPDATE courses SET title = ?, description = ?, status = ?, visibility = ?, join_enabled = IF(? = \'published\', join_enabled, 0)
              WHERE tenant_id = ? AND id = ?',
            [$values['title'], $values['description'], $values['status'], $values['visibility'], $values['status'], $ctx->tenantId, $id]
        );
    }

    public function setJoinCode(TenantContext $ctx, int $id, ?string $hash): void
    {
        $this->db->execute(
            'UPDATE courses SET join_code_hash = ?, join_enabled = ? WHERE tenant_id = ? AND id = ?',
            [$hash, $hash === null ? 0 : 1, $ctx->tenantId, $id]
        );
    }

    /**
     * Global lookup by join code (the joining user may belong to another tenant). Only published, joinable courses.
     *
     * @return array<string, mixed>|null
     */
    public function findJoinable(string $hash): ?array
    {
        return $this->db->selectOne(
            "SELECT c.id, c.public_id, c.tenant_id, c.title, c.owner_user_id, t.public_id AS tenant_public_id, t.name AS tenant_name
               FROM courses c JOIN tenants t ON t.id = c.tenant_id
              WHERE c.join_code_hash = ? AND c.join_enabled = 1 AND c.status = 'published' AND t.status = 'active'",
            [$hash]
        );
    }

    /** @return array<string, mixed>|null */
    public function enrollment(int $tenantId, int $courseId, int $userId): ?array
    {
        return $this->db->selectOne(
            'SELECT id, role, status FROM enrollments WHERE tenant_id = ? AND course_id = ? AND user_id = ?',
            [$tenantId, $courseId, $userId]
        );
    }

    /** Enrolls a student (a 'completed' enrollment becomes active again; 'dropped' never does). Trusted caller: code verified. */
    public function enrollStudent(int $tenantId, int $courseId, int $userId): void
    {
        $this->db->execute(
            "INSERT INTO enrollments (tenant_id, course_id, user_id, role, status) VALUES (?, ?, ?, 'student', 'active')
             ON DUPLICATE KEY UPDATE status = IF(role = 'student' AND status = 'completed', 'active', status)",
            [$tenantId, $courseId, $userId]
        );
    }

    // -------------------------------------------------------------------------------------------- lab assignments

    /** @return list<array<string, mixed>> assigned labs in order, with the current published version's data */
    public function labs(TenantContext $ctx, int $courseId): array
    {
        return $this->db->select(
            "SELECT cl.id, cl.position, cl.is_required, cl.due_at, l.code, cur.title, cur.max_score, cur.difficulty, cur.estimated_minutes
               FROM course_labs cl
               JOIN labs l ON l.id = cl.lab_id
               JOIN labs cur ON cur.code = l.code AND cur.is_current = 1
              WHERE cl.tenant_id = ? AND cl.course_id = ?
              ORDER BY cl.position, cl.id",
            [$ctx->tenantId, $courseId]
        );
    }

    public function isAssigned(TenantContext $ctx, int $courseId, string $labCode): bool
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM course_labs cl JOIN labs l ON l.id = cl.lab_id WHERE cl.tenant_id = ? AND cl.course_id = ? AND l.code = ?',
            [$ctx->tenantId, $courseId, $labCode]
        ) > 0;
    }

    public function assignLab(TenantContext $ctx, int $courseId, int $labId, bool $required, ?string $dueAt): void
    {
        $position = 1 + (int) $this->db->scalar(
            'SELECT COALESCE(MAX(position), 0) FROM course_labs WHERE tenant_id = ? AND course_id = ?',
            [$ctx->tenantId, $courseId]
        );
        $this->db->insert(
            'INSERT INTO course_labs (tenant_id, course_id, lab_id, position, is_required, due_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$ctx->tenantId, $courseId, $labId, $position, $required ? 1 : 0, $dueAt]
        );
    }

    public function unassignLab(TenantContext $ctx, int $courseId, string $labCode): bool
    {
        // Lessons may only link labs assigned to the course (M10b): unlink it from them first.
        $this->db->execute(
            'UPDATE course_lessons l JOIN labs lab ON lab.id = l.lab_id SET l.lab_id = NULL
              WHERE l.tenant_id = ? AND l.course_id = ? AND lab.code = ?',
            [$ctx->tenantId, $courseId, $labCode]
        );
        return $this->db->execute(
            'DELETE cl FROM course_labs cl JOIN labs l ON l.id = cl.lab_id WHERE cl.tenant_id = ? AND cl.course_id = ? AND l.code = ?',
            [$ctx->tenantId, $courseId, $labCode]
        ) > 0;
    }

    /**
     * Courses (in the active tenant) where the user is an active student and the lab is assigned.
     *
     * @return list<int>
     */
    public function studentCoursesWithLab(TenantContext $ctx, string $labCode): array
    {
        return array_map(static fn (array $r): int => (int) $r['id'], $this->db->select(
            "SELECT DISTINCT c.id FROM courses c
               JOIN enrollments e ON e.tenant_id = c.tenant_id AND e.course_id = c.id
               JOIN course_labs cl ON cl.tenant_id = c.tenant_id AND cl.course_id = c.id
               JOIN labs l ON l.id = cl.lab_id
              WHERE c.tenant_id = ? AND e.user_id = ? AND e.role = 'student' AND e.status = 'active'
                AND c.status = 'published' AND l.code = ?",
            [$ctx->tenantId, $ctx->userId, $labCode]
        ));
    }

    // -------------------------------------------------------------------------------------------- progress

    /** @return list<array<string, mixed>> active students of the course */
    public function students(TenantContext $ctx, int $courseId): array
    {
        return $this->db->select(
            "SELECT u.id, u.public_id, u.display_name, e.enrolled_at
               FROM enrollments e JOIN users u ON u.id = e.user_id
              WHERE e.tenant_id = ? AND e.course_id = ? AND e.role = 'student' AND e.status = 'active'
              ORDER BY u.display_name, u.id",
            [$ctx->tenantId, $courseId]
        );
    }

    /**
     * Course attempts with their latest graded results (failed task count).
     *
     * @return list<array<string, mixed>>
     */
    public function courseAttempts(TenantContext $ctx, int $courseId): array
    {
        return $this->db->select(
            "SELECT a.public_id, a.user_id, a.status, a.score, a.best_score, a.max_score, a.submissions, a.completed_at,
                    a.last_submitted_at, l.code,
                    (SELECT COUNT(*) FROM lab_task_results r WHERE r.tenant_id = a.tenant_id AND r.attempt_id = a.id
                        AND r.submission_no = (SELECT MAX(r2.submission_no) FROM lab_task_results r2
                                                WHERE r2.tenant_id = a.tenant_id AND r2.attempt_id = a.id)
                        AND r.passed = 0) AS failed_tasks
               FROM lab_attempts a JOIN labs l ON l.id = a.lab_id
              WHERE a.tenant_id = ? AND a.course_id = ?
              ORDER BY a.id",
            [$ctx->tenantId, $courseId]
        );
    }

    private static function slug(string $title): string
    {
        $ascii = (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-');
        return substr($slug === '' ? 'curso' : $slug, 0, 80);
    }
}
