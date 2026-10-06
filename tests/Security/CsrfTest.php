<?php

declare(strict_types=1);

namespace EduCloud\Tests\Security;

use EduCloud\Tests\Support\AuthHelpers;
use EduCloud\Tests\TestCase;

final class CsrfTest extends TestCase
{
    use AuthHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetDatabase();
    }

    public function testAnonymousTokenIsBoundToTheVisitorCookie(): void
    {
        $token = $this->csrfFromPage('/register');
        $cookie = $this->cookieJar['ec_csrf'];
        self::assertNotSame('', $cookie);
        self::assertStringNotContainsString($cookie, $token, 'the cookie value is never exposed in the page');

        // Same token, but a different victim cookie (attacker-supplied token) → rejected.
        $this->cookieJar['ec_csrf'] = 'victim-cookie-value-xxxxxxxxxxxxxxxxxxxxxxx';
        $r = $this->request('POST', '/api/v1/auth/password/forgot', ['email' => 'a@test.example'], ['X-CSRF-Token' => $token]);
        self::assertSame(403, $r->status);
    }

    public function testAnonymousCookieFlags(): void
    {
        $r = $this->request('GET', '/login');
        $raw = implode("\n", $r->cookies);
        self::assertMatchesRegularExpression('/^ec_csrf=[A-Za-z0-9_-]{43}; Path=\/; HttpOnly; SameSite=Lax$/m', $raw);
        self::assertSame([], $this->request('GET', '/api/v1/health')->cookies, 'API responses never set cookies');
    }

    public function testForeignOriginIsRejectedEvenWithValidToken(): void
    {
        $token = $this->csrfFromPage('/forgot-password');
        $r = $this->request('POST', '/api/v1/auth/password/forgot', ['email' => 'a@test.example'], [
            'X-CSRF-Token' => $token,
            'Origin' => 'https://evil.example',
        ]);
        self::assertSame(403, $r->status);
        self::assertSame('CSRF_INVALID', $r->decoded()['error']['code']);

        $same = $this->request('POST', '/api/v1/auth/password/forgot', ['email' => 'a@test.example'], [
            'X-CSRF-Token' => $token,
            'Origin' => 'http://localhost',
        ]);
        self::assertSame(202, $same->status);
    }

    public function testMissingTokenIsRejected(): void
    {
        $this->csrfFromPage('/login');
        $r = $this->request('POST', '/api/v1/auth/password/forgot', ['email' => 'a@test.example']);
        self::assertSame(403, $r->status, 'no header and no field → rejected');
    }

    public function testCredentialExchangeEndpointsDoNotNeedCsrf(): void
    {
        $this->createVerifiedUser('c@test.example');
        $this->cookieJar = [];
        $r = $this->request('POST', '/api/v1/auth/tokens', ['email' => 'c@test.example', 'password' => $this->password]);
        self::assertSame(201, $r->status);
    }

    public function testEveryUnsafeBrowserRouteEnforcesCsrf(): void
    {
        // Registry-driven: every POST/PATCH/PUT/DELETE route must reject a request without a token,
        // except routes explicitly declared csrf=false (credential exchange without cookies).
        $this->createVerifiedUser('all@test.example');
        $this->login('all@test.example');
        $checked = 0;
        foreach ($this->app()->router->routes() as $route) {
            if ($route['method'] === 'GET' || $route['options']['csrf'] === false) {
                continue;
            }
            $path = preg_replace('/\{[a-z_]+\}/', '01ARZ3NDEKTSV4RRFFQ69G5FAV', $route['pattern']);
            $r = $this->request($route['method'], (string) $path, []);
            self::assertContains($r->status, [403, 429], "{$route['method']} {$route['pattern']} accepted a request without CSRF token");
            $checked++;
        }
        self::assertGreaterThan(5, $checked);
    }
}
