<?php

declare(strict_types=1);

namespace EduCloud\Modules\Courses;

use EduCloud\Core\App;
use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Exceptions\ApiException;
use EduCloud\Core\Exceptions\NotFoundException;
use EduCloud\Core\Exceptions\QuotaExceededException;
use EduCloud\Core\Exceptions\ValidationException;
use EduCloud\Core\Format;
use EduCloud\Core\Markdown;
use EduCloud\Core\Request;
use EduCloud\Modules\Labs\LabRepository;
use EduCloud\Modules\Notifications\NotificationService;

/**
 * Course content (M10b): modules and lessons. Visibility builds on the course visibility (CourseService):
 * staff see everything; an enrolled student sees a lesson only when its module and the lesson are published
 * (and the course is not a draft, which CourseService already enforces). Anything else is 404.
 */
final class ContentService
{
    public const MAX_MODULES = 30;
    public const MAX_LESSONS = 50;

    private ContentRepository $repo;
    private CourseService $courses;

    public function __construct(private readonly App $app)
    {
        $this->repo = new ContentRepository($app->db());
        $this->courses = new CourseService($app);
    }

    /** @return list<array<string, mixed>> modules with their lessons, as the caller may see them */
    public function tree(TenantContext $ctx, string $coursePublicId): array
    {
        $course = $this->courses->findOrFail($ctx, $coursePublicId);
        $staff = $this->courses->isStaff($ctx, $course);
        $byModule = [];
        foreach ($this->repo->courseLessons($ctx, (int) $course['id']) as $l) {
            if ($staff || $l['status'] === 'published') {
                $byModule[(int) $l['module_id']][] = self::presentLessonSummary($l, $staff);
            }
        }
        $out = [];
        foreach ($this->repo->modules($ctx, (int) $course['id']) as $m) {
            if (!$staff && $m['status'] !== 'published') {
                continue;
            }
            $out[] = self::presentModule($m, $staff) + ['lessons' => $byModule[(int) $m['id']] ?? []];
        }
        return $out;
    }

    /**
     * @param array{title: string, summary: string|null} $input
     * @return array<string, mixed>
     */
    public function createModule(Request $request, TenantContext $ctx, string $coursePublicId, array $input): array
    {
        $course = $this->courses->findManaged($request, $ctx, $coursePublicId, 'content');
        $created = $this->app->db()->transaction(function () use ($ctx, $course, $input): array {
            $this->repo->lockCourse($ctx, (int) $course['id']);
            if ($this->repo->countModules($ctx, (int) $course['id']) >= self::MAX_MODULES) {
                throw new QuotaExceededException('El curso ya tiene el máximo de ' . self::MAX_MODULES . ' módulos.');
            }
            return $this->repo->createModule($ctx, (int) $course['id'], $input['title'], $input['summary']);
        });
        $this->audit($request, $ctx, 'course.module_create', 'course_module', $created['public_id']);
        return self::presentModule((array) $this->repo->findModule($ctx, $created['public_id']), true);
    }

    /**
     * @param array{title?: string, summary?: string|null, status?: string, position?: int} $input
     * @return array<string, mixed>
     */
    public function updateModule(Request $request, TenantContext $ctx, string $publicId, array $input): array
    {
        [$module, $course] = $this->managedModule($request, $ctx, $publicId);
        $this->app->db()->transaction(function () use ($ctx, $module, $input): void {
            $this->repo->lockCourse($ctx, (int) $module['course_id']);
            $this->repo->updateModule($ctx, (int) $module['id'], [
                'title' => $input['title'] ?? (string) $module['title'],
                'summary' => array_key_exists('summary', $input) ? $input['summary'] : $module['summary'],
                'status' => $input['status'] ?? (string) $module['status'],
            ]);
            if (isset($input['position'])) {
                $this->repo->move($ctx, 'course_modules', 'course_id', (int) $module['course_id'], (int) $module['id'], $input['position']);
            }
        });
        if (($input['status'] ?? null) === 'published' && $module['status'] !== 'published') {
            $this->notifyVisible($course, (int) $module['id']);
        }
        $this->audit($request, $ctx, 'course.module_update', 'course_module', $publicId, ['fields' => array_keys($input)]);
        return self::presentModule((array) $this->repo->findModule($ctx, $publicId), true);
    }

