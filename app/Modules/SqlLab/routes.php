<?php

declare(strict_types=1);

use EduCloud\Core\App;
use EduCloud\Core\Request;
use EduCloud\Core\Router;
use EduCloud\Modules\SqlLab\SqlLabController;
use EduCloud\Modules\SqlLab\SqlLabPageController;

return static function (Router $router, App $app): void {
    $c = static fn (string $m) => static fn (Request $r, App $app) => (new SqlLabController($app))->$m($r);
    $p = static fn (string $m) => static fn (Request $r, App $app) => (new SqlLabPageController($app))->$m($r);
    $api = static fn (string $permission, string $name): array => ['auth' => 'any', 'permission' => $permission, 'name' => $name];

    $router->post('/api/v1/workspaces/{workspace_id}/queries', $c('run'), $api('execute', 'sql.run'));
    $router->get('/api/v1/workspaces/{workspace_id}/queries', $c('history'), $api('read', 'sql.history'));
    $router->get('/api/v1/queries/{query_id}', $c('show'), $api('read', 'sql.show'));
    $router->get('/api/v1/workspaces/{workspace_id}/catalog', $c('catalog'), $api('read', 'sql.catalog'));
    $router->post('/api/v1/workspaces/{workspace_id}/transforms', $c('transform'), $api('create', 'sql.transform'));

    $page = static fn (string $name): array => ['auth' => 'session', 'permission' => 'read', 'name' => $name];
    $router->get('/app/sql', $p('chooser'), $page('page.sql'));
    $router->get('/app/workspaces/{workspace_id}/sql', $p('lab'), $page('page.sql_lab'));
};
