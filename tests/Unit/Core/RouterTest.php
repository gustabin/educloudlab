<?php

declare(strict_types=1);

namespace EduCloud\Tests\Unit\Core;

use EduCloud\Core\Exceptions\MethodNotAllowedException;
use EduCloud\Core\Exceptions\NotFoundException;
use EduCloud\Core\Response;
use EduCloud\Core\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
        $h = static fn (): Response => Response::json();
        $this->router->get('/api/v1/workspaces', $h);
        $this->router->post('/api/v1/workspaces', $h, ['auth' => 'session', 'permission' => 'create']);
        $this->router->get('/api/v1/workspaces/{id}', $h);
        $this->router->get('/api/v1/workspaces/{id}/resources/{resource_id}', $h);
    }

    public function testMatchesStaticAndParameterisedRoutes(): void
    {
        self::assertSame([], $this->router->match('GET', '/api/v1/workspaces')['params']);
        $m = $this->router->match('GET', '/api/v1/workspaces/01ABC/resources/02DEF');
        self::assertSame(['id' => '01ABC', 'resource_id' => '02DEF'], $m['params']);
    }

    public function testParametersDoNotSpanSegments(): void
    {
        $this->expectException(NotFoundException::class);
        $this->router->match('GET', '/api/v1/workspaces/a/b');
    }

    public function testUnknownPathIs404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->router->match('GET', '/api/v1/nope');
    }

    public function testWrongMethodIs405WithAllowedMethods(): void
    {
        try {
            $this->router->match('DELETE', '/api/v1/workspaces');
            self::fail('Expected 405');
        } catch (MethodNotAllowedException $e) {
            self::assertSame(['GET', 'POST'], $e->allowed);
        }
    }

    public function testHeadFallsBackToGet(): void
    {
        self::assertSame('GET', $this->router->match('HEAD', '/api/v1/workspaces')['route']['method']);
    }

    public function testRegexMetacharactersInPatternsAreLiteral(): void
    {
        $this->router->get('/files/report.csv', static fn (): Response => Response::json());
        $this->router->match('GET', '/files/report.csv');
        $this->expectException(NotFoundException::class);
        $this->router->match('GET', '/files/reportXcsv');
    }

    public function testRegistryExposesOptionsWithDefaults(): void
    {
        $routes = $this->router->routes();
        self::assertCount(4, $routes);
        self::assertSame('session', $routes[1]['options']['auth']);
        self::assertSame('none', $routes[0]['options']['auth']);
        self::assertFalse($routes[0]['options']['public']);
    }
}
