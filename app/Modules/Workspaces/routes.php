<?php

declare(strict_types=1);

use EduCloud\Core\App;
use EduCloud\Core\Request;
use EduCloud\Core\Router;
use EduCloud\Modules\Resources\ResourceController;
use EduCloud\Modules\Workspaces\WorkspaceController;
use EduCloud\Modules\Workspaces\WorkspacePageController;

return static function (Router $router, App $app): void {
    $ws = static fn (string $m) => static fn (Request $r, App $app) => (new WorkspaceController($app))->$m($r);
    $res = static fn (string $m) => static fn (Request $r, App $app) => (new ResourceController($app))->$m($r);
    $page = static fn (string $m) => static fn (Request $r, App $app) => (new WorkspacePageController($app))->$m($r);
    $api = static fn (string $permission, string $name): array => ['auth' => 'any', 'permission' => $permission, 'name' => $name];

    // --- Workspaces --------------------------------------------------------------------------------
    $router->get('/api/v1/workspaces', $ws('index'), $api('read', 'workspaces.index'));
    $router->post('/api/v1/workspaces', $ws('store'), $api('create', 'workspaces.store'));
    $router->get('/api/v1/workspaces/{workspace_id}', $ws('show'), $api('read', 'workspaces.show'));
    $router->patch('/api/v1/workspaces/{workspace_id}', $ws('update'), $api('update', 'workspaces.update'));
    $router->delete('/api/v1/workspaces/{workspace_id}', $ws('destroy'), $api('delete', 'workspaces.destroy'));

    // --- Resources ---------------------------------------------------------------------------------
    $router->get('/api/v1/workspaces/{workspace_id}/resources', $res('index'), $api('read', 'resources.index'));
    $router->post('/api/v1/workspaces/{workspace_id}/resources', $res('store'), $api('create', 'resources.store'));
    $router->get('/api/v1/resources/{resource_id}', $res('show'), $api('read', 'resources.show'));
    $router->patch('/api/v1/resources/{resource_id}', $res('update'), $api('update', 'resources.update'));
    $router->delete('/api/v1/resources/{resource_id}', $res('destroy'), $api('delete', 'resources.destroy'));

    // --- Pages -------------------------------------------------------------------------------------
    $pageOpts = static fn (string $name): array => ['auth' => 'session', 'permission' => 'read', 'name' => $name];
    $router->get('/app/workspaces', $page('index'), $pageOpts('page.workspaces'));
    $router->get('/app/workspaces/{workspace_id}', $page('show'), $pageOpts('page.workspace'));
};
