<?php

declare(strict_types=1);

namespace EduCloud\Tests\Security;

use EduCloud\Core\Kernel;
use EduCloud\Core\Request;
use EduCloud\Core\Ulid;
use EduCloud\Core\UploadedFile;
use EduCloud\Tests\Support\ApiActors;
use EduCloud\Tests\Support\AuthHelpers;
use EduCloud\Tests\Support\DatasetHelpers;
use EduCloud\Tests\TestCase;

/** Malicious and malformed uploads are rejected before anything is stored (spec §10, §20). */
final class UploadSecurityTest extends TestCase
{
    use AuthHelpers;
    use ApiActors;
    use DatasetHelpers;

    private string $ana = '';
    private string $ws = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetDatabase();
        $this->ana = $this->actor('ana@test.example');
        $this->ws = (string) $this->createWorkspace($this->ana)['id'];
    }

    /** @return iterable<string, array{string, string, int, string}> content, client name, status, error code/field code */
    public static function badUploads(): iterable
    {
        yield 'zip/xlsx renamed to csv' => ["PK\x03\x04" . str_repeat('x', 100), 'datos.csv', 422, 'content'];
        yield 'png renamed to csv' => ["\x89PNG\r\n\x1a\n" . str_repeat("\0", 50), 'datos.csv', 422, 'content'];
        yield 'windows executable' => ['MZ' . str_repeat("\x90", 200), 'datos.csv', 422, 'content'];
        yield 'pdf' => ["%PDF-1.7\n1 0 obj", 'datos.csv', 422, 'content'];
        yield 'sqlite database' => ["SQLite format 3\0", 'datos.csv', 422, 'content'];
        yield 'NUL bytes inside text' => ["a,b\n1,\0\n", 'datos.csv', 422, 'content'];
        yield 'latin-1 instead of UTF-8' => ["nombre\nJos\xE9\n", 'datos.csv', 422, 'encoding'];
        yield 'php extension' => ["a\n1\n", 'shell.php', 422, 'extension'];
        yield 'double extension' => ["a\n1\n", 'datos.csv.php', 422, 'extension'];
        yield 'no extension' => ["a\n1\n", 'datos', 422, 'extension'];
        yield 'empty file' => ['', 'vacio.csv', 422, 'empty'];
    }

    /** @dataProvider badUploads */
    public function testRejectsMaliciousOrInvalidFiles(string $content, string $clientName, int $status, string $code): void
    {
        $r = $this->uploadAs($this->ana, $this->ws, 'datos', $content, $clientName);
        self::assertSame($status, $r->status, $r->body);
        self::assertSame($code, $r->decoded()['error']['details'][0]['code'] ?? null);
        $this->assertNothingStored();
    }

    public function testBinaryOrInvalidUtf8AfterTheFirstChunkIsRejected(): void
    {
        $padding = "columna\n" . str_repeat("valor\n", 20000); // ~120 KB of valid text first
        foreach (["NUL" => $padding . "x\0y\n", "latin-1" => $padding . "Jos\xE9\n"] as $label => $content) {
            $r = $this->uploadAs($this->ana, $this->ws, 'tarde', $content);
            self::assertSame(422, $r->status, $label);
        }
        $this->assertNothingStored();
    }

    public function testMultibyteCharacterSplitAcrossReadChunksIsAccepted(): void
    {
        // 1 MiB read chunks: place a 3-byte character (€) exactly across the boundary.
        $content = "c\n" . str_repeat('a', 1048576 - 3) . "€\n";
        $r = $this->uploadAs($this->ana, $this->ws, 'frontera', $content);
        self::assertSame(202, $r->status, $r->body);
    }

    public function testOversizedFileIsRejectedWith413(): void
    {
        $this->app = \EduCloud\Core\App::create($this->app()->config->with('quotas.upload_max_bytes', 16), testDatabase: true);
        $r = $this->uploadAs($this->ana, $this->ws, 'grande', "columna\n" . str_repeat("1\n", 50));
        self::assertSame(413, $r->status);
        $this->assertNothingStored();
    }

    public function testTraversalInClientFileNameNeverReachesTheFilesystem(): void
    {
        $r = $this->uploadAs($this->ana, $this->ws, 'ruta', "a\n1\n", '..\\..\\..\\windows\\evil.csv');
        self::assertSame(202, $r->status, $r->body);
        self::assertSame('evil.csv', $r->decoded()['data']['dataset']['version']['original_name']);
        $stored = glob($this->storagePath . '/t/*/w/*/raw/*.csv') ?: [];
        self::assertCount(1, $stored);
        self::assertStringNotContainsString('evil', $stored[0]);
    }

    public function testDatasetNameIsAStrictIdentifier(): void
    {
        foreach (['../x', 'Clientes', 'a b', "x'; DROP TABLE users; --", str_repeat('a', 64), ''] as $name) {
            $r = $this->uploadAs($this->ana, $this->ws, $name, "a\n1\n");
            self::assertSame(422, $r->status, $name);
        }
        $this->assertNothingStored();
    }

    public function testFilesThatWereNotGenuinelyUploadedCannotBeMoved(): void
    {
        // An attacker-controlled tmp path (e.g. via a crafted request) must never be moved into storage:
        // production UploadedFile instances use is_uploaded_file()/move_uploaded_file().
        $secret = $this->fixtureFile("password\nhunter2\n");
        $request = new Request(
            method: 'POST',
            path: "/api/v1/workspaces/{$this->ws}/datasets",
            post: ['name' => 'robado'],
            headers: ['authorization' => 'Bearer ' . $this->ana, 'content-type' => 'multipart/form-data; boundary=x'],
            files: ['file' => new UploadedFile($secret, 'secret.csv', (int) filesize($secret), UPLOAD_ERR_OK)],
            requestId: Ulid::generate(),
        );
        $r = (new Kernel($this->app()))->handle($request);
        self::assertSame(400, $r->status);
        self::assertSame('UPLOAD_FAILED', $r->decoded()['error']['code']);
        self::assertFileExists($secret, 'the source file was not moved');
        $this->assertNothingStored();
    }

    public function testUploadRequiresCsrfForBrowserSessions(): void
    {
        $this->login('ana@test.example');
        $r = $this->upload("/api/v1/workspaces/{$this->ws}/datasets", ['name' => 'x'], ['file' => [$this->fixtureFile("a\n1\n"), 'x.csv']]);
        self::assertSame(403, $r->status);
        self::assertSame('CSRF_INVALID', $r->decoded()['error']['code']);
    }

    private function assertNothingStored(): void
    {
        self::assertSame(0, (int) $this->app()->db()->scalar("SELECT COUNT(*) FROM resources WHERE type = 'dataset'"));
        self::assertSame(0, (int) $this->app()->db()->scalar('SELECT COUNT(*) FROM jobs'));
        self::assertSame([], glob($this->storagePath . '/t/*/w/*/raw/*') ?: []);
    }
}
