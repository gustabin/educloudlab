<?php

declare(strict_types=1);

namespace EduCloud\Core;

/**
 * ULID generator (26 chars, Crockford base32): 48-bit millisecond timestamp + 80 random bits.
 * Used for every public identifier, storage key and request id.
 */
final class Ulid
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function generate(?int $timeMs = null): string
    {
        $time = $timeMs ?? (int) floor(microtime(true) * 1000);

        $timePart = '';
        for ($i = 9; $i >= 0; $i--) {
            $timePart = self::ALPHABET[$time % 32] . $timePart;
            $time = intdiv($time, 32);
        }

        $bytes = random_bytes(10);
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $randomPart = '';
        foreach (str_split($bits, 5) as $chunk) {
            $randomPart .= self::ALPHABET[bindec($chunk)];
        }

        return $timePart . $randomPart;
    }

    public static function isValid(string $value): bool
    {
        return preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $value) === 1;
    }
}
