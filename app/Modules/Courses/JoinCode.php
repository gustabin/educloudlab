<?php

declare(strict_types=1);

namespace EduCloud\Modules\Courses;

/**
 * Course join codes: 10 characters from an unambiguous alphabet (32^10 ≈ 1.1e15), shown as XXXXX-XXXXX.
 * Only the SHA-256 of the normalised code is stored (courses.join_code_hash, unique); the plain code is shown once.
 */
final class JoinCode
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function generate(): string
    {
        $code = '';
        for ($i = 0; $i < 10; $i++) {
            $code .= self::ALPHABET[random_int(0, 31)];
        }
        return substr($code, 0, 5) . '-' . substr($code, 5);
    }

    /** Uppercases and strips separators; returns null when the input cannot be a code. */
    public static function normalise(string $input): ?string
    {
        $code = strtoupper((string) preg_replace('/[\s-]+/', '', $input));
        return preg_match('/^[' . self::ALPHABET . ']{10}$/D', $code) === 1 ? $code : null;
    }

    public static function hash(string $normalised): string
    {
        return hash('sha256', $normalised, true);
    }
}
