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

    public function testHeadRequestHasNoBody(): void
    {
        $r = $this->request('HEAD', '/api/v1/health');
        self::assertSame(200, $r->status);
        self::assertSame('', $r->body);
    }
}
