<?php

declare(strict_types=1);

namespace EduCloud\Core\Auth;

use EduCloud\Core\Config;
use EduCloud\Http\Middleware\Authorize;

/**
 * Object-level authorization inside the active tenant (route-level RBAC is done by Authorize).
 *
 *  - Owners can see and change their own objects (subject to their role's permissions).
 *  - Tenant-wide visibility/administration: org_admin and platform admins.
 *    Instructors' visibility of students' work is course-scoped (M10a): see AttemptRepository::findVisible()
 *    and CourseService (course staff = owner or active instructor enrollment).
 *
 * Callers must return 404 (not 403) when an object is not visible, so existence is not leaked.
 */
final class Policy
{
    public function __construct(private readonly Config $config)
    {
    }

    public function seesWholeTenant(TenantContext $ctx): bool
    {
        return $ctx->isPlatformAdmin || $ctx->role === 'org_admin';
    }

    public function canView(TenantContext $ctx, int $ownerUserId): bool
    {
        return $ownerUserId === $ctx->userId || $this->seesWholeTenant($ctx);
    }

    /** $permission: update | delete | create | execute */
    public function canModify(TenantContext $ctx, int $ownerUserId, string $permission): bool
    {
        if (!Authorize::allows($this->config, $ctx, $permission)) {
            return false;
        }
        return $ownerUserId === $ctx->userId || $this->seesWholeTenant($ctx);
    }
}
