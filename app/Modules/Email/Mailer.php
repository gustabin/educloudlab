<?php

declare(strict_types=1);

namespace EduCloud\Modules\Email;

use EduCloud\Core\Config;
use EduCloud\Core\Db;
use EduCloud\Core\Logger;
use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;
use Throwable;

/**
 * Delivers queued emails with PHPMailer.
 *  - driver "file": writes RFC 822 .eml files to {storage}/mail (development, no SMTP needed)
 *  - driver "smtp": sends through the configured SMTP server
 * After a successful send the payload (which may contain one-time links) is cleared.
 * SMTP credentials and payloads are never logged.
 */
final class Mailer
{
    public function __construct(
        private readonly Db $db,
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Processes up to $limit due messages.
     *
     * @return array{0: int, 1: int} [sent, failed]
     */
    public function processQueue(int $limit = 20): array
    {
        $sent = 0;
        $failed = 0;
        $rows = $this->db->select(
            "SELECT id FROM email_outbox WHERE status = 'pending' AND send_after <= UTC_TIMESTAMP(3) ORDER BY id LIMIT ?",
            [$limit]
        );
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            // Claim: only one worker can move a row from pending to sending.
            $claimed = $this->db->execute(
                "UPDATE email_outbox SET status = 'sending', attempts = attempts + 1 WHERE id = ? AND status = 'pending'",
                [$id]
            );
            if ($claimed !== 1) {
                continue;
            }
            $this->deliver($id) ? $sent++ : $failed++;
        }
        return [$sent, $failed];
    }

    private function deliver(int $id): bool
    {
        $msg = $this->db->selectOne('SELECT id, to_email, template, locale, payload, attempts FROM email_outbox WHERE id = ?', [$id]);
        if ($msg === null) {
            return false;
        }
        try {
            $payload = json_decode((string) $msg['payload'], true, 8, JSON_THROW_ON_ERROR);
            $rendered = Templates::render((string) $msg['template'], is_array($payload) ? $payload : []);
            $this->send((string) $msg['to_email'], $rendered, $id);

            $this->db->execute(
                "UPDATE email_outbox SET status = 'sent', sent_at = UTC_TIMESTAMP(3), payload = NULL, last_error_code = NULL WHERE id = ?",
                [$id]
            );
            $this->logger->info('email_sent', ['outbox_id' => $id, 'template' => $msg['template']]);
            return true;
        } catch (Throwable $e) {
            $attempts = (int) $msg['attempts'];
            $final = $attempts >= (int) $this->config->get('mail.max_attempts', 5);
            $code = $e instanceof MailException ? 'SMTP_ERROR' : 'SEND_ERROR';
            $this->db->execute(
                'UPDATE email_outbox SET status = ?, last_error_code = ?,
                        send_after = UTC_TIMESTAMP(3) + INTERVAL ? SECOND,
                        payload = IF(? = 1, NULL, payload)
                  WHERE id = ?',
                [$final ? 'failed' : 'pending', $code, min(3600, 60 * (2 ** $attempts)), $final ? 1 : 0, $id]
            );
            $this->logger->error('email_failed', ['outbox_id' => $id, 'exception' => get_class($e), 'final' => $final]);
            return false;
        }
    }

    /** @param array{subject: string, html: string, text: string} $rendered */
    private function send(string $to, array $rendered, int $id): void
    {
        $mail = new PHPMailer(true);
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->setFrom((string) $this->config->get('mail.from'), (string) $this->config->get('mail.from_name'));
        $mail->addAddress($to);
        $mail->Subject = $rendered['subject'];
        $mail->isHTML(true);
        $mail->Body = $rendered['html'];
        $mail->AltBody = $rendered['text'];

        $driver = (string) $this->config->get('mail.driver', 'file');
        if ($driver === 'smtp') {
            $mail->isSMTP();
            $mail->Host = (string) $this->config->get('mail.smtp.host');
            $mail->Port = (int) $this->config->get('mail.smtp.port');
            $mail->Timeout = (int) $this->config->get('mail.smtp.timeout', 15);
            $user = (string) $this->config->get('mail.smtp.user');
            if ($user !== '') {
                $mail->SMTPAuth = true;
                $mail->Username = $user;
                $mail->Password = (string) $this->config->get('mail.smtp.pass');
            }
            $encryption = (string) $this->config->get('mail.smtp.encryption');
            $mail->SMTPSecure = $encryption === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->SMTPDebug = 0;
            $mail->send();
            return;
        }

        // File driver: build the full MIME message without sending it.
        $mail->preSend();
        $dir = (string) $this->config->get('app.storage_path') . '/mail';
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create mail directory');
        }
        $file = sprintf('%s/%s-%06d.eml', $dir, gmdate('Ymd-His'), $id);
        if (file_put_contents($file, $mail->getSentMIMEMessage(), LOCK_EX) === false) {
            throw new \RuntimeException('Cannot write mail file');
        }
    }
}
