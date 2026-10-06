<?php

declare(strict_types=1);

namespace EduCloud\Core\Exceptions;

final class ValidationException extends ApiException
{
    /** @param list<array{field?: string, code: string, message: string}> $details */
    public function __construct(array $details, string $message = 'La solicitud contiene datos no válidos.')
    {
        parent::__construct(422, 'VALIDATION_ERROR', $message, $details);
    }
}
