<?php

declare(strict_types=1);

namespace EduCloud\Tests\Integration\Auth;

use EduCloud\Core\App;
use EduCloud\Modules\Email\Mailer;
use EduCloud\Tests\Support\AuthHelpers;
use EduCloud\Tests\TestCase;
use PHPMailer\PHPMailer\Exception as MailException;

/** Email delivery failures are categorised (no raw SMTP text stored or logged); operator test send. */
final class MailerDiagnosticsTest extends TestCase
{
    use AuthHelpers;

    private const SECRET = 'smtp-Secret-Pa55';

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetDatabase();
    }

    /** A closed local port: the connection is refused immediately. */
    private function unreachableSmtp(): void
    {
        $config = $this->app()->config
            ->with('mail.driver', 'smtp')
            ->with('mail.smtp.host', '127.0.0.1')
            ->with('mail.smtp.port', 1)
            ->with('mail.smtp.user', 'mailer@test.example')
            ->with('mail.smtp.pass', self::SECRET)
            ->with('mail.smtp.encryption', '')
            ->with('mail.smtp.timeout', 3);
        $this->app = App::create($config, testDatabase: true);
    }

    public function testClassifyMapsPhpMailerMessagesToAllowlistedCodes(): void
    {
        $cases = [
            'SMTP Error: Could not authenticate.' => 'SMTP_AUTH',
            'SMTP Error: Could not connect to SMTP host. Failed to connect to server' => 'SMTP_CONNECT',
            'SMTP connect() failed: connection timed out' => 'SMTP_CONNECT',
            'STARTTLS command failed' => 'SMTP_TLS',
            'SMTP Error: The following From address failed: x@y' => 'SMTP_SENDER',
            'SMTP Error: The following recipients failed: a@b' => 'SMTP_RECIPIENT',
            'Something unexpected' => 'SMTP_ERROR',
        ];
        foreach ($cases as $message => $code) {
            self::assertSame($code, Mailer::classify(new MailException($message)), $message);
            self::assertContains($code, Mailer::CODES);
        }
        self::assertSame('SEND_ERROR', Mailer::classify(new \RuntimeException('Cannot write mail file')));
    }

    public function testFailedDeliveryStoresOnlyACategoryAndLogsNoSmtpText(): void
    {
        self::assertSame(202, $this->register('ana@test.example')->status); // queues a verification email
        $this->unreachableSmtp();
        $mailer = new Mailer($this->app()->db(), $this->app()->config, $this->app()->logger);
        self::assertSame([0, 1], $mailer->processQueue());

        $row = $this->app()->db()->selectOne('SELECT status, attempts, last_error_code FROM email_outbox ORDER BY id DESC LIMIT 1');
        self::assertSame(['pending', 1, 'SMTP_CONNECT'], [$row['status'], (int) $row['attempts'], $row['last_error_code']]);
        $log = implode("\n", $this->logLines());
        self::assertStringContainsString('"event":"email_failed"', $log);
        self::assertStringContainsString('"code":"SMTP_CONNECT"', $log);
        self::assertStringNotContainsString('Failed to connect', $log, 'no raw SMTP text in the log');
        self::assertStringNotContainsString(self::SECRET, $log);
    }

    public function testOperatorTestSendExplainsTheFailureOnTheConsoleOnly(): void
    {
        $this->unreachableSmtp();
        $mailer = new Mailer($this->app()->db(), $this->app()->config, $this->app()->logger);
        $console = [];
        $result = $mailer->sendTest('ops@test.example', true, static function (string $line) use (&$console): void {
            $console[] = $line;
        });
        self::assertSame(['ok' => false, 'code' => 'SMTP_CONNECT'], $result);
        $out = implode("\n", $console);
        self::assertStringContainsString('SMTP_CONNECT: ', $out);
        self::assertStringNotContainsString(self::SECRET, $out, 'password masked even in verbose output');
        self::assertSame(0, (int) $this->app()->db()->scalar('SELECT COUNT(*) FROM email_outbox'), 'the test send bypasses the queue');
        $log = implode("\n", $this->logLines());
        self::assertStringContainsString('"event":"email_test"', $log);
        self::assertStringNotContainsString(self::SECRET, $log);
    }

    public function testOperatorTestSendWithTheFileDriverSucceeds(): void
    {
        $mailer = new Mailer($this->app()->db(), $this->app()->config->with('mail.driver', 'file'), $this->app()->logger);
        $result = $mailer->sendTest('ops@test.example', false, static function (): void {
        });
        self::assertSame(['ok' => true, 'code' => null], $result);
        self::assertNotEmpty(glob($this->storagePath . '/mail/*.eml') ?: []);
    }
}
