<?php

declare(strict_types=1);

namespace EduCloud\Tests\Unit\Workspaces;

use EduCloud\Core\Exceptions\ApiException;
use EduCloud\Core\Exceptions\ValidationException;
use EduCloud\Core\Format;
use EduCloud\Core\Pagination;
use EduCloud\Modules\Resources\ResourceLifecycle;
use EduCloud\Modules\Resources\ResourceTypes;
use PHPUnit\Framework\TestCase;

final class ResourceRulesTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool}> */
    public static function transitions(): iterable
    {
        $states = ['provisioning', 'active', 'failed', 'deleting', 'deleted'];
        $allowed = ['provisioning>active', 'provisioning>failed', 'active>deleting', 'failed>deleting', 'deleting>deleted'];
        foreach ($states as $from) {
            foreach ($states as $to) {
                yield "$from>$to" => [$from, $to, in_array("$from>$to", $allowed, true)];
            }
        }
    }

    /** @dataProvider transitions */
    public function testLifecycleMatrix(string $from, string $to, bool $expected): void
    {
        self::assertSame($expected, ResourceLifecycle::canTransition($from, $to));
        if (!$expected) {
            $this->expectException(ApiException::class);
            ResourceLifecycle::assertTransition($from, $to);
        }
    }

    public function testConfigDefaultsAndPartialMerge(): void
    {
        self::assertSame(['access_tier' => 'hot', 'versioning' => false, 'redundancy' => 'lrs'], ResourceTypes::normaliseConfig('storage', null));
        self::assertSame(
            ['access_tier' => 'archive', 'versioning' => true, 'redundancy' => 'lrs'],
            ResourceTypes::normaliseConfig('storage', ['access_tier' => 'archive'], ['versioning' => true])
        );
        $this->expectException(ValidationException::class);
        ResourceTypes::normaliseConfig('storage', ['versioning' => 'yes']);
    }

    public function testTagsLimitAndSorting(): void
    {
        self::assertSame(['a' => '1', 'b' => '2'], ResourceTypes::normaliseTags(['b' => '2', 'a' => '1'], 10));
        self::assertSame([], ResourceTypes::normaliseTags(null, 10));
        $this->expectException(ValidationException::class);
        ResourceTypes::normaliseTags(['a' => '1', 'b' => '2', 'c' => '3'], 2);
    }

    public function testPaginationBounds(): void
    {
        $p = Pagination::fromQuery(['page' => '3', 'per_page' => '10']);
        self::assertSame(20, $p->offset());
        self::assertSame(['page' => 3, 'per_page' => 10, 'total' => 0, 'total_pages' => 1], $p->meta(0));
        foreach ([['page' => '0'], ['per_page' => '101'], ['page' => '-1'], ['page' => ['1']], ['per_page' => '1e3']] as $bad) {
            try {
                Pagination::fromQuery($bad);
                self::fail('accepted ' . json_encode($bad));
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }

    public function testFormatHelpers(): void
    {
        self::assertSame('2026-10-06T16:24:29.123Z', Format::isoUtc('2026-10-06 16:24:29.123'));
        self::assertNull(Format::isoUtc(null));
        self::assertSame('%50\\%\\_off%', Format::likeContains('50%_off'));
        self::assertSame([], Format::jsonColumn('not json'));
    }
}
