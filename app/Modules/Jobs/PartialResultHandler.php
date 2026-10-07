<?php

declare(strict_types=1);

namespace EduCloud\Modules\Jobs;

/**
 * Handlers whose runner op reports partial data on failure (e.g. the pipeline steps executed before the failing one).
 * partial() is called before failed().
 */
interface PartialResultHandler
{
    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $data the runner's partial report
     */
    public function partial(array $job, array $data): void;
}
