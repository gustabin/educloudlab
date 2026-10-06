<?php

declare(strict_types=1);

namespace EduCloud\Tests\Integration\Workspaces;

use EduCloud\Tests\Support\ApiActors;
use EduCloud\Tests\Support\AuthHelpers;
use EduCloud\Tests\TestCase;

final class ResourceApiTest extends TestCase
{
    use AuthHelpers;
    use ApiActors;

    private string $ana = '';
    private string $ws = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetDatabase();
        $this->ana = $this->actor('ana@test.example');
        $this->ws = (string) $this->createWorkspace($this->ana)['id'];
    }

    public function testStorageIsProvisionedActiveWithDefaultsAndAudited(): void
    {
        $r = $this->as($this->ana, 'POST', "/api/v1/workspaces/{$this->ws}/resources", [
            'type' => 'storage', 'name' => 'landing', 'region' => 'edu-local-2',
            'config' => ['access_tier' => 'cool'], 'tags' => ['curso' => 'de-101', 'env' => 'lab'],
        ]);
        self::assertSame(201, $r->status, $r->body);
        $res = $r->decoded()['data'];
        self::assertSame('active', $res['status']);
        self::assertSame('edu-local-2', $res['region']);
        self::assertSame(['access_tier' => 'cool', 'versioning' => false, 'redundancy' => 'lrs'], $res['config']);
        self::assertSame(['curso' => 'de-101', 'env' => 'lab'], $res['tags']);
        self::assertSame($this->ws, $res['workspace_id']);
        self::assertSame(1, (int) $this->app()->db()->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'resource.create'"));

        $list = $this->as($this->ana, 'GET', "/api/v1/workspaces/{$this->ws}/resources")->decoded();
        self::assertCount(1, $list['data']);
        self::assertSame(1, $this->as($this->ana, 'GET', "/api/v1/workspaces/{$this->ws}")->decoded()['data']['resource_count']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidResources(): iterable
    {
        yield 'unknown type' => [['type' => 'vm', 'name' => 'x1'], 'type'];
        yield 'module-owned type' => [['type' => 'dataset', 'name' => 'x1'], 'type'];
        yield 'unknown config key' => [['type' => 'storage', 'name' => 'x1', 'config' => ['public_access' => true]], 'config.public_access'];
        yield 'bad config value' => [['type' => 'storage', 'name' => 'x1', 'config' => ['access_tier' => 'premium']], 'config.access_tier'];
        yield 'config as list' => [['type' => 'storage', 'name' => 'x1', 'config' => ['hot']], 'config'];
        yield 'bad region' => [['type' => 'storage', 'name' => 'x1', 'region' => 'us-east-1'], 'region'];
        yield 'tags as list' => [['type' => 'storage', 'name' => 'x1', 'tags' => ['a', 'b']], 'tags'];
        yield 'bad tag key' => [['type' => 'storage', 'name' => 'x1', 'tags' => ['Bad Key' => 'v']], 'tags.Bad Key'];
        yield 'tag markup' => [['type' => 'storage', 'name' => 'x1', 'tags' => ['k' => '<b>']], 'tags.k'];
        yield 'unknown field' => [['type' => 'storage', 'name' => 'x1', 'owner_user_id' => 1], 'owner_user_id'];
    }

    /**
     * @dataProvider invalidResources
     * @param array<string, mixed> $body
     */
    public function testInvalidResourcesAreRejected(array $body, string $field): void
    {
        $r = $this->as($this->ana, 'POST', "/api/v1/workspaces/{$this->ws}/resources", $body);
        self::assertSame(422, $r->status, $r->body);
        self::assertContains($field, array_column($r->decoded()['error']['details'], 'field'));
        self::assertSame(0, (int) $this->app()->db()->scalar('SELECT COUNT(*) FROM resources'));
    }

    public function testNamesAreUniquePerTypeAndQuotaApplies(): void
    {
        $this->createResource($this->ana, $this->ws, 'storage', 'mismo');
        $dup = $this->as($this->ana, 'POST', "/api/v1/workspaces/{$this->ws}/resources", ['type' => 'storage', 'name' => 'mismo']);
        self::assertSame(409, $dup->status);
        $this->createResource($this->ana, $this->ws, 'lakehouse', 'mismo'); // same name, different type: allowed

        for ($i = 0; $i < 18; $i++) {
            $this->createResource($this->ana, $this->ws, 'storage', 'st-' . $i);
        }
        $r = $this->as($this->ana, 'POST', "/api/v1/workspaces/{$this->ws}/resources", ['type' => 'storage', 'name' => 'uno-mas']);
        self::assertSame('QUOTA_EXCEEDED', $r->decoded()['error']['code']);
    }

    public function testUpdateMergesConfigAndReplacesTags(): void
    {
        $res = $this->createResource($this->ana, $this->ws);
        $r = $this->as($this->ana, 'PATCH', "/api/v1/resources/{$res['id']}", ['config' => ['versioning' => true], 'tags' => ['team' => 'datos']]);
        self::assertSame(200, $r->status, $r->body);
        self::assertSame(['access_tier' => 'hot', 'versioning' => true, 'redundancy' => 'lrs'], $r->decoded()['data']['config']);
        self::assertSame(['team' => 'datos'], $r->decoded()['data']['tags']);
    }

    public function testDeleteFollowsTheLifecycle(): void
    {
        $res = $this->createResource($this->ana, $this->ws);
        self::assertSame(204, $this->as($this->ana, 'DELETE', "/api/v1/resources/{$res['id']}")->status);
        self::assertSame('deleted', $this->app()->db()->scalar('SELECT status FROM resources WHERE public_id = ?', [$res['id']]));
        self::assertSame(404, $this->as($this->ana, 'GET', "/api/v1/resources/{$res['id']}")->status);
        self::assertSame(404, $this->as($this->ana, 'DELETE', "/api/v1/resources/{$res['id']}")->status);
    }

    public function testFailedResourcesCannotBeEditedButCanBeDeleted(): void
    {
        $res = $this->createResource($this->ana, $this->ws);
        $this->app()->db()->execute("UPDATE resources SET status = 'failed' WHERE public_id = ?", [$res['id']]);
        $edit = $this->as($this->ana, 'PATCH', "/api/v1/resources/{$res['id']}", ['name' => 'otro']);
        self::assertSame(409, $edit->status);
        self::assertSame('INVALID_STATE', $edit->decoded()['error']['code']);
        self::assertSame(204, $this->as($this->ana, 'DELETE', "/api/v1/resources/{$res['id']}")->status);
    }

    public function testResourcesOfDeletedWorkspacesAreGone(): void
    {
        $res = $this->createResource($this->ana, $this->ws);
        $this->as($this->ana, 'DELETE', "/api/v1/workspaces/{$this->ws}");
        self::assertSame(404, $this->as($this->ana, 'GET', "/api/v1/resources/{$res['id']}")->status);
        $late = $this->as($this->ana, 'POST', "/api/v1/workspaces/{$this->ws}/resources", ['type' => 'storage', 'name' => 'tarde']);
        self::assertSame(404, $late->status);
    }
}
