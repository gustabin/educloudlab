<?php

declare(strict_types=1);

namespace EduCloud\Core;

use EduCloud\Core\Exceptions\ValidationException;

/**
 * Declarative server-side validation. Unknown fields are rejected (strict allowlist).
 *
 * Rules: required, string, email, min:N, max:N (characters), in:a,b,c, regex:/.../, ulid, bool, int, nullable.
 * Returns only the declared fields, trimmed (strings) and normalised (email lower-cased).
 */
final class Validator
{
    private const MESSAGES = [
        'required' => 'Este campo es obligatorio.',
        'string' => 'Debe ser un texto.',
        'email' => 'Introduce un correo electrónico válido.',
        'min' => 'Debe tener al menos :n caracteres.',
        'max' => 'Debe tener como máximo :n caracteres.',
        'in' => 'El valor no es válido.',
        'regex' => 'El formato no es válido.',
        'ulid' => 'El identificador no es válido.',
        'bool' => 'Debe ser verdadero o falso.',
        'int' => 'Debe ser un número entero.',
        'unknown_field' => 'Campo no permitido.',
    ];

    /**
     * @param array<string, mixed>        $input
     * @param array<string, list<string>> $rules
     * @return array<string, mixed>
     */
    public static function validate(array $input, array $rules): array
    {
        $errors = [];
        $clean = [];

        foreach (array_keys($input) as $field) {
            if (!array_key_exists((string) $field, $rules)) {
                $errors[] = self::error((string) $field, 'unknown_field');
            }
        }

        foreach ($rules as $field => $fieldRules) {
            $present = array_key_exists($field, $input) && $input[$field] !== null && $input[$field] !== '';
            if (!$present) {
                if (in_array('required', $fieldRules, true)) {
                    $errors[] = self::error($field, 'required');
                }
                continue;
            }
            $value = $input[$field];
            $error = self::check($value, $fieldRules);
            if ($error !== null) {
                $errors[] = self::error($field, $error[0], $error[1]);
                continue;
            }
            $clean[$field] = $value;
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $clean;
    }

    /**
     * Checks one value; normalises it in place.
     *
     * @param list<string> $rules
     * @return array{0: string, 1: int}|null [code, n] of the first failing rule
     */
    private static function check(mixed &$value, array $rules): ?array
    {
        foreach ($rules as $rule) {
            [$name, $arg] = array_pad(explode(':', $rule, 2), 2, '');
            switch ($name) {
                case 'required':
                case 'nullable':
                    break;
                case 'string':
                case 'email':
                    if (!is_string($value)) {
                        return ['string', 0];
                    }
                    $value = trim($value);
                    if ($name === 'email') {
                        $value = mb_strtolower($value);
                        if (strlen($value) > 254 || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                            return ['email', 0];
                        }
                    }
                    break;
                case 'min':
                    if (!is_string($value) || mb_strlen($value) < (int) $arg) {
                        return ['min', (int) $arg];
                    }
                    break;
                case 'max':
                    if (!is_string($value) || mb_strlen($value) > (int) $arg) {
                        return ['max', (int) $arg];
                    }
                    break;
                case 'in':
                    if (!is_string($value) || !in_array($value, explode(',', $arg), true)) {
                        return ['in', 0];
                    }
                    break;
                case 'regex':
                    if (!is_string($value) || preg_match($arg, $value) !== 1) {
                        return ['regex', 0];
                    }
                    break;
                case 'ulid':
                    if (!is_string($value) || !Ulid::isValid($value)) {
                        return ['ulid', 0];
                    }
                    break;
                case 'bool':
                    if (!is_bool($value)) {
                        return ['bool', 0];
                    }
                    break;
                case 'int':
                    if (!is_int($value)) {
                        return ['int', 0];
                    }
                    break;
                default:
                    throw new \LogicException("Unknown validation rule '$name'");
            }
        }
        return null;
    }

    /** @return array{field: string, code: string, message: string} */
    private static function error(string $field, string $code, int $n = 0): array
    {
        return ['field' => $field, 'code' => $code, 'message' => str_replace(':n', (string) $n, self::MESSAGES[$code])];
    }
}
