<?php

/**
 * Per-user / per-workspace limits (plan §25). Exceeding one returns 409 QUOTA_EXCEEDED.
 * Execution-related limits (jobs, storage bytes, timeouts) are added with the execution plane (M4/M5).
 */

declare(strict_types=1);

return [
    'workspaces_per_user' => 5,          // active workspaces owned by one user in one tenant
    'resources_per_workspace' => 20,     // non-deleted infrastructure resources (storage, lakehouse) in one workspace
    'tags_per_resource' => 10,
    'upload_max_bytes' => 20 * 1024 * 1024,   // one CSV upload
    'storage_bytes_per_user' => 200 * 1024 * 1024, // raw files of non-deleted datasets, per user per tenant
    'datasets_per_workspace' => 50,
    'active_jobs_per_user' => 3,               // queued + running (lab setup jobs count too; they finish in seconds)
    'active_lab_attempts_per_user' => 3,       // in-progress lab attempts (each has its own lab workspace)
    'containers_per_storage' => 20,            // object storage (M7)
    'objects_per_container' => 200,
];
