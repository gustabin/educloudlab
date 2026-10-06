<?php

declare(strict_types=1);

namespace EduCloud\Modules\Resources;

use EduCloud\Core\Exceptions\ApiException;

/**
 * Educational resource lifecycle (mirrors how cloud resources are provisioned and torn down):
 *
 *   provisioning ──► active ──► deleting ──► deleted
 *        │                         ▲
 *        └──────► failed ──────────┘
 *
 * Any other transition is rejected with 409.
 */
final class ResourceLifecycle
{
    public const TRANSITIONS = [
        'provisioning' => ['active', 'failed'],
        'active' => ['deleting'],
        'failed' => ['deleting'],
        'deleting' => ['deleted'],
        'deleted' => [],
    ];

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function assertTransition(string $from, string $to): void
    {
        if (!self::canTransition($from, $to)) {
            throw new ApiException(409, 'INVALID_STATE', "No se puede pasar el recurso de «{$from}» a «{$to}».");
        }
    }
}
