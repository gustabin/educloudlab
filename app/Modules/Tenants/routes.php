<?php

declare(strict_types=1);

use EduCloud\Core\App;
use EduCloud\Core\Request;
use EduCloud\Core\Router;
use EduCloud\Modules\Tenants\TenantController;

return static function (Router $router, App $app): void {
    $c = static fn (string $m) => static fn (Request $r, App $app) => (new TenantController($app))->$m($r);

    $router->get('/api/v1/tenants', $c('index'), ['auth' => 'any', 'name' => 'tenants.index']);
    $router->post('/api/v1/tenants/{tenant_id}/switch', $c('switch'), ['auth' => 'session', 'name' => 'tenants.switch']);
};
