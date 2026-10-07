<?php

declare(strict_types=1);

namespace EduCloud\Modules\Analytics;

use EduCloud\Core\App;
use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Exceptions\ValidationException;
use EduCloud\Core\Request;
use EduCloud\Core\Response;
use EduCloud\Core\Validator;
use EduCloud\Modules\Workspaces\WorkspaceController;

/** Analytics API (M9): semantic models, dashboards, explorations and renders (202 + GET /semantic-queries/{id}). */
final class AnalyticsController
{
    private AnalyticsService $service;

    public function __construct(private readonly App $app)
    {
        $this->service = new AnalyticsService($app);
    }

    // ------------------------------------------------------------------------------------------------ models

    public function models(Request $request): Response
    {
        $items = $this->service->models($this->ctx($request), WorkspaceController::id($request));
        return Response::json($items, 200, '', ['request_id' => $request->requestId, 'total' => count($items)]);
    }

    public function showModel(Request $request): Response
    {
        $model = AnalyticsService::presentModel($this->service->findModel($this->ctx($request), self::id($request, 'model_id')));
        return Response::json($model, 200, '', ['request_id' => $request->requestId]);
    }

    public function createModel(Request $request): Response
    {
        $valid = self::nameAndDefinition($request->json(), true, []);
        $model = $this->service->createModel($request, $this->ctx($request), WorkspaceController::id($request), $valid);
        return Response::json($model, 201, 'Modelo semántico creado.', ['request_id' => $request->requestId]);
    }

    public function updateModel(Request $request): Response
    {
        $valid = self::nameAndDefinition($request->json(), false, []);
        $model = $this->service->updateModel($request, $this->ctx($request), self::id($request, 'model_id'), $valid);
        return Response::json($model, 200, 'Modelo semántico guardado.', ['request_id' => $request->requestId]);
    }

    public function deleteModel(Request $request): Response
    {
        $this->service->deleteModel($request, $this->ctx($request), self::id($request, 'model_id'));
        return Response::noContent();
    }

    /** Validates a model definition against the workspace catalog without saving it. */
    public function validateModel(Request $request): Response
    {
        $body = $request->json();
        Validator::validate(array_diff_key($body, ['definition' => 1]), []);
        $data = $this->service->validateModel($this->ctx($request), WorkspaceController::id($request), $body['definition'] ?? null);
        return Response::json($data, 200, 'El modelo es válido.', ['request_id' => $request->requestId]);
    }

    public function explore(Request $request): Response
    {
        $query = $this->service->explore($request, $this->ctx($request), self::id($request, 'model_id'), $request->json());
        return Response::json($query, 202, 'Consulta en curso…', ['request_id' => $request->requestId]);
    }

    // ------------------------------------------------------------------------------------------------ dashboards

    public function dashboards(Request $request): Response
    {
        $items = $this->service->dashboards($this->ctx($request), WorkspaceController::id($request));
        return Response::json($items, 200, '', ['request_id' => $request->requestId, 'total' => count($items)]);
    }

    public function showDashboard(Request $request): Response
    {
        $row = $this->service->findDashboard($this->ctx($request), self::id($request, 'dashboard_id'));
        return Response::json(AnalyticsService::presentDashboard($row), 200, '', ['request_id' => $request->requestId]);
    }

    public function createDashboard(Request $request): Response
    {
        $valid = self::nameAndDefinition($request->json(), true, ['model_id' => ['required', 'ulid']]);
        $dashboard = $this->service->createDashboard($request, $this->ctx($request), WorkspaceController::id($request), $valid);
        return Response::json($dashboard, 201, 'Dashboard creado.', ['request_id' => $request->requestId]);
    }

    public function updateDashboard(Request $request): Response
    {
        $valid = self::nameAndDefinition($request->json(), false, ['model_id' => ['ulid']]);
        $dashboard = $this->service->updateDashboard($request, $this->ctx($request), self::id($request, 'dashboard_id'), $valid);
        return Response::json($dashboard, 200, 'Dashboard guardado.', ['request_id' => $request->requestId]);
    }

    public function deleteDashboard(Request $request): Response
    {
        $this->service->deleteDashboard($request, $this->ctx($request), self::id($request, 'dashboard_id'));
        return Response::noContent();
    }

    public function render(Request $request): Response
    {
        $query = $this->service->render($request, $this->ctx($request), self::id($request, 'dashboard_id'), $request->json());
        return Response::json($query, 202, 'Actualizando el dashboard…', ['request_id' => $request->requestId]);
    }

    public function showQuery(Request $request): Response
    {
        $query = $this->service->getQuery($this->ctx($request), self::id($request, 'semantic_query_id'));
        return Response::json($query, 200, '', ['request_id' => $request->requestId]);
    }

    /**
     * @param array<string, mixed>        $body
     * @param array<string, list<string>> $extraRules
     * @return callable(): array<string, mixed>
     */
    private static function nameAndDefinition(array $body, bool $create, array $extraRules): callable
    {
        return static function () use ($body, $create, $extraRules): array {
            $rules = ['name' => $create ? ['required', ...WorkspaceController::NAME_RULES] : WorkspaceController::NAME_RULES] + $extraRules;
            $data = Validator::validate(array_diff_key($body, ['definition' => 1]), $rules);
            if ($create && !array_key_exists('definition', $body)) {
                throw new ValidationException([['field' => 'definition', 'code' => 'required', 'message' => 'La definición es obligatoria.']]);
            }
            if (!$create && $data === [] && !array_key_exists('definition', $body)) {
                throw new ValidationException([['field' => 'name', 'code' => 'required', 'message' => 'Indica al menos un cambio.']]);
            }
            $out = array_map(static fn (mixed $v): string => (string) $v, $data);
            return $out + (array_key_exists('definition', $body) ? ['definition' => $body['definition']] : []);
        };
    }

    private static function id(Request $request, string $param): string
    {
        return WorkspaceController::id($request, $param);
    }

    private function ctx(Request $request): TenantContext
    {
        /** @var TenantContext $ctx */
        $ctx = $request->attribute('tenant');
        return $ctx;
    }
}
