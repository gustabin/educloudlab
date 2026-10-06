<?php

declare(strict_types=1);

namespace EduCloud\Tests\Security;

use EduCloud\Core\Ulid;
use EduCloud\Tests\Support\AuthHelpers;
use EduCloud\Tests\TestCase;
use Firebase\JWT\JWT;

final class JwtTest extends TestCase
{
    use AuthHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetDatabase();
        $this->createVerifiedUser('api@test.example');
    }

    private function me(string $token): \EduCloud\Core\Response
    {
        $this->cookieJar = [];
        return $this->request('GET', '/api/v1/auth/me', null, ['Authorization' => 'Bearer ' . $token]);
    }

    /** @param array<string, mixed> $overrides */
    private function forge(array $overrides = [], ?string $key = null, string $kid = 'k1'): string
    {
        $keys = (string) $this->app()->config->get('security.jwt.keys');
        $raw = base64_decode(explode(':', explode(',', $keys)[0], 2)[1]);
        $sub = (string) $this->app()->db()->scalar("SELECT public_id FROM users WHERE email = 'api@test.example'");
        $tid = (string) $this->app()->db()->scalar("SELECT public_id FROM tenants LIMIT 1");
        $claims = $overrides + [
            'iss' => $this->app()->config->get('security.jwt.issuer'),
            'aud' => $this->app()->config->get('security.jwt.audience'),
            'sub' => $sub, 'tid' => $tid, 'iat' => time(), 'nbf' => time(), 'exp' => time() + 600, 'jti' => Ulid::generate(),
        ];
        return JWT::encode($claims, $key ?? $raw, 'HS256', $kid);
    }

    public function testIssuedAccessTokenAuthenticates(): void
    {
        $pair = $this->issueTokens('api@test.example');
        self::assertSame('Bearer', $pair['token_type']);
        self::assertSame(900, $pair['expires_in']);
        $r = $this->me($pair['access_token']);
        self::assertSame(200, $r->status);
        self::assertSame('jwt', $r->decoded()['data']['auth_method']);
    }

    public function testValidForgedTokenWithRealKeyWorks(): void
    {
        self::assertSame(200, $this->me($this->forge())->status, 'sanity check for the negative cases below');
    }

    /** @return iterable<string, array{0: callable(self): string}> */
    public static function badTokens(): iterable
    {
        yield 'tampered signature' => [fn (self $t): string => substr($t->forge(), 0, -2) . 'xx'];
        yield 'tampered payload' => [function (self $t): string {
            [$h, , $s] = explode('.', $t->forge());
            $p = rtrim(strtr(base64_encode((string) json_encode(['sub' => Ulid::generate(), 'exp' => time() + 999])), '+/', '-_'), '=');
            return "$h.$p.$s";
        }];
        yield 'alg none' => [function (self $t): string {
            $h = rtrim(strtr(base64_encode('{"typ":"JWT","alg":"none","kid":"k1"}'), '+/', '-_'), '=');
            [, $p] = explode('.', $t->forge());
            return "$h.$p.";
        }];
        yield 'wrong key' => [fn (self $t): string => $t->forge([], random_bytes(32))];
        yield 'unknown kid' => [fn (self $t): string => $t->forge([], null, 'k9')];
        yield 'expired' => [fn (self $t): string => $t->forge(['exp' => time() - 120, 'iat' => time() - 1000, 'nbf' => time() - 1000])];
        yield 'not yet valid' => [fn (self $t): string => $t->forge(['nbf' => time() + 600])];
        yield 'wrong audience' => [fn (self $t): string => $t->forge(['aud' => 'another-service'])];
        yield 'wrong issuer' => [fn (self $t): string => $t->forge(['iss' => 'https://evil.example'])];
        yield 'unknown subject' => [fn (self $t): string => $t->forge(['sub' => Ulid::generate()])];
        yield 'tenant without membership' => [fn (self $t): string => $t->forge(['tid' => Ulid::generate()])];
        yield 'garbage' => [fn (self $t): string => 'not-a-jwt'];
        yield 'missing iat' => [fn (self $t): string => $t->forge(['iat' => null])];
        yield 'missing exp' => [fn (self $t): string => $t->forge(['exp' => null])];
    }

    /** @dataProvider badTokens */
    public function testRejectsInvalidTokens(callable $make): void
    {
        $r = $this->me($make($this));
        self::assertContains($r->status, [401, 403], $r->body);
        self::assertFalse($r->decoded()['success']);
    }

    public function testDisabledUserTokenStopsWorking(): void
    {
        $token = $this->issueTokens('api@test.example')['access_token'];
        $this->app()->db()->execute("UPDATE users SET status = 'disabled'");
        self::assertSame(401, $this->me($token)->status);
    }

    public function testBearerCannotUseSessionOnlyRoutes(): void
    {
        $token = $this->issueTokens('api@test.example')['access_token'];
        $this->cookieJar = [];
        $r = $this->request('POST', '/api/v1/auth/logout', null, ['Authorization' => 'Bearer ' . $token]);
        self::assertSame(401, $r->status);
    }

    public function testRefreshRotatesAndReuseRevokesTheWholeFamily(): void
    {
        $first = $this->issueTokens('api@test.example');
        $second = $this->request('POST', '/api/v1/auth/tokens/refresh', ['refresh_token' => $first['refresh_token']]);
        self::assertSame(200, $second->status);
        $secondPair = $second->decoded()['data'];
        self::assertNotSame($first['refresh_token'], $secondPair['refresh_token']);
        self::assertSame(200, $this->me($secondPair['access_token'])->status);

        // Replaying the first (already rotated) refresh token = theft signal.
        $replay = $this->request('POST', '/api/v1/auth/tokens/refresh', ['refresh_token' => $first['refresh_token']]);
        self::assertSame(401, $replay->status);
        $victim = $this->request('POST', '/api/v1/auth/tokens/refresh', ['refresh_token' => $secondPair['refresh_token']]);
        self::assertSame(401, $victim->status, 'the whole family is revoked after reuse');
        self::assertGreaterThanOrEqual(1, (int) $this->app()->db()->scalar(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'auth.token_refresh' AND outcome = 'denied'"
        ), 'reuse is recorded in the audit log');
    }

    public function testRevokeAndExpiry(): void
    {
        $pair = $this->issueTokens('api@test.example');
        self::assertSame(204, $this->request('POST', '/api/v1/auth/tokens/revoke', ['refresh_token' => $pair['refresh_token']])->status);
        self::assertSame(401, $this->request('POST', '/api/v1/auth/tokens/refresh', ['refresh_token' => $pair['refresh_token']])->status);

        $pair2 = $this->issueTokens('api@test.example');
        $this->app()->db()->execute('UPDATE refresh_tokens SET expires_at = UTC_TIMESTAMP(3) - INTERVAL 1 SECOND WHERE revoked_at IS NULL');
        self::assertSame(401, $this->request('POST', '/api/v1/auth/tokens/refresh', ['refresh_token' => $pair2['refresh_token']])->status);
    }

    public function testRefreshTokensAreStoredHashed(): void
    {
        $pair = $this->issueTokens('api@test.example');
        $db = $this->app()->db();
        self::assertSame(0, (int) $db->scalar('SELECT COUNT(*) FROM refresh_tokens WHERE token_hash = ?', [$pair['refresh_token']]));
        $hashed = hash('sha256', $pair['refresh_token'], true);
        self::assertSame(1, (int) $db->scalar('SELECT COUNT(*) FROM refresh_tokens WHERE token_hash = ?', [$hashed]));
    }

    public function testWrongCredentialsDoNotIssueTokens(): void
    {
        $r = $this->request('POST', '/api/v1/auth/tokens', ['email' => 'api@test.example', 'password' => 'incorrecta del todo 1']);
        self::assertSame(401, $r->status);
        self::assertSame(0, (int) $this->app()->db()->scalar('SELECT COUNT(*) FROM refresh_tokens'));
    }
}
