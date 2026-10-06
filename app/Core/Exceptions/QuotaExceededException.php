<?php

declare(strict_types=1);

namespace EduCloud\Core\Exceptions;

final class QuotaExceededException extends ApiException
{
    public function __construct(string $message = 'Se superó la cuota disponible.')
    {
        parent::__construct(409, 'QUOTA_EXCEEDED', $message);
    }
}
