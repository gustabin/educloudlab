<?php

declare(strict_types=1);

namespace EduCloud\Modules\Admin;

use EduCloud\Core\App;
use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Exceptions\ApiException;
use EduCloud\Core\Exceptions\ForbiddenException;
use EduCloud\Core\Exceptions\NotFoundException;
use EduCloud\Core\Format;
use EduCloud\Core\Pagination;
use EduCloud\Core\Request;
use EduCloud\Core\Response;
use EduCloud\Core\Validator;
use EduCloud\Modules\Auth\SessionRepository;
use EduCloud\Modules\Auth\TokenRepository;
use EduCloud\Modules\Workspaces\WorkspaceController;

/** Admin monitor API (M11a): platform admins only (route permission 'platform_admin'). */
final class AdminController
{
    public const JOB_STATUSES = 'queued,running,succeeded,failed,cancelled,timed_out';
    public const JOB_TYPES = 'profile,ingest,cleanup,sql_query,transform,validate,pipeline_run,semantic_query,notebook_run';

    private AdminRepository $repo;

    public function __construct(private readonly App $app)
    {
        $this->repo = new AdminRepository($app->db());
    }

    public function overview(Request $request): Response
    {
        return Response::json($this->repo->overview(), 200, '', ['request_id' => $request->requestId]);
    }

    public function jobs(Request $request): Response
    {
        $q = Validator::validate($request->query, [
            'status' => ['in:' . self::JOB_STATUSES],
            'type' => ['in:' . self::JOB_TYPES],
            'page' => ['string'],
            'per_page' => ['string'],
        ]);
        $page = Pagination::fromQuery($request->query, 25, 100);
        $result = $this->repo->jobs(['status' => $q['status'] ?? null, 'type' => $q['type'] ?? null], $page->perPage, $page->offset());
        $items = array_map(static fn (array $r): array => [
            'id' => (string) $r['public_id'],
            'type' => (string) $r['type'],
            'status' => (string) $r['status'],
            'priority' => (int) $r['priority'],
            'attempts' => (int) $r['attempts'],
            'tenant' => ['id' => (string) $r['tenant_public_id'], 'name' => (string) $r['tenant_name']],
            'workspace_id' => $r['workspace_public_id'] === null ? null : (string) $r['workspace_public_id'],
            'user' => ['id' => (string) $r['user_public_id'], 'display_name' => (string) $r['display_name']],
            'error' => $r['error_code'] === null
                ? null
                : ['code' => (string) $r['error_code'], 'message' => (string) ($r['safe_message'] ?? '')],
            'duration_ms' => self::durationMs($r['result_summary']),
            'queued_at' => Format::isoUtc((string) $r['queued_at']),
            'started_at' => Format::isoUtc($r['started_at'] === null ? null : (string) $r['started_at']),
            'finished_at' => Format::isoUtc($r['finished_at'] === null ? null : (string) $r['finished_at']),
        ], $result['rows']);
        return Response::json($items, 200, '', $page->meta($result['total']) + ['request_id' => $request->requestId]);
    }

    public function audit(Request $request): Response
    {
        $q = Validator::validate($request->query, [
            'action' => ['string', 'max:60', 'regex:/^[a-z_.]*$/D'],
            'outcome' => ['in:success,failure,denied'],
            'page' => ['string'],
            'per_page' => ['string'],
        ]);
        $page = Pagination::fromQuery($request->query, 50, 100);
        $filters = ['action' => (string) ($q['action'] ?? ''), 'outcome' => $q['outcome'] ?? null];
        $result = $this->repo->audit($filters, $page->perPage, $page->offset());
        $items = array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'occurred_at' => Format::isoUtc((string) $r['occurred_at']),
            'action' => (string) $r['action'],
            'outcome' => (string) $r['outcome'],
            'tenant' => $r['tenant_public_id'] === null ? null : ['id' => (string) $r['tenant_public_id'], 'name' => (string) $r['tenant_name']],
            'actor' => $r['user_public_id'] === null ? null : ['id' => (string) $r['user_public_id'], 'display_name' => (string) $r['display_name']],
            'resource' => $r['resource_type'] === null ? null : ['type' => (string) $r['resource_type'], 'id' => $r['resource_public_id']],
            'request_id' => $r['request_id'],
            'meta' => Format::jsonColumn($r['meta']) ?: null,
        ], $result['rows']);
        return Response::json($items, 200, '', $page->meta($result['total']) + ['request_id' => $request->requestId]);
    }

    public function users(Request $request): Response
    {
        $q = Validator::validate($request->query, [
            'q' => ['string', 'max:100'],
            'status' => ['in:pending,active,locked,disabled'],
            'page' => ['string'],
            'per_page' => ['string'],
        ]);
        $page = Pagination::fromQuery($request->query, 25, 100);
        $result = $this->repo->users(['q' => trim((string) ($q['q'] ?? '')), 'status' => $q['status'] ?? null], $page->perPage, $page->offset());
        $items = array_map([self::class, 'presentUser'], $result['rows']);
        return Response::json($items, 200, '', $page->meta($result['total']) + ['request_id' => $request->requestId]);
    }

    /** Activates or disables an account. Disabling also ends every session and refresh token of the user. */
    public function updateUser(Request $request): Response
    {
        $publicId = WorkspaceController::id($request, 'user_id');
        $user = $this->repo->findUser($publicId);
        if ($user === null) {
            throw new NotFoundException('El usuario no existe.');
        }
        $status = (string) Validator::validate($request->json(), ['status' => ['required', 'in:active,disabled']])['status'];
        /** @var TenantContext $ctx */
        $ctx = $request->attribute('tenant');
        if ((int) $user['id'] === $ctx->userId || (int) $user['is_platform_admin'] === 1) {
            throw new ForbiddenException('No se puede cambiar el estado de un administrador de la plataforma desde aquí.');
        }
        if ($user['status'] === 'pending') {
            throw new ApiException(409, 'INVALID_STATE', 'La cuenta aún no confirmó su correo.');
        }
        $this->app->db()->transaction(function () use ($user, $status): void {
            $this->repo->setUserStatus((int) $user['id'], $status);
            if ($status === 'disabled') {
                (new SessionRepository($this->app->db()))->deleteAllForUser((int) $user['id']);
                (new TokenRepository($this->app->db()))->revokeAllForUser((int) $user['id']);
            }
        });
        $this->app->audit()->record($request, 'admin.user_status', 'success', null, $ctx->userId, 'user', $publicId, [
            'from' => $user['status'],
            'to' => $status,
        ]);
        $row = $this->repo->users(['id' => (string) $user['id']], 1, 0)['rows'][0];
        return Response::json(self::presentUser($row), 200, 'Estado actualizado.', ['request_id' => $request->requestId]);
    }

    private static function durationMs(mixed $summary): ?int
    {
        $value = Format::jsonColumn($summary)['duration_ms'] ?? null;
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    public static function presentUser(array $r): array
    {
        return [
            'id' => (string) $r['public_id'],
            'email' => (string) $r['email'],
            'display_name' => (string) $r['display_name'],
            'status' => (string) $r['status'],
            'is_platform_admin' => (bool) $r['is_platform_admin'],
            'email_verified_at' => Format::isoUtc($r['email_verified_at'] === null ? null : (string) $r['email_verified_at']),
            'last_login_at' => Format::isoUtc($r['last_login_at'] === null ? null : (string) $r['last_login_at']),
            'memberships' => (int) $r['memberships'],
            'storage_bytes' => (int) $r['storage_bytes'],
            'created_at' => Format::isoUtc((string) $r['created_at']),
        ];
    }
}
