<?php

declare(strict_types=1);

namespace EduCloud\Tests\Security;

use EduCloud\Core\Response;
use EduCloud\Core\Ulid;
use EduCloud\Tests\Support\ApiActors;
use EduCloud\Tests\Support\AuthHelpers;
use EduCloud\Tests\TestCase;

/**
 * RELEASE-BLOCKING (plan §13, spec §18): nobody can read, modify, execute or delete objects they may not see —
 * neither from another tenant nor from another member of the same organization.
 *
 * Driven by the route registry: every route with an id parameter is attacked by every scenario, so new routes are
 * covered automatically, and a route with an unknown parameter name fails until a fixture is added here.
 *
 * Victim: alice, a student in organization "Universidad", owning a workspace + resource there.
 * Attackers: bob from another tenant (Bearer and session), another student and an instructor of the same org.
 */
final class TenantIsolationTest extends TestCase
{
    use AuthHelpers;
    use ApiActors;

    /** Tables whose content must be byte-identical after every forbidden attempt. */
    private const GUARDED_TABLES = ['tenants', 'memberships', 'workspaces', 'resources', 'sessions', 'refresh_tokens'];

    /** Valid bodies, so a 404 can only come from authorization (never from validation). */
    private const BODIES = [
        'PATCH /api/v1/workspaces/{workspace_id}' => ['name' => 'hackeado'],
        'POST /api/v1/workspaces/{workspace_id}/resources' => ['type' => 'storage', 'name' => 'intruso'],
        'PATCH /api/v1/resources/{resource_id}' => ['name' => 'hackeado'],
    ];

    /** @var array<string, string> route parameter => victim object id */
    private array $victimIds = [];
    /** @var array{id: int, public_id: string} */
    private array $org = ['id' => 0, 'public_id' => ''];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetDatabase();

        $this->createVerifiedUser('alice@test.example');
        $this->createVerifiedUser('est2@test.example');
        $this->createVerifiedUser('prof@test.example');
        $this->org = $this->createOrgTenant('Universidad');
        $this->addMember($this->org['id'], 'alice@test.example', 'student');
        $this->addMember($this->org['id'], 'est2@test.example', 'student');
        $this->addMember($this->org['id'], 'prof@test.example', 'instructor');

        $csrf = $this->sessionIn('alice@test.example', $this->org['public_id']);
        $ws = $this->request('POST', '/api/v1/workspaces', ['name' => 'Trabajo final'], ['X-CSRF-Token' => $csrf]);
        self::assertSame(201, $ws->status, $ws->body);
        $wsId = (string) $ws->decoded()['data']['id'];
        $res = $this->request('POST', "/api/v1/workspaces/$wsId/resources", ['type' => 'storage', 'name' => 'datos'], ['X-CSRF-Token' => $csrf]);
        self::assertSame(201, $res->status, $res->body);

