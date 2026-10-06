<?php

declare(strict_types=1);

namespace EduCloud\Core\Exceptions;

final class PayloadTooLargeException extends ApiException
{
    public function __construct(string $message = 'El cuerpo de la solicitud es demasiado grande.')
    {
        parent::__construct(413, 'PAYLOAD_TOO_LARGE', $message);
    }
}
