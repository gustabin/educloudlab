<?php

declare(strict_types=1);

use EduCloud\Core\App;
use EduCloud\Core\Request;
use EduCloud\Core\Router;
use EduCloud\Modules\Courses\ContentController;
use EduCloud\Modules\Courses\CourseController;
use EduCloud\Modules\Courses\CoursePageController;

return static function (Router $router, App $app): void {
    $c = static fn (string $m) => static fn (Request $r, App $app) => (new CourseController($app))->$m($r);
    $p = static fn (string $m) => static fn (Request $r, App $app) => (new CoursePageController($app))->$m($r);
    $api = static fn (string $permission, string $name): array => ['auth' => 'any', 'permission' => $permission, 'name' => $name];

    $router->get('/api/v1/courses', $c('index'), $api('read', 'courses.index'));
    $router->post('/api/v1/courses', $c('create'), $api('assign', 'courses.create'));
    // Joining works from any tenant (the code identifies the course); brute force is limited per IP and per user.
    $router->post('/api/v1/courses/join', $c('join'), ['auth' => 'any', 'name' => 'courses.join', 'rate' => ['course_join_ip']]);
    $router->get('/api/v1/courses/{course_id}', $c('show'), $api('read', 'courses.show'));
    $router->patch('/api/v1/courses/{course_id}', $c('update'), $api('assign', 'courses.update'));
    $router->post('/api/v1/courses/{course_id}/join-code', $c('rotateJoinCode'), $api('assign', 'courses.join_code'));
    $router->delete('/api/v1/courses/{course_id}/join-code', $c('disableJoinCode'), $api('assign', 'courses.join_code_disable'));
    $router->post('/api/v1/courses/{course_id}/labs', $c('assignLab'), $api('assign', 'courses.assign_lab'));
    $router->delete('/api/v1/courses/{course_id}/labs/{lab_code}', $c('unassignLab'), $api('assign', 'courses.unassign_lab'));
    $router->get('/api/v1/courses/{course_id}/progress', $c('progress'), $api('review', 'courses.progress'));

    // M10b: modules, lessons (CommonMark) and lesson completion.
    $k = static fn (string $m) => static fn (Request $r, App $app) => (new ContentController($app))->$m($r);
    $router->get('/api/v1/courses/{course_id}/modules', $k('tree'), $api('read', 'courses.modules'));
    $router->post('/api/v1/courses/{course_id}/modules', $k('createModule'), $api('assign', 'courses.module_create'));
    $router->patch('/api/v1/course-modules/{module_id}', $k('updateModule'), $api('assign', 'courses.module_update'));
    $router->delete('/api/v1/course-modules/{module_id}', $k('deleteModule'), $api('assign', 'courses.module_delete'));
    $router->post('/api/v1/course-modules/{module_id}/lessons', $k('createLesson'), $api('assign', 'courses.lesson_create'));
    $router->get('/api/v1/lessons/{lesson_id}', $k('showLesson'), $api('read', 'courses.lesson'));
    $router->patch('/api/v1/lessons/{lesson_id}', $k('updateLesson'), $api('assign', 'courses.lesson_update'));
    $router->delete('/api/v1/lessons/{lesson_id}', $k('deleteLesson'), $api('assign', 'courses.lesson_delete'));
    $router->post('/api/v1/lessons/{lesson_id}/complete', $k('complete'), $api('create', 'courses.lesson_complete'));
    $router->delete('/api/v1/lessons/{lesson_id}/complete', $k('uncomplete'), $api('create', 'courses.lesson_uncomplete'));

    $page = static fn (string $name): array => ['auth' => 'session', 'permission' => 'read', 'name' => $name];
    $router->get('/app/courses', $p('index'), $page('page.courses'));
    $router->get('/app/courses/{course_id}', $p('show'), $page('page.course'));
    $router->get('/app/lessons/{lesson_id}', $p('lesson'), $page('page.lesson'));
    $router->get('/app/courses/{course_id}/progress', $p('progress'), [
        'auth' => 'session',
        'permission' => 'review',
        'name' => 'page.course_progress',
    ]);
};
