<?php

declare(strict_types=1);

namespace EduCloud\Modules\Observability;

use EduCloud\Core\App;

/** Builds the admin metrics report (M11b) from request_metrics and the jobs table. */
final class MetricsService
{
    /** window => [seconds, slot minutes for the time series] */
    public const WINDOWS = ['1h' => [3600, 5], '24h' => [86400, 60], '7d' => [604800, 360]];
    private const TOP_ROUTES = 10;

    public function __construct(private readonly App $app)
    {
    }

    /** @return array<string, mixed> */
    public function report(string $window): array
    {
        [$seconds, $slot] = self::WINDOWS[$window];
        $since = gmdate('Y-m-d H:i:s', time() - $seconds);
        $repo = new MetricsRepository($this->app->db());
        return [
            'window' => $window,
            'since' => gmdate('Y-m-d\TH:i:s\Z', time() - $seconds),
            'http' => $this->http($repo->byRoute($since), $repo->series($since, $slot), $slot),
            'jobs' => $this->jobs($repo->jobs($since)),
        ];
    }

    /**
     * @param list<array<string, mixed>> $routes
     * @param list<array<string, mixed>> $series
     * @return array<string, mixed>
     */
    private function http(array $routes, array $series, int $slot): array
    {
        $total = ['requests' => 0, 'client_errors' => 0, 'server_errors' => 0, 'total_ms' => 0, 'max_ms' => 0];
        $buckets = array_fill_keys(array_keys(Histogram::BUCKETS), 0);
        $rows = [];
        foreach ($routes as $r) {
            $counts = [];
            foreach (array_keys(Histogram::BUCKETS) as $column) {
                $counts[$column] = (int) $r[$column];
                $buckets[$column] += (int) $r[$column];
            }
            $requests = (int) $r['requests'];
            foreach (['requests', 'client_errors', 'server_errors', 'total_ms'] as $key) {
                $total[$key] += (int) $r[$key];
            }
            $total['max_ms'] = max($total['max_ms'], (int) $r['max_ms']);
            $rows[] = [
                'route' => (string) $r['route'],
                'method' => (string) $r['method'],
                'requests' => $requests,
                'client_errors' => (int) $r['client_errors'],
                'server_errors' => (int) $r['server_errors'],
                'avg_ms' => $requests > 0 ? (int) round((int) $r['total_ms'] / $requests) : null,
                'p95_ms' => Histogram::percentile($counts, 95, (int) $r['max_ms']),
                'max_ms' => (int) $r['max_ms'],
            ];
        }
        $slowest = $rows;
        usort($slowest, static fn (array $a, array $b): int => [$b['p95_ms'], $b['requests']] <=> [$a['p95_ms'], $a['requests']]);
        $busiest = $rows;
        usort($busiest, static fn (array $a, array $b): int => [$b['requests'], $a['route']] <=> [$a['requests'], $b['route']]);
        $failing = array_values(array_filter($rows, static fn (array $r): bool => $r['server_errors'] > 0));
        usort($failing, static fn (array $a, array $b): int => $b['server_errors'] <=> $a['server_errors']);

        $requests = $total['requests'];
        return [
            'requests' => $requests,
            'client_error_rate' => $requests > 0 ? round($total['client_errors'] / $requests, 4) : null,
            'server_error_rate' => $requests > 0 ? round($total['server_errors'] / $requests, 4) : null,
            'server_errors' => $total['server_errors'],
            'avg_ms' => $requests > 0 ? (int) round($total['total_ms'] / $requests) : null,
            'p50_ms' => Histogram::percentile($buckets, 50, $total['max_ms']),
            'p95_ms' => Histogram::percentile($buckets, 95, $total['max_ms']),
            'p99_ms' => Histogram::percentile($buckets, 99, $total['max_ms']),
            'slowest_routes' => array_slice($slowest, 0, self::TOP_ROUTES),
            'busiest_routes' => array_slice($busiest, 0, self::TOP_ROUTES),
            'failing_routes' => array_slice($failing, 0, self::TOP_ROUTES),
            'series_slot_minutes' => $slot,
            'series' => array_map(static fn (array $s): array => [
                'at' => gmdate('Y-m-d\TH:i:s\Z', (int) $s['slot']),
                'requests' => (int) $s['requests'],
                'client_errors' => (int) $s['client_errors'],
                'server_errors' => (int) $s['server_errors'],
            ], $series),
        ];
    }

    /**
     * @param list<array<string, mixed>> $jobs
     * @return list<array<string, mixed>>
     */
    private function jobs(array $jobs): array
    {
        /** @var array<string, array{count: int, active: int, failed: int, timed_out: int, cancelled: int, wait: list<int>, run: list<int>}> $byType */
        $byType = [];
        foreach ($jobs as $j) {
            $type = (string) $j['type'];
            $t = $byType[$type] ?? ['count' => 0, 'active' => 0, 'failed' => 0, 'timed_out' => 0, 'cancelled' => 0, 'wait' => [], 'run' => []];
            $t['count']++;
            $status = (string) $j['status'];
            if ($status === 'queued' || $status === 'running') {
                $t['active']++;
            } elseif ($status === 'failed' || $status === 'timed_out' || $status === 'cancelled') {
                $t[$status]++;
            }
            $queued = self::epochMs($j['queued_at']);
            $started = self::epochMs($j['started_at']);
            $finished = self::epochMs($j['finished_at']);
            if ($queued !== null && $started !== null) {
                $t['wait'][] = (int) round($started - $queued);
                if ($finished !== null) {
                    $t['run'][] = (int) round($finished - $started);
                }
            }
            $byType[$type] = $t;
        }
        ksort($byType);
        $out = [];
        foreach ($byType as $type => $t) {
            $finishedCount = $t['count'] - $t['active'];
            $out[] = [
                'type' => $type,
                'count' => $t['count'],
                'active' => $t['active'],
                'failed' => $t['failed'],
                'timed_out' => $t['timed_out'],
                'cancelled' => $t['cancelled'],
                'failure_rate' => $finishedCount > 0 ? round(($t['failed'] + $t['timed_out']) / $finishedCount, 4) : null,
                'wait_p50_ms' => Histogram::exact($t['wait'], 50),
                'wait_p95_ms' => Histogram::exact($t['wait'], 95),
                'run_p50_ms' => Histogram::exact($t['run'], 50),
                'run_p95_ms' => Histogram::exact($t['run'], 95),
            ];
        }
        return $out;
    }

    /** UTC DATETIME(3) string → milliseconds since the epoch (null when absent). */
    private static function epochMs(mixed $datetime): ?float
    {
        if (!is_string($datetime) || $datetime === '') {
            return null;
        }
        $value = str_contains($datetime, '.') ? $datetime : $datetime . '.000';
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $value, new \DateTimeZone('UTC'));
        return $parsed === false ? null : (float) $parsed->format('U.u') * 1000;
    }
}
