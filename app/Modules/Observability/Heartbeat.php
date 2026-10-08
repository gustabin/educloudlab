<?php

declare(strict_types=1);

namespace EduCloud\Modules\Observability;

use EduCloud\Core\App;
use Throwable;

/**
 * Throttled liveness signal for a background process (dispatchers, mailer, scheduler). Never throws: a failing
 * heartbeat must not stop the worker. Details are reduced to allowlisted keys with int/bool values.
 */
final class Heartbeat
{
    public const COMPONENTS = ['dispatcher', 'dispatcher_notebooks', 'mailer', 'scheduler'];
    private const DETAIL_KEYS = [
        'processed', 'sent', 'failed', 'docker', 'stale_jobs_failed', 'workspaces_released', 'sessions_purged',
        'metrics_purged', 'logs_removed', 'notebook_containers_reaped',
    ];

    private float $lastBeat = 0.0;
    private bool $warned = false;
    private readonly string $startedAt;
    private readonly string $instance;

    public function __construct(private readonly App $app, private readonly string $component)
    {
        if (!in_array($component, self::COMPONENTS, true)) {
            throw new \InvalidArgumentException('Unknown component');
        }
        $this->startedAt = gmdate('Y-m-d H:i:s');
        $this->instance = substr((string) gethostname(), 0, 60) . ':' . getmypid();
    }

    /** @param array<string, mixed> $details */
    public function tick(array $details = [], bool $force = false): void
    {
        $interval = (int) $this->app->config->get('observability.heartbeat_interval_s', 15);
        if (!$force && microtime(true) - $this->lastBeat < $interval) {
            return;
        }
        $this->lastBeat = microtime(true);
        try {
            (new HeartbeatRepository($this->app->db()))->beat($this->component, $this->instance, $this->startedAt, self::safe($details));
        } catch (Throwable $e) {
            if (!$this->warned) {
                $this->warned = true;
                $this->app->logger->warning('heartbeat_failed', ['component' => $this->component, 'exception' => get_class($e)]);
            }
        }
    }

    /**
     * @param array<string, mixed> $details
     * @return array<string, int|bool|null>
     */
    public static function safe(array $details): array
    {
        $out = [];
        foreach (self::DETAIL_KEYS as $key) {
            if (!array_key_exists($key, $details)) {
                continue;
            }
            $value = $details[$key];
            $out[$key] = is_bool($value) || $value === null ? $value : (is_numeric($value) ? (int) $value : null);
        }
        return $out;
    }
}
