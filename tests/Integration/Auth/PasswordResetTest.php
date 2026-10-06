<?php

declare(strict_types=1);

namespace EduCloud\Tests\Integration\Auth;

use EduCloud\Tests\Support\AuthHelpers;
use EduCloud\Tests\TestCase;

final class PasswordResetTest extends TestCase
{
    use AuthHelpers;

    private string $newPassword = 'mi nueva frase secreta 2026';

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetDatabase();
    }

    private function forgot(string $email): \EduCloud\Core\Response
    {
        $csrf = $this->csrfFromPage('/forgot-password');
        return $this->request('POST', '/api/v1/auth/password/forgot', ['email' => $email], ['X-CSRF-Token' => $csrf]);
    }

    private function reset(string $token, string $password): \EduCloud\Core\Response
    {
        $csrf = $this->csrfFromPage('/reset-password?token=' . $token);
        return $this->request('POST', '/api/v1/auth/password/reset', ['token' => $token, 'password' => $password], ['X-CSRF-Token' => $csrf]);
    }

    public function testForgotIsGenericForKnownAndUnknownAccounts(): void
    {
        $this->createVerifiedUser('known@test.example');
        $known = $this->forgot('known@test.example');
        $unknown = $this->forgot('unknown@test.example');
        self::assertSame(202, $known->status);
        self::assertSame($known->decoded()['message'], $unknown->decoded()['message']);
        self::assertSame(1, (int) $this->app()->db()->scalar("SELECT COUNT(*) FROM email_outbox WHERE template = 'password_reset'"));
    }

    public function testResetChangesPasswordAndRevokesAllSessionsAndRefreshTokens(): void
    {
        $this->createVerifiedUser('r@test.example');
        $this->login('r@test.example');
        $pair = $this->issueTokens('r@test.example');
        $this->forgot('r@test.example');
        $token = $this->tokenFromOutbox('r@test.example', 'password_reset');

        $r = $this->reset($token, $this->newPassword);
        self::assertSame(200, $r->status, $r->body);

        $db = $this->app()->db();
        self::assertSame(0, (int) $db->scalar('SELECT COUNT(*) FROM sessions'), 'all sessions are revoked');
        self::assertSame(401, $this->request('GET', '/api/v1/auth/me')->status);
        $refresh = $this->request('POST', '/api/v1/auth/tokens/refresh', ['refresh_token' => $pair['refresh_token']]);
        self::assertSame(401, $refresh->status, 'refresh tokens are revoked');

        self::assertSame(401, $this->attemptLogin('r@test.example', $this->password)->status, 'old password no longer works');
        self::assertSame(200, $this->attemptLogin('r@test.example', $this->newPassword)->status);
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM email_outbox WHERE template = 'password_changed'"));
    }

    public function testResetTokenIsSingleUse(): void
    {
        $this->createVerifiedUser('once@test.example');
        $this->forgot('once@test.example');
        $token = $this->tokenFromOutbox('once@test.example', 'password_reset');
        self::assertSame(200, $this->reset($token, $this->newPassword)->status);
        $again = $this->reset($token, 'otra frase secreta más 99');
        self::assertSame(422, $again->status);
        self::assertSame('TOKEN_INVALID', $again->decoded()['error']['code']);
    }

    public function testWeakPasswordDoesNotBurnTheToken(): void
    {
        $this->createVerifiedUser('weak@test.example');
        $this->forgot('weak@test.example');
        $token = $this->tokenFromOutbox('weak@test.example', 'password_reset');
        self::assertSame(422, $this->reset($token, 'corta')->status);
        self::assertSame(200, $this->reset($token, $this->newPassword)->status);
    }

    public function testNewRequestInvalidatesPreviousResetLink(): void
    {
        $this->createVerifiedUser('two@test.example');
        $this->forgot('two@test.example');
        $first = $this->tokenFromOutbox('two@test.example', 'password_reset');
        $this->forgot('two@test.example');
        self::assertSame(422, $this->reset($first, $this->newPassword)->status);
    }

    public function testResetUnlocksAccountAndVerifiesEmail(): void
    {
        $this->register('pend@test.example');
        $this->app()->db()->execute("UPDATE users SET failed_login_count = 9, locked_until = UTC_TIMESTAMP(3) + INTERVAL 1 HOUR");
        $this->forgot('pend@test.example');
        $token = $this->tokenFromOutbox('pend@test.example', 'password_reset');
        self::assertSame(200, $this->reset($token, $this->newPassword)->status);
        self::assertSame(200, $this->attemptLogin('pend@test.example', $this->newPassword)->status);
    }
}
