<?php

declare(strict_types=1);

namespace EduCloud\Modules\Usage;

use EduCloud\Core\App;
use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Exceptions\QuotaExceededException;
use Throwable;

/**
 * Storage quota and usage metering (M11a).
 *
 * Storage used by a user in a tenant = raw files of their datasets + the lakehouse files of their workspaces
 * (bronze/silver/gold tables). It is computed from the source of truth (database + file sizes) whenever a quota is
 * checked; the usage_counters gauge is a cached copy for reporting. Monthly counters: jobs, job seconds, SQL queries.
 */
final class UsageService
{
    private UsageRepository $repo;

    public function __construct(private readonly App $app)
    {
        $this->repo = new UsageRepository($app->db());
    }

    /** @return array{raw_bytes: int, lakehouse_bytes: int, used_bytes: int, quota_bytes: int} */
    public function storage(int $tenantId, string $tenantPublicId, int $userId): array
    {
        $raw = $this->repo->rawBytes($tenantId, $userId);
        $lakehouse = 0;
        $storage = $this->app->storage();
        foreach ($this->repo->workspacePublicIds($tenantId, $userId) as $ws) {
            $file = $storage->lakehouseFile($tenantPublicId, $ws);
            if (is_file($file)) {
                $lakehouse += (int) filesize($file);
            }
        }
        return [
            'raw_bytes' => $raw,
            'lakehouse_bytes' => $lakehouse,
            'used_bytes' => $raw + $lakehouse,
            'quota_bytes' => (int) $this->app->config->get('quotas.storage_bytes_per_user'),
        ];
    }

    /**
     * Rejects an operation that would leave the caller over their storage quota. Operations whose final size is
     * unknown up front (ingest, transforms) pass $newBytes = 0 and require the user to still be under the quota.
     */
    public function assertRoom(TenantContext $ctx, int $newBytes, string $message = ''): void
    {
        $s = $this->storage($ctx->tenantId, $ctx->tenantPublicId, $ctx->userId);
        if ($s['used_bytes'] + $newBytes > $s['quota_bytes'] || ($newBytes === 0 && $s['used_bytes'] >= $s['quota_bytes'])) {
            $mb = (int) ($s['quota_bytes'] / 1048576);
            throw new QuotaExceededException($message !== ''
                ? $message
                : "Superarías tu espacio de almacenamiento ($mb MB, incluidas las tablas del lakehouse). Elimina datasets o tablas que no uses.");
        }
    }

    /** Refreshes the cached storage gauge (dispatcher after writes, scheduler periodically). Never throws. */
    public function refreshStorage(int $tenantId, string $tenantPublicId, int $userId): void
    {
        try {
            $this->repo->setGauge($tenantId, $userId, 'storage_bytes', $this->storage($tenantId, $tenantPublicId, $userId)['used_bytes']);
        } catch (Throwable $e) {
            $this->app->logger->error('usage_refresh_failed', ['exception' => get_class($e)]);
        }
    }

    /**
     * Meters a finished job for its user (monthly). Never throws: metering must not affect job processing.
     *
     * @param array<string, mixed> $job
     */
    public function recordJob(array $job, ?int $durationMs): void
    {
        try {
            $tenantId = (int) $job['tenant_id'];
            $userId = (int) $job['user_id'];
            $period = gmdate('Y-m');
            $this->repo->increment($tenantId, $userId, 'jobs_count', $period, 1);
            $this->repo->increment($tenantId, $userId, 'job_seconds', $period, (int) ceil(max(0, (int) $durationMs) / 1000));
            if ($job['type'] === 'sql_query') {
                $this->repo->increment($tenantId, $userId, 'queries_count', $period, 1);
            }
        } catch (Throwable $e) {
            $this->app->logger->error('usage_record_failed', ['exception' => get_class($e)]);
        }
    }

    /** @return array<string, mixed> the caller's usage in the active tenant */
    public function summary(TenantContext $ctx): array
    {
        $month = $this->repo->period($ctx->tenantId, $ctx->userId, gmdate('Y-m'));
        $quotas = $this->app->config;
        return [
            'storage' => $this->storage($ctx->tenantId, $ctx->tenantPublicId, $ctx->userId),
            'month' => [
                'period' => gmdate('Y-m'),
                'jobs' => $month['jobs_count'] ?? 0,
                'job_seconds' => $month['job_seconds'] ?? 0,
                'queries' => $month['queries_count'] ?? 0,
            ],
            'limits' => [
                'workspaces' => (int) $quotas->get('quotas.workspaces_per_user'),
                'active_jobs' => (int) $quotas->get('quotas.active_jobs_per_user'),
                'active_lab_attempts' => (int) $quotas->get('quotas.active_lab_attempts_per_user'),
                'upload_bytes' => (int) $quotas->get('quotas.upload_max_bytes'),
            ],
        ];
    }

    /** Scheduler: refreshes storage gauges of workspace owners. Returns how many were refreshed. */
    public function refreshAll(int $limit = 500): int
    {
        $owners = $this->repo->owners($limit);
        foreach ($owners as $o) {
            $this->refreshStorage($o['tenant_id'], $o['tenant_public_id'], $o['user_id']);
        }
        return count($owners);
    }
}
