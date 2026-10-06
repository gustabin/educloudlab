<?php

declare(strict_types=1);

namespace EduCloud\Modules\Jobs;

use EduCloud\Core\App;
use EduCloud\Modules\Auth\SessionRepository;
use Throwable;

/**
 * Periodic housekeeping (scripts/scheduler.php, every few minutes):
 *  - fail jobs whose dispatcher died;
 *  - release storage of deleted workspaces and finish their resources' lifecycle (deleting → deleted);
 *  - purge expired sessions, old rate-limit windows and stale temp/job files.
 */
final class Maintenance
{
    public function __construct(private readonly App $app)
    {
    }

    /** @return array<string, int> */
    public function run(): array
    {
        return [
            'stale_jobs_failed' => (new Dispatcher($this->app, 'scheduler'))->failStaleJobs(),
            'workspaces_released' => $this->releaseDeletedWorkspaces(),
            'sessions_purged' => (new SessionRepository($this->app->db()))
                ->purgeExpired((int) $this->app->config->get('security.session.idle_timeout')),
            'rate_limits_purged' => $this->app->rateLimiter()->purgeExpired(),
            'temp_files_removed' => $this->purgeOldFiles(['tmp', 'jobs'], 24 * 3600),
        ];
    }

    /** Deletes the storage tree of deleted workspaces that still have resources in 'deleting', then marks them deleted. */
    public function releaseDeletedWorkspaces(): int
    {
        $db = $this->app->db();
        $rows = $db->select(
            "SELECT DISTINCT w.id, w.tenant_id, w.public_id, t.public_id AS tenant_public_id
               FROM workspaces w
               JOIN tenants t ON t.id = w.tenant_id
               JOIN resources r ON r.tenant_id = w.tenant_id AND r.workspace_id = w.id AND r.status = 'deleting'
              WHERE w.status = 'deleted'
                AND NOT EXISTS (SELECT 1 FROM jobs j WHERE j.tenant_id = w.tenant_id AND j.workspace_id = w.id
                                                       AND j.status IN ('queued', 'running'))
              LIMIT 50"
        );
        $released = 0;
        foreach ($rows as $ws) {
            try {
                $storage = $this->app->storage();
                $storage->deleteTree($storage->workspaceDir((string) $ws['tenant_public_id'], (string) $ws['public_id']));
                $db->execute(
                    "UPDATE resources SET status = 'deleted' WHERE tenant_id = ? AND workspace_id = ? AND status = 'deleting'",
                    [(int) $ws['tenant_id'], (int) $ws['id']]
                );
                $released++;
            } catch (Throwable $e) {
                $this->app->logger->error('workspace_release_failed', ['workspace' => $ws['public_id'], 'exception' => get_class($e)]);
            }
        }
        return $released;
    }

    /** @param list<string> $dirs */
    private function purgeOldFiles(array $dirs, int $maxAgeSeconds): int
    {
        $removed = 0;
        foreach ($dirs as $dir) {
            $path = $this->app->storage()->root() . '/' . $dir;
            foreach (glob($path . '/*') ?: [] as $file) {
                if (is_file($file) && filemtime($file) < time() - $maxAgeSeconds) {
                    $this->app->storage()->delete($file);
                    $removed++;
                }
            }
        }
        return $removed;
    }
}