    public function deleteModule(Request $request, TenantContext $ctx, string $publicId): void
    {
        [$module] = $this->managedModule($request, $ctx, $publicId);
        $this->app->db()->transaction(function () use ($ctx, $module): void {
            $this->repo->lockCourse($ctx, (int) $module['course_id']);
            if ($this->repo->countLessons($ctx, (int) $module['id']) > 0) {
                throw new ApiException(409, 'MODULE_NOT_EMPTY', 'Elimina primero las lecciones del módulo.');
            }
            $this->repo->deleteModule($ctx, (int) $module['id']);
        });
        $this->audit($request, $ctx, 'course.module_delete', 'course_module', $publicId);
    }

    // ------------------------------------------------------------------------------------------------ lessons

    /** @return array<string, mixed> lesson with rendered content (and the Markdown source for staff) */
    public function lesson(TenantContext $ctx, string $publicId): array
    {
        [$lesson, $course, $staff] = $this->visibleLesson($ctx, $publicId);
        $published = [];
        foreach ($this->repo->modules($ctx, (int) $course['id']) as $m) {
            $published[(int) $m['id']] = $m['status'] === 'published';
        }
        $siblings = array_values(array_filter(
            $this->repo->courseLessons($ctx, (int) $course['id']),
            static fn (array $l): bool => $staff || ($l['status'] === 'published' && ($published[(int) $l['module_id']] ?? false))
        ));
        $ids = array_column($siblings, 'public_id');
        $index = (int) array_search($publicId, $ids, true);
        return self::presentLessonSummary($lesson, $staff) + [
            'course' => ['id' => (string) $course['public_id'], 'title' => (string) $course['title']],
            'module' => ['id' => (string) $lesson['module_public_id'], 'title' => (string) $lesson['module_title']],
            'lab' => $lesson['lab_code'] === null ? null : ['code' => (string) $lesson['lab_code'], 'title' => (string) $lesson['lab_title']],
            'html' => Markdown::toHtml((string) $lesson['body_md']),
            'body_md' => $staff ? (string) $lesson['body_md'] : null,
            'previous_id' => $index > 0 ? (string) $ids[$index - 1] : null,
            'next_id' => $index + 1 < count($ids) ? (string) $ids[$index + 1] : null,
            'can_manage' => $staff && $this->courses->present($ctx, $course)['can_manage'],
        ];
    }

    /**
     * @param array{title: string, body_md: string, estimated_minutes: int|null, due_at: string|null, lab_code: string|null} $input
     * @return array<string, mixed>
     */
    public function createLesson(Request $request, TenantContext $ctx, string $modulePublicId, array $input): array
    {
        [$module] = $this->managedModule($request, $ctx, $modulePublicId);
        $labId = $this->labId($ctx, (int) $module['course_id'], $input['lab_code']);
        $created = $this->app->db()->transaction(function () use ($ctx, $module, $input, $labId): array {
            $this->repo->lockCourse($ctx, (int) $module['course_id']);
            if ($this->repo->findModule($ctx, (string) $module['public_id']) === null) {
                throw new NotFoundException('El módulo no existe.');
            }
            if ($this->repo->countLessons($ctx, (int) $module['id']) >= self::MAX_LESSONS) {
                throw new QuotaExceededException('El módulo ya tiene el máximo de ' . self::MAX_LESSONS . ' lecciones.');
            }
            return $this->repo->createLesson($ctx, (int) $module['course_id'], (int) $module['id'], [
                'title' => $input['title'],
                'body_md' => $input['body_md'],
                'estimated_minutes' => $input['estimated_minutes'],
                'due_at' => $input['due_at'],
                'lab_id' => $labId,
            ]);
        });
        $this->audit($request, $ctx, 'course.lesson_create', 'course_lesson', $created['public_id']);
        return $this->lesson($ctx, $created['public_id']);
    }

