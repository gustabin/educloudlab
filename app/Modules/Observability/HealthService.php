<?php

declare(strict_types=1);

namespace EduCloud\Modules\Observability;

use EduCloud\Core\App;
use Throwable;

/**
 * Detailed health for platform admins (M11b). Every component reports status ok | warning | down | disabled and a
 * few safe numbers. Never exposes paths, versions, hostnames or error messages. The web server never talks to the
 * Docker daemon (ADR-010): notebook sandbox availability comes from the notebook worker's heartbeat.
 */
final class HealthService
{
    private const RANK = ['disabled' => 0, 'ok' => 0, 'warning' => 1, 'down' => 2];

    public function __construct(private readonly App $app)
    {
    }

    /** @return array{status: string, checked_at: string, components: list<array<string, mixed>>} */
    public function check(): array
    {
        $components = [$this->database()];
        if ($components[0]['status'] === 'ok') {
            $beats = (new HeartbeatRepository($this->app->db()))->all();
            $components[] = $this->queue();
            $components[] = $this->worker('dispatcher', $beats);
            $components[] = $this->notebookWorker($beats);
            $components[] = $this->worker('scheduler', $beats);
            $components[] = $this->mailer($beats);
        }
        $components[] = $this->storage();
        $components[] = $this->runner();

        $worst = 'ok';
        foreach ($components as $c) {
            if (self::RANK[$c['status']] > self::RANK[$worst]) {
                $worst = $c['status'];
            }
        }
        return ['status' => $worst, 'checked_at' => gmdate('Y-m-d\TH:i:s\Z'), 'components' => $components];
    }

    /** @return array<string, mixed> */
    private function database(): array
    {
        $start = microtime(true);
        try {
            $this->app->db()->scalar('SELECT 1');
            return ['name' => 'database', 'status' => 'ok', 'latency_ms' => (int) round((microtime(true) - $start) * 1000)];
        } catch (Throwable $e) {
            $this->app->logger->error('health_db_unavailable', ['exception' => get_class($e)]);
            return ['name' => 'database', 'status' => 'down'];
        }
    }

    /** @return array<string, mixed> */
    private function queue(): array
    {
        $row = $this->app->db()->selectOne(
            "SELECT SUM(status = 'queued') AS queued, SUM(status = 'running') AS running,
                    TIMESTAMPDIFF(SECOND, MIN(CASE WHEN status = 'queued' THEN queued_at END), UTC_TIMESTAMP(3)) AS oldest_s
               FROM jobs WHERE status IN ('queued', 'running')"
        ) ?? [];
        $oldest = $row['oldest_s'] === null ? null : (int) $row['oldest_s'];
        $limit = (int) $this->app->config->get('observability.queue_wait_warning_s', 60);
        return [
            'name' => 'queue',
            'status' => $oldest !== null && $oldest > $limit ? 'warning' : 'ok',
            'queued' => (int) ($row['queued'] ?? 0),
            'running' => (int) ($row['running'] ?? 0),
            'oldest_queued_s' => $oldest,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $beats
     * @return array<string, mixed>
     */
    private function worker(string $component, array $beats): array
    {
        $beat = $beats[$component] ?? null;
        if ($beat === null) {
            return ['name' => $component, 'status' => 'down', 'last_seen_s' => null];
        }
        $age = max(0, (int) $beat['age_s']);
        $stale = (int) $this->app->config->get("observability.stale_after_s.$component", 60);
        $details = json_decode((string) ($beat['details'] ?? 'null'), true);
        return [
            'name' => $component,
            'status' => $age > $stale ? 'down' : 'ok',
            'last_seen_s' => $age,
            'details' => Heartbeat::safe(is_array($details) ? $details : []),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $beats
     * @return array<string, mixed>
     */
    private function notebookWorker(array $beats): array
    {
        $config = $this->app->config;
        if ($config->get('execution.notebooks.mode') !== 'docker') {
            return ['name' => 'dispatcher_notebooks', 'status' => 'disabled'];
        }
        if (!(bool) $config->get('execution.notebooks.dedicated_worker', true)) {
            // The main dispatcher runs notebooks; its heartbeat carries the Docker flag.
            $main = $this->worker('dispatcher', $beats);
            $main['name'] = 'dispatcher_notebooks';
            if ($main['status'] === 'ok' && ($main['details']['docker'] ?? null) === false) {
                $main['status'] = 'warning';
            }
            return $main;
        }
        $worker = $this->worker('dispatcher_notebooks', $beats);
        if ($worker['status'] === 'ok' && ($worker['details']['docker'] ?? null) === false) {
            $worker['status'] = 'warning'; // running, but Docker or the sandbox image is unavailable
        }
        return $worker;
    }

    /**
     * @param array<string, array<string, mixed>> $beats
     * @return array<string, mixed>
     */
    private function mailer(array $beats): array
    {
        $worker = $this->worker('mailer', $beats);
        $row = $this->app->db()->selectOne(
            "SELECT COUNT(*) AS pending, TIMESTAMPDIFF(SECOND, MIN(send_after), UTC_TIMESTAMP(3)) AS oldest_s
               FROM email_outbox WHERE status = 'pending' AND send_after <= UTC_TIMESTAMP(3)"
        ) ?? [];
        $pending = (int) ($row['pending'] ?? 0);
        $oldest = $row['oldest_s'] === null ? null : (int) $row['oldest_s'];
        $worker['outbox_pending'] = $pending;
        $worker['outbox_oldest_s'] = $oldest;
        if ($worker['status'] === 'down' && $pending === 0) {
            // The mailer may run on a schedule (--once): with nothing to send, not running is fine.
            $worker['status'] = 'ok';
        } elseif ($oldest !== null && $oldest > (int) $this->app->config->get('observability.outbox_warning_s', 600)) {
            $worker['status'] = $worker['status'] === 'down' ? 'down' : 'warning';
        }
        return $worker;
    }

    /** @return array<string, mixed> */
    private function storage(): array
    {
        $root = $this->app->storage()->root();
        $writable = is_dir($root) && is_writable($root);
        $free = @disk_free_space($root);
        $free = $free === false ? null : (int) $free;
        $status = !$writable ? 'down'
            : ($free !== null && $free < (int) $this->app->config->get('observability.disk_free_warning_bytes') ? 'warning' : 'ok');
        return ['name' => 'storage', 'status' => $status, 'writable' => $writable, 'free_bytes' => $free];
    }

    /** @return array<string, mixed> */
    private function runner(): array
    {
        $ok = is_file((string) $this->app->config->get('execution.python')) && is_file((string) $this->app->config->get('execution.runner'));
        return ['name' => 'runner', 'status' => $ok ? 'ok' : 'down'];
    }
}
