<?php

declare(strict_types=1);

use EduCloud\Core\App;
use EduCloud\Core\Request;
use EduCloud\Core\Router;
use EduCloud\Modules\System\HealthController;

return static function (Router $router, App $app): void {
    $router->get(
        '/api/v1/health',
        static fn (Request $r, App $app) => (new HealthController($app))->show($r),
        ['name' => 'system.health']
    );
};
