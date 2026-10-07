<?php

declare(strict_types=1);

use EduCloud\Core\App;
use EduCloud\Core\Auth\Policy;
use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Request;
use EduCloud\Core\Response;
use EduCloud\Core\Router;
use EduCloud\Modules\Analytics\AnalyticsController;
use EduCloud\Modules\Analytics\AnalyticsService;
use EduCloud\Modules\Workspaces\WorkspaceController;
use EduCloud\Modules\Workspaces\WorkspaceService;

return static function (Router $router, App $app): void {
    $c = static fn (string $m) => static fn (Request $r, App $app) => (new AnalyticsController($app))->$m($r);
    $api = static fn (string $permission, string $name): array => ['auth' => 'any', 'permission' => $permission, 'name' => $name];

    $router->get('/api/v1/workspaces/{workspace_id}/semantic-models', $c('models'), $api('read', 'analytics.models'));
    $router->post('/api/v1/workspaces/{workspace_id}/semantic-models', $c('createModel'), $api('create', 'analytics.model_create'));
    $router->post('/api/v1/workspaces/{workspace_id}/semantic-models/validate', $c('validateModel'), $api('read', 'analytics.model_validate'));
    $router->get('/api/v1/semantic-models/{model_id}', $c('showModel'), $api('read', 'analytics.model'));
    $router->patch('/api/v1/semantic-models/{model_id}', $c('updateModel'), $api('update', 'analytics.model_update'));
    $router->delete('/api/v1/semantic-models/{model_id}', $c('deleteModel'), $api('delete', 'analytics.model_delete'));
    $router->post('/api/v1/semantic-models/{model_id}/query', $c('explore'), $api('execute', 'analytics.explore'));

    $router->get('/api/v1/workspaces/{workspace_id}/dashboards', $c('dashboards'), $api('read', 'analytics.dashboards'));
    $router->post('/api/v1/workspaces/{workspace_id}/dashboards', $c('createDashboard'), $api('create', 'analytics.dashboard_create'));
    $router->get('/api/v1/dashboards/{dashboard_id}', $c('showDashboard'), $api('read', 'analytics.dashboard'));
    $router->patch('/api/v1/dashboards/{dashboard_id}', $c('updateDashboard'), $api('update', 'analytics.dashboard_update'));
    $router->delete('/api/v1/dashboards/{dashboard_id}', $c('deleteDashboard'), $api('delete', 'analytics.dashboard_delete'));
    $router->post('/api/v1/dashboards/{dashboard_id}/render', $c('render'), $api('execute', 'analytics.render'));

    $router->get('/api/v1/semantic-queries/{semantic_query_id}', $c('showQuery'), $api('read', 'analytics.query'));

    $router->get(
        '/app/workspaces/{workspace_id}/analytics',
        static function (Request $r, App $app): Response {
            /** @var TenantContext $ctx */
            $ctx = $r->attribute('tenant');
            $row = (new WorkspaceService($app))->findOrFail($ctx, WorkspaceController::id($r));
            $policy = new Policy($app->config);
            return $app->renderPage($r, 'Analytics::index', [
                'pageTitle' => t('analytics.title') . ' · ' . $row['name'] . ' · EduCloud Lab',
                'activeNav' => 'workspaces',
                'workspace' => WorkspaceService::present($row),
                'catalog' => (new AnalyticsService($app))->catalog($ctx, (int) $row['id']),
                'canEdit' => $policy->canModify($ctx, (int) $row['owner_user_id'], 'update') && $row['status'] === 'active',
                'extraStyles' => ['vendor/codemirror/codemirror.css'],
                'extraScripts' => [
                    'vendor/codemirror/codemirror.js',
                    'vendor/codemirror/mode/javascript.js',
                    'vendor/codemirror/addon/matchbrackets.js',
                    'vendor/chartjs/chart.umd.min.js',
                    'js/features/charts.js',
                    'js/features/analytics.js',
                ],
            ], 200, 'layouts/app');
        },
        ['auth' => 'session', 'permission' => 'read', 'name' => 'page.analytics']
    );

    $router->get(
        '/app/dashboards/{dashboard_id}',
        static function (Request $r, App $app): Response {
            /** @var TenantContext $ctx */
            $ctx = $r->attribute('tenant');
            $row = (new AnalyticsService($app))->findDashboard($ctx, WorkspaceController::id($r, 'dashboard_id'));
            $dashboard = AnalyticsService::presentDashboard($row);
            return $app->renderPage($r, 'Analytics::dashboard', [
                'pageTitle' => $dashboard['name'] . ' · ' . t('analytics.dashboard') . ' · EduCloud Lab',
                'activeNav' => 'workspaces',
                'dashboard' => $dashboard,
                'canRender' => (new Policy($app->config))->canModify($ctx, (int) $row['owner_user_id'], 'execute'),
                'extraScripts' => ['vendor/chartjs/chart.umd.min.js', 'js/features/charts.js', 'js/features/dashboard.js'],
            ], 200, 'layouts/app');
        },
        ['auth' => 'session', 'permission' => 'read', 'name' => 'page.dashboard']
    );
};
