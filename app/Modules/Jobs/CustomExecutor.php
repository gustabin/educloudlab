<?php

declare(strict_types=1);

namespace EduCloud\Modules\Jobs;

/**
 * A job handler that executes its request itself instead of through the Python runner (M8: notebooks run in a
 * Docker sandbox). It must honour the same contract as RunnerProcess::run(): a runner-style response
 * {ok, data | error_code + safe_message, stats}, the job timeout, and the heartbeat (true = cancel requested).
 */
interface CustomExecutor
{
    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $request what prepare() returned
     * @param callable(): bool     $heartbeat
     * @return array<string, mixed>
     */
    public function execute(array $job, array $request, callable $heartbeat): array;
}
