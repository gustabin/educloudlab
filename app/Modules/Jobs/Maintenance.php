<?php

declare(strict_types=1);

namespace EduCloud\Modules\Jobs;

use EduCloud\Core\App;
use EduCloud\Modules\Auth\SessionRepository;
use EduCloud\Modules\Email\EmailService;
use EduCloud\Modules\Labs\AttemptRepository;
use EduCloud\Modules\Usage\UsageService;
use EduCloud\Modules\Workspaces\WorkspaceRepository;
use Throwable;

/**
 * Periodic housekeeping (scripts/scheduler.php, every few minutes):
 *  - fail jobs whose dispatcher died;
 *  - warn owners of lab workspaces expiring within 3 days (once per expiry), then expire them at their TTL;
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
            'lab_expiry_warnings' => $this->warnExpiringLabs(),
            'lab_attempts_expired' => $this->expireLabAttempts(),
            'workspaces_released' => $this->releaseDeletedWorkspaces(),
            'sessions_purged' => (new SessionRepository($this->app->db()))
                ->purgeExpired((int) $this->app->config->get('security.session.idle_timeout')),
            'rate_limits_purged' => $this->app->rateLimiter()->purgeExpired(),
            'temp_files_removed' => $this->purgeOldFiles(['tmp', 'jobs'], 24 * 3600),
            'query_results_removed' => $this->purgeOldFiles(['t/*/w/*/meta/results'], 24 * 3600),
            'storage_gauges_refreshed' => (new UsageService($this->app))->refreshAll(),
        ];
    }

    /** Emails the owners of lab workspaces that expire within 3 days (once; any activity resets the warning). */
    public function warnExpiringLabs(int $days = 3): int
    {
        $attempts = new AttemptRepository($this->app->db());
        $email = new EmailService($this->app->db());
        $sent = 0;
        foreach ($attempts->findExpiringUnwarned($days) as $row) {
            $this->app->db()->transaction(function () use ($attempts, $email, $row): void {
                $email->queue('lab_expiry_warning', (string) $row['email'], [
                    'display_name' => (string) $row['display_name'],
                    'lab' => (string) $row['lab_title'],
                    'date' => substr((string) $row['expires_at'], 0, 10),
                    'url' => $this->app->config->get('app.url') . '/app/lab-attempts/' . $row['public_id'],
                ], (int) $row['user_id'], (string) ($row['locale'] ?? 'es'));
                $attempts->markWarned((int) $row['tenant_id'], (int) $row['workspace_id']);
            });
            $sent++;
        }
        return $sent;
    }

    /** Soft-deletes expired lab workspaces (storage is released below) and marks unfinished attempts as expired. */
    public function expireLabAttempts(): int
    {
        $db = $this->app->db();
        $attempts = new AttemptRepository($db);
        $expired = 0;
        foreach ($attempts->findExpired() as $row) {
            $db->transaction(static function () use ($db, $attempts, $row): void {
                WorkspaceRepository::softDelete($db, (int) $row['tenant_id'], (int) $row['workspace_id']);
                (new JobRepository($db))->cancelQueuedForWorkspace((int) $row['tenant_id'], (int) $row['workspace_id']);
                $attempts->markExpired((int) $row['tenant_id'], (int) $row['id']);
            });
            $expired++;
        }
        return $expired;
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
