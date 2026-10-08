<?php

declare(strict_types=1);

namespace EduCloud\Modules\Observability;

use EduCloud\Core\App;
use EduCloud\Core\Exceptions\NotFoundException;
use EduCloud\Core\Request;
use EduCloud\Core\Response;

/**
 * GET /metrics (M12): Prometheus text exposition for an external monitoring system.
 *
 * Disabled (the application's regular 404) unless METRICS_TOKEN holds at least 32 characters; then
 * it requires `Authorization: Bearer <token>`, compared in constant time (401 otherwise). Everything is a gauge
 * computed on request: request_metrics is windowed and purged, so monotonic counters would be misleading.
 * Labels only carry route names from the registry, methods, status classes, component names and job types.
 */
final class PrometheusController
{
    public function __construct(private readonly App $app)
    {
    }

    public function show(Request $request): Response
    {
        $token = (string) $this->app->config->get('observability.metrics_token', '');
        if (strlen($token) < 32) {
            throw new NotFoundException();
        }
        $given = (string) $request->bearerToken();
        if (!hash_equals(hash('sha256', $token), hash('sha256', $given))) {
            return Response::error(401, 'UNAUTHENTICATED', 'Credenciales no válidas.', [], ['request_id' => $request->requestId]);
        }
        return new Response(200, $this->render(), [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function render(): string
    {
        $out = [];
        $metric = static function (string $name, string $help, array $samples) use (&$out): void {
            $out[] = "# HELP $name $help";
            $out[] = "# TYPE $name gauge";
            foreach ($samples as [$labels, $value]) {
                $out[] = $name . self::labels($labels) . ' ' . self::number($value);
            }
        };

        $metric('educloud_info', 'Build information (value is always 1).', [[['version' => (string) $this->app->config->get('app.version')], 1]]);

        $health = (new HealthService($this->app))->check();
        $up = [];
        $state = [];
        foreach ($health['components'] as $c) {
            if ($c['status'] === 'disabled') {
                continue;
            }
            $up[] = [['component' => $c['name']], $c['status'] === 'down' ? 0 : 1];
            $state[] = [['component' => $c['name']], ['ok' => 0, 'warning' => 1, 'down' => 2][$c['status']]];
        }
        $metric('educloud_component_up', 'Component reachable and alive (1) or down (0).', $up);
        $metric('educloud_component_status', 'Component status: 0 ok, 1 warning, 2 down.', $state);

        $byName = array_column($health['components'], null, 'name');
        if (isset($byName['queue'])) {
            $q = $byName['queue'];
            $metric('educloud_jobs', 'Jobs currently queued or running.', [
                [['status' => 'queued'], $q['queued']],
                [['status' => 'running'], $q['running']],
            ]);
            $metric('educloud_job_queue_oldest_seconds', 'Age of the oldest queued job (0 when the queue is empty).', [
                [[], $q['oldest_queued_s'] ?? 0],
            ]);
        }
        if (isset($byName['storage']) && $byName['storage']['free_bytes'] !== null) {
            $metric('educloud_storage_free_bytes', 'Free space on the storage volume.', [[[], $byName['storage']['free_bytes']]]);
        }

        if (($byName['database']['status'] ?? 'down') === 'ok') {
            $since = gmdate('Y-m-d H:i:s', time() - 300);
            $rows = (new MetricsRepository($this->app->db()))->byRoute($since);
            $requests = [];
            $errors = [];
            $buckets = array_fill_keys(array_keys(Histogram::BUCKETS), 0);
            $max = 0;
            foreach ($rows as $r) {
                $labels = ['route' => (string) $r['route'], 'method' => (string) $r['method']];
                $requests[] = [$labels, (int) $r['requests']];
                $errors[] = [$labels, (int) $r['server_errors']];
                foreach (array_keys($buckets) as $column) {
                    $buckets[$column] += (int) $r[$column];
                }
                $max = max($max, (int) $r['max_ms']);
            }
            $metric('educloud_http_requests_5m', 'HTTP requests in the last 5 minutes, by route name.', $requests);
            $metric('educloud_http_server_errors_5m', 'HTTP 5xx responses in the last 5 minutes, by route name.', $errors);
            $metric('educloud_http_latency_p95_ms_5m', 'Approximate p95 latency (histogram upper bound) over the last 5 minutes.', [
                [[], Histogram::percentile($buckets, 95, $max) ?? 0],
            ]);
        }
        return implode("\n", $out) . "\n";
    }

    /** @param array<string, string> $labels */
    private static function labels(array $labels): string
    {
        if ($labels === []) {
            return '';
        }
        $parts = [];
        foreach ($labels as $k => $v) {
            $parts[] = $k . '="' . str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $v) . '"';
        }
        return '{' . implode(',', $parts) . '}';
    }

    private static function number(int|float $value): string
    {
        return is_int($value) ? (string) $value : rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
    }
}
