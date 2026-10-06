<?php

declare(strict_types=1);

namespace EduCloud\Modules\Resources;

use EduCloud\Core\Exceptions\ValidationException;

/**
 * Resource types and their configuration schema (allowlisted keys and values only).
 * Types created by other modules (dataset via uploads in M4; pipeline/notebook/dashboard later)
 * cannot be created through the generic resources endpoint.
 */
final class ResourceTypes
{
    /** Types a user can create directly in M3. */
    public const CREATABLE = ['storage', 'lakehouse'];

    /** Simulated regions: teach the concept without real infrastructure. */
    public const REGIONS = ['edu-local-1', 'edu-local-2'];

    /** key => [allowed values (list) | 'bool', default] */
    private const CONFIG = [
        'storage' => [
            'access_tier' => [['hot', 'cool', 'archive'], 'hot'],
            'versioning' => ['bool', false],
            'redundancy' => [['lrs', 'zrs'], 'lrs'],
        ],
        'lakehouse' => [
            'default_layer' => [['bronze', 'silver', 'gold'], 'bronze'],
        ],
    ];

    /**
     * Validates a config object for a type and fills defaults.
     *
     * @param array<string, mixed>|null $config
     * @param array<string, mixed>      $current existing config (for partial updates)
     * @return array<string, mixed>
     */
    public static function normaliseConfig(string $type, ?array $config, array $current = []): array
    {
        $schema = self::CONFIG[$type] ?? [];
        $out = [];
        foreach ($schema as $key => [, $default]) {
            $out[$key] = $current[$key] ?? $default;
        }
        $errors = [];
        foreach ($config ?? [] as $key => $value) {
            if (!isset($schema[$key])) {
                $errors[] = ['field' => "config.$key", 'code' => 'unknown_field', 'message' => 'Opción de configuración no permitida.'];
                continue;
            }
            [$allowed] = $schema[$key];
            $ok = $allowed === 'bool' ? is_bool($value) : (is_string($value) && in_array($value, $allowed, true));
            if (!$ok) {
                $errors[] = ['field' => "config.$key", 'code' => 'in', 'message' => 'El valor no es válido.'];
                continue;
            }
            $out[$key] = $value;
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $out;
    }

    /**
     * Tags: flat object, keys ^[a-z0-9_-]{1,32}$, string values up to 64 chars, at most $max entries.
     *
     * @return array<string, string>
     */
    public static function normaliseTags(mixed $tags, int $max): array
    {
        if ($tags === null) {
            return [];
        }
        if (!is_array($tags) || ($tags !== [] && array_is_list($tags))) {
            throw new ValidationException([['field' => 'tags', 'code' => 'object', 'message' => 'Las etiquetas deben ser pares clave-valor.']]);
        }
        if (count($tags) > $max) {
            throw new ValidationException([['field' => 'tags', 'code' => 'max', 'message' => "Como máximo $max etiquetas."]]);
        }
        $out = [];
        foreach ($tags as $key => $value) {
            $key = (string) $key;
            if (
                preg_match('/^[a-z0-9_-]{1,32}$/D', $key) !== 1 || !is_string($value) || mb_strlen($value) > 64
                || preg_match('/[\x00-\x1F<>]/', $value) === 1
            ) {
                throw new ValidationException([['field' => "tags.$key", 'code' => 'invalid', 'message' => 'Etiqueta no válida.']]);
            }
            $out[$key] = $value;
        }
        ksort($out);
        return $out;
    }
}
