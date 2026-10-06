<?php

declare(strict_types=1);

namespace EduCloud\Modules\Jobs;

use EduCloud\Core\App;
use EduCloud\Core\Auth\Policy;
use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Exceptions\NotFoundException;
use EduCloud\Core\Format;
use EduCloud\Core\Request;
use EduCloud\Core\Response;
use EduCloud\Modules\Workspaces\WorkspaceController;

/** GET /api/v1/jobs/{job_id}: status polling. Visible to the job's creator (or org_admin) in the same tenant. */
final class JobController
{
    public function __construct(private readonly App $app)
    {
    }

    public function show(Request $request): Response
    {
        /** @var TenantContext $ctx */
        $ctx = $request->attribute('tenant');
        $row = (new JobRepository($this->app->db()))->findVisible(
            $ctx,
            WorkspaceController::id($request, 'job_id'),
            (new Policy($this->app->config))->seesWholeTenant($ctx)
        );
        if ($row === null) {
            throw new NotFoundException('El trabajo no existe.');
        }
        return Response::json(self::present($row), 200, '', ['request_id' => $request->requestId]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function present(array $row): array
    {
        return [
            'id' => (string) $row['public_id'],
            'type' => (string) $row['type'],
            'status' => (string) $row['status'],
            'workspace_id' => $row['workspace_public_id'] ?? null,
            'error' => $row['error_code'] === null ? null : ['code' => (string) $row['error_code'], 'message' => (string) $row['safe_message']],
            'result' => Format::jsonColumn($row['result_summary']) ?: null,
            'queued_at' => Format::isoUtc((string) $row['queued_at']),
            'started_at' => Format::isoUtc($row['started_at'] === null ? null : (string) $row['started_at']),
            'finished_at' => Format::isoUtc($row['finished_at'] === null ? null : (string) $row['finished_at']),
        ];
    }
}
