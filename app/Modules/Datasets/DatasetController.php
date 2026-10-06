<?php

declare(strict_types=1);

namespace EduCloud\Modules\Datasets;

use EduCloud\Core\App;
use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Exceptions\ValidationException;
use EduCloud\Core\Request;
use EduCloud\Core\Response;
use EduCloud\Core\Validator;
use EduCloud\Modules\Workspaces\WorkspaceController;

/** /api/v1/workspaces/{workspace_id}/datasets and /api/v1/datasets/{dataset_id} */
final class DatasetController
{
    private const NAME_RULES = ['string', 'regex:' . DatasetService::TABLE_NAME];
    private const NAME_HELP = 'Usa minúsculas, números y guion bajo, empezando por una letra (máx. 63), p. ej. customers.';

    private DatasetService $service;

    public function __construct(private readonly App $app)
    {
        $this->service = new DatasetService($app);
    }

    public function index(Request $request): Response
    {
        $items = $this->service->list($this->ctx($request), WorkspaceController::id($request));
        return Response::json($items, 200, '', ['request_id' => $request->requestId, 'total' => count($items)]);
    }

    /** multipart/form-data: name, file (+ csrf_token or X-CSRF-Token header) */
    public function store(Request $request): Response
    {
        $fields = array_diff_key($request->post, ['csrf_token' => 1]);
        // Visibility is resolved first (404), then the input is validated (422).
        $validate = static fn (): string => self::validateName($fields, 'name')['name'];
        $result = $this->service->upload($request, $this->ctx($request), WorkspaceController::id($request), $validate, $request->file('file'));
        return Response::json($result, 202, 'Archivo recibido. Analizando su contenido…', ['request_id' => $request->requestId]);
    }

    public function show(Request $request): Response
    {
        $row = $this->service->findOrFail($this->ctx($request), WorkspaceController::id($request, 'dataset_id'));
        return Response::json(DatasetService::present($row), 200, '', ['request_id' => $request->requestId]);
    }

    public function preview(Request $request): Response
    {
        $preview = $this->service->preview($this->ctx($request), WorkspaceController::id($request, 'dataset_id'));
        return Response::json($preview, 200, '', ['request_id' => $request->requestId]);
    }

    public function ingest(Request $request): Response
    {
        $body = $request->json();
        $validate = static fn (): string => self::validateName($body, 'table_name')['table_name'];
        $result = $this->service->ingest($request, $this->ctx($request), WorkspaceController::id($request, 'dataset_id'), $validate);
        return Response::json($result, 202, 'Ingesta en la capa bronze en curso…', ['request_id' => $request->requestId]);
    }

    public function destroy(Request $request): Response
    {
        $job = $this->service->delete($request, $this->ctx($request), WorkspaceController::id($request, 'dataset_id'));
        return Response::json(['job' => $job], 202, 'Eliminando dataset…', ['request_id' => $request->requestId]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    private static function validateName(array $input, string $field): array
    {
        try {
            /** @var array<string, string> $data */
            $data = Validator::validate($input, [$field => ['required', ...self::NAME_RULES]]);
            return $data;
        } catch (ValidationException $e) {
            $details = array_map(
                static fn (array $d): array => ($d['field'] ?? '') === $field && $d['code'] === 'regex'
                    ? ['field' => $field, 'code' => 'regex', 'message' => self::NAME_HELP]
                    : $d,
                $e->details
            );
            throw new ValidationException($details);
        }
    }

    private function ctx(Request $request): TenantContext
    {
        /** @var TenantContext $ctx */
        $ctx = $request->attribute('tenant');
        return $ctx;
    }
}
