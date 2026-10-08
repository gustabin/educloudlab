<?php

declare(strict_types=1);

namespace EduCloud\Tests\Integration\Notebooks;

use EduCloud\Core\App;
use EduCloud\Modules\Notebooks\DockerSandbox;
use EduCloud\Tests\Support\ApiActors;
use EduCloud\Tests\Support\AuthHelpers;
use EduCloud\Tests\Support\DatasetHelpers;
use EduCloud\Tests\TestCase;

/** Notebooks (M8): CRUD, cell validation, demo mode, real runs through the dispatcher (Docker), isolation. */
final class NotebookFlowTest extends TestCase
{
    use AuthHelpers;
    use ApiActors;
    use DatasetHelpers;

    private string $ana = '';
    private string $ws = '';

    private const CELLS = [
        ['id' => 'intro', 'type' => 'markdown', 'source' => '## Clientes'],
        ['id' => 'c1', 'type' => 'code', 'source' => "con = lakehouse()\ndf = con.sql('SELECT count(*) AS n FROM bronze.clientes').df()\n"
            . "print('filas', int(df.n[0]))\ndf"],
        ['id' => 'c2', 'type' => 'code', 'source' => "save_result('conteo', df)\n'ok'"],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // Independent of the machine's .env: every test starts in the default mode and switches explicitly.
        $this->withMode('demo');
        $this->resetDatabase();
        $this->ana = $this->actor('ana@test.example');
        $this->ws = (string) $this->createWorkspace($this->ana)['id'];
    }

    private function withMode(string $mode): void
    {
        $this->app = App::create($this->app()->config->with('execution.notebooks.mode', $mode), testDatabase: true);
    }

    /** @return array<string, mixed> */
    private function create(array $cells = self::CELLS, string $name = 'exploracion'): array
    {
        $r = $this->as($this->ana, 'POST', "/api/v1/workspaces/{$this->ws}/notebooks", ['name' => $name, 'cells' => $cells]);
        self::assertSame(201, $r->status, $r->body);
        return $r->decoded()['data'];
    }

    public function testCellsAreValidatedAndDemoModeCannotRun(): void
    {
        $bad = [
            [],
            [['id' => 'a', 'type' => 'code', 'source' => 'x'], ['id' => 'a', 'type' => 'code', 'source' => 'y']],
            [['id' => 'Mal Id', 'type' => 'code', 'source' => 'x']],
            [['id' => 'a', 'type' => 'shell', 'source' => 'rm -rf /']],
            [['id' => 'a', 'type' => 'code', 'source' => str_repeat('x', 20001)]],
            [['id' => 'a', 'type' => 'code', 'source' => "a\x00b"]],
            [['id' => 'a', 'type' => 'code', 'source' => 'x', 'extra' => 1]],
            array_fill(0, 51, ['id' => 'a', 'type' => 'markdown', 'source' => '']),
        ];
        foreach ($bad as $i => $cells) {
            $r = $this->as($this->ana, 'POST', "/api/v1/workspaces/{$this->ws}/notebooks", ['name' => "malo $i", 'cells' => $cells]);
            self::assertSame(422, $r->status, "case $i: {$r->body}");
        }
        $nb = $this->create();
        self::assertSame(['intro', 'c1', 'c2'], array_column($nb['cells'], 'id'));
        $list = $this->as($this->ana, 'GET', "/api/v1/workspaces/{$this->ws}/notebooks")->decoded();
        self::assertSame('demo', $list['meta']['mode']);

        $run = $this->as($this->ana, 'POST', "/api/v1/notebooks/{$nb['id']}/runs", []);
        self::assertSame(409, $run->status);
        self::assertSame('NOTEBOOKS_DISABLED', $run->decoded()['error']['code']);
        self::assertSame(0, (int) $this->app()->db()->scalar('SELECT COUNT(*) FROM jobs'));

        $body = ['name' => 'renombrado', 'cells' => [['id' => 'x', 'type' => 'code', 'source' => '1']]];
        $u = $this->as($this->ana, 'PATCH', "/api/v1/notebooks/{$nb['id']}", $body);
        self::assertSame(200, $u->status, $u->body);
        self::assertSame(2, $u->decoded()['data']['version']);
        // The generic resources API refuses the managed notebook resource.
        self::assertSame(409, $this->as($this->ana, 'DELETE', "/api/v1/resources/{$nb['id']}")->status);
        self::assertSame(204, $this->as($this->ana, 'DELETE', "/api/v1/notebooks/{$nb['id']}")->status);
        self::assertSame(404, $this->as($this->ana, 'GET', "/api/v1/notebooks/{$nb['id']}")->status);
    }

