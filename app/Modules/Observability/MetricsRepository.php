<?php

declare(strict_types=1);

namespace EduCloud\Modules\Observability;

use EduCloud\Core\Db;

/** request_metrics (M11b): platform table, aggregated by route name; no tenant or user data. */
final class MetricsRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** Adds one request to its minute bucket (portable upsert). $column comes from Histogram::column(). */
    public function record(string $bucketStart, string $route, string $method, int $statusClass, int $ms): void
    {
        $column = Histogram::column($ms);
        $this->db->execute(
            "INSERT INTO request_metrics (bucket_start, route, method, status_class, requests, total_ms, max_ms, $column)
             VALUES (?, ?, ?, ?, 1, ?, ?, 1)
             ON DUPLICATE KEY UPDATE requests = requests + 1, total_ms = total_ms + VALUES(total_ms),
                                     max_ms = GREATEST(max_ms, VALUES(max_ms)), $column = $column + 1",
            [$bucketStart, $route, $method, $statusClass, $ms, $ms]
        );
    }

    /**
     * Totals per route (and method) since $since, with histogram counts per status class summed.
     *
     * @return list<array<string, mixed>>
     */
    public function byRoute(string $since): array
    {
        return $this->db->select(
            'SELECT route, method, ' . self::sums() . ",
                    SUM(CASE WHEN status_class = 4 THEN requests ELSE 0 END) AS client_errors,
                    SUM(CASE WHEN status_class = 5 THEN requests ELSE 0 END) AS server_errors
               FROM request_metrics WHERE bucket_start >= ?
              GROUP BY route, method",
            [$since]
        );
    }

    /**
     * Requests and errors per time slot of $slotMinutes minutes since $since.
     *
     * @return list<array<string, mixed>>
     */
    public function series(string $since, int $slotMinutes): array
    {
        $seconds = $slotMinutes * 60;
        return $this->db->select(
            "SELECT FLOOR(UNIX_TIMESTAMP(bucket_start) / $seconds) * $seconds AS slot,
                    SUM(requests) AS requests,
                    SUM(CASE WHEN status_class = 5 THEN requests ELSE 0 END) AS server_errors,
                    SUM(CASE WHEN status_class = 4 THEN requests ELSE 0 END) AS client_errors
               FROM request_metrics WHERE bucket_start >= ?
              GROUP BY slot ORDER BY slot",
            [$since]
        );
    }

    /**
     * Finished and active jobs queued since $since (newest first, bounded).
     *
     * @return list<array<string, mixed>>
     */
    public function jobs(string $since, int $limit = 5000): array
    {
        return $this->db->select(
            'SELECT type, status, queued_at, started_at, finished_at FROM jobs
              WHERE queued_at >= ? ORDER BY queued_at DESC LIMIT ' . max(1, min(20000, $limit)),
            [$since]
        );
    }

    public function purge(string $before): int
    {
        return $this->db->execute('DELETE FROM request_metrics WHERE bucket_start < ?', [$before]);
    }

    private static function sums(): string
    {
        $parts = ['SUM(requests) AS requests', 'SUM(total_ms) AS total_ms', 'MAX(max_ms) AS max_ms'];
        foreach (array_keys(Histogram::BUCKETS) as $column) {
            $parts[] = "SUM($column) AS $column";
        }
        return implode(', ', $parts);
    }
}
