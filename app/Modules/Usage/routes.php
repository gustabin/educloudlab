<?php

declare(strict_types=1);

use EduCloud\Core\App;
use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Request;
use EduCloud\Core\Response;
use EduCloud\Core\Router;
use EduCloud\Modules\Usage\UsageService;

return static function (Router $router, App $app): void {
    // The caller's own usage in the active tenant (storage quota incl. lakehouse tables, monthly job counters).
    $router->get(
        '/api/v1/usage',
        static function (Request $r, App $app): Response {
            /** @var TenantContext $ctx */
            $ctx = $r->attribute('tenant');
            return Response::json((new UsageService($app))->summary($ctx), 200, '', ['request_id' => $r->requestId]);
        },
        ['auth' => 'any', 'permission' => 'read', 'name' => 'usage.me']
    );
};
