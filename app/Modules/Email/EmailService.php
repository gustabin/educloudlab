<?php

declare(strict_types=1);

namespace EduCloud\Modules\Email;

use EduCloud\Core\Db;

/**
 * Queues emails in email_outbox. Delivery happens out of band (scripts/mailer.php → Mailer),
 * so requests never block on SMTP and failures are retried.
 */
final class EmailService
{
    public const TEMPLATES = ['verify_email', 'password_reset', 'account_exists', 'password_changed', 'account_locked'];

    public function __construct(private readonly Db $db)
    {
    }

    /** @param array<string, string> $payload values interpolated into the template (escaped at render time) */
    public function queue(string $template, string $toEmail, array $payload, ?int $userId = null, string $locale = 'es'): int
    {
        if (!in_array($template, self::TEMPLATES, true)) {
            throw new \InvalidArgumentException("Unknown email template '$template'");
        }
        return $this->db->insert(
            'INSERT INTO email_outbox (user_id, to_email, template, locale, payload) VALUES (?, ?, ?, ?, ?)',
            [$userId, $toEmail, $template, $locale, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
        );
    }
}
