<?php

declare(strict_types=1);

use EduCloud\Core\App;
use EduCloud\Core\Request;
use EduCloud\Core\Response;
use EduCloud\Core\Router;
use EduCloud\Modules\ObjectStorage\ObjectStorageController;
use EduCloud\Modules\Resources\ResourceService;
use EduCloud\Modules\Workspaces\WorkspaceController;

return static function (Router $router, App $app): void {
    $c = static fn (string $m) => static fn (Request $r, App $app) => (new ObjectStorageController($app))->$m($r);
    $api = static fn (string $permission, string $name): array => ['auth' => 'any', 'permission' => $permission, 'name' => $name];

    $router->get('/api/v1/resources/{resource_id}/containers', $c('containers'), $api('read', 'storage.containers'));
    $router->post('/api/v1/resources/{resource_id}/containers', $c('createContainer'), $api('create', 'storage.container_create'));
    $router->get('/api/v1/containers/{container_id}', $c('showContainer'), $api('read', 'storage.container'));
    $router->patch('/api/v1/containers/{container_id}', $c('updateContainer'), $api('update', 'storage.container_update'));
    $router->delete('/api/v1/containers/{container_id}', $c('deleteContainer'), $api('delete', 'storage.container_delete'));
    $router->post('/api/v1/containers/{container_id}/objects', $c('putObject'), $api('create', 'storage.object_put'));
    $router->get('/api/v1/objects/{object_id}', $c('showObject'), $api('read', 'storage.object'));
    $router->patch('/api/v1/objects/{object_id}', $c('updateObject'), $api('update', 'storage.object_update'));
    $router->get('/api/v1/objects/{object_id}/download', $c('download'), $api('read', 'storage.object_download'));
    $router->delete('/api/v1/objects/{object_id}', $c('deleteObject'), $api('delete', 'storage.object_delete'));

    $router->get(
        '/app/resources/{resource_id}/storage',
        static function (Request $r, App $app): Response {
            /** @var \EduCloud\Core\Auth\TenantContext $ctx */
            $ctx = $r->attribute('tenant');
            $row = (new ResourceService($app))->findOrFail($ctx, WorkspaceController::id($r, 'resource_id'));
            if ($row['type'] !== 'storage' || $row['status'] !== 'active') {
                throw new \EduCloud\Core\Exceptions\NotFoundException('El almacenamiento no existe.');
            }
            $policy = new \EduCloud\Core\Auth\Policy($app->config);
            return $app->renderPage($r, 'ObjectStorage::browser', [
                'pageTitle' => t('storage.title') . ' · ' . $row['name'] . ' · EduCloud Lab',
                'activeNav' => 'workspaces',
                'resource' => ResourceService::present($row),
                'canEdit' => $policy->canModify($ctx, (int) $row['owner_user_id'], 'create'),
                'uploadMaxBytes' => (int) $app->config->get('quotas.upload_max_bytes'),
                'extraScripts' => ['js/features/storage.js'],
            ], 200, 'layouts/app');
        },
        ['auth' => 'session', 'permission' => 'read', 'name' => 'page.storage']
    );
};
