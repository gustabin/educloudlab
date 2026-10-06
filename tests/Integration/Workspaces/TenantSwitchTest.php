<?php

declare(strict_types=1);

namespace EduCloud\Tests\Integration\Workspaces;

use EduCloud\Tests\Support\ApiActors;
use EduCloud\Tests\Support\AuthHelpers;
use EduCloud\Tests\TestCase;

final class TenantSwitchTest extends TestCase
{
    use AuthHelpers;
    use ApiActors;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetDatabase();
    }

    public function testListAndSwitchBetweenOwnTenants(): void
    {
        $this->actor('m@test.example');
        $org = $this->createOrgTenant('Bootcamp Datos');
        $this->addMember($org['id'], 'm@test.example', 'instructor');

        $csrf = $this->login('m@test.example');
        $list = $this->request('GET', '/api/v1/tenants')->decoded()['data'];
        self::assertCount(2, $list);
        self::assertSame('personal', $list[0]['type']);
        self::assertTrue($list[0]['active']);

        $switch = $this->request('POST', "/api/v1/tenants/{$org['public_id']}/switch", null, ['X-CSRF-Token' => $csrf]);
        self::assertSame(200, $switch->status);
        $me = $this->request('GET', '/api/v1/auth/me')->decoded()['data'];
        self::assertSame($org['public_id'], $me['tenant']['id']);
        self::assertSame('instructor', $me['tenant']['role']);
        $audited = $this->app()->db()->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'tenant.switch' AND outcome = 'success'");
        self::assertSame(1, (int) $audited);

        // Workspaces are scoped to the active tenant.
        $this->request('POST', '/api/v1/workspaces', ['name' => 'En el bootcamp'], ['X-CSRF-Token' => $csrf]);
        $wsTenant = (int) $this->app()->db()->scalar('SELECT tenant_id FROM workspaces');
        self::assertSame($org['id'], $wsTenant);
    }

    public function testCannotSwitchIntoForeignOrSuspendedTenant(): void
    {
        $this->actor('m@test.example');
        $foreign = $this->createOrgTenant('Ajena');
        $suspended = $this->createOrgTenant('Suspendida');
        $this->addMember($suspended['id'], 'm@test.example', 'student');
        $this->app()->db()->execute("UPDATE tenants SET status = 'suspended' WHERE id = ?", [$suspended['id']]);

        $csrf = $this->login('m@test.example');
        foreach ([$foreign['public_id'], $suspended['public_id'], 'not-a-ulid'] as $id) {
            self::assertSame(404, $this->request('POST', "/api/v1/tenants/$id/switch", null, ['X-CSRF-Token' => $csrf])->status, $id);
        }
        $denied = $this->app()->db()->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'tenant.switch' AND outcome = 'denied'");
        self::assertSame(3, (int) $denied);
    }

    public function testRevokedMembershipFallsBackToPersonalTenant(): void
    {
        $this->actor('m@test.example');
        $org = $this->createOrgTenant('Temporal');
        $this->addMember($org['id'], 'm@test.example', 'student');
        $this->sessionIn('m@test.example', $org['public_id']);

        $this->app()->db()->execute("UPDATE memberships SET status = 'suspended' WHERE tenant_id = ?", [$org['id']]);
        $me = $this->request('GET', '/api/v1/auth/me')->decoded()['data'];
        self::assertSame('personal', $me['tenant']['type'], 'access to the revoked organization ends immediately');
    }

    public function testSwitchRequiresABrowserSession(): void
    {
        $token = $this->actor('m@test.example');
        $org = $this->createOrgTenant('X');
        $this->addMember($org['id'], 'm@test.example', 'student');
        self::assertSame(401, $this->as($token, 'POST', "/api/v1/tenants/{$org['public_id']}/switch")->status);
    }
}
