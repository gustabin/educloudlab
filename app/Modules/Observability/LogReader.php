<?php

declare(strict_types=1);

namespace EduCloud\Modules\Observability;

/**
 * Reads recent app log events for the admin log lookup (M11b). Returns only ts, level, event and request_id:
 * the context is never returned (it may hold student data even after redaction). Bounded: the last $days files,
 * lines up to 64 KB, at most $maxBytes read per call, newest first, at most $limit entries.
 */
final class LogReader
{
    public const LEVELS = ['info', 'warning', 'error'];
    private const MAX_LINE = 65536;

    public function __construct(private readonly string $directory, private readonly int $maxBytes = 20_971_520)
    {
    }

    /** @return list<array{ts: string, level: string, event: string, request_id: string|null}> */
    public function recent(?string $requestId, ?string $minLevel, int $days = 2, int $limit = 200): array
    {
        $levels = $minLevel === null ? self::LEVELS : array_slice(self::LEVELS, (int) array_search($minLevel, self::LEVELS, true));
        $budget = $this->maxBytes;
        $out = [];
        for ($i = 0; $i < $days && $budget > 0; $i++) {
            $file = $this->directory . '/app-' . gmdate('Y-m-d', time() - $i * 86400) . '.log';
            if (!is_file($file)) {
                continue;
            }
            $entries = [];
            $fh = @fopen($file, 'rb');
            if ($fh === false) {
                continue;
            }
            // Recent entries matter most: read only the tail that fits in the remaining budget.
            $size = (int) filesize($file);
            if ($size > $budget) {
                fseek($fh, $size - $budget);
                fgets($fh, self::MAX_LINE); // skip the partial first line
            }
            $budget -= min($size, $budget);
            while (($line = fgets($fh, self::MAX_LINE)) !== false) {
                if ($requestId !== null && !str_contains($line, $requestId)) {
                    continue;
                }
                $record = json_decode($line, true, 8);
                if (!is_array($record) || !in_array($record['level'] ?? null, $levels, true)) {
                    continue;
                }
                $rid = isset($record['request_id']) && is_string($record['request_id']) ? $record['request_id'] : null;
                if ($requestId !== null && $rid !== $requestId) {
                    continue;
                }
                $entries[] = [
                    'ts' => substr((string) ($record['ts'] ?? ''), 0, 30),
                    'level' => (string) $record['level'],
                    'event' => substr((string) ($record['event'] ?? ''), 0, 80),
                    'request_id' => $rid,
                ];
                if (count($entries) > $limit) {
                    array_shift($entries); // only the newest $limit can be returned (gate M11b-F1)
                }
            }
            fclose($fh);
            foreach (array_reverse($entries) as $entry) {
                $out[] = $entry;
                if (count($out) >= $limit) {
                    return $out;
                }
            }
        }
        return $out;
    }

    /** Removes app-YYYY-MM-DD.log files older than $days days; nothing else in the directory is touched. */
    public function purge(int $days): int
    {
        $cutoff = gmdate('Y-m-d', time() - $days * 86400);
        $removed = 0;
        foreach (glob($this->directory . '/app-*.log') ?: [] as $file) {
            if (preg_match('/^app-(\d{4}-\d{2}-\d{2})\.log$/D', basename($file), $m) === 1 && $m[1] < $cutoff && @unlink($file)) {
                $removed++;
            }
        }
        return $removed;
    }
}
