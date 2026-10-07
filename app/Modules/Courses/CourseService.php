<?php

declare(strict_types=1);

namespace EduCloud\Modules\Courses;

use EduCloud\Core\App;
use EduCloud\Core\Auth\Policy;
use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Exceptions\ApiException;
use EduCloud\Core\Exceptions\ConflictException;
use EduCloud\Core\Exceptions\ForbiddenException;
use EduCloud\Core\Exceptions\NotFoundException;
use EduCloud\Core\Exceptions\ValidationException;
use EduCloud\Core\Format;
use EduCloud\Core\Request;
use EduCloud\Http\Middleware\Authorize;
use EduCloud\Modules\Labs\LabRepository;
use EduCloud\Modules\Tenants\TenantRepository;

/**
 * Minimal course management (M10a): courses in organization tenants, join codes, lab assignments and the
 * instructor progress grid. Course staff = the owner and instructors enrolled as 'instructor' (plus tenant-wide
 * roles); only staff with the 'assign' permission manage a course and only staff with 'review' see progress.
 */
final class CourseService
{
    private CourseRepository $courses;
    private Policy $policy;

    public function __construct(private readonly App $app)
    {
        $this->courses = new CourseRepository($app->db());
        $this->policy = new Policy($app->config);
    }

    /** @return list<array<string, mixed>> */
    public function list(TenantContext $ctx): array
    {
        $rows = $this->courses->listVisible($ctx, $this->policy->seesWholeTenant($ctx));
        return array_map(fn (array $row): array => $this->present($ctx, $row), $rows);
    }

    /** @return array<string, mixed> */
    public function findOrFail(TenantContext $ctx, string $publicId): array
    {
        $row = $this->courses->findVisible($ctx, $publicId, $this->policy->seesWholeTenant($ctx));
        if ($row === null) {
            throw new NotFoundException('El curso no existe.');
        }
        return $row;
    }

    /** @return array<string, mixed> course with its labs */
    public function get(TenantContext $ctx, string $publicId): array
    {
        $row = $this->findOrFail($ctx, $publicId);
        return $this->present($ctx, $row) + ['labs' => $this->presentLabs($ctx, (int) $row['id'])];
    }

    /**
     * @param callable(): array{code: string, title: string, description: string|null} $validInput
     * @return array<string, mixed>
     */
    public function create(Request $request, TenantContext $ctx, callable $validInput): array
    {
        if ($ctx->tenantType !== 'organization') {
            throw new ApiException(409, 'ORGANIZATION_REQUIRED', 'Los cursos se crean dentro de una organización. Cambia a tu organización.');
        }
        $input = $validInput();
        $created = $this->app->db()->transaction(function () use ($ctx, $input): array {
            $this->app->db()->select('SELECT id FROM tenants WHERE id = ? FOR UPDATE', [$ctx->tenantId]);
            if ($this->courses->codeTaken($ctx, $input['code'])) {
                throw new ConflictException('Ya existe un curso con ese código en la organización.');
            }
            return $this->courses->create($ctx, $input['code'], $input['title'], $input['description']);
        });
        $this->app->audit()->record($request, 'course.create', 'success', $ctx->tenantId, $ctx->userId, 'course', $created['public_id']);
        return $this->get($ctx, $created['public_id']);
    }

    /**
     * @param callable(array<string, mixed>): array{title?: string, description?: string|null, status?: string, visibility?: string} $validInput
     * @return array<string, mixed>
     */
    public function update(Request $request, TenantContext $ctx, string $publicId, callable $validInput): array
    {
        $row = $this->findManaged($request, $ctx, $publicId, 'update');
        $changes = $validInput($row);
        $values = [
            'title' => $changes['title'] ?? (string) $row['title'],
            'description' => array_key_exists('description', $changes) ? $changes['description'] : $row['description'],
            'status' => $changes['status'] ?? (string) $row['status'],
            'visibility' => $changes['visibility'] ?? (string) $row['visibility'],
        ];
        $allowed = ['draft' => ['draft', 'published'], 'published' => ['published', 'archived'], 'archived' => ['archived', 'published']];
        if (!in_array($values['status'], $allowed[(string) $row['status']] ?? [], true)) {
            throw new ApiException(409, 'INVALID_STATE', 'Ese cambio de estado no está permitido.');
        }
        $this->courses->update($ctx, (int) $row['id'], $values);
        $this->app->audit()->record($request, 'course.update', 'success', $ctx->tenantId, $ctx->userId, 'course', $publicId, [
            'fields' => array_keys($changes),
        ]);
        if ($row['status'] !== 'published' && $values['status'] === 'published') {
            // Lessons that were already published become visible with the course (M10b).
            (new ContentService($this->app))->notifyVisible(['status' => 'published', 'title' => $values['title']] + $row);
        }
        return $this->get($ctx, $publicId);
    }

