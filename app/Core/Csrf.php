<?php

declare(strict_types=1);

namespace EduCloud\Core;

/**
 * CSRF tokens (ADR-003), stateless and bound to a secret the attacker cannot read:
 *  - authenticated browser session: token = HMAC(key, "sess|" + session id)  (rotates with the session)
 *  - anonymous visitor (login/register forms): token = HMAC(key, "anon|" + value of the HttpOnly ec_csrf cookie)
 * Tokens are sent by JavaScript in the X-CSRF-Token header (or a csrf_token form field) and compared in constant time.
 */
final class Csrf
{
    public const ANON_COOKIE = 'ec_csrf';

    public function __construct(private readonly string $key)
    {
    }

    public function forSession(string $sessionId): string
    {
        return $this->sign('sess|' . $sessionId);
    }

    public function forAnonymous(string $cookieValue): string
    {
        return $this->sign('anon|' . $cookieValue);
    }

    public static function newAnonymousValue(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public function verify(string $expected, string $provided): bool
    {
        return $provided !== '' && hash_equals($expected, $provided);
    }

    private function sign(string $value): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', $value, $this->key, true)), '+/', '-_'), '=');
    }
}
