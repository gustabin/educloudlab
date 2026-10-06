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
        Url::setBasePath('/educloudlab');
        self::assertSame('/educloudlab', Url::baseHref());
        self::assertSame('/educloudlab/register', Url::to('/register'));
        self::assertSame('/educloudlab/', Url::to('/'));
        self::assertSame('/educloudlab/assets/css/app.css', Url::to('assets/css/app.css'));
    }

    public function testSubDirectoryWithSpaceIsEncodedInLinks(): void
    {
        Url::setBasePath('/Edu Cloud');
        self::assertSame('/Edu%20Cloud', Url::baseHref());
        self::assertSame('/Edu%20Cloud/register', Url::to('/register'));
    }

    public function testBasePathIsNormalised(): void
    {
        Url::setBasePath('educloudlab/');
        self::assertSame('/educloudlab', Url::basePath());
        Url::setBasePath('/');
        self::assertSame('', Url::basePath());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function paths(): iterable
    {
        yield 'root of app' => ['/educloudlab', '/educloudlab', '/'];
        yield 'trailing slash' => ['/educloudlab/', '/educloudlab', '/'];
        yield 'api route' => ['/educloudlab/api/v1/health', '/educloudlab', '/api/v1/health'];
        yield 'similar prefix is not stripped' => ['/educloudlabs/x', '/educloudlab', '/educloudlabs/x'];
        yield 'outside base unchanged' => ['/other/x', '/educloudlab', '/other/x'];
        yield 'base matched case-insensitively' => ['/EduCloudLab/api/v1/health', '/educloudlab', '/api/v1/health'];
        yield 'mixed-case base, root' => ['/EDUCLOUDLAB', '/educloudlab', '/'];
        yield 'route part keeps its case' => ['/EduCloudLab/API/V1', '/educloudlab', '/API/V1'];
        yield 'similar prefix in other case is not stripped' => ['/EduCloudLabs/x', '/educloudlab', '/EduCloudLabs/x'];
        yield 'no base' => ['/api/v1/health/', '', '/api/v1/health'];
    }

    /** @dataProvider paths */
    public function testStripBasePath(string $path, string $base, string $expected): void
    {
        self::assertSame($expected, Url::stripBasePath($path, $base));
    }
}
