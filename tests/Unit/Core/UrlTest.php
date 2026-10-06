<?php

declare(strict_types=1);

namespace EduCloud\Tests\Unit\Core;

use EduCloud\Core\Url;
use PHPUnit\Framework\TestCase;

final class UrlTest extends TestCase
{
    protected function tearDown(): void
    {
        Url::setBasePath('');
    }

    public function testRootDeploymentHasNoPrefix(): void
    {
        Url::setBasePath('');
        self::assertSame('/register', Url::to('/register'));
        self::assertSame('/', Url::to('/'));
        self::assertSame('', Url::baseHref());
    }

    public function testSubDirectoryWithSpaceIsEncodedInLinks(): void
    {
        Url::setBasePath('/EduCloud Lab');
        self::assertSame('/EduCloud%20Lab', Url::baseHref());
        self::assertSame('/EduCloud%20Lab/register', Url::to('/register'));
        self::assertSame('/EduCloud%20Lab/', Url::to('/'));
        self::assertSame('/EduCloud%20Lab/assets/css/app.css', Url::to('assets/css/app.css'));
    }

    public function testBasePathIsNormalised(): void
    {
        Url::setBasePath('EduCloud Lab/');
        self::assertSame('/EduCloud Lab', Url::basePath());
        Url::setBasePath('/');
        self::assertSame('', Url::basePath());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function paths(): iterable
    {
        yield 'root of app' => ['/EduCloud Lab', '/EduCloud Lab', '/'];
        yield 'trailing slash' => ['/EduCloud Lab/', '/EduCloud Lab', '/'];
        yield 'api route' => ['/EduCloud Lab/api/v1/health', '/EduCloud Lab', '/api/v1/health'];
        yield 'similar prefix is not stripped' => ['/EduCloud Labs/x', '/EduCloud Lab', '/EduCloud Labs/x'];
        yield 'outside base unchanged' => ['/other/x', '/EduCloud Lab', '/other/x'];
        yield 'no base' => ['/api/v1/health/', '', '/api/v1/health'];
    }

    /** @dataProvider paths */
    public function testStripBasePath(string $path, string $base, string $expected): void
    {
        self::assertSame($expected, Url::stripBasePath($path, $base));
    }
}
