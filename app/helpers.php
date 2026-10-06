<?php

declare(strict_types=1);

use EduCloud\Core\I18n;

if (!function_exists('e')) {
    /** HTML-escape for text and quoted attribute contexts. Use for EVERY dynamic value in templates. */
    function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        }
        if (!is_scalar($value) && !$value instanceof Stringable) {
            return '';
        }
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('t')) {
    /**
     * Translated string (NOT escaped - wrap with e() in templates).
     *
     * @param array<string, string|int|float> $params
     */
    function t(string $key, array $params = []): string
    {
        return I18n::get($key, $params);
    }
}

if (!function_exists('asset')) {
    /** Public asset URL with cache-busting version derived from the file's mtime. */
    function asset(string $path): string
    {
        $path = ltrim($path, '/');
        if (str_contains($path, '..')) {
            return '';
        }
        $file = dirname(__DIR__) . '/public/assets/' . $path;
        $version = is_file($file) ? (string) filemtime($file) : '0';
        return '/assets/' . $path . '?v=' . $version;
    }
}
