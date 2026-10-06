<?php

declare(strict_types=1);

namespace EduCloud\Core\Exceptions;

final class UnauthorizedException extends ApiException
{
    public function __construct(string $message = 'Se requiere autenticación.')
    {
        parent::__construct(401, 'UNAUTHENTICATED', $message);
    }
}
