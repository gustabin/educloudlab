<?php

declare(strict_types=1);

namespace EduCloud\Tests\Integration\Pipelines;

use EduCloud\Core\App;
use EduCloud\Tests\Support\ApiActors;
use EduCloud\Tests\Support\AuthHelpers;
use EduCloud\Tests\Support\DatasetHelpers;
use EduCloud\Tests\TestCase;

/** Pipelines (M7): definitions, runs through the dispatcher and runner, per-step report, lineage, retries, cancel. */
final class PipelineFlowTest extends TestCase
{
    use AuthHelpers;
    use ApiActors;
    use DatasetHelpers;

    private string $ana = '';
    private string $ws = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireRunner();
        $this->resetDatabase();
        $this->ana = $this->actor('ana@test.example');
        $this->ws = (string) $this->createWorkspace($this->ana)['id'];
        $this->createResource($this->ana, $this->ws, 'lakehouse', 'lago');
        $raw = $this->uploadAs($this->ana, $this->ws, 'clientes', self::customersCsv())->decoded()['data']['dataset']['id'];
        $this->runJobs();
        self::assertSame(202, $this->as($this->ana, 'POST', "/api/v1/datasets/$raw/ingest", ['table_name' => 'clientes'])->status);
        $this->runJobs();
    }

    /** @return array<string, mixed> */
    private static function definition(string $onFail = 'stop', string $table = 'clientes_ok'): array
    {
        return ['retries' => 0, 'nodes' => [
            ['id' => 'leer', 'type' => 'source', 'table' => 'bronze.clientes'],
            ['id' => 'con_email', 'type' => 'filter', 'conditions' => [['column' => 'e_mail', 'op' => 'not_null']]],
            ['id' => 'normalizar', 'type' => 'select', 'columns' => [
                ['column' => 'customer_id'], ['column' => 'e_mail', 'as' => 'email', 'transform' => 'lower'], ['column' => 'amount'],
            ]],
            ['id' => 'calidad', 'type' => 'quality_check', 'on_fail' => $onFail, 'rules' => [['rule' => 'range', 'column' => 'amount', 'min' => 5]]],
            ['id' => 'guardar', 'type' => 'output', 'layer' => 'silver', 'table' => $table],
        ]];
    }

    /** @return array<string, mixed> */
    private function create(string $name, array $definition): array
    {
        $r = $this->as($this->ana, 'POST', "/api/v1/workspaces/{$this->ws}/pipelines", ['name' => $name, 'definition' => $definition]);
        self::assertSame(201, $r->status, $r->body);
        return $r->decoded()['data'];
    }

    /** @return array<string, mixed> finished run */
    private function runPipeline(string $id): array
    {
        $r = $this->as($this->ana, 'POST', "/api/v1/pipelines/$id/runs", []);
        self::assertSame(202, $r->status, $r->body);
        self::assertSame('queued', $r->decoded()['data']['status']);
        $this->runJobs();
        return $this->as($this->ana, 'GET', '/api/v1/pipeline-runs/' . $r->decoded()['data']['id'])->decoded()['data'];
    }

    public function testDefinitionsAreValidatedWithPreciseErrors(): void
    {
        $bad = self::definition();
        $bad['nodes'][1]['conditions'][0]['op'] = 'like';
        $bad['nodes'][2]['columns'][0]['column'] = 'id; DROP TABLE x';
        $r = $this->as($this->ana, 'POST', "/api/v1/workspaces/{$this->ws}/pipelines", ['name' => 'malo', 'definition' => $bad]);
        self::assertSame(422, $r->status);
        $fields = array_column($r->decoded()['error']['details'], 'field');
        self::assertContains('definition/nodes/1/conditions/0/op', $fields);
        self::assertContains('definition/nodes/2/columns/0/column', $fields);

        $order = self::definition();
        $order['nodes'] = array_reverse($order['nodes']);
        $r = $this->as($this->ana, 'POST', '/api/v1/pipeline-definitions/validate', ['definition' => $order]);
        self::assertSame(422, $r->status);
        self::assertSame('El primer nodo debe ser de tipo source.', $r->decoded()['error']['details'][0]['message']);
        $ok = $this->as($this->ana, 'POST', '/api/v1/pipeline-definitions/validate', ['definition' => self::definition()]);
        self::assertSame(['valid' => true, 'nodes' => 5, 'output' => ['layer' => 'silver', 'table' => 'clientes_ok']], $ok->decoded()['data']);
        self::assertSame(0, (int) $this->app()->db()->scalar('SELECT COUNT(*) FROM pipelines'));
    }

    public function testRunWritesTheOutputReportsStepsAndRecordsLineage(): void
    {
        $p = $this->create('limpieza', self::definition('warn'));
        $run = $this->runPipeline($p['id']);
        self::assertSame('succeeded', $run['status'], json_encode($run) ?: '');
        self::assertSame(['succeeded', 'succeeded', 'succeeded', 'warning', 'succeeded'], array_column($run['steps'], 'status'));
        self::assertSame([3, 2, 2, 2, 2], array_column($run['steps'], 'rows'));
        self::assertStringContainsString('fuera de rango', (string) $run['steps'][3]['message']);
        self::assertSame('silver.clientes_ok', $run['output']);

        $datasets = $this->as($this->ana, 'GET', "/api/v1/workspaces/{$this->ws}/datasets")->decoded()['data'];
        $silver = array_values(array_filter($datasets, static fn (array $d): bool => $d['table'] === 'silver.clientes_ok'))[0];
        self::assertSame(['active', 2], [$silver['status'], $silver['version']['row_count']]);
        self::assertSame(['customer_id', 'email', 'amount'], array_column($silver['columns'], 'name'));

        $lineage = $this->as($this->ana, 'GET', '/api/v1/datasets/' . $silver['id'] . '/lineage')->decoded()['data'];
        self::assertSame(['bronze.clientes'], array_column($lineage['upstream'], 'table'));
        self::assertSame(['pipeline', 'limpieza'], [$lineage['upstream'][0]['via'], $lineage['upstream'][0]['pipeline']['name']]);
        $bronze = array_values(array_filter($datasets, static fn (array $d): bool => $d['table'] === 'bronze.clientes'))[0];
        $bronzeLineage = $this->as($this->ana, 'GET', '/api/v1/datasets/' . $bronze['id'] . '/lineage')->decoded()['data'];
        self::assertSame(['ingest'], array_column($bronzeLineage['upstream'], 'via'));
        self::assertSame(['silver.clientes_ok'], array_column($bronzeLineage['downstream'], 'table'));

        // Re-running replaces the table as a new version of the same dataset.
        self::assertSame('succeeded', $this->runPipeline($p['id'])['status']);
        self::assertSame(2, $this->dataset($this->ana, $silver['id'])['version']['number']);
        self::assertCount(2, $this->as($this->ana, 'GET', '/api/v1/pipelines/' . $p['id'] . '/runs')->decoded()['data']);
    }

    public function testQualityFailureStopsWithoutTouchingTheOutput(): void
    {
        $p = $this->create('estricto', self::definition('stop'));
        $run = $this->runPipeline($p['id']);
        self::assertSame(['failed', 'QUALITY_FAILED'], [$run['status'], $run['error']['code']]);
        self::assertSame(['succeeded', 'succeeded', 'succeeded', 'failed', 'skipped'], array_column($run['steps'], 'status'));
        $tables = array_column($this->as($this->ana, 'GET', "/api/v1/workspaces/{$this->ws}/datasets")->decoded()['data'], 'table');
        self::assertNotContains('silver.clientes_ok', $tables, 'the provisional output dataset is removed');

        // Fix the definition and run again: the output name was released.
        $fixed = $this->as($this->ana, 'PATCH', '/api/v1/pipelines/' . $p['id'], ['definition' => self::definition('warn')]);
        self::assertSame(2, $fixed->decoded()['data']['version']);
        self::assertSame('succeeded', $this->runPipeline($p['id'])['status']);
    }

    public function testOutputMustNotOverwriteForeignTables(): void
    {
        $body = ['sql' => 'SELECT 1 AS x', 'layer' => 'silver', 'table' => 'clientes_ok'];
        $t = $this->as($this->ana, 'POST', "/api/v1/workspaces/{$this->ws}/transforms", $body);
        self::assertSame(202, $t->status);
        $this->runJobs();
        $p = $this->create('pisa', self::definition('warn'));
        $r = $this->as($this->ana, 'POST', '/api/v1/pipelines/' . $p['id'] . '/runs', []);
        self::assertSame(409, $r->status);
        self::assertStringContainsString('no la creó este pipeline', $r->decoded()['error']['message']);
    }

    public function testRawFilesCanFeedAPipeline(): void
    {
        $p = $this->create('desde_raw', ['nodes' => [
            ['id' => 'archivo', 'type' => 'source', 'raw' => 'clientes'],
            ['id' => 'guardar', 'type' => 'output', 'layer' => 'silver', 'table' => 'copia'],
        ]]);
        $run = $this->runPipeline($p['id']);
        self::assertSame('succeeded', $run['status'], json_encode($run['error']) ?: '');
        self::assertSame(3, $run['steps'][1]['rows']);
        $missing = $this->create('falta', ['nodes' => [
            ['id' => 'archivo', 'type' => 'source', 'raw' => 'no_existe'],
            ['id' => 'guardar', 'type' => 'output', 'layer' => 'silver', 'table' => 'nada'],
        ]]);
        self::assertSame(422, $this->as($this->ana, 'POST', '/api/v1/pipelines/' . $missing['id'] . '/runs', [])->status);
    }

    public function testQueuedRunsCanBeCancelledAndRunningOnesAreStopped(): void
    {
        $p = $this->create('cancelable', self::definition('warn'));
        $queued = $this->as($this->ana, 'POST', '/api/v1/pipelines/' . $p['id'] . '/runs', [])->decoded()['data'];
        $c = $this->as($this->ana, 'POST', '/api/v1/pipeline-runs/' . $queued['id'] . '/cancel', []);
        self::assertSame('cancelled', $c->decoded()['data']['status']);
        self::assertSame(409, $this->as($this->ana, 'POST', '/api/v1/pipeline-runs/' . $queued['id'] . '/cancel', [])->status);
        self::assertSame(0, $this->runJobs(), 'nothing left to run');

        // A running job is stopped at its next heartbeat when cancellation was requested.
        $this->app = App::create($this->app()->config->with('execution.heartbeat_seconds', 0), testDatabase: true);
        $running = $this->as($this->ana, 'POST', '/api/v1/pipelines/' . $p['id'] . '/runs', [])->decoded()['data'];
        $this->app()->db()->execute('UPDATE jobs SET cancel_requested = 1 WHERE type = ? AND status = ?', ['pipeline_run', 'queued']);
        $this->runJobs();
        $after = $this->as($this->ana, 'GET', '/api/v1/pipeline-runs/' . $running['id'])->decoded()['data'];
        self::assertSame(['cancelled', 'CANCELLED'], [$after['status'], $after['error']['code']]);
    }

    public function testTransientFailuresAreRetriedUpToTheConfiguredRetries(): void
    {
        $definition = self::definition('warn');
        $definition['retries'] = 1;
        $p = $this->create('reintento', $definition);
        $this->app = App::create($this->app()->config->with('execution.python', 'C:/nope/python.exe'), testDatabase: true);
        $r = $this->as($this->ana, 'POST', '/api/v1/pipelines/' . $p['id'] . '/runs', [])->decoded()['data'];
        self::assertSame(2, $this->runJobs(), 'first attempt re-queued, second attempt fails');
        $run = $this->as($this->ana, 'GET', '/api/v1/pipeline-runs/' . $r['id'])->decoded()['data'];
        self::assertSame(['failed', 'ENGINE_UNAVAILABLE', 2], [$run['status'], $run['error']['code'], $run['attempts']]);
    }

    public function testPipelinesAreManagedOnlyThroughTheirApi(): void
    {
        $p = $this->create('protegido', self::definition());
        self::assertSame(409, $this->as($this->ana, 'PATCH', '/api/v1/resources/' . $p['id'], ['name' => 'otro'])->status);
        self::assertSame(409, $this->as($this->ana, 'DELETE', '/api/v1/resources/' . $p['id'])->status);
        self::assertSame(204, $this->as($this->ana, 'DELETE', '/api/v1/pipelines/' . $p['id'])->status);
        self::assertSame(404, $this->as($this->ana, 'GET', '/api/v1/pipelines/' . $p['id'])->status);
    }
}
