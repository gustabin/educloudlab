<?php

declare(strict_types=1);

namespace EduCloud\Http\Middleware;

use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Config;
use EduCloud\Core\Exceptions\ForbiddenException;
use EduCloud\Core\Request;
use EduCloud\Core\Response;

/**
 * Route-level RBAC (ADR-007): the active tenant role must grant the route's 'permission'.
 * Object-level rules (ownership, course scope, cross-tenant 404) are enforced in services/repositories.
 */
final class Authorize implements Middleware
{
    public function __construct(private readonly Config $config, private readonly string $permission)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $context = $request->attribute('tenant');
        if (!$context instanceof TenantContext || !self::allows($this->config, $context, $this->permission)) {
            throw new ForbiddenException();
        }
        return $next($request);
    }

    public static function allows(Config $config, TenantContext $context, string $permission): bool
    {
        if ($context->isPlatformAdmin) {
            return true;
        }
        $granted = $config->get('permissions.roles.' . $context->role, []);
        return is_array($granted) && in_array($permission, $granted, true);
    }
}
