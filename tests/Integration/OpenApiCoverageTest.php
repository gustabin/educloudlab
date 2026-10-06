<?php

declare(strict_types=1);

namespace EduCloud\Tests\Integration;

use EduCloud\Tests\TestCase;

/** Every registered /api/v1 route must be documented in docs/api/openapi.yaml (Definition of Done). */
final class OpenApiCoverageTest extends TestCase
{
    public function testEveryApiRouteIsDocumented(): void
    {
        $spec = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/api/openapi.yaml');
        $documented = [];
        $currentPath = null;
        foreach (explode("\n", str_replace("\r", '', $spec)) as $line) {
            if (preg_match('#^  (/[^:]*):\s*$#D', $line, $m) === 1) {
                $currentPath = $m[1];
            } elseif ($currentPath !== null && preg_match('#^    (get|post|put|patch|delete):\s*$#D', $line, $m) === 1) {
                $documented[] = strtoupper($m[1]) . ' ' . $currentPath;
            } elseif (preg_match('#^\S#', $line) === 1) {
                $currentPath = null;
            }
        }

        $missing = [];
        foreach ($this->app()->router->routes() as $route) {
            if (!str_starts_with($route['pattern'], '/api/v1/')) {
                continue;
            }
            $key = $route['method'] . ' ' . substr($route['pattern'], strlen('/api/v1'));
            if (!in_array($key, $documented, true)) {
                $missing[] = $key;
            }
        }

        self::assertNotEmpty($documented, 'OpenAPI spec could not be parsed');
        self::assertSame([], $missing, 'Undocumented API routes: ' . implode(', ', $missing));
    }
}
