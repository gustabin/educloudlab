<?php

declare(strict_types=1);

namespace EduCloud\Core\Auth;

/**
 * Tenant scope of the current request, derived server-side from the authenticated user's membership
 * (never from client input). Tenant-owned repository methods take this as their first argument.
 */
final class TenantContext
{
    public function __construct(
        public readonly int $tenantId,
        public readonly string $tenantPublicId,
        public readonly string $tenantType,
        public readonly int $userId,
        public readonly string $role,
        public readonly bool $isPlatformAdmin,
    ) {
    }
}
