<?php

declare(strict_types=1);

namespace EduCloud\Modules\Workspaces;

use EduCloud\Core\App;
use EduCloud\Core\Auth\Policy;
use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Request;
use EduCloud\Core\Response;
use EduCloud\Http\Middleware\Authorize;

/** Portal pages. Data is loaded client-side from the API; the server only resolves what the page needs to render. */
final class WorkspacePageController
{
    public function __construct(private readonly App $app)
    {
    }

    public function index(Request $request): Response
    {
        /** @var TenantContext $ctx */
        $ctx = $request->attribute('tenant');
        return $this->app->renderPage($request, 'Workspaces::index', [
            'pageTitle' => t('ws.title') . ' · EduCloud Lab',
            'activeNav' => 'workspaces',
            'canCreate' => Authorize::allows($this->app->config, $ctx, 'create'),
            'quota' => (int) $this->app->config->get('quotas.workspaces_per_user'),
        ], 200, 'layouts/app');
    }

    public function show(Request $request): Response
    {
        /** @var TenantContext $ctx */
        $ctx = $request->attribute('tenant');
        // 404 page (via ErrorHandler) when the workspace is not visible to this user/tenant.
        $row = (new WorkspaceService($this->app))->findOrFail($ctx, WorkspaceController::id($request));
        $policy = new Policy($this->app->config);
        return $this->app->renderPage($request, 'Workspaces::show', [
            'pageTitle' => $row['name'] . ' · ' . t('ws.title') . ' · EduCloud Lab',
            'activeNav' => 'workspaces',
            'workspace' => WorkspaceService::present($row),
            'canUpdate' => $policy->canModify($ctx, (int) $row['owner_user_id'], 'update'),
            'canDelete' => $policy->canModify($ctx, (int) $row['owner_user_id'], 'delete'),
            'canCreate' => $policy->canModify($ctx, (int) $row['owner_user_id'], 'create'),
        ], 200, 'layouts/app');
    }
}
