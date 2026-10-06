<?php

declare(strict_types=1);

namespace EduCloud\Tests\Integration\Workspaces;

use EduCloud\Tests\Support\ApiActors;
use EduCloud\Tests\Support\AuthHelpers;
use EduCloud\Tests\TestCase;

final class WorkspaceApiTest extends TestCase
{
    use AuthHelpers;
    use ApiActors;

    private string $ana = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetDatabase();
        $this->ana = $this->actor('ana@test.example');
    }

    /** Acceptance criteria from spec §49, verbatim. */
    public function testCreateWorkspaceAcceptanceCriteria(): void
    {
        $r = $this->as($this->ana, 'POST', '/api/v1/workspaces', ['name' => 'Ventas 2026', 'description' => 'Análisis']);

        self::assertSame(201, $r->status, 'The API returns HTTP 201');
        $data = $r->decoded()['data'];
        $row = $this->app()->db()->selectOne('SELECT tenant_id, owner_user_id, name FROM workspaces WHERE public_id = ?', [$data['id']]);
        self::assertNotNull($row, 'The workspace is stored in MySQL');
        $anaTenant = (int) $this->app()->db()->scalar('SELECT tenant_id FROM memberships WHERE user_id = ?', [$this->userId('ana@test.example')]);
        self::assertSame($anaTenant, (int) $row['tenant_id'], 'The authenticated tenant is assigned server-side');
        self::assertSame($this->userId('ana@test.example'), (int) $row['owner_user_id']);

        self::assertSame(
            ['id', 'name', 'description', 'purpose', 'status', 'owner', 'resource_count', 'created_at', 'updated_at'],
            array_keys($data),
            'The response contains a consistent JSON representation'
        );
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $data['created_at']);
        self::assertSame(1, (int) $this->app()->db()->scalar(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'workspace.create' AND outcome = 'success' AND resource_public_id = ?",
            [$data['id']]
        ), 'An audit event is recorded');

        $override = $this->as($this->ana, 'POST', '/api/v1/workspaces', ['name' => 'Otro', 'tenant_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV']);
        self::assertSame(422, $override->status, 'Unauthorized tenant identifiers cannot override ownership');
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidBodies(): iterable
    {
        yield 'missing name' => [['description' => 'x'], 'name'];
        yield 'too short' => [['name' => 'a'], 'name'];
        yield 'markup' => [['name' => '<b>x</b>'], 'name'];
        yield 'description control chars' => [['name' => 'ok', 'description' => "linea\x00nula"], 'description'];
        yield 'name not string' => [['name' => ['x']], 'name'];
    }

    /**
     * @dataProvider invalidBodies
     * @param array<string, mixed> $body
     */
    public function testInvalidRequestsAreRejected(array $body, string $field): void
    {
        $r = $this->as($this->ana, 'POST', '/api/v1/workspaces', $body);
        self::assertSame(422, $r->status);
        self::assertContains($field, array_column($r->decoded()['error']['details'], 'field'));
        self::assertSame(0, (int) $this->app()->db()->scalar('SELECT COUNT(*) FROM workspaces'));
    }

    public function testNameIsUniquePerOwnerAndQuotaIsEnforced(): void
    {
        $this->createWorkspace($this->ana, 'Uno');
        self::assertSame(409, $this->as($this->ana, 'POST', '/api/v1/workspaces', ['name' => 'Uno'])->status);
        foreach (['Dos', 'Tres', 'Cuatro', 'Cinco'] as $name) {
            $this->createWorkspace($this->ana, $name);
        }
        $r = $this->as($this->ana, 'POST', '/api/v1/workspaces', ['name' => 'Seis']);
        self::assertSame(409, $r->status);
        self::assertSame('QUOTA_EXCEEDED', $r->decoded()['error']['code']);
    }

    public function testListPaginatesSearchesLiterallyAndSortsByAllowlist(): void
    {
        foreach (['Gamma', 'Alfa', 'Delta_x', 'Beta'] as $name) {
            $this->createWorkspace($this->ana, $name);
        }
        $page = $this->as($this->ana, 'GET', '/api/v1/workspaces?per_page=2&page=2&sort=name&dir=asc')->decoded();
        self::assertSame(['Delta_x', 'Gamma'], array_column($page['data'], 'name'));
        $meta = array_intersect_key($page['meta'], array_flip(['page', 'per_page', 'total', 'total_pages']));
        self::assertSame(['page' => 2, 'per_page' => 2, 'total' => 4, 'total_pages' => 2], $meta);

        $literal = $this->as($this->ana, 'GET', '/api/v1/workspaces?q=' . rawurlencode('_'))->decoded();
        self::assertSame(['Delta_x'], array_column($literal['data'], 'name'), 'LIKE wildcards in the search are literal');
        self::assertSame(0, $this->as($this->ana, 'GET', '/api/v1/workspaces?q=' . rawurlencode('%'))->decoded()['meta']['total']);

        self::assertSame(422, $this->as($this->ana, 'GET', '/api/v1/workspaces?sort=' . rawurlencode('name; DROP TABLE users'))->status);
        self::assertSame(422, $this->as($this->ana, 'GET', '/api/v1/workspaces?per_page=1000')->status);
        self::assertSame(422, $this->as($this->ana, 'GET', '/api/v1/workspaces?page=abc')->status);
    }

    public function testUpdateAndSoftDeleteCascadeToResources(): void
    {
        $ws = $this->createWorkspace($this->ana, 'Original');
        $this->createResource($this->ana, (string) $ws['id']);

        $r = $this->as($this->ana, 'PATCH', "/api/v1/workspaces/{$ws['id']}", ['name' => 'Renombrado', 'description' => null]);
        self::assertSame(200, $r->status, $r->body);
        self::assertSame('Renombrado', $r->decoded()['data']['name']);
        self::assertNull($r->decoded()['data']['description']);
        self::assertSame(422, $this->as($this->ana, 'PATCH', "/api/v1/workspaces/{$ws['id']}", [])->status);

        self::assertSame(204, $this->as($this->ana, 'DELETE', "/api/v1/workspaces/{$ws['id']}")->status);
        self::assertSame(404, $this->as($this->ana, 'GET', "/api/v1/workspaces/{$ws['id']}")->status);
        self::assertSame(0, (int) $this->app()->db()->scalar("SELECT COUNT(*) FROM resources WHERE status = 'active'"));
        $deleting = (int) $this->app()->db()->scalar("SELECT COUNT(*) FROM resources WHERE status = 'deleting'");
        self::assertSame(1, $deleting, 'cascade follows the lifecycle');
        self::assertSame(1, (int) $this->app()->db()->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'workspace.delete'"));
        $this->createWorkspace($this->ana, 'Original'); // names of deleted workspaces can be reused
    }

    public function testMalformedIdsAreNotFound(): void
    {
        foreach (['abc', '01ARZ3NDEKTSV4RRFFQ69G5FAV', "1' OR '1'='1"] as $id) {
            self::assertSame(404, $this->as($this->ana, 'GET', '/api/v1/workspaces/' . rawurlencode($id))->status);
        }
    }

    public function testVisibilityAndRolesInsideAnOrganization(): void
    {
        $org = $this->createOrgTenant('Universidad Demo');
        $this->actor('admin@test.example');
        $this->actor('est1@test.example');
        $this->actor('est2@test.example');
        $this->actor('lector@test.example');
        $this->addMember($org['id'], 'admin@test.example', 'org_admin');
        $this->addMember($org['id'], 'est1@test.example', 'student');
        $this->addMember($org['id'], 'est2@test.example', 'student');
        $this->addMember($org['id'], 'lector@test.example', 'read_only');

        $csrf = $this->sessionIn('est1@test.example', $org['public_id']);
        $created = $this->request('POST', '/api/v1/workspaces', ['name' => 'Trabajo de est1'], ['X-CSRF-Token' => $csrf]);
        self::assertSame(201, $created->status, $created->body);
        $wsId = $created->decoded()['data']['id'];

        // Another student of the same organization cannot see it (404, not 403).
        $csrf2 = $this->sessionIn('est2@test.example', $org['public_id']);
        self::assertSame(404, $this->request('GET', "/api/v1/workspaces/$wsId")->status);
        self::assertSame(404, $this->request('DELETE', "/api/v1/workspaces/$wsId", null, ['X-CSRF-Token' => $csrf2])->status);
        self::assertSame(0, $this->request('GET', '/api/v1/workspaces')->decoded()['meta']['total']);

        // The org admin sees and can manage it.
        $this->sessionIn('admin@test.example', $org['public_id']);
        self::assertSame(200, $this->request('GET', "/api/v1/workspaces/$wsId")->status);
        self::assertSame(1, $this->request('GET', '/api/v1/workspaces')->decoded()['meta']['total']);

        // read_only can list (nothing of their own) but cannot create.
        $csrf4 = $this->sessionIn('lector@test.example', $org['public_id']);
        self::assertSame(200, $this->request('GET', '/api/v1/workspaces')->status);
        $denied = $this->request('POST', '/api/v1/workspaces', ['name' => 'No permitido'], ['X-CSRF-Token' => $csrf4]);
        self::assertSame(403, $denied->status);
    }

    public function testMarkupInDescriptionIsStoredVerbatimAndRenderedEscaped(): void
    {
        $text = 'ventas > 1000 <script>alert(1)</script>';
        $r = $this->as($this->ana, 'POST', '/api/v1/workspaces', ['name' => 'Ventas', 'description' => $text]);
        self::assertSame(201, $r->status);
        self::assertSame($text, $r->decoded()['data']['description']);
        self::assertStringNotContainsString('<script>', $r->body, 'JSON output escapes < and > (JSON_HEX_TAG)');

        $this->login('ana@test.example');
        $page = $this->request('GET', '/app/workspaces/' . $r->decoded()['data']['id']);
        self::assertStringNotContainsString('<script>alert(1)</script>', $page->body);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $page->body);
    }

    public function testWorkspacePagesRender(): void
    {
        $ws = $this->createWorkspace($this->ana, 'Pagina segura');
        $this->login('ana@test.example');
        $list = $this->request('GET', '/app/workspaces');
        self::assertSame(200, $list->status);
        self::assertStringContainsString('id="ws-list"', $list->body);
        $detail = $this->request('GET', '/app/workspaces/' . $ws['id']);
        self::assertSame(200, $detail->status);
        self::assertStringContainsString('Pagina segura', $detail->body);
        self::assertSame(404, $this->request('GET', '/app/workspaces/01ARZ3NDEKTSV4RRFFQ69G5FAV')->status);
    }
}
