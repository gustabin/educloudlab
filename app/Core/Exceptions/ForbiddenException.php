<?php

declare(strict_types=1);

namespace EduCloud\Core\Exceptions;

final class ForbiddenException extends ApiException
{
    public function __construct(string $message = 'No tienes permiso para realizar esta acción.')
    {
        parent::__construct(403, 'FORBIDDEN', $message);
    }
}
