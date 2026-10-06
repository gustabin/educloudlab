<?php

declare(strict_types=1);

namespace EduCloud\Tests\Unit\Core;

use EduCloud\Core\Logger;
use PHPUnit\Framework\TestCase;

final class LoggerAndEscapingTest extends TestCase
{
    public function testRedactsSensitiveKeysRecursively(): void
    {
        $out = Logger::redact([
            'email' => 'a@b.test',
            'password' => 'hunter2',
            'Authorization' => 'Bearer abc.def.ghi',
            'nested' => ['refresh_token' => 'xyz', 'smtp_pass' => 'p', 'ok' => 1],
            'jwt' => 'aaa',
        ]);
        self::assertSame('a@b.test', $out['email']);
        self::assertSame('[REDACTED]', $out['password']);
        self::assertSame('[REDACTED]', $out['Authorization']);
        self::assertSame('[REDACTED]', $out['nested']['refresh_token']);
        self::assertSame('[REDACTED]', $out['nested']['smtp_pass']);
        self::assertSame(1, $out['nested']['ok']);
        self::assertSame('[REDACTED]', $out['jwt']);
        self::assertStringNotContainsString('hunter2', (string) json_encode($out));
    }

    public function testTruncatesLongStringsAndDescribesObjects(): void
    {
        $out = Logger::redact(['sql' => str_repeat('a', 5000), 'obj' => new \stdClass()]);
        self::assertLessThanOrEqual(2001, mb_strlen($out['sql']));
        self::assertSame('stdClass', $out['obj']);
    }

    public function testEscapeHelperCoversHtmlAndAttributeContexts(): void
    {
        self::assertSame('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', e('<script>alert("x")</script>'));
        self::assertSame('&apos; onmouseover=&apos;x', e("' onmouseover='x"));
        self::assertSame('', e(null));
        self::assertSame('', e(['array']));
        self::assertSame('5', e(5));
        // Invalid UTF-8 is substituted, never passed through raw.
        self::assertStringNotContainsString("\xC3\x28", e("a\xC3\x28b"));
    }
}
