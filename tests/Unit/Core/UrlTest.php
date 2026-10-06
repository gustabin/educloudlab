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

    public function testSubDirectoryDeployment(): void
    {
        Url::setBasePath('/EduCloudLab');
        self::assertSame('/EduCloudLab', Url::baseHref());
        self::assertSame('/EduCloudLab/register', Url::to('/register'));
        self::assertSame('/EduCloudLab/', Url::to('/'));
        self::assertSame('/EduCloudLab/assets/css/app.css', Url::to('assets/css/app.css'));
    }

    public function testSubDirectoryWithSpaceIsEncodedInLinks(): void
    {
        Url::setBasePath('/Edu Cloud');
        self::assertSame('/Edu%20Cloud', Url::baseHref());
        self::assertSame('/Edu%20Cloud/register', Url::to('/register'));
    }

    public function testBasePathIsNormalised(): void
    {
        Url::setBasePath('EduCloudLab/');
        self::assertSame('/EduCloudLab', Url::basePath());
        Url::setBasePath('/');
        self::assertSame('', Url::basePath());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function paths(): iterable
    {
        yield 'root of app' => ['/EduCloudLab', '/EduCloudLab', '/'];
        yield 'trailing slash' => ['/EduCloudLab/', '/EduCloudLab', '/'];
        yield 'api route' => ['/EduCloudLab/api/v1/health', '/EduCloudLab', '/api/v1/health'];
        yield 'similar prefix is not stripped' => ['/EduCloudLabs/x', '/EduCloudLab', '/EduCloudLabs/x'];
        yield 'outside base unchanged' => ['/other/x', '/EduCloudLab', '/other/x'];
        yield 'no base' => ['/api/v1/health/', '', '/api/v1/health'];
    }

    /** @dataProvider paths */
    public function testStripBasePath(string $path, string $base, string $expected): void
    {
        self::assertSame($expected, Url::stripBasePath($path, $base));
    }
}
