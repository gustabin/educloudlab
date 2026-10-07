<?php

declare(strict_types=1);

namespace EduCloud\Tests\Integration\Admin;

use EduCloud\Tests\Support\ApiActors;
use EduCloud\Tests\Support\AuthHelpers;
use EduCloud\Tests\TestCase;

/** Admin monitor (M11a): platform admins only; account disabling cuts every session and token. */
final class AdminMonitorTest extends TestCase
{
    use AuthHelpers;
    use ApiActors;

    private string $admin = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetDatabase();
        $this->createVerifiedUser('root@test.example');
        $this->app()->db()->execute("UPDATE users SET is_platform_admin = 1 WHERE email = 'root@test.example'");
        $this->admin = $this->actor('root@test.example');
    }

    public function testOnlyPlatformAdminsReachTheMonitor(): void
    {
        $ana = $this->actor('ana@test.example'); // org_admin of her personal tenant: still not a platform admin
        foreach (['/api/v1/admin/overview', '/api/v1/admin/jobs', '/api/v1/admin/audit', '/api/v1/admin/users'] as $path) {
            self::assertSame(403, $this->as($ana, 'GET', $path)->status, $path);
            self::assertSame(200, $this->as($this->admin, 'GET', $path)->status, $path);
        }
        $this->cookieJar = [];
        $this->login('ana@test.example');
        self::assertSame(403, $this->request('GET', '/app/admin')->status);
        self::assertStringNotContainsString('/app/admin', $this->request('GET', '/app')->body, 'no admin link for normal users');

        $this->cookieJar = [];
        $this->login('root@test.example');
        self::assertSame(200, $this->request('GET', '/app/admin')->status);
        self::assertStringContainsString('/app/admin', $this->request('GET', '/app')->body);
    }

    public function testListsAreFilteredAndNeverExposeSecrets(): void
    {
        $ana = $this->actor('ana@test.example');
        $ws = $this->createWorkspace($ana);
        $users = $this->as($this->admin, 'GET', '/api/v1/admin/users?q=ana@');
        self::assertSame(1, $users->decoded()['meta']['total']);
        self::assertSame('ana@test.example', $users->decoded()['data'][0]['email']);
        $audit = $this->as($this->admin, 'GET', '/api/v1/admin/audit?action=workspace.&outcome=success');
        self::assertSame('workspace.create', $audit->decoded()['data'][0]['action']);
        self::assertSame($ws['id'], $audit->decoded()['data'][0]['resource']['id']);

        foreach (['password_hash', 'argon2', 'ip_hash', 'payload', 'token_hash'] as $secret) {
            foreach (['/api/v1/admin/users', '/api/v1/admin/audit', '/api/v1/admin/jobs', '/api/v1/admin/overview'] as $path) {
                self::assertStringNotContainsStringIgnoringCase($secret, $this->as($this->admin, 'GET', $path)->body, "$path leaks $secret");
            }
        }
        self::assertSame(422, $this->as($this->admin, 'GET', '/api/v1/admin/jobs?status=evil')->status);
        self::assertSame(422, $this->as($this->admin, 'GET', "/api/v1/admin/audit?action=x'%20OR%201=1")->status);
        self::assertSame(422, $this->as($this->admin, 'GET', '/api/v1/admin/users?sort=password_hash')->status);
        $overview = $this->as($this->admin, 'GET', '/api/v1/admin/overview')->decoded()['data'];
        self::assertSame(2, $overview['users']['active']);
    }

    public function testDisablingAnAccountEndsItsSessionsAndTokens(): void
    {
        $ana = $this->actor('ana@test.example');
        $anaId = (string) $this->as($ana, 'GET', '/api/v1/auth/me')->decoded()['data']['user']['id'];
        $this->cookieJar = [];
        $this->login('ana@test.example');
        $anaCookies = $this->cookieJar;

        $this->actor('xana@test.example'); // newer account whose email contains ana's (regression: response re-read by id)
        $r = $this->as($this->admin, 'PATCH', "/api/v1/admin/users/$anaId", ['status' => 'disabled']);
        self::assertSame(200, $r->status, $r->body);
        self::assertSame(['ana@test.example', 'disabled'], [$r->decoded()['data']['email'], $r->decoded()['data']['status']]);
        self::assertSame(401, $this->as($ana, 'GET', '/api/v1/auth/me')->status, 'bearer token rejected');
        $this->cookieJar = $anaCookies;
        self::assertSame(401, $this->request('GET', '/api/v1/auth/me')->status, 'browser session ended');
        $sessions = 'SELECT COUNT(*) FROM sessions s JOIN users u ON u.id = s.user_id WHERE u.email = ?';
        self::assertSame(0, (int) $this->app()->db()->scalar($sessions, ['ana@test.example']));
        $this->cookieJar = [];
        self::assertSame(403, $this->attemptLogin('ana@test.example', $this->password)->status, 'disabled accounts cannot log in');
        self::assertSame(1, (int) $this->app()->db()->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'admin.user_status'"));

        self::assertSame(200, $this->as($this->admin, 'PATCH', "/api/v1/admin/users/$anaId", ['status' => 'active'])->status);
        self::assertSame(200, $this->attemptLogin('ana@test.example', $this->password)->status);

        $rootId = (string) $this->as($this->admin, 'GET', '/api/v1/auth/me')->decoded()['data']['user']['id'];
        self::assertSame(403, $this->as($this->admin, 'PATCH', "/api/v1/admin/users/$rootId", ['status' => 'disabled'])->status, 'not yourself');
        self::assertSame(422, $this->as($this->admin, 'PATCH', "/api/v1/admin/users/$anaId", ['status' => 'locked'])->status);
        self::assertSame(404, $this->as($this->admin, 'PATCH', '/api/v1/admin/users/01ARZ3NDEKTSV4RRFFQ69G5FAV', ['status' => 'disabled'])->status);
    }
}
