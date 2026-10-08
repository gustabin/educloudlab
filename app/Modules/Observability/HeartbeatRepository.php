<?php

declare(strict_types=1);

namespace EduCloud\Modules\Observability;

use EduCloud\Core\Db;

/** component_heartbeats (M11b): one row per background component (the latest instance wins). */
final class HeartbeatRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @param array<string, int|bool|null> $details already allowlisted by Heartbeat */
    public function beat(string $component, string $instance, string $startedAt, array $details): void
    {
        $this->db->execute(
            'INSERT INTO component_heartbeats (component, instance, started_at, last_seen_at, details)
             VALUES (?, ?, ?, UTC_TIMESTAMP(3), ?)
             ON DUPLICATE KEY UPDATE instance = VALUES(instance), started_at = VALUES(started_at),
                                     last_seen_at = VALUES(last_seen_at), details = VALUES(details)',
            [$component, $instance, $startedAt, json_encode($details, JSON_THROW_ON_ERROR)]
        );
    }

    /** @return array<string, array<string, mixed>> component => row (with seconds since last_seen_at) */
    public function all(): array
    {
        $out = [];
        $rows = $this->db->select(
            'SELECT component, started_at, last_seen_at, details,
                    TIMESTAMPDIFF(SECOND, last_seen_at, UTC_TIMESTAMP(3)) AS age_s
               FROM component_heartbeats'
        );
        foreach ($rows as $row) {
            $out[(string) $row['component']] = $row;
        }
        return $out;
    }
}