        $this->victimIds = [
            'workspace_id' => $wsId,
            'resource_id' => (string) $res->decoded()['data']['id'],
            // A tenant none of the attackers belongs to: alice's personal tenant.
            'tenant_id' => (string) $this->app()->db()->scalar(
                "SELECT t.public_id FROM tenants t JOIN memberships m ON m.tenant_id = t.id WHERE m.user_id = ? AND t.type = 'personal'",
                [$this->userId('alice@test.example')]
            ),
        ];
        $this->cookieJar = [];
    }

    /** @return iterable<string, array{string}> */
    public static function attackers(): iterable
    {
        yield 'other tenant via Bearer token' => ['bob-bearer'];
        yield 'other tenant via browser session' => ['bob-session'];
        yield 'another student of the same organization' => ['same-org-student'];
        yield 'an instructor of the same organization (no course scope yet)' => ['same-org-instructor'];
    }

    /** @dataProvider attackers */
    public function testEveryIdRouteHidesObjectsTheCallerMayNotSee(string $attacker): void
    {
        $send = $this->attacker($attacker);
        $checked = [];

        foreach ($this->app()->router->routes() as $route) {
            if (!str_contains($route['pattern'], '{') || $route['options']['auth'] === 'none') {
                continue;
            }
            if ($attacker === 'bob-bearer' && $route['options']['auth'] === 'session') {
                continue; // Bearer tokens cannot reach session-only routes at all (401, covered in JwtTest).
            }
            $path = (string) preg_replace_callback('/\{([a-z_]+)\}/', function (array $m) use ($route): string {
                self::assertArrayHasKey($m[1], $this->victimIds, "No isolation fixture for parameter {{$m[1]}} in {$route['pattern']}");
                return $this->victimIds[$m[1]];
            }, $route['pattern']);
            $key = $route['method'] . ' ' . $route['pattern'];

            $before = $this->snapshot();
            $response = $send($route['method'], $path, self::BODIES[$key] ?? null);

            self::assertSame(404, $response->status, "[$attacker] $key leaked or allowed access: {$response->body}");
            self::assertSame($before, $this->snapshot(), "[$attacker] $key changed data it must not touch");
            $checked[] = $key;
        }

        $minimum = $attacker === 'bob-bearer' ? 8 : 10;
        self::assertGreaterThanOrEqual($minimum, count($checked), "[$attacker] matrix covered too few routes: " . implode(', ', $checked));
    }

    public function testVictimCanStillUseTheSameIds(): void
    {
        // Guards against a vacuous pass: the fixtures are real objects reachable by their owner.
        $this->sessionIn('alice@test.example', $this->org['public_id']);
        $ws = $this->victimIds['workspace_id'];
        $paths = [
            "/api/v1/workspaces/$ws",
            "/api/v1/workspaces/$ws/resources",
            "/api/v1/resources/{$this->victimIds['resource_id']}",
            "/app/workspaces/$ws",
        ];
        foreach ($paths as $path) {
            self::assertSame(200, $this->request('GET', $path)->status, $path);
        }
    }

    public function testOrgAdminSeesTheWorkspaceButOtherMembersListNothing(): void
    {
        $this->createVerifiedUser('admin@test.example');
        $this->addMember($this->org['id'], 'admin@test.example', 'org_admin');
        $this->sessionIn('admin@test.example', $this->org['public_id']);
        self::assertSame(1, $this->request('GET', '/api/v1/workspaces')->decoded()['meta']['total']);

        $this->sessionIn('est2@test.example', $this->org['public_id']);
        self::assertSame(0, $this->request('GET', '/api/v1/workspaces')->decoded()['meta']['total']);
        $this->sessionIn('prof@test.example', $this->org['public_id']);
        self::assertSame(0, $this->request('GET', '/api/v1/workspaces')->decoded()['meta']['total']);
    }

    public function testClientSuppliedTenantIdIsNeverTrusted(): void
    {
        $bob = $this->actor('bob@test.example');
        $r = $this->as($bob, 'POST', '/api/v1/workspaces', ['name' => 'colado', 'tenant_id' => $this->org['public_id']]);
        self::assertSame(422, $r->status, 'unknown field tenant_id is rejected');
        self::assertSame('unknown_field', array_column($r->decoded()['error']['details'], 'code', 'field')['tenant_id']);
        self::assertSame(1, (int) $this->app()->db()->scalar('SELECT COUNT(*) FROM workspaces'));
    }

    public function testDatabaseRejectsCrossTenantReferencesEvenIfCodeWereWrong(): void
    {
        $this->createVerifiedUser('bob@test.example');
        $bobTenant = (int) $this->app()->db()->scalar('SELECT tenant_id FROM memberships WHERE user_id = ?', [$this->userId('bob@test.example')]);
        $aliceWorkspace = (int) $this->app()->db()->scalar('SELECT id FROM workspaces LIMIT 1');
        $this->expectException(\mysqli_sql_exception::class);
        $this->app()->db()->insert(
            "INSERT INTO resources (public_id, tenant_id, workspace_id, owner_user_id, type, name) VALUES (?, ?, ?, ?, 'storage', 'x')",
            [Ulid::generate(), $bobTenant, $aliceWorkspace, $this->userId('bob@test.example')]
        );
    }

    /** @return callable(string, string, array<string, mixed>|null): Response */
    private function attacker(string $name): callable
    {
        if ($name === 'bob-bearer') {
            $token = $this->actor('bob@test.example');
            return fn (string $method, string $path, ?array $body): Response => $this->as($token, $method, $path, $body);
        }
        $csrf = match ($name) {
            'bob-session' => (function (): string {
                $this->createVerifiedUser('bob@test.example');
                $this->cookieJar = [];
                return $this->login('bob@test.example');
            })(),
            'same-org-student' => $this->sessionIn('est2@test.example', $this->org['public_id']),
            'same-org-instructor' => $this->sessionIn('prof@test.example', $this->org['public_id']),
            default => throw new \LogicException($name),
        };
        return fn (string $method, string $path, ?array $body): Response =>
            $this->request($method, $path, $body, $method === 'GET' ? [] : ['X-CSRF-Token' => $csrf]);
    }

    /** @return array<string, string> table => checksum */
    private function snapshot(): array
    {
        $out = [];
        foreach (self::GUARDED_TABLES as $table) {
            $row = $this->app()->db()->selectOne("CHECKSUM TABLE `$table` EXTENDED");
            $out[$table] = (string) ($row['Checksum'] ?? '');
        }
        return $out;
    }
}
