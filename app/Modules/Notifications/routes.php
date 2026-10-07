<?php

/** In-app notifications of the caller (M10b): list, mark one or all as read. */

declare(strict_types=1);

use EduCloud\Core\App;
use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Request;
use EduCloud\Core\Response;
use EduCloud\Core\Router;
use EduCloud\Core\Validator;
use EduCloud\Modules\Notifications\NotificationService;
use EduCloud\Modules\Workspaces\WorkspaceController;

return static function (Router $router, App $app): void {
    $ctx = static function (Request $r): TenantContext {
        /** @var TenantContext $ctx */
        $ctx = $r->attribute('tenant');
        return $ctx;
    };
    $api = static fn (string $name): array => ['auth' => 'any', 'permission' => 'read', 'name' => $name];

    $router->get('/api/v1/notifications', static function (Request $r, App $app) use ($ctx): Response {
        $q = Validator::validate($r->query, ['unread' => ['in:0,1']]);
        $data = (new NotificationService($app))->list($ctx($r), ($q['unread'] ?? '0') === '1');
        return Response::json($data['items'], 200, '', ['request_id' => $r->requestId, 'unread' => $data['unread']]);
    }, $api('notifications.index'));

    $router->post('/api/v1/notifications/read-all', static function (Request $r, App $app) use ($ctx): Response {
        Validator::validate($r->json(), []);
        $count = (new NotificationService($app))->markAllRead($ctx($r));
        return Response::json(['marked' => $count], 200, '', ['request_id' => $r->requestId]);
    }, $api('notifications.read_all'));

    $router->post('/api/v1/notifications/{notification_id}/read', static function (Request $r, App $app) use ($ctx): Response {
        (new NotificationService($app))->markRead($ctx($r), WorkspaceController::id($r, 'notification_id'));
        return Response::noContent();
    }, $api('notifications.read'));
};
