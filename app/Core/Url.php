<?php

declare(strict_types=1);

namespace EduCloud\Core;

/**
 * Builds application-relative URLs that honour the deployment base path
 * (e.g. "/educloudlab" when served from http://localhost/educloudlab/).
 * Configured once in bootstrap; templates use the url() and asset() helpers.
 */
final class Url
{
    /** Decoded base path, e.g. "/educloudlab" ("" when served from the domain root). */
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

    /** URL-encoded base path for use in href/src attributes and JavaScript (spaces become %20), e.g. "/educloudlab". */
    public static function baseHref(): string
    {
        if (self::$basePath === '') {
            return '';
        }
        return implode('/', array_map('rawurlencode', explode('/', self::$basePath)));
    }

    /** to('/register') -> "/educloudlab/register". The path is app-relative and must not contain a scheme. */
    public static function to(string $path = '/'): string
    {
        return self::baseHref() . '/' . ltrim($path, '/');
    }

    /**
     * Removes the base path from a decoded request path; paths outside the base are returned unchanged.
     * The base is matched case-insensitively because Windows/XAMPP serves "/EduCloudLab/" and
     * "/educloudlab/" from the same folder (ADR-013). Route matching after the base stays case-sensitive.
     */
    public static function stripBasePath(string $path, string $basePath): string
    {
        $basePath = rtrim($basePath, '/');
        $length = strlen($basePath);
        if ($length > 0) {
            $prefix = substr($path, 0, $length);
            $rest = substr($path, $length);
            if (strcasecmp($prefix, $basePath) === 0 && ($rest === '' || $rest[0] === '/')) {
                $path = $rest;
            }
        }
        return '/' . trim($path, '/');
    }
}
