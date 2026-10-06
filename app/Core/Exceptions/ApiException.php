<?php

declare(strict_types=1);

namespace EduCloud\Core\Exceptions;

use RuntimeException;

/**
 * Domain/HTTP exceptions mapped by ErrorHandler to the JSON error envelope.
 * Messages are user-safe by construction; never pass raw driver/system messages into them.
 */
class ApiException extends RuntimeException
{
    /** @param list<array{field?: string, code: string, message: string}> $details */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
