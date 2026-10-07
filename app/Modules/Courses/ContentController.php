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

/** Course content API (M10b): modules, lessons (CommonMark), ordering, publishing and lesson completion. */
final class ContentController
{
    public const BODY_MAX = 50000;

    private ContentService $service;

    public function __construct(private readonly App $app)
    {
        $this->service = new ContentService($app);
    }

    public function tree(Request $request): Response
    {
        $modules = $this->service->tree($this->ctx($request), self::id($request, 'course_id'));
        return Response::json($modules, 200, '', ['request_id' => $request->requestId, 'total' => count($modules)]);
    }

    public function createModule(Request $request): Response
    {
        $data = Validator::validate($request->json(), [
            'title' => ['required', 'string', 'min:3', 'max:150'],
            'summary' => ['nullable', 'string', 'max:500'],
        ]);
        $module = $this->service->createModule($request, $this->ctx($request), self::id($request, 'course_id'), [
            'title' => trim((string) $data['title']),
            'summary' => self::text($data['summary'] ?? null),
        ]);
        return Response::json($module, 201, 'Módulo creado.', ['request_id' => $request->requestId]);
    }

    public function updateModule(Request $request): Response
    {
        $raw = $request->json();
        $data = self::withNulls($raw, ['summary']) + Validator::validate($raw, [
            'title' => ['string', 'min:3', 'max:150'],
            'summary' => ['nullable', 'string', 'max:500'],
            'status' => ['in:draft,published'],
            'position' => ['int'],
        ]);
        $input = self::common($data);
        if (array_key_exists('summary', $data)) {
            $input['summary'] = self::text($data['summary']);
        }
        $module = $this->service->updateModule($request, $this->ctx($request), self::id($request, 'module_id'), $input);
        return Response::json($module, 200, 'Módulo guardado.', ['request_id' => $request->requestId]);
    }

    public function deleteModule(Request $request): Response
    {
        $this->service->deleteModule($request, $this->ctx($request), self::id($request, 'module_id'));
        return Response::noContent();
    }

    public function showLesson(Request $request): Response
    {
        $lesson = $this->service->lesson($this->ctx($request), self::id($request, 'lesson_id'));
        return Response::json($lesson, 200, '', ['request_id' => $request->requestId]);
    }

    public function createLesson(Request $request): Response
    {
        $data = Validator::validate($request->json(), self::lessonRules(true));
        $input = self::lessonInput($data);
        $lesson = $this->service->createLesson($request, $this->ctx($request), self::id($request, 'module_id'), $input + [
            'estimated_minutes' => null, 'due_at' => null, 'lab_code' => null,
        ]);
        return Response::json($lesson, 201, 'Lección creada.', ['request_id' => $request->requestId]);
    }

    public function updateLesson(Request $request): Response
    {
        $raw = $request->json();
        $rules = self::lessonRules(false) + ['status' => ['in:draft,published'], 'position' => ['int']];
        $data = self::withNulls($raw, ['estimated_minutes', 'due_at', 'lab_code']) + Validator::validate($raw, $rules);
        $input = self::lessonInput($data) + self::common($data);
        $lesson = $this->service->updateLesson($request, $this->ctx($request), self::id($request, 'lesson_id'), $input);
        return Response::json($lesson, 200, 'Lección guardada.', ['request_id' => $request->requestId]);
    }

    public function deleteLesson(Request $request): Response
    {
        $this->service->deleteLesson($request, $this->ctx($request), self::id($request, 'lesson_id'));
        return Response::noContent();
    }

    public function complete(Request $request): Response
    {
        Validator::validate($request->json(), []);
        $lesson = $this->service->setCompleted($request, $this->ctx($request), self::id($request, 'lesson_id'), true);
        return Response::json($lesson, 200, 'Lección completada.', ['request_id' => $request->requestId]);
    }

    public function uncomplete(Request $request): Response
    {
        $lesson = $this->service->setCompleted($request, $this->ctx($request), self::id($request, 'lesson_id'), false);
        return Response::json($lesson, 200, '', ['request_id' => $request->requestId]);
    }

    /**
     * The Validator drops null values; for optional fields an explicit null (or empty string) means "clear it".
     *
     * @param array<string, mixed> $raw
     * @param list<string>         $fields
     * @return array<string, null>
     */
    private static function withNulls(array $raw, array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $raw) && ($raw[$field] === null || $raw[$field] === '')) {
                $out[$field] = null;
            }
        }
        return $out;
    }

    /** @return array<string, list<string>> */
    private static function lessonRules(bool $create): array
    {
        return [
            'title' => [...($create ? ['required'] : []), 'string', 'min:3', 'max:150'],
            'body_md' => [...($create ? ['required'] : []), 'string', 'max:' . self::BODY_MAX],
            'estimated_minutes' => ['nullable', 'int'],
            'due_at' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}-\d{2}$/D'],
            'lab_code' => ['nullable', 'string', 'regex:/^LAB-[0-9]{3}$/D'],
        ];
    }

    /**
     * @param array<string, mixed> $data validated
     * @return array<string, mixed>
     */
    private static function lessonInput(array $data): array
    {
        $out = [];
        if (isset($data['title'])) {
            $out['title'] = trim((string) $data['title']);
        }
        if (isset($data['body_md'])) {
            $body = str_replace("\r\n", "\n", (string) $data['body_md']);
            if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $body) === 1) {
                throw new ValidationException([['field' => 'body_md', 'code' => 'regex', 'message' => 'El texto contiene caracteres de control.']]);
            }
            $out['body_md'] = $body;
        }
        if (array_key_exists('estimated_minutes', $data)) {
            $minutes = $data['estimated_minutes'];
            if ($minutes !== null && ($minutes < 1 || $minutes > 600)) {
                throw new ValidationException([['field' => 'estimated_minutes', 'code' => 'range', 'message' => 'Entre 1 y 600 minutos.']]);
            }
            $out['estimated_minutes'] = $minutes === null ? null : (int) $minutes;
        }
        if (array_key_exists('due_at', $data)) {
            $out['due_at'] = self::dueAt($data['due_at']);
        }
        if (array_key_exists('lab_code', $data)) {
            $out['lab_code'] = $data['lab_code'] === null ? null : (string) $data['lab_code'];
        }
        return $out;
    }

    /**
     * Status/position shared by modules and lessons.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function common(array $data): array
    {
        $out = [];
        if (isset($data['title'])) {
            $out['title'] = trim((string) $data['title']);
        }
        if (isset($data['status'])) {
            $out['status'] = (string) $data['status'];
        }
        if (isset($data['position'])) {
            if ($data['position'] < 1 || $data['position'] > 1000) {
                throw new ValidationException([['field' => 'position', 'code' => 'range', 'message' => 'Posición no válida.']]);
            }
            $out['position'] = (int) $data['position'];
        }
        if ($out === [] && $data === []) {
            throw new ValidationException([['field' => 'title', 'code' => 'required', 'message' => 'Indica al menos un cambio.']]);
        }
        return $out;
    }

    private static function dueAt(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $value, new \DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new ValidationException([['field' => 'due_at', 'code' => 'date', 'message' => 'Fecha no válida.']]);
        }
        return $date->format('Y-m-d') . ' 23:59:59.000';
    }

    private static function text(mixed $value): ?string
    {
        $text = $value === null ? '' : trim((string) $value);
        return $text === '' ? null : $text;
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