    public function testRunsExecuteInTheSandboxAndStoreOutputs(): void
    {
        $this->requireRunner();
        if (!(new DockerSandbox($this->app()->config, $this->app()->storage()))->available()) {
            self::markTestSkipped('Docker or the educloud-nb:1 image is not available.');
        }
        $this->withMode('docker');
        $this->createResource($this->ana, $this->ws, 'lakehouse', 'lago');
        $raw = $this->uploadAs($this->ana, $this->ws, 'clientes', self::customersCsv())->decoded()['data']['dataset']['id'];
        $this->runJobs();
        self::assertSame(202, $this->as($this->ana, 'POST', "/api/v1/datasets/$raw/ingest", ['table_name' => 'clientes'])->status);
        $this->runJobs();

        $nb = $this->create();
        $queued = $this->as($this->ana, 'POST', "/api/v1/notebooks/{$nb['id']}/runs", []);
        self::assertSame(202, $queued->status, $queued->body);
        self::assertSame('queued', $queued->decoded()['data']['status']);
        self::assertSame(409, $this->as($this->ana, 'POST', "/api/v1/notebooks/{$nb['id']}/runs", [])->status, 'one active run per notebook');
        $this->runJobs();

        $run = $this->as($this->ana, 'GET', '/api/v1/notebook-runs/' . $queued->decoded()['data']['id'])->decoded()['data'];
        self::assertSame('succeeded', $run['status'], (string) json_encode($run));
        self::assertSame(['c1', 'c2'], array_column($run['outputs'], 'id'), 'markdown cells are not executed');
        self::assertSame("filas 3\n", $run['outputs'][0]['stdout']);
        self::assertEquals([[3]], $run['outputs'][0]['table']['rows']);
        self::assertEquals([[3]], $run['artifacts']['conteo']['rows']);

        // A failing cell: the run finishes as failed with the cell error; later cells do not run.
        $this->as($this->ana, 'PATCH', "/api/v1/notebooks/{$nb['id']}", ['cells' => [
            ['id' => 'a', 'type' => 'code', 'source' => "1/0"], ['id' => 'b', 'type' => 'code', 'source' => "print('nunca')"],
        ]]);
        $second = $this->as($this->ana, 'POST', "/api/v1/notebooks/{$nb['id']}/runs", [])->decoded()['data']['id'];
        $this->runJobs();
        $failed = $this->as($this->ana, 'GET', "/api/v1/notebook-runs/$second")->decoded()['data'];
        self::assertSame(['failed', 'CELL_ERROR'], [$failed['status'], $failed['error']['code']]);
        self::assertSame('ZeroDivisionError', $failed['outputs'][0]['error']['type']);
        self::assertCount(1, $failed['outputs']);
        self::assertCount(2, $this->as($this->ana, 'GET', "/api/v1/notebooks/{$nb['id']}/runs")->decoded()['data']);
    }

    public function testQueuedRunsCanBeCancelledAndOtherTenantsSeeNothing(): void
    {
        $this->withMode('docker');
        $nb = $this->create();
        $runId = (string) $this->as($this->ana, 'POST', "/api/v1/notebooks/{$nb['id']}/runs", [])->decoded()['data']['id'];

        $eve = $this->actor('eve@test.example');
        $calls = [
            ['GET', "/api/v1/notebooks/{$nb['id']}", null],
            ['PATCH', "/api/v1/notebooks/{$nb['id']}", ['name' => 'robado']],
            ['POST', "/api/v1/notebooks/{$nb['id']}/runs", []],
            ['GET', "/api/v1/notebook-runs/$runId", null],
            ['POST', "/api/v1/notebook-runs/$runId/cancel", []],
            ['DELETE', "/api/v1/notebooks/{$nb['id']}", null],
        ];
        foreach ($calls as [$method, $path, $body]) {
            self::assertSame(404, $this->as($eve, $method, $path, $body)->status, "$method $path");
        }

        $cancel = $this->as($this->ana, 'POST', "/api/v1/notebook-runs/$runId/cancel", []);
        self::assertSame(200, $cancel->status, $cancel->body);
        self::assertSame('cancelled', $cancel->decoded()['data']['status']);
        self::assertSame(409, $this->as($this->ana, 'POST', "/api/v1/notebook-runs/$runId/cancel", [])->status);
        self::assertSame(0, $this->runJobs(), 'the cancelled job never reaches the dispatcher');
    }

    public function testNotebookLabsCannotStartInDemoMode(): void
    {
        $this->importLabsForTest();
        $catalog = array_column($this->as($this->ana, 'GET', '/api/v1/labs')->decoded()['data'], null, 'code');
        self::assertSame(['notebooks'], $catalog['LAB-008']['requires']);
        self::assertFalse($catalog['LAB-008']['available']);
        self::assertTrue($catalog['LAB-001']['available']);
        $r = $this->as($this->ana, 'POST', '/api/v1/lab-attempts', ['lab_code' => 'LAB-008']);
        self::assertSame(409, $r->status);
        self::assertSame('LAB_UNAVAILABLE', $r->decoded()['error']['code']);
        self::assertSame(0, (int) $this->app()->db()->scalar('SELECT COUNT(*) FROM lab_attempts'));
    }

    private function importLabsForTest(): void
    {
        foreach ((new \EduCloud\Modules\Labs\LabImporter($this->app()))->import() as $row) {
            self::assertContains($row['status'], ['imported', 'unchanged'], $row['lab']);
        }
    }

    public function testPageRendersInDemoMode(): void
    {
        $this->create();
        $this->cookieJar = [];
        $this->login('ana@test.example');
        $page = $this->request('GET', "/app/workspaces/{$this->ws}/notebooks");
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Modo demostración', $page->body);
        self::assertStringContainsString('id="nb-run" disabled', $page->body);
        self::assertStringContainsString('vendor/codemirror/mode/python.js', $page->body);
    }
}
