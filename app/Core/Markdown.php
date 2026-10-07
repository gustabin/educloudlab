<?php

declare(strict_types=1);

namespace EduCloud\Core;

use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * GitHub-flavoured Markdown (CommonMark + tables) → HTML for platform content (lab instructions).
 * Raw HTML in the source is escaped and unsafe link schemes (javascript:, data:, …) are dropped,
 * so the output is safe to print without e().
 */
final class Markdown
{
    private static ?GithubFlavoredMarkdownConverter $converter = null;

    public static function toHtml(string $markdown): string
    {
        self::$converter ??= new GithubFlavoredMarkdownConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
        ]);
        return (string) self::$converter->convert($markdown);
    }
}
