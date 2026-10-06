<?php

declare(strict_types=1);

namespace EduCloud\Core;

/**
 * HTTP response with helpers for the standard JSON envelope:
 *   success: {"success": true, "data": ..., "message": "...", "meta": {...}}
 *   error:   {"success": false, "error": {"code", "message", "details"}, "meta": {...}}
 */
final class Response
{
    /** Set by the Kernel for routes marked 'public'; SecurityHeaders omits noindex for these. */
    public bool $indexable = false;

    /** @var list<string> raw Set-Cookie header values */
    public array $cookies = [];

    /** @param array<string, string> $headers */
    public function __construct(
        public int $status = 200,
        public string $body = '',
        public array $headers = [],
    ) {
    }

    /** @param array<string, mixed> $meta */
    public static function json(mixed $data = null, int $status = 200, string $message = '', array $meta = []): self
    {
        $payload = ['success' => true, 'data' => $data];
        if ($message !== '') {
            $payload['message'] = $message;
        }
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }
        return self::encode($payload, $status);
    }

    /**
     * @param list<array<string, string>> $details
     * @param array<string, mixed>        $meta
     */
    public static function error(int $status, string $code, string $message, array $details = [], array $meta = []): self
    {
        $payload = [
            'success' => false,
            'error' => ['code' => $code, 'message' => $message, 'details' => $details],
        ];
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }
        return self::encode($payload, $status);
    }

    public static function noContent(): self
    {
        return new self(204);
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($status, $html, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return new self($status, '', ['Location' => $location]);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * Adds a cookie. Defaults are the secure ones: HttpOnly, SameSite=Lax, path = app base path.
     * $maxAge = 0 creates a session cookie; a negative value deletes the cookie.
     */
    public function withCookie(
        string $name,
        string $value,
        int $maxAge,
        bool $secure,
        bool $httpOnly = true,
        string $sameSite = 'Lax',
    ): self {
        $parts = [rawurlencode($name) . '=' . rawurlencode($value), 'Path=' . Url::baseHref() . '/'];
        if ($maxAge !== 0) {
            $parts[] = 'Max-Age=' . max(0, $maxAge);
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s', time() + max(0, $maxAge)) . ' GMT';
        }
        if ($secure) {
            $parts[] = 'Secure';
        }
        if ($httpOnly) {
            $parts[] = 'HttpOnly';
        }
        $parts[] = 'SameSite=' . $sameSite;
        $this->cookies[] = implode('; ', $parts);
        return $this;
    }

    /** Value set for a cookie in this response (tests); null if not set. */
    public function cookie(string $name): ?string
    {
        foreach ($this->cookies as $raw) {
            [$pair] = explode(';', $raw, 2);
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            if (rawurldecode($k) === $name) {
                return rawurldecode($v);
            }
        }
        return null;
    }

    /** @return array<string, mixed> decoded JSON body (tests and middleware) */
    public function decoded(): array
    {
        $data = json_decode($this->body, true);
        return is_array($data) ? $data : [];
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
            foreach ($this->cookies as $cookie) {
                header('Set-Cookie: ' . $cookie, false);
            }
        }
        if ($this->status !== 204) {
            echo $this->body;
        }
    }

    /** @param array<string, mixed> $payload */
    private static function encode(array $payload, int $status): self
    {
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
        return new self($status, $json, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
