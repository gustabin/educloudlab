<?php

/**
 * Observability (M11b). Platform admins only: 'platform_admin' is a permission no tenant role holds.
 */

declare(strict_types=1);

use EduCloud\Core\App;
use EduCloud\Core\Request;
use EduCloud\Core\Router;
use EduCloud\Modules\Observability\ObservabilityController;

return static function (Router $router, App $app): void {
    $c = static fn (string $m) => static fn (Request $r, App $app) => (new ObservabilityController($app))->$m($r);
    $api = static fn (string $name): array => ['auth' => 'any', 'permission' => 'platform_admin', 'name' => $name];

    $router->get('/api/v1/admin/health', $c('health'), $api('admin.health'));
    $router->get('/api/v1/admin/metrics', $c('metrics'), $api('admin.metrics'));
    $router->get('/api/v1/admin/logs', $c('logs'), $api('admin.logs'));
};
