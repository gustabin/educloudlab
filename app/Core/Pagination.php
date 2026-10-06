<?php

declare(strict_types=1);

namespace EduCloud\Core;

use EduCloud\Core\Exceptions\ValidationException;

/** Validated page/per_page from the query string, plus the response meta block. */
final class Pagination
{
    public function __construct(public readonly int $page, public readonly int $perPage)
    {
    }

    /** @param array<string, mixed> $query */
    public static function fromQuery(array $query, int $default = 20, int $max = 100): self
    {
        $page = self::intParam($query, 'page', 1);
        $perPage = self::intParam($query, 'per_page', $default);
        if ($page < 1 || $page > 10000) {
            throw new ValidationException([['field' => 'page', 'code' => 'range', 'message' => 'La página no es válida.']]);
        }
        if ($perPage < 1 || $perPage > $max) {
            throw new ValidationException([['field' => 'per_page', 'code' => 'range', 'message' => "Debe estar entre 1 y $max."]]);
        }
        return new self($page, $perPage);
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /** @return array{page: int, per_page: int, total: int, total_pages: int} */
    public function meta(int $total): array
    {
        return [
            'page' => $this->page,
            'per_page' => $this->perPage,
            'total' => $total,
            'total_pages' => (int) max(1, (int) ceil($total / $this->perPage)),
        ];
    }

    /** @param array<string, mixed> $query */
    private static function intParam(array $query, string $name, int $default): int
    {
        if (!isset($query[$name]) || $query[$name] === '') {
            return $default;
        }
        $value = $query[$name];
        if (!is_string($value) || preg_match('/^\d{1,6}$/D', $value) !== 1) {
            throw new ValidationException([['field' => $name, 'code' => 'int', 'message' => 'Debe ser un número entero.']]);
        }
        return (int) $value;
    }
}
