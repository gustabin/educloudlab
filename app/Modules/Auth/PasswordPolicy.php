<?php

declare(strict_types=1);

namespace EduCloud\Modules\Auth;

use EduCloud\Core\Exceptions\ValidationException;

/**
 * Password rules (NIST SP 800-63B style): length-based, no composition rules, block obvious choices.
 * Hashing uses Argon2id (no 72-byte truncation as with bcrypt).
 */
final class PasswordPolicy
{
    /** Hash of a random secret: verified when the account does not exist so timing does not reveal accounts. */
    public const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$dE9XMXhQeXdLVWpSLkcvOQ$bPQH6HYmwM94s+fuXP+H+N518Xvr4B9bfgvZ5FqcIQw';

    private const COMMON = [
        'password1234', 'contraseña123', 'contrasena123', '123456789012', 'qwertyuiop12', 'educloudlab1',
        'educloud1234', 'administrador', 'iloveyou1234', 'passwordpassword', '111111111111', '000000000000',
        'abc123456789', 'qwerty123456', 'aaaaaaaaaaaa', 'password123!',
    ];

    public function __construct(private readonly int $minLength = 12, private readonly int $maxLength = 128)
    {
    }

    public function assertAcceptable(string $password, string $email, string $field = 'password'): void
    {
        $length = mb_strlen($password);
        $code = null;
        $message = '';
        if ($length < $this->minLength) {
            [$code, $message] = ['min', "La contraseña debe tener al menos {$this->minLength} caracteres."];
        } elseif ($length > $this->maxLength) {
            [$code, $message] = ['max', "La contraseña debe tener como máximo {$this->maxLength} caracteres."];
        } elseif ($this->isObvious($password, $email)) {
            [$code, $message] = ['weak_password', 'Elige una contraseña menos predecible (no uses tu correo ni contraseñas comunes).'];
        }
        if ($code !== null) {
            throw new ValidationException([['field' => $field, 'code' => $code, 'message' => $message]]);
        }
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID);
    }

    private function isObvious(string $password, string $email): bool
    {
        $p = mb_strtolower($password);
        $email = mb_strtolower($email);
        $local = explode('@', $email)[0];
        if (in_array($p, self::COMMON, true) || $p === $email || ($local !== '' && str_contains($p, $local) && mb_strlen($local) >= 4)) {
            return true;
        }
        // A single repeated character, e.g. "aaaaaaaaaaaa".
        return count(array_unique(mb_str_split($p))) <= 2;
    }
}
