<?php

declare(strict_types=1);

namespace EduCloud\Tests\Integration\Auth;

use EduCloud\Tests\Support\AuthHelpers;
use EduCloud\Tests\TestCase;

final class LoginSessionTest extends TestCase
{
    use AuthHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetDatabase();
    }

    public function testLoginBeforeVerificationIsRefused(): void
    {
        $this->register('p@test.example');
        $r = $this->attemptLogin('p@test.example', $this->password);
        self::assertSame(403, $r->status);
        self::assertSame('EMAIL_NOT_VERIFIED', $r->decoded()['error']['code']);
        self::assertNull($r->cookie('ecsid'));
    }

    public function testLoginSetsHardenedSessionCookieAndMeReturnsTenant(): void
    {
        $this->createVerifiedUser('ok@test.example');
        $csrf = $this->csrfFromPage('/login');
        $r = $this->request('POST', '/api/v1/auth/login', ['email' => 'ok@test.example', 'password' => $this->password], ['X-CSRF-Token' => $csrf]);
        self::assertSame(200, $r->status);

        $raw = implode("\n", $r->cookies);
        self::assertMatchesRegularExpression('/^ecsid=[A-Za-z0-9_-]{43}; Path=\/; HttpOnly; SameSite=Lax$/m', $raw);
        $sessionId = (string) $r->cookie('ecsid');
        $db = $this->app()->db();
        self::assertSame(1, (int) $db->scalar('SELECT COUNT(*) FROM sessions WHERE id_hash = ?', [hash('sha256', $sessionId, true)]));
        self::assertSame(0, (int) $db->scalar('SELECT COUNT(*) FROM sessions WHERE id_hash = ?', [$sessionId]), 'raw id never stored');
        self::assertNotSame($csrf, $r->decoded()['data']['csrf_token'], 'CSRF token rotates with the session');

        $me = $this->request('GET', '/api/v1/auth/me');
        self::assertSame(200, $me->status);
        $data = $me->decoded()['data'];
        self::assertSame('ok@test.example', $data['user']['email']);
        self::assertSame('session', $data['auth_method']);
        self::assertSame('org_admin', $data['tenant']['role']);
        self::assertSame('personal', $data['tenant']['type']);
        self::assertArrayNotHasKey('password_hash', $data['user']);
        self::assertStringNotContainsString('"id":1', $me->body, 'internal ids never leave the server');
    }

    public function testUnknownEmailAndWrongPasswordAreIndistinguishable(): void
    {
        $this->createVerifiedUser('known@test.example');
        $wrong = $this->attemptLogin('known@test.example', 'contraseña equivocada 1');
        $unknown = $this->attemptLogin('unknown@test.example', 'contraseña equivocada 1');
        self::assertSame(401, $wrong->status);
        self::assertSame($wrong->status, $unknown->status);
        self::assertSame($wrong->decoded()['error'], $unknown->decoded()['error']);
    }

    public function testAccountLocksAfterRepeatedFailuresAndOwnerIsNotified(): void
    {
        $this->createVerifiedUser('lock@test.example');
        for ($i = 0; $i < 10; $i++) {
            $this->clientIp = '10.1.0.' . ($i % 3); // distributed attempts still count toward the lock
            self::assertSame(401, $this->attemptLogin('lock@test.example', 'mala contraseña número ' . $i)->status);
        }
        $this->clientIp = '127.0.0.1';
        self::assertSame(1, (int) $this->app()->db()->scalar("SELECT COUNT(*) FROM email_outbox WHERE template = 'account_locked'"));
        $correct = $this->attemptLogin('lock@test.example', $this->password);
        self::assertSame(401, $correct->status, 'correct password is refused while locked');
        self::assertSame('INVALID_CREDENTIALS', $correct->decoded()['error']['code']);

        $this->app()->db()->execute("UPDATE users SET locked_until = UTC_TIMESTAMP(3) - INTERVAL 1 SECOND WHERE email = 'lock@test.example'");
        self::assertSame(200, $this->attemptLogin('lock@test.example', $this->password)->status);
        self::assertSame(0, (int) $this->app()->db()->scalar("SELECT failed_login_count FROM users WHERE email = 'lock@test.example'"));
    }

    public function testIpRateLimitStopsBruteForce(): void
    {
        $csrf = $this->csrfFromPage('/login');
        $last = null;
        for ($i = 0; $i < 31; $i++) {
            $last = $this->request('POST', '/api/v1/auth/login', ['email' => "u$i@test.example", 'password' => 'x'], ['X-CSRF-Token' => $csrf]);
        }
        self::assertSame(429, $last->status);
        self::assertSame('RATE_LIMITED', $last->decoded()['error']['code']);
        self::assertGreaterThan(0, (int) $last->headers['Retry-After']);

        $this->clientIp = '10.0.0.2';
        $other = $this->request('POST', '/api/v1/auth/login', ['email' => 'u@test.example', 'password' => 'x'], ['X-CSRF-Token' => $csrf]);
        self::assertSame(401, $other->status, 'other clients are not affected');
    }

    public function testAttackerAtOtherIpCannotExhaustTheOwnersLoginAllowance(): void
    {
        $this->createVerifiedUser('dos@test.example');
        $this->clientIp = '203.0.113.9';
        for ($i = 0; $i < 9; $i++) {
            $this->attemptLogin('dos@test.example', 'adivinando claves ' . $i);
        }
        $this->clientIp = '127.0.0.1';
        self::assertSame(200, $this->attemptLogin('dos@test.example', $this->password)->status);
    }

    public function testLoginReplacesThePreviousSession(): void
    {
        $this->createVerifiedUser('twice@test.example');
        $sessionCsrf = $this->login('twice@test.example');
        $first = $this->cookieJar['ecsid'];
        // Logged-in users are redirected away from /login, so re-login uses the session-bound token.
        $r = $this->request('POST', '/api/v1/auth/login', ['email' => 'twice@test.example', 'password' => $this->password], [
            'X-CSRF-Token' => $sessionCsrf,
        ]);
        self::assertSame(200, $r->status);
        self::assertNotSame($first, $this->cookieJar['ecsid']);
        self::assertSame(1, (int) $this->app()->db()->scalar('SELECT COUNT(*) FROM sessions'), 'the old session is deleted');
    }

    public function testLogoutRequiresSessionBoundCsrfAndDestroysSession(): void
    {
        $this->createVerifiedUser('out@test.example');
        $anonToken = $this->csrfFromPage('/login');
        $sessionToken = $this->login('out@test.example');

        self::assertSame(403, $this->request('POST', '/api/v1/auth/logout', null, ['X-CSRF-Token' => $anonToken])->status);
        self::assertSame(403, $this->request('POST', '/api/v1/auth/logout')->status);

        $r = $this->request('POST', '/api/v1/auth/logout', null, ['X-CSRF-Token' => $sessionToken]);
        self::assertSame(204, $r->status);
        self::assertSame(0, (int) $this->app()->db()->scalar('SELECT COUNT(*) FROM sessions'));
        self::assertSame(401, $this->request('GET', '/api/v1/auth/me')->status);
    }

    public function testIdleSessionExpiresAndCookieIsCleared(): void
    {
        $this->createVerifiedUser('idle@test.example');
        $this->login('idle@test.example');
        $this->app()->db()->execute('UPDATE sessions SET last_activity_at = UTC_TIMESTAMP(3) - INTERVAL 31 MINUTE');

        $r = $this->request('GET', '/api/v1/auth/me');
        self::assertSame(401, $r->status);
        self::assertStringContainsString('Max-Age=0', implode("\n", $r->cookies));
        self::assertSame(0, (int) $this->app()->db()->scalar('SELECT COUNT(*) FROM sessions'));
    }

    public function testAbsoluteLifetimeIsEnforced(): void
    {
        $this->createVerifiedUser('abs@test.example');
        $this->login('abs@test.example');
        $this->app()->db()->execute('UPDATE sessions SET expires_at = UTC_TIMESTAMP(3) - INTERVAL 1 SECOND');
        self::assertSame(401, $this->request('GET', '/api/v1/auth/me')->status);
    }

    public function testAttackerChosenSessionIdIsNeverAdopted(): void
    {
        $this->createVerifiedUser('fix@test.example');
        $this->cookieJar['ecsid'] = 'attacker-chosen-session-id-0000000000000000';
        $this->login('fix@test.example');
        self::assertNotSame('attacker-chosen-session-id-0000000000000000', $this->cookieJar['ecsid']);
    }

    public function testDisabledUserLosesAccessImmediately(): void
    {
        $this->createVerifiedUser('dis@test.example');
        $this->login('dis@test.example');
        $this->app()->db()->execute("UPDATE users SET status = 'disabled' WHERE email = 'dis@test.example'");
        self::assertSame(401, $this->request('GET', '/api/v1/auth/me')->status);
    }

    public function testSuspendedMembershipIsDeniedEvenWithLiveSession(): void
    {
        $this->createVerifiedUser('mem@test.example');
        $this->login('mem@test.example');
        $this->app()->db()->execute("UPDATE memberships SET status = 'suspended'");
        self::assertSame(403, $this->request('GET', '/api/v1/auth/me')->status);
    }

    public function testDashboardRedirectsAnonymousVisitorsAndRendersForUsers(): void
    {
        $anon = $this->request('GET', '/app');
        self::assertSame(302, $anon->status);
        self::assertSame('/login?next=%2Fapp', $anon->headers['Location']);

        $this->createVerifiedUser('dash@test.example');
        $this->login('dash@test.example');
        $page = $this->request('GET', '/app');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Hola, Ana Prueba', $page->body);
        self::assertSame('noindex, nofollow', $page->headers['X-Robots-Tag']);
        self::assertSame('no-store', $page->headers['Cache-Control'], 'private pages are never cached');
        self::assertArrayNotHasKey('Cache-Control', $this->request('GET', '/')->headers, 'public pages stay cacheable');

        self::assertSame(302, $this->request('GET', '/login')->status, 'logged-in users skip the login page');
    }
}
