<?php

declare(strict_types=1);

use EduCloud\Core\App;
use EduCloud\Core\Request;
use EduCloud\Core\Router;
use EduCloud\Modules\Datasets\DatasetController;
use EduCloud\Modules\Jobs\JobController;

return static function (Router $router, App $app): void {
    $ds = static fn (string $m) => static fn (Request $r, App $app) => (new DatasetController($app))->$m($r);
    $api = static fn (string $permission, string $name): array => ['auth' => 'any', 'permission' => $permission, 'name' => $name];

    $router->get('/api/v1/workspaces/{workspace_id}/datasets', $ds('index'), $api('read', 'datasets.index'));
    $router->post('/api/v1/workspaces/{workspace_id}/datasets', $ds('store'), $api('create', 'datasets.store'));
    $router->get('/api/v1/datasets/{dataset_id}', $ds('show'), $api('read', 'datasets.show'));
    $router->get('/api/v1/datasets/{dataset_id}/preview', $ds('preview'), $api('read', 'datasets.preview'));
    $router->get('/api/v1/datasets/{dataset_id}/lineage', $ds('lineage'), $api('read', 'datasets.lineage'));
    $router->post('/api/v1/datasets/{dataset_id}/ingest', $ds('ingest'), $api('create', 'datasets.ingest'));
    $router->delete('/api/v1/datasets/{dataset_id}', $ds('destroy'), $api('delete', 'datasets.destroy'));

    $router->get(
        '/api/v1/jobs/{job_id}',
        static fn (Request $r, App $app) => (new JobController($app))->show($r),
        $api('read', 'jobs.show')
    );
};
