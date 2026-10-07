<?php

declare(strict_types=1);

namespace EduCloud\Modules\Courses;

use EduCloud\Core\App;
use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Request;
use EduCloud\Core\Response;
use EduCloud\Http\Middleware\Authorize;
use EduCloud\Modules\Labs\LabService;

final class CoursePageController
{
    public function __construct(private readonly App $app)
    {
    }

    /** /app/courses: my courses + join form (+ create form for staff in organizations). */
    public function index(Request $request): Response
    {
        $ctx = $this->ctx($request);
        return $this->app->renderPage($request, 'Courses::index', [
            'pageTitle' => t('courses.title') . ' · EduCloud Lab',
            'activeNav' => 'courses',
            'courses' => (new CourseService($this->app))->list($ctx),
            'canCreate' => $ctx->tenantType === 'organization' && Authorize::allows($this->app->config, $ctx, 'assign'),
            'isOrganization' => $ctx->tenantType === 'organization',
            'extraScripts' => ['js/features/courses.js'],
        ], 200, 'layouts/app');
    }

    /** /app/courses/{course_id}: assigned labs (students: start/continue; staff: manage). */
    public function show(Request $request): Response
    {
        $ctx = $this->ctx($request);
        $service = new CourseService($this->app);
        $course = $service->get($ctx, CourseController::id($request));
        $mine = [];
        foreach ((new LabService($this->app))->catalog($ctx) as $lab) {
            $mine[(string) $lab['code']] = $lab['my_attempt'];
        }
        $available = array_values(array_filter(
            (new LabService($this->app))->catalog($ctx),
            static fn (array $lab): bool => !in_array($lab['code'], array_column($course['labs'], 'code'), true)
        ));
        return $this->app->renderPage($request, 'Courses::show', [
            'pageTitle' => $course['title'] . ' · EduCloud Lab',
            'activeNav' => 'courses',
            'course' => $course,
            'myAttempts' => $mine,
            'modules' => (new ContentService($this->app))->tree($ctx, $course['id']),
            'availableLabs' => $available,
            'canReview' => $course['my_role'] === 'staff' && Authorize::allows($this->app->config, $ctx, 'review'),
            'canStart' => Authorize::allows($this->app->config, $ctx, 'create'),
            'extraScripts' => ['js/features/courses.js', 'js/features/course-content.js'],
        ], 200, 'layouts/app');
    }

    /** /app/lessons/{lesson_id}: lesson content, completion, linked lab, editor for staff (M10b). */
    public function lesson(Request $request): Response
    {
        $ctx = $this->ctx($request);
        $lesson = (new ContentService($this->app))->lesson($ctx, \EduCloud\Modules\Workspaces\WorkspaceController::id($request, 'lesson_id'));
        $course = (new CourseService($this->app))->get($ctx, $lesson['course']['id']);
        $myAttempt = null;
        if ($lesson['lab'] !== null) {
            foreach ((new LabService($this->app))->catalog($ctx) as $lab) {
                if ($lab['code'] === $lesson['lab']['code']) {
                    $myAttempt = $lab['my_attempt'];
                }
            }
        }
        return $this->app->renderPage($request, 'Courses::lesson', [
            'pageTitle' => $lesson['title'] . ' · ' . $course['title'] . ' · EduCloud Lab',
            'activeNav' => 'courses',
            'lesson' => $lesson,
            'courseLabs' => $course['labs'],
            'myAttempt' => $myAttempt,
            'isStudent' => $course['my_role'] === 'student',
            'canStart' => $course['status'] === 'published' && Authorize::allows($this->app->config, $ctx, 'create'),
            'extraScripts' => ['js/features/courses.js', 'js/features/course-content.js'],
        ], 200, 'layouts/app');
    }

    /** /app/courses/{course_id}/progress: instructor grid. */
    public function progress(Request $request): Response
    {
        $progress = (new CourseService($this->app))->progress($request, $this->ctx($request), CourseController::id($request));
        return $this->app->renderPage($request, 'Courses::progress', [
            'pageTitle' => t('courses.progress') . ' · ' . $progress['course']['title'] . ' · EduCloud Lab',
            'activeNav' => 'courses',
            'progress' => $progress,
        ], 200, 'layouts/app');
    }

    private function ctx(Request $request): TenantContext
    {
        /** @var TenantContext $ctx */
        $ctx = $request->attribute('tenant');
        return $ctx;
    }
}
