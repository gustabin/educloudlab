<?php

declare(strict_types=1);

namespace EduCloud\Modules\Observability;

use EduCloud\Core\App;
use Throwable;

/**
 * Records one HTTP request (M11b), called by the Kernel after the response is built. Keyed by route name (never
 * the URL, so ids never reach the table). Never throws: a metrics failure must not break the response.
 */
final class RequestMetrics
{
    private static bool $warned = false;

    public static function record(App $app, string $route, string $method, int $status, float $startedAt): void
    {
        $ms = max(0, (int) round((microtime(true) - $startedAt) * 1000));
        try {
            if ($ms > (int) $app->config->get('observability.slow_request_ms', 2000)) {
                $app->logger->warning('slow_request', ['route' => $route, 'method' => $method, 'status' => $status, 'duration_ms' => $ms]);
            }
            if (!(bool) $app->config->get('observability.request_metrics', true)) {
                return;
            }
            $method = in_array($method, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true) ? $method : 'OTHER';
            $route = preg_match('/^[a-z_][a-z0-9_.-]{0,79}$/D', $route) === 1 ? $route : '_invalid';
            (new MetricsRepository($app->db()))->record(gmdate('Y-m-d H:i:00'), $route, $method, max(1, min(5, intdiv($status, 100))), $ms);
        } catch (Throwable $e) {
            if (!self::$warned) {
                self::$warned = true;
                $app->logger->warning('request_metrics_failed', ['exception' => get_class($e)]);
            }
        }
    }
}
