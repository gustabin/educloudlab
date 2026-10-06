<?php

declare(strict_types=1);

namespace EduCloud\Core\Exceptions;

final class RateLimitedException extends ApiException
{
    public function __construct(public readonly int $retryAfter, string $message = 'Demasiadas solicitudes. Inténtalo más tarde.')
    {
        parent::__construct(429, 'RATE_LIMITED', $message);
    }
}
