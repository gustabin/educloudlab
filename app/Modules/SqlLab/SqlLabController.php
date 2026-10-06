<?php

declare(strict_types=1);

namespace EduCloud\Modules\SqlLab;

use EduCloud\Core\App;
use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Pagination;
use EduCloud\Core\Request;
use EduCloud\Core\Response;
use EduCloud\Core\Validator;
use EduCloud\Modules\Datasets\DatasetService;
use EduCloud\Modules\Workspaces\WorkspaceController;

/** SQL Lab API: queries (async, 202), history, catalog, transforms to silver/gold. */
final class SqlLabController
{
    private SqlLabService $service;

    public function __construct(private readonly App $app)
    {
        $this->service = new SqlLabService($app);
    }

    public function run(Request $request): Response
    {
        $body = $request->json();
        $max = (int) $this->app->config->get('execution.limits.sql_max_length', 20000);
        $valid = static fn (): string => (string) Validator::validate($body, ['sql' => ['required', 'string', "max:$max"]])['sql'];
        $query = $this->service->run($request, $this->ctx($request), WorkspaceController::id($request), $valid);
        return Response::json($query, 202, 'Consulta en ejecución…', ['request_id' => $request->requestId]);
    }

    public function show(Request $request): Response
    {
        $query = $this->service->get($this->ctx($request), WorkspaceController::id($request, 'query_id'));
        return Response::json($query, 200, '', ['request_id' => $request->requestId]);
    }

    public function history(Request $request): Response
    {
        Validator::validate($request->query, ['page' => ['string'], 'per_page' => ['string']]);
        $result = $this->service->history($this->ctx($request), WorkspaceController::id($request), Pagination::fromQuery($request->query, 20, 50));
        return Response::json($result['items'], 200, '', $result['meta'] + ['request_id' => $request->requestId]);
    }

    public function catalog(Request $request): Response
    {
        $catalog = $this->service->catalog($this->ctx($request), WorkspaceController::id($request));
        return Response::json($catalog, 200, '', ['request_id' => $request->requestId]);
    }

    public function transform(Request $request): Response
    {
        $body = $request->json();
        $max = (int) $this->app->config->get('execution.limits.sql_max_length', 20000);
        $valid = static function () use ($body, $max): array {
            /** @var array{sql: string, layer: string, table: string} $data */
            $data = Validator::validate($body, [
                'sql' => ['required', 'string', "max:$max"],
                'layer' => ['required', 'in:silver,gold'],
                'table' => ['required', 'string', 'regex:' . DatasetService::TABLE_NAME],
            ]);
            return $data;
        };
        $result = $this->service->transform($request, $this->ctx($request), WorkspaceController::id($request), $valid);
        return Response::json($result, 202, 'Creando la tabla…', ['request_id' => $request->requestId]);
    }

    private function ctx(Request $request): TenantContext
    {
        /** @var TenantContext $ctx */
        $ctx = $request->attribute('tenant');
        return $ctx;
    }
}
