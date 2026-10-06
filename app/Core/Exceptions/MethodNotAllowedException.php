<?php

declare(strict_types=1);

namespace EduCloud\Core\Exceptions;

final class MethodNotAllowedException extends ApiException
{
    /** @param list<string> $allowed */
    public function __construct(public readonly array $allowed)
    {
        parent::__construct(405, 'METHOD_NOT_ALLOWED', 'Método HTTP no permitido para este recurso.');
    }
}
