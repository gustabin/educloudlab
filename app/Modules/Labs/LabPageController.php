<?php

declare(strict_types=1);

namespace EduCloud\Modules\Labs;

use EduCloud\Core\App;
use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Format;
use EduCloud\Core\Markdown;
use EduCloud\Core\Request;
use EduCloud\Core\Response;
use EduCloud\Http\Middleware\Authorize;

final class LabPageController
{
    public function __construct(private readonly App $app)
    {
    }

    /** /app/labs: catalog with the caller's progress. */
    public function catalog(Request $request): Response
    {
        /** @var TenantContext $ctx */
        $ctx = $request->attribute('tenant');
        return $this->app->renderPage($request, 'Labs::catalog', [
            'pageTitle' => t('labs.title') . ' · EduCloud Lab',
            'activeNav' => 'labs',
            'labs' => (new LabService($this->app))->catalog($ctx),
            'canStart' => Authorize::allows($this->app->config, $ctx, 'create'),
            'extraScripts' => ['js/features/labs.js'],
        ], 200, 'layouts/app');
    }

    /** /app/lab-attempts/{attempt_id}: the lab interface (instructions rendered server-side from trusted Markdown). */
    public function attempt(Request $request): Response
    {
        /** @var TenantContext $ctx */
        $ctx = $request->attribute('tenant');
        $service = new LabService($this->app);
        $row = $service->findOrFail($ctx, LabController::id($request));
        $attempt = $service->present($ctx, $row);
        $definition = Format::jsonColumn($row['definition']);
        $instructions = [];
        foreach ($definition['tasks'] ?? [] as $task) {
            $instructions[(string) $task['key']] = Markdown::toHtml((string) ($task['instructions_md'] ?? ''));
        }
        return $this->app->renderPage($request, 'Labs::attempt', [
            'pageTitle' => $attempt['lab']['title'] . ' · EduCloud Lab',
            'activeNav' => 'labs',
            'attempt' => $attempt,
            'introHtml' => Markdown::toHtml((string) ($definition['intro_md'] ?? '')),
            'instructionsHtml' => $instructions,
            'maxSql' => (int) $this->app->config->get('execution.limits.sql_max_length', 20000),
            'extraScripts' => ['js/features/labs.js'],
        ], 200, 'layouts/app');
    }
}
