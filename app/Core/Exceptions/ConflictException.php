<?php

declare(strict_types=1);

namespace EduCloud\Core\Exceptions;

final class ConflictException extends ApiException
{
    public function __construct(string $message = 'La operación entra en conflicto con el estado actual.')
    {
        parent::__construct(409, 'CONFLICT', $message);
    }
}
