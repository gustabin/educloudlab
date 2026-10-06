<?php

declare(strict_types=1);

namespace EduCloud\Modules\Jobs;

/**
 * Turns a queued job into a runner request and applies the runner's result to the database.
 * $job rows include tenant_public_id and workspace_public_id (used to compute storage paths).
 */
interface JobHandler
{
    /**
     * @param array<string, mixed> $job
     * @return array{op: string, args: array<string, mixed>}|null null when no runner call is needed
     */
    public function prepare(array $job): ?array;

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $data runner "data" (empty when prepare() returned null)
     * @return array<string, mixed>|null summary stored in jobs.result_summary (no sensitive data)
     */
    public function succeeded(array $job, array $data): ?array;

    /** @param array<string, mixed> $job */
    public function failed(array $job, string $errorCode, string $safeMessage): void;
}