    /** Generates (rotates) the join code. The plain code is returned once and only its hash is stored. */
    public function rotateJoinCode(Request $request, TenantContext $ctx, string $publicId): string
    {
        $row = $this->findManaged($request, $ctx, $publicId, 'join_code');
        if ($row['status'] !== 'published') {
            throw new ApiException(409, 'INVALID_STATE', 'Publica el curso antes de generar un código de acceso.');
        }
        $code = JoinCode::generate();
        $this->courses->setJoinCode($ctx, (int) $row['id'], JoinCode::hash((string) JoinCode::normalise($code)));
        $this->app->audit()->record($request, 'course.join_code', 'success', $ctx->tenantId, $ctx->userId, 'course', $publicId);
        return $code;
    }

    public function disableJoinCode(Request $request, TenantContext $ctx, string $publicId): void
    {
        $row = $this->findManaged($request, $ctx, $publicId, 'join_code');
        $this->courses->setJoinCode($ctx, (int) $row['id'], null);
        $this->app->audit()->record($request, 'course.join_code_disabled', 'success', $ctx->tenantId, $ctx->userId, 'course', $publicId);
    }

    /**
     * @param callable(): array{lab_code: string, required: bool, due_at: string|null} $validInput
     * @return array<string, mixed>
     */
    public function assignLab(Request $request, TenantContext $ctx, string $publicId, callable $validInput): array
    {
        $row = $this->findManaged($request, $ctx, $publicId, 'assign_lab');
        $input = $validInput();
        $lab = (new LabRepository($this->app->db()))->findPublishedByCode($input['lab_code']);
        if ($lab === null) {
            throw new ValidationException([['field' => 'lab_code', 'code' => 'in', 'message' => 'Ese laboratorio no existe en el catálogo.']]);
        }
        $this->app->db()->transaction(function () use ($ctx, $row, $lab, $input): void {
            $this->app->db()->select('SELECT id FROM courses WHERE tenant_id = ? AND id = ? FOR UPDATE', [$ctx->tenantId, (int) $row['id']]);
            if ($this->courses->isAssigned($ctx, (int) $row['id'], $input['lab_code'])) {
                throw new ConflictException('Ese laboratorio ya está asignado al curso.');
            }
            $this->courses->assignLab($ctx, (int) $row['id'], (int) $lab['id'], $input['required'], $input['due_at']);
        });
        $this->app->audit()->record($request, 'course.assign_lab', 'success', $ctx->tenantId, $ctx->userId, 'course', $publicId, [
            'lab' => $input['lab_code'],
        ]);
        return $this->get($ctx, $publicId);
    }

    public function unassignLab(Request $request, TenantContext $ctx, string $publicId, string $labCode): void
    {
        $row = $this->findManaged($request, $ctx, $publicId, 'unassign_lab');
        if (preg_match('/^LAB-[0-9]{3}$/D', $labCode) !== 1 || !$this->courses->unassignLab($ctx, (int) $row['id'], $labCode)) {
            throw new NotFoundException('Ese laboratorio no está asignado al curso.');
        }
        $this->app->audit()->record($request, 'course.unassign_lab', 'success', $ctx->tenantId, $ctx->userId, 'course', $publicId, [
            'lab' => $labCode,
        ]);
    }

