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
        static fn (Request $r, App $app): Response => $app->renderPage($r, 'Portal::dashboard', [
            'pageTitle' => t('portal.dashboard.title') . ' · EduCloud Lab',
        ]),
        ['auth' => 'session', 'permission' => 'read', 'name' => 'portal.dashboard']
    );
};
