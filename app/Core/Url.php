<?php

declare(strict_types=1);

namespace EduCloud\Core;

/**
 * Builds application-relative URLs that honour the deployment base path
 * (e.g. "/EduCloud Lab" when served from http://localhost/EduCloud%20Lab/).
 * Configured once in bootstrap; templates use the url() and asset() helpers.
 */
final class Url
{
    /** Decoded base path, e.g. "/EduCloud Lab" ("" when served from the domain root). */
    private static string $basePath = '';

    public static function setBasePath(string $basePath): void
    {
        $basePath = '/' . trim($basePath, '/');
        self::$basePath = $basePath === '/' ? '' : $basePath;
    }

    public static function basePath(): string
    {
        return self::$basePath;
    }

    /** URL-encoded base path for use in href/src attributes and JavaScript, e.g. "/EduCloud%20Lab". */
    public static function baseHref(): string
    {
        if (self::$basePath === '') {
            return '';
        }
        return implode('/', array_map('rawurlencode', explode('/', self::$basePath)));
    }

    /** to('/register') -> "/EduCloud%20Lab/register". The path is app-relative and must not contain a scheme. */
    public static function to(string $path = '/'): string
    {
        return self::baseHref() . '/' . ltrim($path, '/');
    }

    /** Removes the base path from a decoded request path; paths outside the base are returned unchanged. */
    public static function stripBasePath(string $path, string $basePath): string
    {
        $basePath = rtrim($basePath, '/');
        if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
            $path = substr($path, strlen($basePath));
        }
        return '/' . trim($path, '/');
    }
}
