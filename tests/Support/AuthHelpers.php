<?php

declare(strict_types=1);

namespace EduCloud\Tests\Support;

use EduCloud\Core\Response;

/** Helpers for tests that drive the real auth API through the kernel. Requires EduCloud\Tests\TestCase. */
trait AuthHelpers
{
    protected string $password = 'una frase larga de prueba 42';

    protected function register(string $email, ?string $password = null, string $name = 'Ana Prueba'): Response
    {
        $csrf = $this->csrfFromPage('/register');
        return $this->request('POST', '/api/v1/auth/register', [
            'email' => $email,
            'password' => $password ?? $this->password,
            'display_name' => $name,
        ], ['X-CSRF-Token' => $csrf]);
    }

    /** Latest one-time token sent to $email in a template's link. */
    protected function tokenFromOutbox(string $email, string $template): string
    {
        $payload = $this->app()->db()->scalar(
            'SELECT payload FROM email_outbox WHERE to_email = ? AND template = ? ORDER BY id DESC LIMIT 1',
            [$email, $template]
        );
        self::assertIsString($payload, "No $template email queued for $email");
        $url = (string) (json_decode($payload, true)['url'] ?? '');
        self::assertSame(1, preg_match('/[?&]token=([A-Za-z0-9_-]+)/', $url, $m), 'No token in ' . $template . ' link');
        return $m[1];
    }

    protected function verify(string $email, ?string $password = null): Response
    {
        $token = $this->tokenFromOutbox($email, 'verify_email');
        return $this->verifyToken($token, $password ?? $this->password);
    }

    protected function verifyToken(string $token, string $password): Response
    {
        $csrf = $this->csrfFromPage('/verify-email?token=' . $token);
        return $this->request('POST', '/api/v1/auth/verify-email', ['token' => $token, 'password' => $password], ['X-CSRF-Token' => $csrf]);
    }

    /** Registers + verifies a user and returns its email. */
    protected function createVerifiedUser(string $email): string
    {
        self::assertSame(202, $this->register($email)->status);
        self::assertSame(200, $this->verify($email)->status);
        return $email;
    }

    /** Logs in through the API and returns the session-bound CSRF token. */
    protected function login(string $email, ?string $password = null): string
    {
        $csrf = $this->csrfFromPage('/login');
        $r = $this->request('POST', '/api/v1/auth/login', ['email' => $email, 'password' => $password ?? $this->password], ['X-CSRF-Token' => $csrf]);
        self::assertSame(200, $r->status, $r->body);
        return (string) $r->decoded()['data']['csrf_token'];
    }

    protected function attemptLogin(string $email, string $password): Response
    {
        $csrf = $this->csrfFromPage('/login');
        return $this->request('POST', '/api/v1/auth/login', ['email' => $email, 'password' => $password], ['X-CSRF-Token' => $csrf]);
    }

    /** @return array<string, mixed> token pair */
    protected function issueTokens(string $email): array
    {
        $r = $this->request('POST', '/api/v1/auth/tokens', ['email' => $email, 'password' => $this->password]);
        self::assertSame(201, $r->status, $r->body);
        return $r->decoded()['data'];
    }
}