    /**
     * Joins a course with its code, from any tenant: creates the student membership in the course's organization
     * (an existing role is kept) and the enrollment. Rate-limited per user and per IP (brute force of codes).
     *
     * @param callable(): string $validCode
     * @return array<string, mixed>
     */
    public function join(Request $request, int $userId, callable $validCode): array
    {
        $this->app->rateLimiter()->hit('course_join_user', 'user|' . $userId);
        $normalised = JoinCode::normalise($validCode());
        $course = $normalised === null ? null : $this->courses->findJoinable(JoinCode::hash($normalised));
        if ($course === null) {
            $this->app->audit()->record($request, 'course.join', 'failure', null, $userId, 'course', null);
            throw new ValidationException([
                ['field' => 'code', 'code' => 'invalid', 'message' => 'El código no es válido o el curso no admite inscripciones.'],
            ]);
        }
        $tenantId = (int) $course['tenant_id'];
        $tenants = new TenantRepository($this->app->db());
        $already = $this->app->db()->transaction(function () use ($tenants, $tenantId, $course, $userId, $request): bool {
            $membership = $tenants->membershipOf($tenantId, $userId);
            $enrollment = $this->courses->enrollment($tenantId, (int) $course['id'], $userId);
            // Suspended members and dropped students get the same answer as a wrong code (no validity oracle,
            // and a removed student cannot re-enroll with a code they still know).
            if (($membership !== null && $membership['status'] !== 'active') || ($enrollment !== null && $enrollment['status'] === 'dropped')) {
                $this->app->audit()->record($request, 'course.join', 'denied', $tenantId, $userId, 'course', (string) $course['public_id']);
                throw new ValidationException([
                    ['field' => 'code', 'code' => 'invalid', 'message' => 'El código no es válido o el curso no admite inscripciones.'],
                ]);
            }
            if ((int) $course['owner_user_id'] === $userId || ($enrollment !== null && $enrollment['role'] === 'instructor')) {
                throw new ConflictException('Ya eres docente de este curso.');
            }
            $tenants->addMemberIfMissing($tenantId, $userId, 'student');
            $this->courses->enrollStudent($tenantId, (int) $course['id'], $userId);
            return $enrollment !== null && $enrollment['status'] === 'active';
        });
        $this->app->audit()->record($request, 'course.join', 'success', $tenantId, $userId, 'course', (string) $course['public_id']);
        return [
            'course' => ['id' => (string) $course['public_id'], 'title' => (string) $course['title']],
            'tenant' => ['id' => (string) $course['tenant_public_id'], 'name' => (string) $course['tenant_name']],
            'already_enrolled' => $already,
        ];
    }

    /**
     * Instructor progress grid: students × assigned labs, best attempt per cell (course attempts only).
     *
     * @return array<string, mixed>
     */
    public function progress(Request $request, TenantContext $ctx, string $publicId): array
    {
        $row = $this->findOrFail($ctx, $publicId);
        if (!$this->isStaff($ctx, $row) || !Authorize::allows($this->app->config, $ctx, 'review')) {
            $this->app->audit()->record($request, 'course.progress', 'denied', $ctx->tenantId, $ctx->userId, 'course', $publicId);
            throw new ForbiddenException();
        }
        $courseId = (int) $row['id'];
        $labs = $this->courses->labs($ctx, $courseId);
        $byStudent = [];
        foreach ($this->courses->courseAttempts($ctx, $courseId) as $a) {
            $key = $a['user_id'] . '|' . $a['code'];
            $current = $byStudent[$key] ?? null;
            // Best attempt wins; ties go to the most recent one.
            if ($current === null || (float) $a['best_score'] >= (float) $current['best_score']) {
                $byStudent[$key] = $a;
            }
        }
        $content = new ContentService($this->app);
        $completedLessons = $content->completedByStudent($ctx, $courseId);
        $students = [];
        foreach ($this->courses->students($ctx, $courseId) as $s) {
            $cells = [];
            foreach ($labs as $lab) {
                $a = $byStudent[$s['id'] . '|' . $lab['code']] ?? null;
                $cells[(string) $lab['code']] = $a === null ? null : [
                    'attempt_id' => (string) $a['public_id'],
                    'status' => (string) $a['status'],
                    'best_score' => (float) $a['best_score'],
                    'max_score' => (float) $a['max_score'],
                    'submissions' => (int) $a['submissions'],
                    'failed_tasks' => (int) $a['submissions'] > 0 ? (int) $a['failed_tasks'] : null,
                    'completed_at' => Format::isoUtc($a['completed_at'] === null ? null : (string) $a['completed_at']),
                    'last_submitted_at' => Format::isoUtc($a['last_submitted_at'] === null ? null : (string) $a['last_submitted_at']),
                ];
            }
            $students[] = [
                'id' => (string) $s['public_id'],
                'display_name' => (string) $s['display_name'],
                'labs' => $cells,
                'lessons_completed' => $completedLessons[(int) $s['id']] ?? 0,
            ];
        }
        $summary = [];
        foreach ($labs as $lab) {
            $code = (string) $lab['code'];
            $started = array_filter(array_column($students, 'labs'), static fn (array $c): bool => $c[$code] !== null);
            $completed = array_filter($started, static fn (array $c): bool => $c[$code]['status'] === 'completed');
            $summary[$code] = [
                'started' => count($started),
                'completed' => count($completed),
                'average_best_score' => $started === [] ? null
                    : round(array_sum(array_map(static fn (array $c): float => $c[$code]['best_score'], $started)) / count($started), 1),
            ];
        }
        return [
            'course' => $this->present($ctx, $row),
            'labs' => $this->presentLabs($ctx, $courseId),
            'students' => $students,
            'summary' => $summary,
            'lessons_total' => $content->visibleLessonCount($ctx, $courseId),
        ];
    }

