<?php

/**
 * Per-user / per-workspace limits (plan §25). Exceeding one returns 409 QUOTA_EXCEEDED.
 * Execution-related limits (jobs, storage bytes, timeouts) are added with the execution plane (M4/M5).
 */

declare(strict_types=1);

return [
    'workspaces_per_user' => 5,          // active workspaces owned by one user in one tenant
    'resources_per_workspace' => 20,     // non-deleted resources in one workspace
    'tags_per_resource' => 10,
];
