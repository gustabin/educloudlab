<?php

declare(strict_types=1);

namespace EduCloud\Modules\Tenants;

use EduCloud\Core\App;
use EduCloud\Core\Auth\AuthUser;
use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Exceptions\NotFoundException;
use EduCloud\Core\Request;
use EduCloud\Core\Response;
use EduCloud\Core\Ulid;
use EduCloud\Modules\Auth\SessionRepository;

/** /api/v1/tenants: the caller's organizations and switching the active one (browser sessions). */
final class TenantController
{
    public function __construct(private readonly App $app)
    {
    }

    public function index(Request $request): Response
    {
        /** @var AuthUser $user */
        $user = $request->attribute('user');
        /** @var TenantContext $active */
        $active = $request->attribute('tenant');
        $items = array_map(static fn (array $m): array => [
            'id' => $m['tenant_public_id'],
            'name' => $m['tenant_name'],
            'type' => $m['tenant_type'],
            'role' => $m['role'],
            'active' => $m['tenant_id'] === $active->tenantId,
        ], (new TenantRepository($this->app->db()))->listActiveMemberships($user->id));
        return Response::json($items, 200, '', ['request_id' => $request->requestId, 'total' => count($items)]);
    }

    /** Switches the session's active tenant. Only tenants with an active membership are reachable (else 404). */
    public function switch(Request $request): Response
    {
        /** @var AuthUser $user */
        $user = $request->attribute('user');
        $tenantPublicId = $request->param('tenant_id');
        $membership = Ulid::isValid($tenantPublicId)
            ? (new TenantRepository($this->app->db()))->findActiveMembershipByPublicId($user->id, $tenantPublicId)
            : null;
        if ($membership === null) {
            $attempted = Ulid::isValid($tenantPublicId) ? $tenantPublicId : null;
            $this->app->audit()->record($request, 'tenant.switch', 'denied', null, $user->id, 'tenant', $attempted);
            throw new NotFoundException('La organización no existe.');
        }
        /** @var array{id: int} $session */
        $session = $request->attribute('session');
        (new SessionRepository($this->app->db()))->setTenant((int) $session['id'], $membership['tenant_id']);
        $this->app->audit()->record($request, 'tenant.switch', 'success', $membership['tenant_id'], $user->id, 'tenant', $tenantPublicId);

        return Response::json([
            'id' => $membership['tenant_public_id'],
            'name' => $membership['tenant_name'],
            'type' => $membership['tenant_type'],
            'role' => $membership['role'],
            'active' => true,
        ], 200, 'Organización activa cambiada.', ['request_id' => $request->requestId]);
    }
}
