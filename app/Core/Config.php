<?php

declare(strict_types=1);

namespace EduCloud\Core;

use Dotenv\Dotenv;
use RuntimeException;

/**
 * Read-only configuration loaded from config/*.php.
 * The .env file is parsed into an array (never into $_ENV/$_SERVER, so secrets are not exposed via globals).
 */
final class Config
{
    private const REQUIRED_ENV = ['APP_ENV', 'APP_URL', 'DB_HOST', 'DB_NAME', 'DB_USER', 'STORAGE_PATH', 'APP_HASH_KEY'];

    /** @param array<string, mixed> $items */
    public function __construct(private array $items)
    {
    }

    public static function load(string $rootPath): self
    {
        if (!is_file($rootPath . '/.env')) {
            throw new RuntimeException('Missing .env file. Copy .env.example to .env.');
        }
        $env = Dotenv::createArrayBacked($rootPath)->load();
        $missing = array_filter(self::REQUIRED_ENV, static fn (string $k): bool => ($env[$k] ?? '') === '');
        if ($missing !== []) {
            throw new RuntimeException('Missing required .env keys: ' . implode(', ', $missing));
        }

        $items = ['root_path' => $rootPath];
        foreach (glob($rootPath . '/config/*.php') ?: [] as $file) {
            $items[basename($file, '.php')] = (static fn (array $env): mixed => require $file)($env);
        }

        return new self($items);
    }

    /** Dot-notation lookup, e.g. get('app.debug'). */
    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    /** Returns a copy with one value overridden (used by tests). */
    public function with(string $key, mixed $value): self
    {
        $items = $this->items;
        $ref = &$items;
        foreach (explode('.', $key) as $segment) {
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }
        $ref = $value;
        unset($ref);
        return new self($items);
    }
}
