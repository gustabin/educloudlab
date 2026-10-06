<?php

declare(strict_types=1);

namespace EduCloud\Modules\Audit;

use EduCloud\Core\Db;
use EduCloud\Core\Logger;
use EduCloud\Core\Request;
use Throwable;

/**
 * Append-only audit trail (audit_logs). IPs are stored only as HMAC hashes.
 * Never put passwords, tokens or dataset contents in $meta.
 * Audit failures are logged but never break the user's request.
 */
final class AuditLogger
{
    public function __construct(
        private readonly Db $db,
        private readonly Logger $logger,
        private readonly string $hashKey,
    ) {
    }

    /** @param array<string, mixed> $meta */
    public function record(
        Request $request,
        string $action,
        string $outcome,
        ?int $tenantId = null,
        ?int $actorUserId = null,
        ?string $resourceType = null,
        ?string $resourcePublicId = null,
        array $meta = [],
    ): void {
        try {
            $this->db->execute(
                'INSERT INTO audit_logs
                    (request_id, tenant_id, actor_user_id, action, resource_type, resource_public_id, outcome, ip_hash, meta)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $request->requestId !== '' ? $request->requestId : null,
                    $tenantId,
                    $actorUserId,
                    $action,
                    $resourceType,
                    $resourcePublicId,
                    $outcome,
                    hash_hmac('sha256', 'ip|' . $request->ip, $this->hashKey, true),
                    $meta === [] ? null : json_encode(Logger::redact($meta), JSON_UNESCAPED_UNICODE),
                ]
            );
        } catch (Throwable $e) {
            $this->logger->error('audit_write_failed', ['action' => $action, 'exception' => get_class($e)]);
        }
    }
}
