<?php

/** Authenticated student/instructor portal. M2 ships the dashboard shell; features arrive from M3. */

declare(strict_types=1);

use EduCloud\Core\App;
use EduCloud\Core\Request;
use EduCloud\Core\Response;
use EduCloud\Core\Router;

return static function (Router $router, App $app): void {
    $router->get(
        '/app',
        static function (Request $r, App $app): Response {
            /** @var \EduCloud\Core\Auth\TenantContext $ctx */
            $ctx = $r->attribute('tenant');
            $recent = (new \EduCloud\Modules\Workspaces\WorkspaceService($app))
                ->list($ctx, new \EduCloud\Core\Pagination(1, 5), '', 'updated_at', 'desc');
            return $app->renderPage($r, 'Portal::dashboard', [
                'pageTitle' => t('portal.dashboard.title') . ' · EduCloud Lab',
                'activeNav' => 'home',
                'recentWorkspaces' => $recent['items'],
                'workspaceTotal' => $recent['meta']['total'],
            ], 200, 'layouts/app');
        },
        ['auth' => 'session', 'permission' => 'read', 'name' => 'portal.dashboard']
    );
};