    /**
     * @param array<string, mixed> $input title?, body_md?, estimated_minutes?, due_at?, lab_code?, status?, position?
     * @return array<string, mixed>
     */
    public function updateLesson(Request $request, TenantContext $ctx, string $publicId, array $input): array
    {
        [$lesson, $course] = $this->managedLesson($request, $ctx, $publicId);
        $labId = array_key_exists('lab_code', $input)
            ? $this->labId($ctx, (int) $course['id'], $input['lab_code'])
            : ($lesson['lab_id'] === null ? null : (int) $lesson['lab_id']);
        $this->app->db()->transaction(function () use ($ctx, $lesson, $input, $labId): void {
            $this->repo->lockCourse($ctx, (int) $lesson['course_id']);
            $this->repo->updateLesson($ctx, (int) $lesson['id'], [
                'title' => $input['title'] ?? (string) $lesson['title'],
                'body_md' => $input['body_md'] ?? (string) $lesson['body_md'],
                'estimated_minutes' => array_key_exists('estimated_minutes', $input)
                    ? $input['estimated_minutes'] : ($lesson['estimated_minutes'] === null ? null : (int) $lesson['estimated_minutes']),
                'due_at' => array_key_exists('due_at', $input) ? $input['due_at'] : $lesson['due_at'],
                'lab_id' => $labId,
                'status' => $input['status'] ?? (string) $lesson['status'],
            ]);
            if (isset($input['position'])) {
                $this->repo->move($ctx, 'course_lessons', 'module_id', (int) $lesson['module_id'], (int) $lesson['id'], (int) $input['position']);
            }
        });
        if (($input['status'] ?? null) === 'published' && $lesson['status'] !== 'published') {
            $this->notifyVisible($course, null, (int) $lesson['id']);
        }
        $this->audit($request, $ctx, 'course.lesson_update', 'course_lesson', $publicId, ['fields' => array_keys($input)]);
        return $this->lesson($ctx, $publicId);
    }

    public function deleteLesson(Request $request, TenantContext $ctx, string $publicId): void
    {
        [$lesson] = $this->managedLesson($request, $ctx, $publicId);
        $this->app->db()->transaction(function () use ($ctx, $lesson): void {
            $this->repo->lockCourse($ctx, (int) $lesson['course_id']);
            $this->repo->deleteLesson($ctx, (int) $lesson['id']);
        });
        $this->audit($request, $ctx, 'course.lesson_delete', 'course_lesson', $publicId);
    }

    /**
     * Marks (or unmarks) a visible lesson as completed by the caller (enrolled students only).
     *
     * @return array<string, mixed>
     */
    public function setCompleted(Request $request, TenantContext $ctx, string $publicId, bool $completed): array
    {
        [$lesson, $course, $staff] = $this->visibleLesson($ctx, $publicId);
        if ($staff || $course['my_enrollment_role'] !== 'student' || $course['my_enrollment_status'] !== 'active') {
            throw new ApiException(409, 'NOT_A_STUDENT', 'Solo los estudiantes del curso registran su progreso.');
        }
        if ($completed) {
            $this->repo->complete($ctx, (int) $lesson['id']);
        } else {
            $this->repo->uncomplete($ctx, (int) $lesson['id']);
        }
        $this->audit($request, $ctx, $completed ? 'course.lesson_complete' : 'course.lesson_uncomplete', 'course_lesson', $publicId);
        return $this->lesson($ctx, $publicId);
    }

    /**
     * Notifies the course students of lessons that are visible now (idempotent: one notification per user and lesson).
     *
     * @param array<string, mixed> $course
     */
    public function notifyVisible(array $course, ?int $moduleId = null, ?int $lessonId = null): int
    {
        if ($course['status'] !== 'published') {
            return 0;
        }
        $tenantId = (int) $course['tenant_id'];
        $students = $this->repo->studentIds($tenantId, (int) $course['id']);
        if ($students === []) {
            return 0;
        }
        $sent = 0;
        $notifications = new NotificationService($this->app);
        try {
            foreach ($this->repo->visibleLessons($tenantId, (int) $course['id'], $moduleId, $lessonId) as $l) {
                $title = (string) $course['title'];
                $sent += $notifications->lessonPublished($tenantId, $students, (string) $l['public_id'], (string) $l['title'], $title);
            }
        } catch (\Throwable $e) {
            // The publish itself already succeeded: notifications are best effort (deduplicated, so a retry is safe).
            $this->app->logger->error('notify_failed', ['course' => (string) $course['public_id'], 'error' => $e::class]);
        }
        return $sent;
    }

