<?php

declare(strict_types=1);

namespace EduCloud\Modules\Labs;

use EduCloud\Core\Db;
use EduCloud\Core\Ulid;

/** The global lab catalog (platform content, not tenant-owned). */
final class LabRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<array<string, mixed>> current published versions */
    public function listPublished(): array
    {
        return $this->db->select(
            "SELECT id, public_id, code, version, slug, title, summary, difficulty, estimated_minutes, max_score, definition
               FROM labs WHERE status = 'published' AND is_current = 1 ORDER BY code"
        );
    }

    /** @return array<string, mixed>|null */
    public function findPublishedByCode(string $code): ?array
    {
        return $this->db->selectOne(
            "SELECT id, public_id, code, version, slug, title, summary, difficulty, estimated_minutes, max_score, definition
               FROM labs WHERE code = ? AND status = 'published' AND is_current = 1",
            [$code]
        );
    }

    /** @return array<string, mixed>|null */
    public function findVersion(string $code, string $version): ?array
    {
        return $this->db->selectOne('SELECT id, checksum, is_current FROM labs WHERE code = ? AND version = ?', [$code, $version]);
    }

    /**
     * Inserts a new version and makes it the current one for its code.
     *
     * @param array<string, mixed> $definition
     */
    public function insertVersion(array $definition, int $maxScore, string $checksum): void
    {
        $this->db->transaction(function () use ($definition, $maxScore, $checksum): void {
            $this->db->execute('UPDATE labs SET is_current = 0 WHERE code = ?', [$definition['code']]);
            $this->db->insert(
                'INSERT INTO labs (public_id, code, version, slug, title, summary, difficulty, estimated_minutes, max_score,
                                   definition, checksum, status, is_current)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)',
                [
                    Ulid::generate(), $definition['code'], $definition['version'], $definition['slug'], $definition['title'],
                    $definition['summary'], $definition['difficulty'], (int) $definition['estimated_minutes'], $maxScore,
                    (string) json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $checksum,
                    $definition['status'],
                ]
            );
        });
    }

    /** Re-publishes an already imported version (e.g. after a status change in lab.json would need a new version). */
    public function makeCurrent(int $id, string $code): void
    {
        $this->db->transaction(function () use ($id, $code): void {
            $this->db->execute('UPDATE labs SET is_current = 0 WHERE code = ?', [$code]);
            $this->db->execute('UPDATE labs SET is_current = 1 WHERE id = ?', [$id]);
        });
    }

    /** @return array<string, mixed>|null any version, by internal id (attempts keep the version they started with) */
    public function findById(int $id): ?array
    {
        return $this->db->selectOne(
            'SELECT id, public_id, code, version, slug, title, summary, difficulty, estimated_minutes, max_score, definition FROM labs WHERE id = ?',
            [$id]
        );
    }
}
