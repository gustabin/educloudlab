<?php

declare(strict_types=1);

namespace EduCloud\Http\Middleware;

use EduCloud\Core\App;
use EduCloud\Core\Auth\AuthUser;
use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Exceptions\ForbiddenException;
use EduCloud\Core\Request;
use EduCloud\Core\Response;
use EduCloud\Modules\Auth\SessionRepository;
use EduCloud\Modules\Tenants\TenantRepository;

/**
 * Derives the active tenant from the session (or JWT 'tid' claim) and re-validates the membership on
 * every request. Client-supplied tenant ids are never trusted. If a session's tenant membership was
 * revoked, the session falls back to the user's default tenant.
 */
final class ResolveTenant implements Middleware
{
    public function __construct(private readonly App $app)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $user = $request->attribute('user');
        if (!$user instanceof AuthUser) {
            return $next($request);
        }

        $tenants = new TenantRepository($this->app->db());
        $membership = null;
        $session = $request->attribute('session');

        if ($request->attribute('auth_method') === 'jwt') {
            $membership = $tenants->findActiveMembershipByPublicId($user->id, (string) $request->attribute('jwt_tenant'));
        } elseif (is_array($session)) {
            $membership = $tenants->findActiveMembership($user->id, (int) $session['tenant_id']);
            if ($membership === null) {
                $membership = $tenants->findDefaultMembership($user->id);
                if ($membership !== null) {
                    (new SessionRepository($this->app->db()))->setTenant((int) $session['id'], $membership['tenant_id']);
                }
            }
        }

        if ($membership === null) {
            throw new ForbiddenException('No perteneces a ninguna organización activa.');
        }

        $context = new TenantContext(
            $membership['tenant_id'],
            $membership['tenant_public_id'],
            $membership['tenant_type'],
            $user->id,
            $membership['role'],
            $user->isPlatformAdmin,
        );
        return $next($request->withAttribute('tenant', $context)->withAttribute('tenant_name', $membership['tenant_name']));
    }
}
