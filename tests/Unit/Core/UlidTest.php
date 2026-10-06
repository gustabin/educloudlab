<?php

declare(strict_types=1);

namespace EduCloud\Tests\Unit\Core;

use EduCloud\Core\Ulid;
use PHPUnit\Framework\TestCase;

final class UlidTest extends TestCase
{
    public function testGeneratesValid26CharCrockfordIds(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $id = Ulid::generate();
            self::assertSame(26, strlen($id));
            self::assertTrue(Ulid::isValid($id), $id);
        }
    }

    public function testIdsAreUnique(): void
    {
        $ids = [];
        for ($i = 0; $i < 5000; $i++) {
            $ids[Ulid::generate()] = true;
        }
        self::assertCount(5000, $ids);
    }

    public function testTimePrefixSortsChronologically(): void
    {
        $a = Ulid::generate(1_700_000_000_000);
        $b = Ulid::generate(1_700_000_000_001);
        self::assertLessThan(0, strcmp(substr($a, 0, 10), substr($b, 0, 10)));
    }

    public function testRejectsInvalidValues(): void
    {
        $invalid = [
            '',
            'abc',
            str_repeat('U', 26),
            '01ARZ3NDEKTSV4RRFFQ69G5FA',    // 25 chars
            "01ARZ3NDEKTSV4RRFFQ69G5FAV\n", // trailing newline must not pass the anchor
            '81ARZ3NDEKTSV4RRFFQ69G5FAV',   // timestamp overflow
        ];
        foreach ($invalid as $bad) {
            self::assertFalse(Ulid::isValid($bad), var_export($bad, true));
        }
    }
}
