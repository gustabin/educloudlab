<?php

declare(strict_types=1);

use EduCloud\Core\App;
use EduCloud\Core\Request;
use EduCloud\Core\Router;
use EduCloud\Modules\Labs\LabController;
use EduCloud\Modules\Labs\LabPageController;

return static function (Router $router, App $app): void {
    $c = static fn (string $m) => static fn (Request $r, App $app) => (new LabController($app))->$m($r);
    $p = static fn (string $m) => static fn (Request $r, App $app) => (new LabPageController($app))->$m($r);
    $api = static fn (string $permission, string $name): array => ['auth' => 'any', 'permission' => $permission, 'name' => $name];

    $router->get('/api/v1/labs', $c('catalog'), $api('read', 'labs.catalog'));
    $router->post('/api/v1/lab-attempts', $c('start'), $api('create', 'labs.start'));
    $router->get('/api/v1/lab-attempts/{attempt_id}', $c('show'), $api('read', 'labs.attempt'));
    $router->post('/api/v1/lab-attempts/{attempt_id}/hints', $c('hint'), $api('execute', 'labs.hint'));
    $router->post('/api/v1/lab-attempts/{attempt_id}/answers', $c('answer'), $api('execute', 'labs.answer'));
    $router->post('/api/v1/lab-attempts/{attempt_id}/submit', $c('submit'), $api('execute', 'labs.submit'));
    $router->delete('/api/v1/lab-attempts/{attempt_id}', $c('abandon'), $api('delete', 'labs.abandon'));

    $page = static fn (string $name): array => ['auth' => 'session', 'permission' => 'read', 'name' => $name];
    $router->get('/app/labs', $p('catalog'), $page('page.labs'));
    $router->get('/app/lab-attempts/{attempt_id}', $p('attempt'), $page('page.lab_attempt'));
};
