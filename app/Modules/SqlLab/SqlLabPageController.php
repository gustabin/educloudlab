<?php

declare(strict_types=1);

namespace EduCloud\Modules\SqlLab;

use EduCloud\Core\App;
use EduCloud\Core\Auth\Policy;
use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Pagination;
use EduCloud\Core\Request;
use EduCloud\Core\Response;
use EduCloud\Modules\Datasets\DatasetRepository;
use EduCloud\Modules\Workspaces\WorkspaceController;
use EduCloud\Modules\Workspaces\WorkspaceService;

final class SqlLabPageController
{
    public function __construct(private readonly App $app)
    {
    }

    /** /app/sql: pick a workspace. */
    public function chooser(Request $request): Response
    {
        /** @var TenantContext $ctx */
        $ctx = $request->attribute('tenant');
        $list = (new WorkspaceService($this->app))->list($ctx, new Pagination(1, 50), '', 'updated_at', 'desc');
        return $this->app->renderPage($request, 'SqlLab::chooser', [
            'pageTitle' => t('sql.title') . ' · EduCloud Lab',
            'activeNav' => 'sql',
            'workspaces' => $list['items'],
        ], 200, 'layouts/app');
    }

    /** /app/workspaces/{workspace_id}/sql: the editor. */
    public function lab(Request $request): Response
    {
        /** @var TenantContext $ctx */
        $ctx = $request->attribute('tenant');
        $row = (new WorkspaceService($this->app))->findOrFail($ctx, WorkspaceController::id($request));
        $policy = new Policy($this->app->config);
        return $this->app->renderPage($request, 'SqlLab::lab', [
            'pageTitle' => t('sql.title') . ' · ' . $row['name'] . ' · EduCloud Lab',
            'activeNav' => 'sql',
            'workspace' => WorkspaceService::present($row),
            'hasLakehouse' => (new DatasetRepository($this->app->db()))->hasActiveLakehouse($ctx, (int) $row['id']),
            'canExecute' => $policy->canModify($ctx, (int) $row['owner_user_id'], 'execute'),
            'canCreate' => $policy->canModify($ctx, (int) $row['owner_user_id'], 'create'),
            'maxRows' => (int) $this->app->config->get('execution.limits.sql_max_rows', 1000),
            'extraStyles' => ['vendor/codemirror/codemirror.css', 'vendor/codemirror/addon/show-hint.css'],
            'extraScripts' => [
                'vendor/codemirror/codemirror.js',
                'vendor/codemirror/mode/sql.js',
                'vendor/codemirror/addon/matchbrackets.js',
                'vendor/codemirror/addon/show-hint.js',
                'vendor/codemirror/addon/sql-hint.js',
                'js/features/sqllab.js',
            ],
        ], 200, 'layouts/app');
    }
}
