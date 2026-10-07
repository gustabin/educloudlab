<?php

declare(strict_types=1);

namespace EduCloud\Modules\Labs;

use EduCloud\Core\App;
use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Request;
use EduCloud\Core\Response;
use EduCloud\Core\Validator;
use EduCloud\Modules\Workspaces\WorkspaceController;

/** Lab Engine API: catalog, attempts (start, read, hints, answers, submit → 202, abandon). */
final class LabController
{
    private const TASK_KEY = 'regex:/^[a-z][a-z0-9_]{0,39}$/D';

    private LabService $service;

    public function __construct(private readonly App $app)
    {
        $this->service = new LabService($app);
    }

    public function catalog(Request $request): Response
    {
        return Response::json($this->service->catalog($this->ctx($request)), 200, '', ['request_id' => $request->requestId]);
    }

    public function start(Request $request): Response
    {
        $body = $request->json();
        $valid = static function () use ($body): array {
            $data = Validator::validate($body, [
                'lab_code' => ['required', 'string', 'regex:/^LAB-[0-9]{3}$/D'],
                'course_id' => ['nullable', 'ulid'],
            ]);
            return ['lab_code' => (string) $data['lab_code'], 'course_id' => isset($data['course_id']) ? (string) $data['course_id'] : null];
        };
        $result = $this->service->start($request, $this->ctx($request), $valid);
        return $result['created']
            ? Response::json($result['attempt'], 201, 'Laboratorio iniciado. Estamos preparando tu entorno…', ['request_id' => $request->requestId])
            : Response::json($result['attempt'], 200, 'Ya tenías este laboratorio en curso.', ['request_id' => $request->requestId]);
    }

    public function show(Request $request): Response
    {
        $attempt = $this->service->get($this->ctx($request), self::id($request));
        return Response::json($attempt, 200, '', ['request_id' => $request->requestId]);
    }

    public function hint(Request $request): Response
    {
        $body = $request->json();
        $valid = static function () use ($body): array {
            $data = Validator::validate($body, ['task_key' => ['required', 'string', self::TASK_KEY], 'hint_index' => ['required', 'int']]);
            return ['task_key' => (string) $data['task_key'], 'hint_index' => (int) $data['hint_index']];
        };
        $hint = $this->service->revealHint($request, $this->ctx($request), self::id($request), $valid);
        return Response::json($hint, 200, '', ['request_id' => $request->requestId]);
    }

    public function answer(Request $request): Response
    {
        $body = $request->json();
        $max = (int) $this->app->config->get('execution.limits.sql_max_length', 20000);
        $valid = static function () use ($body, $max): array {
            $data = Validator::validate($body, ['task_key' => ['required', 'string', self::TASK_KEY], 'sql' => ['required', 'string', "max:$max"]]);
            return ['task_key' => (string) $data['task_key'], 'sql' => (string) $data['sql']];
        };
        $answer = $this->service->saveAnswer($request, $this->ctx($request), self::id($request), $valid);
        return Response::json($answer, 200, 'Respuesta guardada.', ['request_id' => $request->requestId]);
    }

    public function submit(Request $request): Response
    {
        $body = $request->json();
        $valid = static function () use ($body): void {
            Validator::validate($body, []); // no client-supplied results, scores or flags are accepted
        };
        $attempt = $this->service->submit($request, $this->ctx($request), self::id($request), $valid);
        return Response::json($attempt, 202, 'Validando tu laboratorio…', ['request_id' => $request->requestId]);
    }

    public function abandon(Request $request): Response
    {
        $this->service->abandon($request, $this->ctx($request), self::id($request));
        return Response::noContent();
    }

    public static function id(Request $request): string
    {
        return WorkspaceController::id($request, 'attempt_id');
    }

    private function ctx(Request $request): TenantContext
    {
        /** @var TenantContext $ctx */
        $ctx = $request->attribute('tenant');
        return $ctx;
    }
}
