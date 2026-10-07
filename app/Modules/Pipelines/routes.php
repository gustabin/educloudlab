<?php

declare(strict_types=1);

use EduCloud\Core\App;
use EduCloud\Core\Request;
use EduCloud\Core\Response;
use EduCloud\Core\Router;
use EduCloud\Modules\Pipelines\PipelineController;
use EduCloud\Modules\Workspaces\WorkspaceController;
use EduCloud\Modules\Workspaces\WorkspaceService;

return static function (Router $router, App $app): void {
    $c = static fn (string $m) => static fn (Request $r, App $app) => (new PipelineController($app))->$m($r);
    $api = static fn (string $permission, string $name): array => ['auth' => 'any', 'permission' => $permission, 'name' => $name];

    $router->get('/api/v1/workspaces/{workspace_id}/pipelines', $c('index'), $api('read', 'pipelines.index'));
    $router->post('/api/v1/workspaces/{workspace_id}/pipelines', $c('create'), $api('create', 'pipelines.create'));
    $router->post('/api/v1/pipeline-definitions/validate', $c('validateDefinition'), $api('read', 'pipelines.validate'));
    $router->get('/api/v1/pipelines/{pipeline_id}', $c('show'), $api('read', 'pipelines.show'));
    $router->patch('/api/v1/pipelines/{pipeline_id}', $c('update'), $api('update', 'pipelines.update'));
    $router->delete('/api/v1/pipelines/{pipeline_id}', $c('delete'), $api('delete', 'pipelines.delete'));
    $router->post('/api/v1/pipelines/{pipeline_id}/runs', $c('run'), $api('execute', 'pipelines.run'));
    $router->get('/api/v1/pipelines/{pipeline_id}/runs', $c('runs'), $api('read', 'pipelines.runs'));
    $router->get('/api/v1/pipeline-runs/{run_id}', $c('showRun'), $api('read', 'pipelines.run_show'));
    $router->post('/api/v1/pipeline-runs/{run_id}/cancel', $c('cancel'), $api('execute', 'pipelines.cancel'));

    $router->get(
        '/app/workspaces/{workspace_id}/pipelines',
        static function (Request $r, App $app): Response {
            /** @var \EduCloud\Core\Auth\TenantContext $ctx */
            $ctx = $r->attribute('tenant');
            $row = (new WorkspaceService($app))->findOrFail($ctx, WorkspaceController::id($r));
            $policy = new \EduCloud\Core\Auth\Policy($app->config);
            return $app->renderPage($r, 'Pipelines::index', [
                'pageTitle' => t('pipelines.title') . ' · ' . $row['name'] . ' · EduCloud Lab',
                'activeNav' => 'workspaces',
                'workspace' => WorkspaceService::present($row),
                'canEdit' => $policy->canModify($ctx, (int) $row['owner_user_id'], 'update') && $row['status'] === 'active',
                'extraStyles' => ['vendor/codemirror/codemirror.css'],
                'extraScripts' => [
                    'vendor/codemirror/codemirror.js',
                    'vendor/codemirror/mode/javascript.js',
                    'vendor/codemirror/addon/matchbrackets.js',
                    'js/features/pipelines.js',
                ],
            ], 200, 'layouts/app');
        },
        ['auth' => 'session', 'permission' => 'read', 'name' => 'page.pipelines']
    );
};
