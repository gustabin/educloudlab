<?php

declare(strict_types=1);

namespace EduCloud\Modules\Courses;

use EduCloud\Core\App;
use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Exceptions\ValidationException;
use EduCloud\Core\Request;
use EduCloud\Core\Response;
use EduCloud\Core\Validator;
use EduCloud\Modules\Workspaces\WorkspaceController;

/** Courses API (M10a): CRUD-lite, join codes, lab assignments, join and the progress grid. */
final class CourseController
{
    public const CODE_RULE = 'regex:/^[A-Z0-9][A-Z0-9-]{1,29}$/D';

    private CourseService $service;

    public function __construct(private readonly App $app)
    {
        $this->service = new CourseService($app);
    }

    public function index(Request $request): Response
    {
        $items = $this->service->list($this->ctx($request));
        return Response::json($items, 200, '', ['request_id' => $request->requestId, 'total' => count($items)]);
    }

    public function show(Request $request): Response
    {
        return Response::json($this->service->get($this->ctx($request), self::id($request)), 200, '', ['request_id' => $request->requestId]);
    }

    public function create(Request $request): Response
    {
        $body = $request->json();
        $valid = static function () use ($body): array {
            $data = Validator::validate($body, [
                'code' => ['required', 'string', self::CODE_RULE],
                'title' => ['required', 'string', 'min:3', 'max:150'],
                'description' => ['nullable', 'string', 'max:2000'],
            ]);
            return [
                'code' => (string) $data['code'],
                'title' => trim((string) $data['title']),
                'description' => self::text($data['description'] ?? null),
            ];
        };
        $course = $this->service->create($request, $this->ctx($request), $valid);
        return Response::json($course, 201, 'Curso creado.', ['request_id' => $request->requestId]);
    }

    public function update(Request $request): Response
    {
        $body = $request->json();
        $valid = static function () use ($body): array {
            $data = Validator::validate($body, [
                'title' => ['string', 'min:3', 'max:150'],
                'description' => ['nullable', 'string', 'max:2000'],
                'status' => ['in:draft,published,archived'],
                'visibility' => ['in:private,public'],
            ]);
            if ($data === []) {
                throw new ValidationException([['field' => 'title', 'code' => 'required', 'message' => 'Indica al menos un cambio.']]);
            }
            if (array_key_exists('description', $data)) {
                $data['description'] = self::text($data['description']);
            }
            if (isset($data['title'])) {
                $data['title'] = trim((string) $data['title']);
            }
            return $data;
        };
        $course = $this->service->update($request, $this->ctx($request), self::id($request), $valid);
        return Response::json($course, 200, 'Curso actualizado.', ['request_id' => $request->requestId]);
    }

    public function rotateJoinCode(Request $request): Response
    {
        $code = $this->service->rotateJoinCode($request, $this->ctx($request), self::id($request));
        return Response::json(['join_code' => $code], 201, 'Nuevo código generado. Compártelo con tus estudiantes: no se volverá a mostrar.', [
            'request_id' => $request->requestId,
        ]);
    }

    public function disableJoinCode(Request $request): Response
    {
        $this->service->disableJoinCode($request, $this->ctx($request), self::id($request));
        return Response::noContent();
    }

    public function assignLab(Request $request): Response
    {
        $body = $request->json();
        $valid = static function () use ($body): array {
            $data = Validator::validate($body, [
                'lab_code' => ['required', 'string', 'regex:/^LAB-[0-9]{3}$/D'],
                'required' => ['bool'],
                'due_at' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}-\d{2}$/D'],
            ]);
            $due = null;
            if (isset($data['due_at'])) {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $data['due_at'], new \DateTimeZone('UTC'));
                if ($date === false || $date->format('Y-m-d') !== $data['due_at']) {
                    throw new ValidationException([['field' => 'due_at', 'code' => 'date', 'message' => 'Fecha no válida.']]);
                }
                $due = $date->format('Y-m-d') . ' 23:59:59.000';
            }
            return ['lab_code' => (string) $data['lab_code'], 'required' => (bool) ($data['required'] ?? true), 'due_at' => $due];
        };
        $course = $this->service->assignLab($request, $this->ctx($request), self::id($request), $valid);
        return Response::json($course, 201, 'Laboratorio asignado.', ['request_id' => $request->requestId]);
    }

    public function unassignLab(Request $request): Response
    {
        $this->service->unassignLab($request, $this->ctx($request), self::id($request), $request->param('lab_code'));
        return Response::noContent();
    }

    public function join(Request $request): Response
    {
        $body = $request->json();
        $valid = static fn (): string => (string) Validator::validate($body, ['code' => ['required', 'string', 'max:20']])['code'];
        $result = $this->service->join($request, $this->ctx($request)->userId, $valid);
        $message = $result['already_enrolled'] ? 'Ya estabas inscrito en este curso.' : 'Te has inscrito en el curso.';
        return Response::json($result, 200, $message, ['request_id' => $request->requestId]);
    }

    public function progress(Request $request): Response
    {
        $progress = $this->service->progress($request, $this->ctx($request), self::id($request));
        return Response::json($progress, 200, '', ['request_id' => $request->requestId]);
    }

    public static function id(Request $request): string
    {
        return WorkspaceController::id($request, 'course_id');
    }

    private static function text(mixed $value): ?string
    {
        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }

    private function ctx(Request $request): TenantContext
    {
        /** @var TenantContext $ctx */
        $ctx = $request->attribute('tenant');
        return $ctx;
    }
}
