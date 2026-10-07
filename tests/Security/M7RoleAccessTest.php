<?php

declare(strict_types=1);

namespace EduCloud\Tests\Security;

use EduCloud\Tests\Support\ApiActors;
use EduCloud\Tests\Support\AuthHelpers;
use EduCloud\Tests\TestCase;

/**
 * Security gate M7-05: inside one organization, pipelines and object storage follow the ownership rules -
 * another student sees nothing (404), a read_only member cannot change anything (403 for every id, existing or not),
 * and the org admin can see and manage the owner's objects.
 */
final class M7RoleAccessTest extends TestCase
{
    use AuthHelpers;
    use ApiActors;

    /** @var array{id: int, public_id: string} */
    private array $org = ['id' => 0, 'public_id' => ''];
    /** @var array<string, string> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetDatabase();
        $this->org = $this->createOrgTenant('Universidad M7');
        foreach (['duena' => 'student', 'otra' => 'student', 'lector' => 'read_only', 'admin' => 'org_admin'] as $user => $role) {
            $this->actor("$user@test.example");
            $this->addMember($this->org['id'], "$user@test.example", $role);
        }
        $csrf = $this->sessionIn('duena@test.example', $this->org['public_id']);
        $h = ['X-CSRF-Token' => $csrf];
        $ws = (string) $this->request('POST', '/api/v1/workspaces', ['name' => 'Privado'], $h)->decoded()['data']['id'];
        $this->request('POST', "/api/v1/workspaces/$ws/resources", ['type' => 'lakehouse', 'name' => 'lago'], $h);
        $storage = (string) $this->request('POST', "/api/v1/workspaces/$ws/resources", ['type' => 'storage', 'name' => 'almacen'], $h)
            ->decoded()['data']['id'];
        $container = (string) $this->request('POST', "/api/v1/resources/$storage/containers", ['name' => 'privado'], $h)->decoded()['data']['id'];
        $object = $this->upload("/api/v1/containers/$container/objects", ['key' => 'a.csv'], ['file' => [$this->fixtureFile("a\n1\n"), 'a.csv']], $h);
        self::assertSame(201, $object->status, $object->body);
        $definition = ['nodes' => [
            ['id' => 'leer', 'type' => 'source', 'table' => 'bronze.clientes'],
            ['id' => 'guardar', 'type' => 'output', 'layer' => 'silver', 'table' => 'copia'],
        ]];
        $pipeline = $this->request('POST', "/api/v1/workspaces/$ws/pipelines", ['name' => 'etl', 'definition' => $definition], $h);
        self::assertSame(201, $pipeline->status, $pipeline->body);
        $pipelineId = (string) $pipeline->decoded()['data']['id'];
        $run = $this->request('POST', "/api/v1/pipelines/$pipelineId/runs", [], $h);
        self::assertSame(202, $run->status, $run->body);
        $this->ids = [
            'storage' => $storage,
            'container' => $container,
            'object' => (string) $object->decoded()['data']['id'],
            'pipeline' => $pipelineId,
            'run' => (string) $run->decoded()['data']['id'],
        ];
    }

    /** @return list<array{string, string, array<string, mixed>|null}> method, path, body */
    private function calls(): array
    {
        ['storage' => $s, 'container' => $c, 'object' => $o, 'pipeline' => $p, 'run' => $r] = $this->ids;
        return [
            ['GET', "/api/v1/resources/$s/containers", null],
            ['POST', "/api/v1/resources/$s/containers", ['name' => 'intruso']],
            ['GET', "/api/v1/containers/$c", null],
            ['PATCH', "/api/v1/containers/$c", ['lifecycle' => null]],
            ['DELETE', "/api/v1/containers/$c", null],
            ['GET', "/api/v1/objects/$o", null],
            ['GET', "/api/v1/objects/$o/download", null],
            ['PATCH', "/api/v1/objects/$o", ['tier' => 'cool']],
            ['DELETE', "/api/v1/objects/$o", null],
            ['GET', "/api/v1/pipelines/$p", null],
            ['PATCH', "/api/v1/pipelines/$p", ['name' => 'hackeado']],
            ['DELETE', "/api/v1/pipelines/$p", null],
            ['POST', "/api/v1/pipelines/$p/runs", []],
            ['GET', "/api/v1/pipelines/$p/runs", null],
            ['GET', "/api/v1/pipeline-runs/$r", null],
            ['POST', "/api/v1/pipeline-runs/$r/cancel", []],
        ];
    }

    /** @return array<string, string> */
    private function state(): array
    {
        $out = [];
        foreach (['storage_containers', 'storage_objects', 'pipelines', 'pipeline_runs', 'jobs', 'resources'] as $table) {
            $out[$table] = md5((string) json_encode($this->app()->db()->select("SELECT * FROM $table ORDER BY id")));
        }
        return $out;
    }

    public function testAnotherStudentOfTheOrganizationSeesNothing(): void
    {
        $csrf = $this->sessionIn('otra@test.example', $this->org['public_id']);
        $before = $this->state();
        foreach ($this->calls() as [$method, $path, $body]) {
            $r = $this->request($method, $path, $body, ['X-CSRF-Token' => $csrf]);
            self::assertSame(404, $r->status, "$method $path: {$r->body}");
        }
        self::assertSame($before, $this->state());
    }

    public function testReadOnlyMembersCannotChangeAnything(): void
    {
        $csrf = $this->sessionIn('lector@test.example', $this->org['public_id']);
        $before = $this->state();
        foreach ($this->calls() as [$method, $path, $body]) {
            $r = $this->request($method, $path, $body, ['X-CSRF-Token' => $csrf]);
            // Reads: the object is not theirs (404). Writes: the role lacks the permission (403, before any lookup).
            self::assertSame($method === 'GET' ? 404 : 403, $r->status, "$method $path: {$r->body}");
        }
        self::assertSame($before, $this->state());
    }

    public function testTheOrgAdminCanSeeAndManageTheOwnersObjects(): void
    {
        $csrf = $this->sessionIn('admin@test.example', $this->org['public_id']);
        foreach ($this->calls() as [$method, $path]) {
            if ($method === 'GET') {
                self::assertSame(200, $this->request($method, $path)->status, "$method $path");
            }
        }
        $r = $this->request('PATCH', '/api/v1/objects/' . $this->ids['object'], ['tier' => 'cool'], ['X-CSRF-Token' => $csrf]);
        self::assertSame(200, $r->status, $r->body);
    }
}
