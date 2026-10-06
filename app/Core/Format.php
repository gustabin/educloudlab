<?php

declare(strict_types=1);

namespace EduCloud\Core;

/** Output formatting helpers shared by API representations. */
final class Format
{
    /** MySQL DATETIME(3) stored in UTC → ISO 8601 ("2026-10-06T16:24:29.123Z"); null stays null. */
    public static function isoUtc(?string $datetime): ?string
    {
        if ($datetime === null || $datetime === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.v', $datetime, new \DateTimeZone('UTC'))
            ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $datetime, new \DateTimeZone('UTC'));
        return $dt === false ? null : $dt->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * Decodes a JSON column into an array (empty array for NULL/invalid).
     *
     * @return array<mixed>
     */
    public static function jsonColumn(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** Escapes LIKE wildcards so user search text is matched literally (use with "... LIKE ? ESCAPE '\\\\'"). */
    public static function likeContains(string $text): string
    {
        return '%' . addcslashes($text, '%_\\') . '%';
    }
}
