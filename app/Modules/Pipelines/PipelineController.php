<?php

declare(strict_types=1);

namespace EduCloud\Modules\Pipelines;

use EduCloud\Core\App;
use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Exceptions\ValidationException;
use EduCloud\Core\Request;
use EduCloud\Core\Response;
use EduCloud\Core\Validator;
use EduCloud\Modules\Workspaces\WorkspaceController;

/** Pipelines API (M7): CRUD, definition validation, runs (202), run detail and cancellation. */
final class PipelineController
{
    private PipelineService $service;

    public function __construct(private readonly App $app)
    {
        $this->service = new PipelineService($app);
    }

    public function index(Request $request): Response
    {
        $items = $this->service->list($this->ctx($request), WorkspaceController::id($request));
        return Response::json($items, 200, '', ['request_id' => $request->requestId, 'total' => count($items)]);
    }

    public function show(Request $request): Response
    {
        $pipeline = PipelineService::present($this->service->findOrFail($this->ctx($request), self::id($request)));
        return Response::json($pipeline, 200, '', ['request_id' => $request->requestId]);
    }

    public function create(Request $request): Response
    {
        $body = $request->json();
        $valid = static function () use ($body): array {
            $data = Validator::validate(array_diff_key($body, ['definition' => 1]), ['name' => ['required', ...WorkspaceController::NAME_RULES]]);
            if (!array_key_exists('definition', $body)) {
                throw new ValidationException([['field' => 'definition', 'code' => 'required', 'message' => 'La definición es obligatoria.']]);
            }
            return ['name' => (string) $data['name'], 'definition' => $body['definition']];
        };
        $pipeline = $this->service->create($request, $this->ctx($request), WorkspaceController::id($request), $valid);
        return Response::json($pipeline, 201, 'Pipeline creado.', ['request_id' => $request->requestId]);
    }

    public function update(Request $request): Response
    {
        $body = $request->json();
        $valid = static function () use ($body): array {
            $data = Validator::validate(array_diff_key($body, ['definition' => 1]), ['name' => WorkspaceController::NAME_RULES]);
            if ($data === [] && !array_key_exists('definition', $body)) {
                throw new ValidationException([['field' => 'name', 'code' => 'required', 'message' => 'Indica al menos un cambio.']]);
            }
            return $data + (array_key_exists('definition', $body) ? ['definition' => $body['definition']] : []);
        };
        $pipeline = $this->service->update($request, $this->ctx($request), self::id($request), $valid);
        return Response::json($pipeline, 200, 'Pipeline guardado.', ['request_id' => $request->requestId]);
    }

    public function delete(Request $request): Response
    {
        $this->service->delete($request, $this->ctx($request), self::id($request));
        return Response::noContent();
    }

    /** Validates a definition without saving it (editor feedback). Never executes anything. */
    public function validateDefinition(Request $request): Response
    {
        $body = $request->json();
        Validator::validate(array_diff_key($body, ['definition' => 1]), []);
        $definition = PipelineDefinition::validate($body['definition'] ?? null);
        $data = ['valid' => true, 'nodes' => count($definition['nodes']), 'output' => PipelineDefinition::output($definition)];
        return Response::json($data, 200, 'La definición es válida.', ['request_id' => $request->requestId]);
    }

    public function run(Request $request): Response
    {
        Validator::validate($request->json(), []);
        $run = $this->service->run($request, $this->ctx($request), self::id($request));
        return Response::json($run, 202, 'Pipeline en ejecución…', ['request_id' => $request->requestId]);
    }

    public function runs(Request $request): Response
    {
        $runs = $this->service->runs($this->ctx($request), self::id($request));
        return Response::json($runs, 200, '', ['request_id' => $request->requestId, 'total' => count($runs)]);
    }

    public function showRun(Request $request): Response
    {
        $run = $this->service->getRun($this->ctx($request), WorkspaceController::id($request, 'run_id'));
        return Response::json($run, 200, '', ['request_id' => $request->requestId]);
    }

    public function cancel(Request $request): Response
    {
        Validator::validate($request->json(), []);
        $run = $this->service->cancel($request, $this->ctx($request), WorkspaceController::id($request, 'run_id'));
        return Response::json($run, 200, 'Cancelación solicitada.', ['request_id' => $request->requestId]);
    }

    private static function id(Request $request): string
    {
        return WorkspaceController::id($request, 'pipeline_id');
    }

    private function ctx(Request $request): TenantContext
    {
        /** @var TenantContext $ctx */
        $ctx = $request->attribute('tenant');
        return $ctx;
    }
}
