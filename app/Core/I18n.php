<?php

declare(strict_types=1);

namespace EduCloud\Core;

/**
 * Minimal translation layer. Strings live in app/lang/{locale}.php as flat "dot.key" => "text" arrays.
 * Placeholders use :name and are replaced verbatim (escape at output time with e()).
 */
final class I18n
{
    /** @var array<string, array<string, string>> */
    private static array $catalogs = [];
    private static string $locale = 'es';
    private static string $langPath = __DIR__ . '/../lang';

    public static function setLocale(string $locale): void
    {
        if (preg_match('/^[a-z]{2}(-[A-Z]{2})?$/D', $locale) === 1) {
            self::$locale = $locale;
        }
    }

    public static function locale(): string
    {
        return self::$locale;
    }

    /** @param array<string, string|int|float> $params */
    public static function get(string $key, array $params = []): string
    {
        $catalog = self::catalog(self::$locale);
        $text = $catalog[$key] ?? self::catalog('es')[$key] ?? $key;
        foreach ($params as $name => $value) {
            $text = str_replace(':' . $name, (string) $value, $text);
        }
        return $text;
    }

    /** @return array<string, string> */
    private static function catalog(string $locale): array
    {
        if (!isset(self::$catalogs[$locale])) {
            $file = self::$langPath . '/' . $locale . '.php';
            $data = is_file($file) ? require $file : [];
            self::$catalogs[$locale] = is_array($data) ? $data : [];
        }
        return self::$catalogs[$locale];
    }
}
