<?php

declare(strict_types=1);

namespace EduCloud\Modules\Observability;

/**
 * Fixed latency histogram shared by the recorder and the reports. Buckets are non-cumulative:
 * le_50 = [0, 50] ms, le_100 = (50, 100] ms, ..., le_inf = above 5000 ms.
 */
final class Histogram
{
    /** column => upper bound in ms (null = +Inf) */
    public const BUCKETS = [
        'le_50' => 50, 'le_100' => 100, 'le_250' => 250, 'le_500' => 500,
        'le_1000' => 1000, 'le_2500' => 2500, 'le_5000' => 5000, 'le_inf' => null,
    ];

    public static function column(int $ms): string
    {
        foreach (self::BUCKETS as $column => $bound) {
            if ($bound === null || $ms <= $bound) {
                return $column;
            }
        }
        return 'le_inf';
    }

    /**
     * Approximate percentile: the upper bound of the bucket holding the p-th request (nearest rank). For the open
     * bucket the observed maximum is returned. Null when there are no requests.
     *
     * @param array<string, int> $counts column => requests
     */
    public static function percentile(array $counts, float $p, int $maxMs): ?int
    {
        $total = array_sum($counts);
        if ($total <= 0) {
            return null;
        }
        $rank = max(1, (int) ceil($p / 100 * $total));
        $seen = 0;
        foreach (self::BUCKETS as $column => $bound) {
            $seen += (int) ($counts[$column] ?? 0);
            if ($seen >= $rank) {
                return $bound === null ? $maxMs : min($bound, $maxMs);
            }
        }
        return $maxMs;
    }

    /**
     * Exact nearest-rank percentile of a list of values (job durations).
     *
     * @param list<int> $values
     */
    public static function exact(array $values, float $p): ?int
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        return $values[max(0, (int) ceil($p / 100 * count($values)) - 1)];
    }
}
