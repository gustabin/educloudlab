<?php

declare(strict_types=1);

namespace EduCloud\Tests\Integration\Auth;

use EduCloud\Tests\Support\AuthHelpers;
use EduCloud\Tests\TestCase;

final class RegistrationTest extends TestCase
{
    use AuthHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetDatabase();
    }

    public function testRegisterRequiresCsrfToken(): void
    {
        $r = $this->request('POST', '/api/v1/auth/register', ['email' => 'a@test.example', 'password' => $this->password, 'display_name' => 'Ana']);
        self::assertSame(403, $r->status);
        self::assertSame('CSRF_INVALID', $r->decoded()['error']['code']);
        self::assertSame(0, (int) $this->app()->db()->scalar('SELECT COUNT(*) FROM users'));
    }

    public function testRegisterCreatesPendingUserWithPersonalTenantAndQueuesVerification(): void
    {
        $r = $this->register('Ana@Test.Example');
        self::assertSame(202, $r->status);

        $db = $this->app()->db();
        $user = $db->selectOne('SELECT id, email, status, password_hash, email_verified_at FROM users');
        self::assertSame('ana@test.example', $user['email'], 'email is normalised to lowercase');
        self::assertSame('pending', $user['status']);
        self::assertNull($user['email_verified_at']);
        self::assertStringStartsWith('$argon2id$', $user['password_hash']);
        self::assertStringNotContainsString($this->password, $user['password_hash']);

        $membership = $db->selectOne(
            'SELECT t.type, m.role FROM memberships m JOIN tenants t ON t.id = m.tenant_id WHERE m.user_id = ?',
            [$user['id']]
        );
        self::assertSame(['type' => 'personal', 'role' => 'org_admin'], $membership);

        $token = $this->tokenFromOutbox('ana@test.example', 'verify_email');
        $stored = $db->scalar("SELECT COUNT(*) FROM auth_tokens WHERE token_hash = ? AND type = 'email_verify'", [hash('sha256', $token, true)]);
        self::assertSame(1, (int) $stored, 'only the hash of the token is stored');
        self::assertSame(0, (int) $db->scalar('SELECT COUNT(*) FROM auth_tokens WHERE token_hash = ?', [$token]));

        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'auth.register' AND outcome = 'success'"));
    }

    public function testExistingEmailGetsIdenticalResponseAndOwnerIsNotified(): void
    {
        $first = $this->register('dup@test.example');
        $this->verify('dup@test.example');
        $second = $this->register('dup@test.example', 'otra frase distinta 2024', 'Intruso');

        self::assertSame($first->status, $second->status);
        self::assertSame($first->decoded()['message'], $second->decoded()['message']);
        $db = $this->app()->db();
        self::assertSame(1, (int) $db->scalar('SELECT COUNT(*) FROM users'));
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM email_outbox WHERE template = 'account_exists'"));
        $payload = (string) $db->scalar("SELECT payload FROM email_outbox WHERE template = 'account_exists'");
        self::assertStringNotContainsString('Intruso', $payload, 'no attacker-controlled text reaches the owner');
        self::assertSame(401, $this->attemptLogin('dup@test.example', 'otra frase distinta 2024')->status);
    }

    public function testPreRegistrationTakeoverIsPrevented(): void
    {
        $attackerPassword = 'contraseña del atacante 666';
        $this->register('victim@test.example', $attackerPassword, 'Atacante');
        $attackerLink = $this->tokenFromOutbox('victim@test.example', 'verify_email');

        // The real owner signs up later: their registration replaces the pending credentials.
        $this->register('victim@test.example', $this->password, 'Víctima');
        self::assertSame(422, $this->verifyToken($attackerLink, $attackerPassword)->status, 'old link is invalidated');

        $ownerLink = $this->tokenFromOutbox('victim@test.example', 'verify_email');
        $withAttackerPassword = $this->verifyToken($ownerLink, $attackerPassword);
        self::assertSame(422, $withAttackerPassword->status, 'the link alone is useless without the owner password');
        self::assertSame('password', $withAttackerPassword->decoded()['error']['details'][0]['field']);

        self::assertSame(200, $this->verifyToken($ownerLink, $this->password)->status);
        self::assertSame(401, $this->attemptLogin('victim@test.example', $attackerPassword)->status);
        self::assertSame(200, $this->attemptLogin('victim@test.example', $this->password)->status);
        self::assertSame(1, (int) $this->app()->db()->scalar('SELECT COUNT(*) FROM users'));
    }

    public function testVerificationNeedsPasswordAndWrongPasswordKeepsTheToken(): void
    {
        $this->register('pw@test.example');
        $token = $this->tokenFromOutbox('pw@test.example', 'verify_email');
        self::assertSame(422, $this->verifyToken($token, 'no es mi contraseña 123')->status);
        self::assertSame('pending', $this->app()->db()->scalar("SELECT status FROM users WHERE email = 'pw@test.example'"));
        self::assertSame(200, $this->verifyToken($token, $this->password)->status);
    }

    public function testVerificationEmailContainsNoUserSuppliedName(): void
    {
        $this->register('n@test.example', null, 'Visita evil punto example');
        $payload = (string) $this->app()->db()->scalar("SELECT payload FROM email_outbox WHERE template = 'verify_email'");
        self::assertStringNotContainsString('evil', $payload);
    }

    /** @return iterable<string, array{string, string}> */
    public static function weakPasswords(): iterable
    {
        yield 'too short' => ['corta', 'min'];
        yield 'too long' => [str_repeat('x', 129) . 'y', 'max'];
        yield 'common' => ['password1234', 'weak_password'];
        yield 'contains email local part' => ['mi correo es anagarcia', 'weak_password'];
        yield 'repeated character' => ['aaaaaaaaaaaaaa', 'weak_password'];
    }

    /** @dataProvider weakPasswords */
    public function testWeakPasswordsAreRejected(string $password, string $code): void
    {
        $r = $this->register('anagarcia@test.example', $password);
        self::assertSame(422, $r->status);
        self::assertSame('password', $r->decoded()['error']['details'][0]['field']);
        self::assertSame($code, $r->decoded()['error']['details'][0]['code']);
        self::assertSame(0, (int) $this->app()->db()->scalar('SELECT COUNT(*) FROM users'));
    }

    public function testMassAssignmentOfPrivilegedFieldsIsRejected(): void
    {
        $csrf = $this->csrfFromPage('/register');
        $r = $this->request('POST', '/api/v1/auth/register', [
            'email' => 'x@test.example',
            'password' => $this->password,
            'display_name' => 'X',
            'is_platform_admin' => true,
            'status' => 'active',
        ], ['X-CSRF-Token' => $csrf]);
        self::assertSame(422, $r->status);
        $fields = array_column($r->decoded()['error']['details'], 'code', 'field');
        self::assertSame('unknown_field', $fields['is_platform_admin']);
        self::assertSame('unknown_field', $fields['status']);
        self::assertSame(0, (int) $this->app()->db()->scalar('SELECT COUNT(*) FROM users'));
    }

    public function testDisplayNameRejectsMarkup(): void
    {
        $r = $this->register('x@test.example', null, '<script>alert(1)</script>');
        self::assertSame(422, $r->status);
        self::assertSame('display_name', $r->decoded()['error']['details'][0]['field']);
    }

    public function testVerificationTokenIsSingleUseAndActivatesAccount(): void
    {
        $this->register('v@test.example');
        $token = $this->tokenFromOutbox('v@test.example', 'verify_email');

        self::assertSame(200, $this->verifyToken($token, $this->password)->status);
        self::assertSame('active', $this->app()->db()->scalar("SELECT status FROM users WHERE email = 'v@test.example'"));

        $again = $this->verifyToken($token, $this->password);
        self::assertSame(422, $again->status);
        self::assertSame('TOKEN_INVALID', $again->decoded()['error']['code']);
    }

    public function testExpiredVerificationTokenIsRejected(): void
    {
        $this->register('exp@test.example');
        $this->app()->db()->execute("UPDATE auth_tokens SET expires_at = UTC_TIMESTAMP(3) - INTERVAL 1 MINUTE");
        $r = $this->verify('exp@test.example');
        self::assertSame(422, $r->status);
        self::assertSame('pending', $this->app()->db()->scalar("SELECT status FROM users WHERE email = 'exp@test.example'"));
    }

    public function testResendIsGenericAndInvalidatesPreviousToken(): void
    {
        $this->register('re@test.example');
        $old = $this->tokenFromOutbox('re@test.example', 'verify_email');
        $csrf = $this->csrfFromPage('/verify-email');

        $known = $this->request('POST', '/api/v1/auth/verify-email/resend', ['email' => 're@test.example'], ['X-CSRF-Token' => $csrf]);
        $unknown = $this->request('POST', '/api/v1/auth/verify-email/resend', ['email' => 'nobody@test.example'], ['X-CSRF-Token' => $csrf]);
        self::assertSame(202, $known->status);
        self::assertSame($known->decoded()['message'], $unknown->decoded()['message']);

        $r = $this->verifyToken($old, $this->password);
        self::assertSame(422, $r->status, 'the previous token is invalidated by a resend');
        self::assertSame(200, $this->verify('re@test.example')->status);
    }
}
