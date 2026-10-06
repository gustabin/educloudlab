<?php

declare(strict_types=1);

namespace EduCloud\Modules\Workspaces;

use EduCloud\Core\App;
use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Exceptions\ValidationException;
use EduCloud\Core\Pagination;
use EduCloud\Core\Request;
use EduCloud\Core\Response;
use EduCloud\Core\Ulid;
use EduCloud\Core\Validator;

/** /api/v1/workspaces */
final class WorkspaceController
{
    /** Letters/digits first, then letters, digits, spaces, dot, underscore, hyphen. 2–80 characters. */
    public const NAME_RULES = ['string', 'min:2', 'max:80', 'regex:/^[\p{L}\p{N}][\p{L}\p{N} ._-]*$/uD'];
    /** Free text (any printable character; output is always HTML-escaped). Control characters are rejected. */
    public const DESCRIPTION_RULES = ['string', 'max:500', 'regex:/^[^\x00-\x08\x0B\x0C\x0E-\x1F\x7F]*$/uD'];

    private WorkspaceService $service;

    public function __construct(private readonly App $app)
    {
        $this->service = new WorkspaceService($app);
    }

    public function index(Request $request): Response
    {
        $q = Validator::validate($request->query, [
            'page' => ['string'],
            'per_page' => ['string'],
            'q' => ['string', 'max:80'],
            'sort' => ['in:' . implode(',', array_keys(WorkspaceRepository::SORTS))],
            'dir' => ['in:asc,desc'],
        ]);
        $result = $this->service->list(
            $this->ctx($request),
            Pagination::fromQuery($request->query),
            (string) ($q['q'] ?? ''),
            (string) ($q['sort'] ?? 'created_at'),
            (string) ($q['dir'] ?? 'desc'),
        );
        return Response::json($result['items'], 200, '', $result['meta'] + ['request_id' => $request->requestId]);
    }

    public function store(Request $request): Response
    {
        $data = Validator::validate($request->json(), [
            'name' => ['required', ...self::NAME_RULES],
            'description' => self::DESCRIPTION_RULES,
        ]);
        $ws = $this->service->create($request, $this->ctx($request), $data['name'], $data['description'] ?? null);
        return Response::json($ws, 201, 'Workspace creado.', ['request_id' => $request->requestId]);
    }

    public function show(Request $request): Response
    {
        $row = $this->service->findOrFail($this->ctx($request), self::id($request));
        return Response::json(WorkspaceService::present($row), 200, '', ['request_id' => $request->requestId]);
    }

    public function update(Request $request): Response
    {
        $body = $request->json();
        $data = Validator::validate($body, ['name' => self::NAME_RULES, 'description' => ['nullable', ...self::DESCRIPTION_RULES]]);
        if (array_key_exists('description', $body) && $body['description'] === null) {
            $data['description'] = null;
        }
        if ($data === [] && !array_key_exists('description', $body)) {
            throw new ValidationException([['code' => 'empty', 'message' => 'Indica al menos un campo para actualizar.']]);
        }
        $ws = $this->service->update($request, $this->ctx($request), self::id($request), $data);
        return Response::json($ws, 200, 'Workspace actualizado.', ['request_id' => $request->requestId]);
    }

    public function destroy(Request $request): Response
    {
        $this->service->delete($request, $this->ctx($request), self::id($request));
        return Response::noContent();
    }

    private function ctx(Request $request): TenantContext
    {
        /** @var TenantContext $ctx */
        $ctx = $request->attribute('tenant');
        return $ctx;
    }

    /** Malformed ids are indistinguishable from missing ones (404). */
    public static function id(Request $request, string $param = 'workspace_id'): string
    {
        $id = $request->param($param);
        if (!Ulid::isValid($id)) {
            throw new \EduCloud\Core\Exceptions\NotFoundException();
        }
        return $id;
    }
}
