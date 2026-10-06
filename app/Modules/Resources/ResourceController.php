<?php

declare(strict_types=1);

namespace EduCloud\Modules\Resources;

use EduCloud\Core\App;
use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Exceptions\ValidationException;
use EduCloud\Core\Request;
use EduCloud\Core\Response;
use EduCloud\Core\Validator;
use EduCloud\Modules\Workspaces\WorkspaceController;

/** /api/v1/workspaces/{workspace_id}/resources and /api/v1/resources/{resource_id} */
final class ResourceController
{
    private const TYPES = 'storage,lakehouse,dataset,pipeline,notebook,dashboard';

    private ResourceService $service;

    public function __construct(private readonly App $app)
    {
        $this->service = new ResourceService($app);
    }

    public function index(Request $request): Response
    {
        $q = Validator::validate($request->query, ['type' => ['in:' . self::TYPES]]);
        $items = $this->service->listForWorkspace($this->ctx($request), WorkspaceController::id($request), $q['type'] ?? null);
        return Response::json($items, 200, '', ['request_id' => $request->requestId, 'total' => count($items)]);
    }

    public function store(Request $request): Response
    {
        $body = $request->json();
        $data = Validator::validate(array_diff_key($body, ['config' => 1, 'tags' => 1]), [
            'type' => ['required', 'in:' . self::TYPES],
            'name' => ['required', ...WorkspaceController::NAME_RULES],
            'region' => ['in:' . implode(',', ResourceTypes::REGIONS)],
        ]);
        $resource = $this->service->create(
            $request,
            $this->ctx($request),
            WorkspaceController::id($request),
            $data['type'],
            $data['name'],
            $data['region'] ?? ResourceTypes::REGIONS[0],
            self::objectOrNull($body, 'config'),
            $body['tags'] ?? null,
        );
        return Response::json($resource, 201, 'Recurso creado.', ['request_id' => $request->requestId]);
    }

    public function show(Request $request): Response
    {
        $row = $this->service->findOrFail($this->ctx($request), WorkspaceController::id($request, 'resource_id'));
        return Response::json(ResourceService::present($row), 200, '', ['request_id' => $request->requestId]);
    }

    public function update(Request $request): Response
    {
        $body = $request->json();
        $data = Validator::validate(array_diff_key($body, ['config' => 1, 'tags' => 1]), ['name' => WorkspaceController::NAME_RULES]);
        $changes = $data;
        if (array_key_exists('config', $body)) {
            $changes['config'] = self::objectOrNull($body, 'config') ?? [];
        }
        if (array_key_exists('tags', $body)) {
            $changes['tags'] = $body['tags'];
        }
        if ($changes === []) {
            throw new ValidationException([['code' => 'empty', 'message' => 'Indica al menos un campo para actualizar.']]);
        }
        $resource = $this->service->update($request, $this->ctx($request), WorkspaceController::id($request, 'resource_id'), $changes);
        return Response::json($resource, 200, 'Recurso actualizado.', ['request_id' => $request->requestId]);
    }

    public function destroy(Request $request): Response
    {
        $this->service->delete($request, $this->ctx($request), WorkspaceController::id($request, 'resource_id'));
        return Response::noContent();
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>|null
     */
    private static function objectOrNull(array $body, string $key): ?array
    {
        if (!array_key_exists($key, $body) || $body[$key] === null) {
            return null;
        }
        if (!is_array($body[$key]) || ($body[$key] !== [] && array_is_list($body[$key]))) {
            throw new ValidationException([['field' => $key, 'code' => 'object', 'message' => 'Debe ser un objeto.']]);
        }
        return $body[$key];
    }

    private function ctx(Request $request): TenantContext
    {
        /** @var TenantContext $ctx */
        $ctx = $request->attribute('tenant');
        return $ctx;
    }
}
