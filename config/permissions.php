<?php

/**
 * Role → permission map (ADR-007: fixed roles in code).
 * Ownership ("own" vs tenant-wide) and course scoping are enforced in services; this map answers
 * "may this role perform this kind of action at all in the active tenant?".
 * Platform administrators (users.is_platform_admin) are granted every permission.
 *
 *   create      create workspaces/resources/datasets
 *   read        read own resources
 *   read_tenant read other members' resources in the tenant (instructors: course-scoped in services)
 *   update      update own resources
 *   delete      delete own resources
 *   execute     run jobs (queries, transforms, validations)
 *   assign      assign labs to courses
 *   review      review students' attempts (instructors: course-scoped in services)
 *   administer  manage members, roles and quotas of the tenant
 */

declare(strict_types=1);

return [
    'roles' => [
        'org_admin' => ['create', 'read', 'read_tenant', 'update', 'delete', 'execute', 'assign', 'review', 'administer'],
        'instructor' => ['create', 'read', 'read_tenant', 'update', 'delete', 'execute', 'assign', 'review'],
        'student' => ['create', 'read', 'update', 'delete', 'execute'],
        'read_only' => ['read'],
    ],
];
