<?php

declare(strict_types=1);

namespace EduCloud\Tests\Integration;

use EduCloud\Tests\TestCase;

final class KernelTest extends TestCase
{
    public function testHealthReturnsEnvelopeWithRequestId(): void
    {
        $r = $this->request('GET', '/api/v1/health');
        self::assertSame(200, $r->status);
        $d = $r->decoded();
        self::assertTrue($d['success']);
        self::assertSame('ok', $d['data']['status']);
        self::assertSame($r->headers['X-Request-Id'], $d['meta']['request_id']);
        self::assertStringStartsWith('application/json', $r->headers['Content-Type']);
    }

    public function testUnknownApiRouteReturns404Envelope(): void
    {
        $r = $this->request('GET', '/api/v1/does-not-exist');
        self::assertSame(404, $r->status);
        self::assertSame('NOT_FOUND', $r->decoded()['error']['code']);
    }

    public function testWrongMethodReturns405WithAllowHeader(): void
    {
        $r = $this->request('POST', '/api/v1/health', ['x' => 1]);
        self::assertSame(405, $r->status);
        self::assertSame('GET', $r->headers['Allow']);
    }

    public function testSecurityHeadersOnEveryResponse(): void
    {
        foreach (['/api/v1/health', '/api/v1/missing', '/', '/missing-page'] as $path) {
            $r = $this->request('GET', $path);
            self::assertStringContainsString("script-src 'self'", $r->headers['Content-Security-Policy'] ?? '', $path);
            self::assertSame('nosniff', $r->headers['X-Content-Type-Options'] ?? null, $path);
            self::assertSame('DENY', $r->headers['X-Frame-Options'] ?? null, $path);
            self::assertArrayHasKey('X-Request-Id', $r->headers, $path);
        }
    }

    public function testOnlyPublicRoutesAreIndexable(): void
    {
        self::assertArrayNotHasKey('X-Robots-Tag', $this->request('GET', '/')->headers);
        self::assertSame('noindex, nofollow', $this->request('GET', '/api/v1/health')->headers['X-Robots-Tag'] ?? null);
        self::assertSame('noindex, nofollow', $this->request('GET', '/missing-page')->headers['X-Robots-Tag'] ?? null);
    }

    public function testHomePageRendersSeoMetadata(): void
    {
        $r = $this->request('GET', '/');
        self::assertSame(200, $r->status);
        self::assertStringContainsString('<html lang="es"', $r->body);
        self::assertStringContainsString('<meta name="description"', $r->body);
        self::assertStringContainsString('<link rel="canonical"', $r->body);
        self::assertSame(1, substr_count($r->body, '<h1'));
    }

    public function testExternalAuthNeverReadsUserCredentials(): void
    {
        // 'external' routes (GET /metrics) authenticate with their own secret: an invalid Bearer JWT is not even parsed
        // (an 'none' route would reject it with 401) and no user or tenant is resolved (gate M12-04).
        $this->app()->router->get('/probe-external', static fn (\EduCloud\Core\Request $r): \EduCloud\Core\Response => \EduCloud\Core\Response::json([
            'user' => $r->attribute('user'), 'auth_method' => $r->attribute('auth_method'), 'tenant' => $r->attribute('tenant'),
        ]), ['auth' => 'external', 'name' => 'test.external']);
        $r = $this->request('GET', '/probe-external', null, ['Authorization' => 'Bearer not-a-jwt']);
        self::assertSame(200, $r->status);
        self::assertSame(['user' => null, 'auth_method' => null, 'tenant' => null], $r->decoded()['data']);
        self::assertSame(401, $this->request('GET', '/api/v1/health', null, ['Authorization' => 'Bearer not-a-jwt'])->status);
    }

    public function testExternalAuthCannotBeCombinedWithAPermission(): void
    {
        $this->app()->router->get('/probe-external-admin', static fn (): \EduCloud\Core\Response => \EduCloud\Core\Response::json(['leak' => true]), [
            'auth' => 'external', 'permission' => 'platform_admin', 'name' => 'test.external_admin',
        ]);
        $r = $this->request('GET', '/probe-external-admin');
        self::assertSame(500, $r->status, 'misconfigured route fails closed');
        self::assertStringNotContainsString('leak', $r->body);
    }

    public function testHeadRequestHasNoBody(): void
    {
        $r = $this->request('HEAD', '/api/v1/health');
        self::assertSame(200, $r->status);
        self::assertSame('', $r->body);
    }
}