    /**
     * The single course a lab attempt should belong to, for a student starting $labCode (null when none).
     * With an explicit course id the student must be actively enrolled and the lab assigned.
     */
    public function courseForAttempt(TenantContext $ctx, string $labCode, ?string $coursePublicId): ?int
    {
        if ($coursePublicId !== null) {
            $row = $this->findOrFail($ctx, $coursePublicId);
            if ($row['my_enrollment_role'] !== 'student' || $row['my_enrollment_status'] !== 'active' || $row['status'] !== 'published') {
                throw new ApiException(409, 'NOT_ENROLLED', 'Solo los estudiantes inscritos pueden hacer los laboratorios de este curso.');
            }
            if (!$this->courses->isAssigned($ctx, (int) $row['id'], $labCode)) {
                throw new ValidationException([['field' => 'lab_code', 'code' => 'in', 'message' => 'Ese laboratorio no está asignado al curso.']]);
            }
            return (int) $row['id'];
        }
        $candidates = $this->courses->studentCoursesWithLab($ctx, $labCode);
        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /** @param array<string, mixed> $row */
    public function isStaff(TenantContext $ctx, array $row): bool
    {
        return $this->policy->seesWholeTenant($ctx)
            || (int) $row['owner_user_id'] === $ctx->userId
            || ($row['my_enrollment_role'] === 'instructor' && $row['my_enrollment_status'] === 'active');
    }

    /** @return array<string, mixed> visible course the caller may manage (403 for visible non-staff) */
    public function findManaged(Request $request, TenantContext $ctx, string $publicId, string $action): array
    {
        $row = $this->findOrFail($ctx, $publicId);
        if (!$this->isStaff($ctx, $row) || !Authorize::allows($this->app->config, $ctx, 'assign')) {
            $this->app->audit()->record($request, 'course.' . $action, 'denied', $ctx->tenantId, $ctx->userId, 'course', $publicId);
            throw new ForbiddenException();
        }
        return $row;
    }

    /** @return list<array<string, mixed>> */
    private function presentLabs(TenantContext $ctx, int $courseId): array
    {
        return array_map(static fn (array $l): array => [
            'code' => (string) $l['code'],
            'title' => (string) $l['title'],
            'difficulty' => (string) $l['difficulty'],
            'estimated_minutes' => (int) $l['estimated_minutes'],
            'max_score' => (float) $l['max_score'],
            'required' => (bool) $l['is_required'],
            'due_at' => Format::isoUtc($l['due_at'] === null ? null : (string) $l['due_at']),
        ], $this->courses->labs($ctx, $courseId));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function present(TenantContext $ctx, array $row): array
    {
        $staff = $this->isStaff($ctx, $row);
        return [
            'id' => (string) $row['public_id'],
            'code' => (string) $row['code'],
            'title' => (string) $row['title'],
            'description' => $row['description'] === null ? null : (string) $row['description'],
            'status' => (string) $row['status'],
            'visibility' => (string) $row['visibility'],
            'public_url' => $row['visibility'] === 'public' && $row['status'] === 'published' ? url('/courses/' . $row['slug']) : null,
            'owner' => ['id' => (string) $row['owner_public_id'], 'display_name' => (string) $row['owner_name']],
            'my_role' => $staff ? 'staff' : 'student',
            'can_manage' => $staff && Authorize::allows($this->app->config, $ctx, 'assign'),
            'student_count' => $staff ? (int) $row['student_count'] : null,
            'lab_count' => (int) $row['lab_count'],
            'join_enabled' => $staff ? (bool) $row['join_enabled'] : null,
            'created_at' => Format::isoUtc((string) $row['created_at']),
            'updated_at' => Format::isoUtc((string) $row['updated_at']),
        ];
    }
}
