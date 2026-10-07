<?php

declare(strict_types=1);

namespace EduCloud\Modules\PublicSite;

use EduCloud\Core\Db;

/**
 * Data for public (anonymous, indexable) pages: published labs and courses an organization made public.
 * Only catalog fields are selected - never checks, solutions, join codes, enrollments or student data.
 */
final class PublicRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function labs(): array
    {
        return $this->db->select(
            "SELECT code, slug, title, summary, difficulty, estimated_minutes, max_score, definition, updated_at
               FROM labs WHERE status = 'published' AND is_current = 1 ORDER BY code"
        );
    }

    /** @return array<string, mixed>|null */
    public function lab(string $slug): ?array
    {
        return $this->db->selectOne(
            "SELECT code, slug, title, summary, difficulty, estimated_minutes, max_score, definition, updated_at
               FROM labs WHERE slug = ? AND status = 'published' AND is_current = 1",
            [$slug]
        );
    }

    /** @return list<array<string, mixed>> */
    public function courses(): array
    {
        return $this->db->select(
            "SELECT c.slug, c.code, c.title, c.description, c.updated_at, t.name AS organization,
                    (SELECT COUNT(*) FROM course_labs cl WHERE cl.tenant_id = c.tenant_id AND cl.course_id = c.id) AS lab_count
               FROM courses c JOIN tenants t ON t.id = c.tenant_id
              WHERE c.visibility = 'public' AND c.status = 'published' AND t.status = 'active'
              ORDER BY c.title"
        );
    }

    /** @return array<string, mixed>|null */
    public function course(string $slug): ?array
    {
        return $this->db->selectOne(
            "SELECT c.id, c.tenant_id, c.slug, c.code, c.title, c.description, c.updated_at, t.name AS organization
               FROM courses c JOIN tenants t ON t.id = c.tenant_id
              WHERE c.slug = ? AND c.visibility = 'public' AND c.status = 'published' AND t.status = 'active'",
            [$slug]
        );
    }

    /** @return list<array<string, mixed>> */
    public function courseLabs(int $tenantId, int $courseId): array
    {
        return $this->db->select(
            "SELECT cur.slug, cur.code, cur.title, cur.difficulty, cur.estimated_minutes
               FROM course_labs cl JOIN labs l ON l.id = cl.lab_id
               JOIN labs cur ON cur.code = l.code AND cur.is_current = 1 AND cur.status = 'published'
              WHERE cl.tenant_id = ? AND cl.course_id = ? ORDER BY cl.position, cl.id",
            [$tenantId, $courseId]
        );
    }
}
