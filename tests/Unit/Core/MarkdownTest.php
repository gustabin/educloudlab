<?php

declare(strict_types=1);

namespace EduCloud\Tests\Unit\Core;

use EduCloud\Core\Markdown;
use PHPUnit\Framework\TestCase;

/** Lab instructions are printed without e(): the converter itself must neutralise HTML and unsafe links. */
final class MarkdownTest extends TestCase
{
    public function testRendersCommonMarkAndTables(): void
    {
        $html = Markdown::toHtml("**Hola** `SELECT 1`\n\n| a | b |\n|---|---|\n| 1 | 2 |\n");
        self::assertStringContainsString('<strong>Hola</strong>', $html);
        self::assertStringContainsString('<code>SELECT 1</code>', $html);
        self::assertStringContainsString('<table>', $html);
    }

    /** @return iterable<string, array{string, string}> */
    public static function payloads(): iterable
    {
        yield 'script tag' => ['<script>alert(1)</script>', '<script'];
        yield 'inline handler' => ['<img src=x onerror=alert(1)>', '<img'];
        yield 'javascript link' => ['[clic](javascript:alert(1))', 'javascript:'];
        yield 'data link' => ['[clic](data:text/html;base64,PHNjcmlwdD4=)', 'data:text/html'];
        yield 'iframe' => ['<iframe src="https://evil.example"></iframe>', '<iframe'];
        yield 'autolink javascript' => ['<javascript:alert(1)>', 'href="javascript'];
    }

    /** @dataProvider payloads */
    public function testRawHtmlAndUnsafeLinksAreNeutralised(string $markdown, string $forbidden): void
    {
        self::assertStringNotContainsString($forbidden, Markdown::toHtml($markdown));
    }
}
