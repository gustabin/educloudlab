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
 * SMTP credentials and payloads are never logged. Failures are stored and logged as an allowlisted category
 * (classify()); the raw SMTP message is only ever shown on the console by the operator diagnostic (sendTest()).
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
            $code = self::classify($e);
            $this->db->execute(
                'UPDATE email_outbox SET status = ?, last_error_code = ?,
                        send_after = UTC_TIMESTAMP(3) + INTERVAL ? SECOND,
                        payload = IF(? = 1, NULL, payload)
                  WHERE id = ?',
                [$final ? 'failed' : 'pending', $code, min(3600, 60 * (2 ** $attempts)), $final ? 1 : 0, $id]
            );
            $this->logger->error('email_failed', ['outbox_id' => $id, 'code' => $code, 'exception' => get_class($e), 'final' => $final]);
            return false;
        }
    }

    /** Error categories stored in email_outbox.last_error_code (never the raw SMTP text). */
    public const CODES = ['SMTP_AUTH', 'SMTP_CONNECT', 'SMTP_TLS', 'SMTP_SENDER', 'SMTP_RECIPIENT', 'SMTP_ERROR', 'SEND_ERROR'];

    /** Maps a delivery exception to an allowlisted category, from PHPMailer's (English) error messages. */
    public static function classify(Throwable $e): string
    {
        if (!$e instanceof MailException) {
            return 'SEND_ERROR';
        }
        $m = strtolower($e->getMessage());
        return match (true) {
            str_contains($m, 'authenticate') || str_contains($m, 'auth not accepted') || str_contains($m, 'password') => 'SMTP_AUTH',
            str_contains($m, 'starttls') || str_contains($m, 'certificate') || str_contains($m, 'ssl') || str_contains($m, 'tls')
                || str_contains($m, 'crypto') => 'SMTP_TLS',
            str_contains($m, 'from address failed') || str_contains($m, 'mail from') || str_contains($m, 'sender') => 'SMTP_SENDER',
            str_contains($m, 'recipients failed') || str_contains($m, 'rcpt to') || str_contains($m, 'recipient') => 'SMTP_RECIPIENT',
            str_contains($m, 'connect') || str_contains($m, 'timed out') || str_contains($m, 'timeout') => 'SMTP_CONNECT',
            default => 'SMTP_ERROR',
        };
    }

    /**
     * Operator diagnostic (CLI only: scripts/mailer.php --test): sends one message synchronously, outside the queue.
     * The SMTP error text and, with $debug, PHPMailer's SMTP dialogue go to $out (the console), with the password
     * and AUTH payloads masked. Only the result category is logged.
     *
     * @param callable(string): void $out
     * @return array{ok: bool, code: string|null}
     */
    public function sendTest(string $to, bool $debug, callable $out): array
    {
        $secret = (string) $this->config->get('mail.smtp.pass');
        $mask = static function (string $text) use ($secret): string {
            $text = $secret !== '' ? str_replace([$secret, base64_encode($secret)], '********', $text) : $text;
            // AUTH LOGIN/PLAIN payloads are bare base64 lines sent by the client: mask them all.
            $authPayload = '/^(\s*(?:CLIENT -> SERVER: )?)(?!(?:EHLO|HELO|MAIL|RCPT|DATA|QUIT|STARTTLS|AUTH))[A-Za-z0-9+\/=]{8,}\s*$/m';
            return (string) preg_replace($authPayload, '$1********', $text);
        };
        try {
            $this->send($to, [
                'subject' => 'EduCloud Lab: prueba de correo',
                'html' => '<p>Si lees esto, el envío de correo de EduCloud Lab funciona.</p>',
                'text' => 'Si lees esto, el envío de correo de EduCloud Lab funciona.',
            ], 0, $debug ? static function (string $line) use ($out, $mask): void {
                $out($mask(rtrim($line)));
            } : null);
            $this->logger->info('email_test', ['ok' => true, 'driver' => (string) $this->config->get('mail.driver', 'file')]);
            return ['ok' => true, 'code' => null];
        } catch (Throwable $e) {
            $code = self::classify($e);
            $out($code . ': ' . $mask($e->getMessage()));
            $this->logger->warning('email_test', ['ok' => false, 'code' => $code]);
            return ['ok' => false, 'code' => $code];
        }
    }

    /**
     * @param array{subject: string, html: string, text: string} $rendered
     * @param (callable(string): void)|null $debug receives PHPMailer's SMTP dialogue (diagnostic only)
     */
    private function send(string $to, array $rendered, int $id, ?callable $debug = null): void
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
            // ssl = implicit TLS (465), tls = STARTTLS (587), "" or none = plain connection (local relays, port 25).
            // Plain must disable PHPMailer's automatic STARTTLS, which previously was always forced.
            $encryption = strtolower(trim((string) $this->config->get('mail.smtp.encryption')));
            if ($encryption === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($encryption === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            }
            $mail->SMTPDebug = $debug === null ? 0 : 2;
            if ($debug !== null) {
                $mail->Debugoutput = static function (string $line) use ($debug): void {
                    $debug($line);
                };
            }
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
