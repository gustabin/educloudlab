<?php

declare(strict_types=1);

namespace EduCloud\Core\Exceptions;

final class NotFoundException extends ApiException
{
    public function __construct(string $message = 'El recurso solicitado no existe.')
    {
        parent::__construct(404, 'NOT_FOUND', $message);
    }
}
