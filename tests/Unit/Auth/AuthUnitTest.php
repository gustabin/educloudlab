<?php

declare(strict_types=1);

namespace EduCloud\Tests\Unit\Auth;

use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Config;
use EduCloud\Core\Csrf;
use EduCloud\Core\Exceptions\ValidationException;
use EduCloud\Core\Validator;
use EduCloud\Http\Middleware\Authorize;
use EduCloud\Modules\Auth\AuthPageController;
use EduCloud\Modules\Auth\PasswordPolicy;
use PHPUnit\Framework\TestCase;

final class AuthUnitTest extends TestCase
{
    private function config(): Config
    {
        return new Config(['permissions' => require dirname(__DIR__, 3) . '/config/permissions.php']);
    }

    private function ctx(string $role, bool $admin = false): TenantContext
    {
        return new TenantContext(1, '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'organization', 1, $role, $admin);
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function rbacMatrix(): iterable
    {
        $perms = ['create', 'read', 'read_tenant', 'update', 'delete', 'execute', 'assign', 'review', 'administer'];
        $grants = [
            'org_admin' => $perms,
            'instructor' => ['create', 'read', 'read_tenant', 'update', 'delete', 'execute', 'assign', 'review'],
            'student' => ['create', 'read', 'update', 'delete', 'execute'],
            'read_only' => ['read'],
        ];
        foreach ($grants as $role => $allowed) {
            foreach ($perms as $p) {
                yield "$role/$p" => [$role, $p, in_array($p, $allowed, true)];
            }
        }
    }

    /** @dataProvider rbacMatrix */
    public function testRbacMatrix(string $role, string $permission, bool $expected): void
    {
        self::assertSame($expected, Authorize::allows($this->config(), $this->ctx($role), $permission));
    }

    public function testPlatformAdminHasEveryPermissionAndUnknownRoleNone(): void
    {
        self::assertTrue(Authorize::allows($this->config(), $this->ctx('read_only', true), 'administer'));
        self::assertFalse(Authorize::allows($this->config(), $this->ctx('ghost'), 'read'));
        self::assertFalse(Authorize::allows($this->config(), $this->ctx('student'), 'nonexistent'));
    }

    public function testValidatorNormalisesAndRejectsUnknownFields(): void
    {
        $out = Validator::validate(['email' => '  Ana@Example.COM '], ['email' => ['required', 'email']]);
        self::assertSame(['email' => 'ana@example.com'], $out);

        try {
            Validator::validate(['email' => 'a@b.co', 'role' => 'admin'], ['email' => ['required', 'email']]);
            self::fail('unknown field accepted');
        } catch (ValidationException $e) {
            self::assertSame('role', $e->details[0]['field']);
            self::assertSame('unknown_field', $e->details[0]['code']);
        }
    }

    public function testValidatorTypeChecks(): void
    {
        foreach ([[['n' => ['x']], ['n' => ['string']]], [['n' => 5], ['n' => ['email']]], [['n' => 'abc'], ['n' => ['in:x,y']]]] as [$in, $rules]) {
            try {
                Validator::validate($in, $rules);
                self::fail('invalid input accepted: ' . json_encode($in));
            } catch (ValidationException $e) {
                self::assertSame('n', $e->details[0]['field']);
            }
        }
    }

    public function testPasswordHashIsArgon2idAndVerifies(): void
    {
        $hash = PasswordPolicy::hash('una frase larga de prueba');
        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertTrue(password_verify('una frase larga de prueba', $hash));
        self::assertFalse(PasswordPolicy::needsRehash($hash));
        self::assertTrue(PasswordPolicy::needsRehash(password_hash('x', PASSWORD_BCRYPT)));
        self::assertTrue(str_starts_with(PasswordPolicy::DUMMY_HASH, '$argon2id$'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function nextUrls(): iterable
    {
        yield 'app path' => ['/app', '/app'];
        yield 'nested with query' => ['/labs/LAB-001?tab=tasks', '/labs/LAB-001?tab=tasks'];
        yield 'absolute url' => ['https://evil.example/', '/app'];
        yield 'protocol relative' => ['//evil.example/x', '/app'];
        yield 'backslash trick' => ['/\\evil.example', '/app'];
        yield 'javascript' => ['javascript:alert(1)', '/app'];
        yield 'traversal' => ['/../../etc', '/app'];
        yield 'empty' => ['', '/app'];
        yield 'header injection' => ["/app\r\nSet-Cookie: x=1", '/app'];
    }

    /** @dataProvider nextUrls */
    public function testPostLoginRedirectOnlyAllowsAppRelativePaths(string $next, string $expected): void
    {
        self::assertSame($expected, AuthPageController::safeNext($next));
    }

    public function testCsrfTokensAreContextBound(): void
    {
        $csrf = new Csrf(random_bytes(32));
        $a = $csrf->forAnonymous('cookie-a');
        self::assertTrue($csrf->verify($a, $csrf->forAnonymous('cookie-a')));
        self::assertNotSame($a, $csrf->forAnonymous('cookie-b'));
        self::assertNotSame($csrf->forSession('x'), $csrf->forAnonymous('x'), 'session and anonymous namespaces differ');
        self::assertFalse($csrf->verify($a, ''));
        self::assertNotSame($a, (new Csrf(random_bytes(32)))->forAnonymous('cookie-a'), 'tokens depend on the secret key');
    }
}
