<?php

/**
 * Admin monitor. 'platform_admin' is a permission no tenant role holds: only users.is_platform_admin = 1 pass
 * Authorize (which grants platform admins every permission). Everyone else gets 403 whatever the id.
 */

declare(strict_types=1);

use EduCloud\Core\App;
use EduCloud\Core\Request;
use EduCloud\Core\Response;
use EduCloud\Core\Router;
use EduCloud\Modules\Admin\AdminController;

return static function (Router $router, App $app): void {
    $c = static fn (string $m) => static fn (Request $r, App $app) => (new AdminController($app))->$m($r);
    $api = static fn (string $name): array => ['auth' => 'any', 'permission' => 'platform_admin', 'name' => $name];

    $router->get('/api/v1/admin/overview', $c('overview'), $api('admin.overview'));
    $router->get('/api/v1/admin/jobs', $c('jobs'), $api('admin.jobs'));
    $router->get('/api/v1/admin/audit', $c('audit'), $api('admin.audit'));
    $router->get('/api/v1/admin/users', $c('users'), $api('admin.users'));
    $router->patch('/api/v1/admin/users/{user_id}', $c('updateUser'), $api('admin.user_update'));

    $router->get(
        '/app/admin',
        static fn (Request $r, App $app): Response => $app->renderPage($r, 'Admin::index', [
            'pageTitle' => t('admin.title') . ' · EduCloud Lab',
            'activeNav' => 'admin',
            'extraScripts' => ['js/features/admin.js'],
        ], 200, 'layouts/app'),
        ['auth' => 'session', 'permission' => 'platform_admin', 'name' => 'page.admin']
    );
};
