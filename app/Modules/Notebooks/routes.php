<?php

/** Notebooks (M8, ADR-010): CRUD, runs in the Docker sandbox (202), run detail, cancel, and the workspace page. */

declare(strict_types=1);

use EduCloud\Core\App;
use EduCloud\Core\Auth\Policy;
use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Exceptions\ValidationException;
use EduCloud\Core\Request;
use EduCloud\Core\Response;
use EduCloud\Core\Router;
use EduCloud\Core\Validator;
use EduCloud\Modules\Notebooks\NotebookService;
use EduCloud\Modules\Workspaces\WorkspaceController;
use EduCloud\Modules\Workspaces\WorkspaceService;

return static function (Router $router, App $app): void {
    $ctx = static function (Request $r): TenantContext {
        /** @var TenantContext $ctx */
        $ctx = $r->attribute('tenant');
        return $ctx;
    };
    $api = static fn (string $permission, string $name): array => ['auth' => 'any', 'permission' => $permission, 'name' => $name];
    $meta = static fn (Request $r): array => ['request_id' => $r->requestId];
    $input = static function (Request $r, bool $create): array {
        $body = $r->json();
        $rules = ['name' => $create ? ['required', ...WorkspaceController::NAME_RULES] : WorkspaceController::NAME_RULES];
        $data = Validator::validate(array_diff_key($body, ['cells' => 1]), $rules);
        if ($create && !array_key_exists('cells', $body)) {
            throw new ValidationException([['field' => 'cells', 'code' => 'required', 'message' => 'Las celdas son obligatorias.']]);
        }
        if (!$create && $data === [] && !array_key_exists('cells', $body)) {
            throw new ValidationException([['field' => 'name', 'code' => 'required', 'message' => 'Indica al menos un cambio.']]);
        }
        return array_map(static fn (mixed $v): string => (string) $v, $data) + (array_key_exists('cells', $body) ? ['cells' => $body['cells']] : []);
    };

    $router->get('/api/v1/workspaces/{workspace_id}/notebooks', static function (Request $r, App $app) use ($ctx, $meta): Response {
        $items = (new NotebookService($app))->list($ctx($r), WorkspaceController::id($r));
        return Response::json($items, 200, '', $meta($r) + ['total' => count($items), 'mode' => (new NotebookService($app))->mode()]);
    }, $api('read', 'notebooks.index'));

    $router->post('/api/v1/workspaces/{workspace_id}/notebooks', static function (Request $r, App $app) use ($ctx, $meta, $input): Response {
        $notebook = (new NotebookService($app))->create($r, $ctx($r), WorkspaceController::id($r), $input($r, true));
        return Response::json($notebook, 201, 'Notebook creado.', $meta($r));
    }, $api('create', 'notebooks.create'));

    $router->get('/api/v1/notebooks/{notebook_id}', static function (Request $r, App $app) use ($ctx, $meta): Response {
        $row = (new NotebookService($app))->findOrFail($ctx($r), WorkspaceController::id($r, 'notebook_id'));
        return Response::json(NotebookService::present($row), 200, '', $meta($r));
    }, $api('read', 'notebooks.show'));

    $router->patch('/api/v1/notebooks/{notebook_id}', static function (Request $r, App $app) use ($ctx, $meta, $input): Response {
        $notebook = (new NotebookService($app))->update($r, $ctx($r), WorkspaceController::id($r, 'notebook_id'), $input($r, false));
        return Response::json($notebook, 200, 'Notebook guardado.', $meta($r));
    }, $api('update', 'notebooks.update'));

    $router->delete('/api/v1/notebooks/{notebook_id}', static function (Request $r, App $app) use ($ctx): Response {
        (new NotebookService($app))->delete($r, $ctx($r), WorkspaceController::id($r, 'notebook_id'));
        return Response::noContent();
    }, $api('delete', 'notebooks.delete'));

    $router->post('/api/v1/notebooks/{notebook_id}/runs', static function (Request $r, App $app) use ($ctx, $meta): Response {
        Validator::validate($r->json(), []);
        $run = (new NotebookService($app))->run($r, $ctx($r), WorkspaceController::id($r, 'notebook_id'));
        return Response::json($run, 202, 'Notebook en ejecución…', $meta($r));
    }, $api('execute', 'notebooks.run'));

    $router->get('/api/v1/notebooks/{notebook_id}/runs', static function (Request $r, App $app) use ($ctx, $meta): Response {
        $runs = (new NotebookService($app))->runs($ctx($r), WorkspaceController::id($r, 'notebook_id'));
        return Response::json($runs, 200, '', $meta($r) + ['total' => count($runs)]);
    }, $api('read', 'notebooks.runs'));

    $router->get('/api/v1/notebook-runs/{notebook_run_id}', static function (Request $r, App $app) use ($ctx, $meta): Response {
        return Response::json((new NotebookService($app))->getRun($ctx($r), WorkspaceController::id($r, 'notebook_run_id')), 200, '', $meta($r));
    }, $api('read', 'notebooks.run_show'));

    $router->post('/api/v1/notebook-runs/{notebook_run_id}/cancel', static function (Request $r, App $app) use ($ctx, $meta): Response {
        $run = (new NotebookService($app))->cancel($r, $ctx($r), WorkspaceController::id($r, 'notebook_run_id'));
        return Response::json($run, 200, 'Cancelación solicitada.', $meta($r));
    }, $api('execute', 'notebooks.cancel'));

    $router->get(
        '/app/workspaces/{workspace_id}/notebooks',
        static function (Request $r, App $app) use ($ctx): Response {
            $row = (new WorkspaceService($app))->findOrFail($ctx($r), WorkspaceController::id($r));
            $service = new NotebookService($app);
            return $app->renderPage($r, 'Notebooks::index', [
                'pageTitle' => t('notebooks.title') . ' · ' . $row['name'] . ' · EduCloud Lab',
                'activeNav' => 'workspaces',
                'workspace' => WorkspaceService::present($row),
                'canEdit' => (new Policy($app->config))->canModify($ctx($r), (int) $row['owner_user_id'], 'update') && $row['status'] === 'active',
                'mode' => $service->mode(),
                'extraStyles' => ['vendor/codemirror/codemirror.css'],
                'extraScripts' => [
                    'vendor/codemirror/codemirror.js',
                    'vendor/codemirror/mode/python.js',
                    'vendor/codemirror/addon/matchbrackets.js',
                    'js/features/notebooks.js',
                ],
            ], 200, 'layouts/app');
        },
        ['auth' => 'session', 'permission' => 'read', 'name' => 'page.notebooks']
    );
};