    /** @return array<int, int> user id => completed visible lessons (progress grid) */
    public function completedByStudent(TenantContext $ctx, int $courseId): array
    {
        return $this->repo->completedByStudent($ctx, $courseId);
    }

    public function visibleLessonCount(TenantContext $ctx, int $courseId): int
    {
        return count($this->repo->visibleLessons($ctx->tenantId, $courseId));
    }

    // ------------------------------------------------------------------------------------------------ helpers

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: bool} lesson, course, staff (404 when hidden) */
    private function visibleLesson(TenantContext $ctx, string $publicId): array
    {
        $lesson = $this->repo->findLesson($ctx, $publicId);
        if ($lesson === null) {
            throw new NotFoundException('La lección no existe.');
        }
        try {
            $course = $this->courses->findOrFail($ctx, (string) $lesson['course_public_id']);
        } catch (NotFoundException) {
            throw new NotFoundException('La lección no existe.');
        }
        $staff = $this->courses->isStaff($ctx, $course);
        if (!$staff && ($lesson['status'] !== 'published' || $lesson['module_status'] !== 'published')) {
            throw new NotFoundException('La lección no existe.');
        }
        return [$lesson, $course, $staff];
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} module and its course (manageable by the caller) */
    private function managedModule(Request $request, TenantContext $ctx, string $publicId): array
    {
        $module = $this->repo->findModule($ctx, $publicId);
        if ($module === null) {
            throw new NotFoundException('El módulo no existe.');
        }
        try {
            $course = $this->courses->findManaged($request, $ctx, (string) $module['course_public_id'], 'content');
        } catch (NotFoundException) {
            throw new NotFoundException('El módulo no existe.');
        }
        return [$module, $course];
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function managedLesson(Request $request, TenantContext $ctx, string $publicId): array
    {
        $lesson = $this->repo->findLesson($ctx, $publicId);
        if ($lesson === null) {
            throw new NotFoundException('La lección no existe.');
        }
        try {
            $course = $this->courses->findManaged($request, $ctx, (string) $lesson['course_public_id'], 'content');
        } catch (NotFoundException) {
            throw new NotFoundException('La lección no existe.');
        }
        return [$lesson, $course];
    }

    /** Lab linked to a lesson: must be assigned to the course (422 otherwise). */
    private function labId(TenantContext $ctx, int $courseId, ?string $labCode): ?int
    {
        if ($labCode === null) {
            return null;
        }
        $lab = (new LabRepository($this->app->db()))->findPublishedByCode($labCode);
        if ($lab === null || !(new CourseRepository($this->app->db()))->isAssigned($ctx, $courseId, $labCode)) {
            throw new ValidationException([['field' => 'lab_code', 'code' => 'in', 'message' => 'Enlaza un laboratorio asignado a este curso.']]);
        }
        return (int) $lab['id'];
    }

    /** @param array<string, mixed> $meta */
    private function audit(Request $request, TenantContext $ctx, string $action, string $type, string $publicId, array $meta = []): void
    {
        $this->app->audit()->record($request, $action, 'success', $ctx->tenantId, $ctx->userId, $type, $publicId, $meta);
    }

    /**
     * @param array<string, mixed> $m
     * @return array<string, mixed>
     */
    public static function presentModule(array $m, bool $staff): array
    {
        return [
            'id' => (string) $m['public_id'],
            'title' => (string) $m['title'],
            'summary' => $m['summary'] === null ? null : (string) $m['summary'],
            'position' => (int) $m['position'],
            'status' => $staff ? (string) $m['status'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $l
     * @return array<string, mixed>
     */
    public static function presentLessonSummary(array $l, bool $staff): array
    {
        return [
            'id' => (string) $l['public_id'],
            'title' => (string) $l['title'],
            'position' => (int) $l['position'],
            'estimated_minutes' => $l['estimated_minutes'] === null ? null : (int) $l['estimated_minutes'],
            'due_at' => Format::isoUtc($l['due_at'] === null ? null : (string) $l['due_at']),
            'lab_code' => $l['lab_code'] === null ? null : (string) $l['lab_code'],
            'status' => $staff ? (string) $l['status'] : null,
            'completed' => $l['my_completed_at'] !== null,
        ];
    }
}
