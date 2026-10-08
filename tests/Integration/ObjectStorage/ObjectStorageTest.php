<?php

declare(strict_types=1);

namespace EduCloud\Tests\Integration\ObjectStorage;

use EduCloud\Core\Response;
use EduCloud\Modules\Jobs\Maintenance;
use EduCloud\Tests\Support\ApiActors;
use EduCloud\Tests\Support\AuthHelpers;
use EduCloud\Tests\TestCase;

/** Object storage (M7, LAB-002): containers, objects, metadata, tiers, lifecycle, downloads, quotas and guards. */
final class ObjectStorageTest extends TestCase
{
    use AuthHelpers;
    use ApiActors;

    private string $ana = '';
    private string $ws = '';
    private string $storage = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetDatabase();
        $this->ana = $this->actor('ana@test.example');
        $this->ws = (string) $this->createWorkspace($this->ana)['id'];
        $this->storage = (string) $this->createResource($this->ana, $this->ws, 'storage', 'archivos')['id'];
    }

    /** @param array<string, mixed> $values */
    private function withConfig(array $values): void
    {
        $config = $this->app()->config;
        foreach ($values as $key => $value) {
            $config = $config->with($key, $value);
        }
        $this->app = \EduCloud\Core\App::create($config, testDatabase: true);
    }

    /** @return array<string, mixed> */
    private function container(string $name, ?array $lifecycle = null): array
    {
        $body = ['name' => $name] + ($lifecycle !== null ? ['lifecycle' => $lifecycle] : []);
        $r = $this->as($this->ana, 'POST', "/api/v1/resources/{$this->storage}/containers", $body);
        self::assertSame(201, $r->status, $r->body);
        return $r->decoded()['data'];
    }

    /** @param array<string, string> $fields */
    private function put(string $containerId, array $fields, string $content, string $clientName, ?string $token = null): Response
    {
        $jar = $this->cookieJar;
        $this->cookieJar = [];
        try {
            return $this->upload(
                "/api/v1/containers/$containerId/objects",
                $fields,
                ['file' => [$this->fixtureFile($content), $clientName]],
                ['Authorization' => 'Bearer ' . ($token ?? $this->ana)]
            );
        } finally {
            $this->cookieJar = $jar;
        }
    }

    public function testContainersAreValidatedUniqueAndListed(): void
    {
        $c = $this->container('datos-crudos', ['archive_after_days' => 30, 'delete_after_days' => 365]);
        self::assertSameIgnoringKeyOrder(['archive_after_days' => 30, 'delete_after_days' => 365], $c['lifecycle']);
        self::assertSame(0, $c['object_count']);

        foreach (['AB', 'Mayus', '-guion', 'con espacio', 'x'] as $bad) {
            $r = $this->as($this->ana, 'POST', "/api/v1/resources/{$this->storage}/containers", ['name' => $bad]);
            self::assertSame(422, $r->status, $bad);
        }
        $dup = $this->as($this->ana, 'POST', "/api/v1/resources/{$this->storage}/containers", ['name' => 'datos-crudos']);
        self::assertSame(409, $dup->status);

        $badPolicy = [
            ['archive_after_days' => 0],
            ['archive_after_days' => 30, 'delete_after_days' => 10],
            ['expire' => 3],
            ['archive_after_days' => '5'],
            [1, 2],
        ];
        foreach ($badPolicy as $policy) {
            $r = $this->as($this->ana, 'POST', "/api/v1/resources/{$this->storage}/containers", ['name' => 'otro', 'lifecycle' => $policy]);
            self::assertSame(422, $r->status, (string) json_encode($policy));
        }

        $list = $this->as($this->ana, 'GET', "/api/v1/resources/{$this->storage}/containers")->decoded();
        self::assertSame(['datos-crudos'], array_column($list['data'], 'name'));

        // Containers only exist in storage resources.
        $lake = (string) $this->createResource($this->ana, $this->ws, 'lakehouse', 'lago')['id'];
        self::assertSame(404, $this->as($this->ana, 'POST', "/api/v1/resources/$lake/containers", ['name' => 'nope'])->status);
    }

    public function testObjectsUploadReplaceMetadataTiersAndDownload(): void
    {
        $c = (string) $this->container('ventas')['id'];
        $r = $this->put($c, ['key' => 'ventas/2025/enero.csv', 'metadata' => '{"origen":"erp"}', 'tier' => 'hot'], "id,total\n1,10\n", 'enero.csv');
        self::assertSame(201, $r->status, $r->body);
        $obj = $r->decoded()['data'];
        self::assertSame('ventas/2025/enero.csv', $obj['key']);
        self::assertSame('text/csv', $obj['content_type']);
        self::assertSame(['origen' => 'erp'], $obj['metadata']);
        self::assertSame(hash('sha256', "id,total\n1,10\n"), $obj['sha256']);

        // Same key: replaces the content (one object, new bytes) and removes the old file.
        $oldKey = (string) $this->app()->db()->scalar('SELECT storage_key FROM storage_objects');
        $r2 = $this->put($c, ['key' => 'ventas/2025/enero.csv'], "id,total\n1,10\n2,20\n", 'otro.csv');
        self::assertSame(201, $r2->status, $r2->body);
        self::assertSame($obj['id'], $r2->decoded()['data']['id']);
        self::assertSame(1, (int) $this->app()->db()->scalar('SELECT COUNT(*) FROM storage_objects'));
        $tenant = (string) $this->app()->db()->scalar('SELECT t.public_id FROM tenants t JOIN storage_objects o ON o.tenant_id = t.id');
        self::assertFileDoesNotExist($this->app()->storage()->objectFile($tenant, $this->ws, $oldKey));

        $this->put($c, ['key' => 'notas/leeme.md'], "# Hola\n", 'leeme.md');
        $prefixed = $this->as($this->ana, 'GET', "/api/v1/containers/$c?prefix=ventas/")->decoded()['data'];
        self::assertSame(['ventas/2025/enero.csv'], array_column($prefixed['objects'], 'key'));
        // LIKE wildcards in the prefix are literal.
        self::assertSame([], $this->as($this->ana, 'GET', "/api/v1/containers/$c?prefix=%25")->decoded()['data']['objects']);

        $dl = $this->as($this->ana, 'GET', '/api/v1/objects/' . $obj['id'] . '/download');
        self::assertSame(200, $dl->status);
        self::assertSame("id,total\n1,10\n2,20\n", $dl->content());
        self::assertSame('no-store, private', $dl->headers['Cache-Control']);
        self::assertSame((string) strlen("id,total\n1,10\n2,20\n"), $dl->headers['Content-Length']);
        self::assertSame('application/octet-stream', $dl->headers['Content-Type']);
        self::assertStringStartsWith('attachment; filename="enero.csv"', $dl->headers['Content-Disposition']);

        // Metadata and tier updates; archive blocks reads until "rehydrated".
        $u = $this->as($this->ana, 'PATCH', '/api/v1/objects/' . $obj['id'], ['tier' => 'archive', 'metadata' => ['estado' => 'cerrado']]);
        self::assertSame(200, $u->status, $u->body);
        self::assertSame(['estado' => 'cerrado'], $u->decoded()['data']['metadata']);
        $blocked = $this->as($this->ana, 'GET', '/api/v1/objects/' . $obj['id'] . '/download');
        self::assertSame(409, $blocked->status);
        self::assertSame('OBJECT_ARCHIVED', $blocked->decoded()['error']['code']);
        self::assertSame(200, $this->as($this->ana, 'PATCH', '/api/v1/objects/' . $obj['id'], ['tier' => 'cool'])->status);
        self::assertSame(200, $this->as($this->ana, 'GET', '/api/v1/objects/' . $obj['id'] . '/download')->status);

        self::assertSame(422, $this->as($this->ana, 'PATCH', '/api/v1/objects/' . $obj['id'], ['tier' => 'frozen'])->status);
        self::assertSame(422, $this->as($this->ana, 'PATCH', '/api/v1/objects/' . $obj['id'], [])->status);
        self::assertSame(422, $this->as($this->ana, 'PATCH', '/api/v1/objects/' . $obj['id'], ['metadata' => ['bad key!' => 'x']])->status);
    }

    public function testMaliciousKeysAndFilesAreRejected(): void
    {
        $c = (string) $this->container('seguro')['id'];
        $badKeys = ['../escape.csv', '/abs.csv', 'a//b.csv', 'a/../b.csv', 'C:\\win.csv', "nul\0.csv", '.oculto', 'a/.b', str_repeat('a', 101)];
        foreach ($badKeys as $key) {
            self::assertSame(422, $this->put($c, ['key' => $key], "a\n1\n", 'a.csv')->status, $key);
        }
        self::assertSame(422, $this->put($c, ['key' => 'x.php'], "<?php echo 1;", 'x.php')->status);
        self::assertSame(422, $this->put($c, ['key' => 'x.exe'], "MZ\x90\x00", 'x.txt')->status);
        self::assertSame(422, $this->put($c, ['key' => 'x.html'], '<script>alert(1)</script>', 'x.html')->status);
        self::assertSame(422, $this->put($c, ['key' => 'meta.csv', 'metadata' => '[1,2]'], "a\n1\n", 'a.csv')->status);
        self::assertSame(422, $this->put($c, ['key' => 'meta.csv', 'metadata' => '{bad'], "a\n1\n", 'a.csv')->status);
        self::assertSame(422, $this->put($c, ['key' => 'tier.csv', 'tier' => 'gold'], "a\n1\n", 'a.csv')->status);
        self::assertSame(0, (int) $this->app()->db()->scalar('SELECT COUNT(*) FROM storage_objects'));

        // HTML disguised as .txt is sniffed and rejected.
        self::assertSame(422, $this->put($c, ['key' => 'nota.txt'], "<script>alert(1)</script>\n", 'nota.txt')->status);

        // Text served from storage is never rendered inline: a .txt download is still an opaque attachment.
        $ok = $this->put($c, ['key' => 'nota.txt'], "Notas del proyecto: revisar <b> y & en los datos.\n", 'nota.txt');
        self::assertSame(201, $ok->status, $ok->body);
        self::assertSame('text/plain', $ok->decoded()['data']['content_type']);
        $dl = $this->as($this->ana, 'GET', '/api/v1/objects/' . $ok->decoded()['data']['id'] . '/download');
        self::assertSame('application/octet-stream', $dl->headers['Content-Type']);
        self::assertStringStartsWith('attachment;', $dl->headers['Content-Disposition']);
    }

    public function testQuotasAndDeleteGuards(): void
    {
        $this->withConfig(['quotas.objects_per_container' => 2, 'quotas.containers_per_storage' => 1]);
        $c = (string) $this->container('cuota')['id'];
        $r = $this->as($this->ana, 'POST', "/api/v1/resources/{$this->storage}/containers", ['name' => 'segundo']);
        self::assertSame(409, $r->status, $r->body);

        self::assertSame(201, $this->put($c, ['key' => 'a.csv'], "a\n1\n", 'a.csv')->status);
        self::assertSame(201, $this->put($c, ['key' => 'b.csv'], "a\n1\n", 'b.csv')->status);
        self::assertSame(409, $this->put($c, ['key' => 'c.csv'], "a\n1\n", 'c.csv')->status);
        // Replacing an existing key does not count as a new object.
        self::assertSame(201, $this->put($c, ['key' => 'a.csv'], "a\n2\n", 'a.csv')->status);

        // The database itself refuses to cascade-delete objects with their container (bytes would be orphaned).
        try {
            $this->app()->db()->execute('DELETE FROM storage_containers');
            self::fail('a container with objects must not be deletable');
        } catch (\mysqli_sql_exception $e) {
            self::assertSame(1451, $e->getCode(), 'foreign key RESTRICT');
        }
        self::assertSame(2, (int) $this->app()->db()->scalar('SELECT COUNT(*) FROM storage_objects'));

        // Non-empty container and non-empty storage resource cannot be deleted.
        $del = $this->as($this->ana, 'DELETE', "/api/v1/containers/$c");
        self::assertSame(409, $del->status);
        self::assertSame('CONTAINER_NOT_EMPTY', $del->decoded()['error']['code']);
        $res = $this->as($this->ana, 'DELETE', "/api/v1/resources/{$this->storage}");
        self::assertSame(409, $res->status);
        self::assertSame('STORAGE_NOT_EMPTY', $res->decoded()['error']['code']);

        foreach ($this->as($this->ana, 'GET', "/api/v1/containers/$c")->decoded()['data']['objects'] as $o) {
            self::assertSame(204, $this->as($this->ana, 'DELETE', '/api/v1/objects/' . $o['id'])->status);
        }
        self::assertSame(204, $this->as($this->ana, 'DELETE', "/api/v1/containers/$c")->status);
        self::assertSame(204, $this->as($this->ana, 'DELETE', "/api/v1/resources/{$this->storage}")->status);
    }

    public function testObjectsCountTowardsTheStorageQuota(): void
    {
        $this->withConfig(['quotas.storage_bytes_per_user' => 30]);
        $c = (string) $this->container('limite')['id'];
        self::assertSame(201, $this->put($c, ['key' => 'a.txt'], str_repeat('a', 20) . "\n", 'a.txt')->status);
        $r = $this->put($c, ['key' => 'b.txt'], str_repeat('b', 20) . "\n", 'b.txt');
        self::assertSame(409, $r->status, $r->body);
    }

    public function testLifecyclePoliciesAreAppliedByTheScheduler(): void
    {
        $c = $this->container('ciclo', ['archive_after_days' => 7, 'delete_after_days' => 30]);
        foreach (['nuevo.csv', 'viejo.csv', 'antiguo.csv'] as $key) {
            self::assertSame(201, $this->put((string) $c['id'], ['key' => $key], "a\n1\n", $key)->status);
        }
        $db = $this->app()->db();
        $db->execute("UPDATE storage_objects SET created_at = UTC_TIMESTAMP(3) - INTERVAL 10 DAY WHERE object_key = 'viejo.csv'");
        $db->execute("UPDATE storage_objects SET created_at = UTC_TIMESTAMP(3) - INTERVAL 40 DAY WHERE object_key = 'antiguo.csv'");
        $file = $this->app()->storage()->objectFile(
            (string) $db->scalar('SELECT public_id FROM tenants WHERE id = (SELECT tenant_id FROM storage_objects LIMIT 1)'),
            $this->ws,
            (string) $db->scalar("SELECT storage_key FROM storage_objects WHERE object_key = 'antiguo.csv'")
        );
        self::assertFileExists($file);

        $result = (new Maintenance($this->app()))->run();
        self::assertSame(['archived' => 1, 'deleted' => 1], $result['storage_lifecycle']);
        $tiers = $db->select('SELECT object_key, tier FROM storage_objects ORDER BY object_key');
        self::assertSame([['object_key' => 'nuevo.csv', 'tier' => 'hot'], ['object_key' => 'viejo.csv', 'tier' => 'archive']], $tiers);
        self::assertFileDoesNotExist($file);

        // Idempotent: nothing more to do.
        self::assertSame(['archived' => 0, 'deleted' => 0], (new Maintenance($this->app()))->run()['storage_lifecycle']);

        // Removing the policy (null) is allowed; unknown fields are not.
        self::assertNull($this->as($this->ana, 'PATCH', '/api/v1/containers/' . $c['id'], ['lifecycle' => null])->decoded()['data']['lifecycle']);
        self::assertSame(422, $this->as($this->ana, 'PATCH', '/api/v1/containers/' . $c['id'], ['lifecycle' => null, 'name' => 'x'])->status);
        self::assertSame(422, $this->as($this->ana, 'PATCH', '/api/v1/containers/' . $c['id'], [])->status);
    }

    public function testOtherUsersAndReadOnlyMembersCannotTouchObjects(): void
    {
        $c = (string) $this->container('privado')['id'];
        $obj = (string) $this->put($c, ['key' => 'a.csv'], "a\n1\n", 'a.csv')->decoded()['data']['id'];

        // Another tenant: 404 everywhere (no enumeration).
        $eve = $this->actor('eve@test.example');
        self::assertSame(404, $this->as($eve, 'GET', "/api/v1/containers/$c")->status);
        self::assertSame(404, $this->as($eve, 'GET', "/api/v1/objects/$obj/download")->status);
        self::assertSame(404, $this->put($c, ['key' => 'x.csv'], "a\n1\n", 'x.csv', $eve)->status);
        self::assertSame(404, $this->as($eve, 'DELETE', "/api/v1/objects/$obj")->status);
        self::assertSame(1, (int) $this->app()->db()->scalar('SELECT COUNT(*) FROM storage_objects'));
    }

    public function testBrowserPageRendersForStorageResourcesOnly(): void
    {
        $this->cookieJar = [];
        $this->login('ana@test.example');
        $page = $this->request('GET', "/app/resources/{$this->storage}/storage");
        self::assertSame(200, $page->status);
        self::assertStringContainsString('id="storage"', $page->body);
        self::assertStringContainsString('js/features/storage.js', $page->body);
        $lake = (string) $this->createResource($this->ana, $this->ws, 'lakehouse', 'lago')['id'];
        self::assertSame(404, $this->request('GET', "/app/resources/$lake/storage")->status);
    }
}
