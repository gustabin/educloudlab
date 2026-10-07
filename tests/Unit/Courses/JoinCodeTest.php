<?php

declare(strict_types=1);

namespace EduCloud\Tests\Unit\Courses;

use EduCloud\Modules\Courses\JoinCode;
use PHPUnit\Framework\TestCase;

final class JoinCodeTest extends TestCase
{
    public function testGeneratedCodesAreReadableAndUnique(): void
    {
        $codes = [];
        for ($i = 0; $i < 200; $i++) {
            $code = JoinCode::generate();
            self::assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{5}-[A-HJ-NP-Z2-9]{5}$/D', $code, 'no 0/O/1/I ambiguity');
            $codes[$code] = true;
        }
        self::assertCount(200, $codes);
    }

    public function testNormalisationAcceptsCommonTypingVariants(): void
    {
        self::assertSame('ABCDE23456', JoinCode::normalise('abcde-23456'));
        self::assertSame('ABCDE23456', JoinCode::normalise(' ABCDE 23456 '));
        self::assertNull(JoinCode::normalise('ABCDE-2345'));
        self::assertNull(JoinCode::normalise('ABCDE-O2345'), 'O is not in the alphabet');
        self::assertSame('ABCDE23456', JoinCode::normalise("ABCDE23456\n"), 'pasted with a line break');
        self::assertNull(JoinCode::normalise("' OR 1=1 --"));
    }

    public function testOnlyAHashIsStored(): void
    {
        $hash = JoinCode::hash('ABCDE23456');
        self::assertSame(32, strlen($hash));
        self::assertSame($hash, JoinCode::hash((string) JoinCode::normalise('abcde-23456')));
    }
}
