<?php

declare(strict_types=1);

namespace EduCloud\Tests\Labs;

use EduCloud\Modules\Labs\LabImporter;
use EduCloud\Tests\Support\ApiActors;
use EduCloud\Tests\Support\AuthHelpers;
use EduCloud\Tests\Support\DatasetHelpers;
use EduCloud\Tests\Support\LabHelpers;
use EduCloud\Tests\TestCase;

/**
 * Release gate for lab content (master plan §30, M6): for every shipped lab
 *   - an attempt where nothing was done scores 0;
 *   - the reference solution (labs/LAB-xxx/solution/solution.json), applied through the public API like a student
 *     would, scores the maximum;
 * all graded end-to-end by the dispatcher and the sandboxed runner.
 */
final class LabSolutionsTest extends TestCase
{
    use AuthHelpers;
    use ApiActors;
    use DatasetHelpers;
    use LabHelpers;

    private string $student = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireRunner();
        $this->resetDatabase();
        $this->importLabs();
        $this->student = $this->actor('estudiante@test.example');
    }

    /** @return iterable<string, array{string}> */
    public static function labs(): iterable
    {
        foreach (glob(LabImporter::labsDir() . '/LAB-*', GLOB_ONLYDIR) ?: [] as $dir) {
            yield basename($dir) => [basename($dir)];
        }
    }

    /** @dataProvider labs */
    public function testEmptyAttemptScoresZeroAndReferenceSolutionScoresMax(string $code): void
    {
        if ($code === 'LAB-008') {
            // Notebook labs need the Docker sandbox (ADR-010): run them in docker mode when it is available.
            if (!(new \EduCloud\Modules\Notebooks\DockerSandbox($this->app()->config, $this->app()->storage()))->available()) {
                self::markTestSkipped('LAB-008 needs Docker and the educloud-nb:1 image.');
            }
            $this->app = \EduCloud\Core\App::create($this->app()->config->with('execution.notebooks.mode', 'docker'), testDatabase: true);
        }
        $attempt = $this->startLab($this->student, $code);
        $this->runJobs(); // setup (profile + ingest of samples)
        $ready = $this->attempt($this->student, $attempt['id']);
        self::assertTrue($ready['environment']['ready'], "$code setup did not finish");
        foreach ($this->as($this->student, 'GET', '/api/v1/workspaces/' . $attempt['workspace']['id'] . '/datasets')->decoded()['data'] as $dataset) {
            $label = "$code setup dataset {$dataset['layer']}.{$dataset['name']}";
            self::assertSame('active', $dataset['status'], "$label failed: " . json_encode($dataset['error']));
        }

        $empty = $this->submitAndGrade($this->student, $attempt['id']);
        self::assertEquals(0, $empty['score'], "$code: an untouched environment must score 0");
        self::assertSame('in_progress', $empty['status']);

        $this->applySolution($code, $attempt['workspace']['id'], $attempt['id']);
        $graded = $this->submitAndGrade($this->student, $attempt['id']);
        $failed = array_filter($graded['tasks'], static fn (array $t): bool => !$t['result']['passed']);
        $explained = array_map(static fn (array $t): string => $t['key'] . ': ' . $t['result']['feedback'], $failed);
        self::assertSame([], $explained, "$code reference solution");
        self::assertEquals($graded['max_score'], $graded['score']);
        self::assertSame('completed', $graded['status']);
    }

    public function testWrongAnswersFailWithUsefulFeedback(): void
    {
        $id = $this->startLab($this->student, 'LAB-003')['id'];
        $this->runJobs();
        $answers = [
            't1' => "SELECT product_id, product_name, price FROM bronze.products WHERE category = 'Libros'",
            't2' => 'SELECT product_id, product_name, price FROM bronze.products ORDER BY price LIMIT 5',
            't3' => 'SELECT store_id FROM bronze.orders',
            't6' => 'SELEC 1',
            't8' => "SELECT * FROM read_csv('C:/Windows/win.ini')",
        ];
        foreach ($answers as $task => $sql) {
            $saved = $this->as($this->student, 'POST', "/api/v1/lab-attempts/$id/answers", ['task_key' => $task, 'sql' => $sql]);
            self::assertSame(200, $saved->status);
        }
        $graded = $this->submitAndGrade($this->student, $id);
        $feedback = array_column(array_column($graded['tasks'], 'result', 'key'), 'feedback', null);
        $byTask = array_combine(array_column($graded['tasks'], 'key'), $feedback);
        self::assertEquals(0, $graded['score']);
        self::assertStringContainsString('revisa las columnas pedidas', (string) $byTask['t1']);
        self::assertStringContainsString('Tu consulta falló: Error de sintaxis', (string) $byTask['t6']);
        self::assertStringContainsString('Tu consulta falló', (string) $byTask['t8']);
        self::assertStringContainsString('read_csv', (string) $byTask['t8']);
        self::assertStringContainsString('Guarda una respuesta', (string) $byTask['t5']);
    }

    public function testStorageAndPipelineChecksExplainWhatIsMissing(): void
    {
        $storage = $this->startLab($this->student, 'LAB-002');
        $ws = (string) $storage['workspace']['id'];
        $this->applySteps('LAB-002', $ws, (string) $storage['id'], [
            ['do' => 'create_resource', 'type' => 'storage', 'name' => 'almacen'],
            ['do' => 'create_container', 'storage' => 'almacen', 'name' => 'ventas-crudas'],
            ['do' => 'upload_object', 'storage' => 'almacen', 'container' => 'ventas-crudas', 'key' => '2025/pedidos.csv',
                'sample' => 'retail/orders.csv', 'metadata' => ['origen' => 'crm']],
            ['do' => 'upload_object', 'storage' => 'almacen', 'container' => 'ventas-crudas', 'key' => 'productos.csv',
                'sample' => 'retail/products.csv', 'tier' => 'archive'],
            ['do' => 'set_lifecycle', 'storage' => 'almacen', 'container' => 'ventas-crudas', 'lifecycle' => ['archive_after_days' => 30]],
        ]);
        $byTask = self::feedback($this->submitAndGrade($this->student, (string) $storage['id']));
        self::assertSame('', $byTask['t1']);
        self::assertStringContainsString('No existe el objeto 2025/productos.csv', $byTask['t2']);
        self::assertStringContainsString('debe tener el metadato origen = erp', $byTask['t3']);
        self::assertStringContainsString('su nivel debe ser cool', $byTask['t4']);
        self::assertStringContainsString('archivar a los 30 días y eliminar a los 365', $byTask['t5']);

        $etl = $this->startLab($this->student, 'LAB-006');
        $this->runJobs();
        $ws = (string) $etl['workspace']['id'];
        // A quality gate that cannot pass (status is not unique) stops the run: no table, failed run.
        $this->applySteps('LAB-006', $ws, (string) $etl['id'], [
            ['do' => 'create_pipeline', 'name' => 'pedidos-completados', 'definition' => ['nodes' => [
                ['id' => 'leer', 'type' => 'source', 'table' => 'bronze.orders'],
                ['id' => 'calidad', 'type' => 'quality_check', 'rules' => [['rule' => 'unique', 'columns' => ['status']]]],
                ['id' => 'guardar', 'type' => 'output', 'layer' => 'silver', 'table' => 'orders_completed'],
            ]]],
            ['do' => 'run_pipeline', 'name' => 'pedidos-completados'],
        ]);
        $byTask = self::feedback($this->submitAndGrade($this->student, (string) $etl['id']));
        self::assertStringContainsString('debe tener pasos source, filter', $byTask['t1']);
        self::assertStringContainsString('La última ejecución del pipeline pedidos-completados no terminó bien', $byTask['t1']);
        self::assertStringContainsString('No existe el pipeline ingresos-region', $byTask['t2']);
        self::assertStringContainsString('No existe el pipeline catalogo', $byTask['t3']);
        $runs = $this->app()->db()->select('SELECT status FROM pipeline_runs');
        self::assertSame([['status' => 'failed']], $runs);
    }

    public function testWarehouseAndSemanticChecksExplainWhatIsMissing(): void
    {
        $attempt = $this->startLab($this->student, 'LAB-009');
        $this->runJobs();
        $ws = (string) $attempt['workspace']['id'];
        $this->applySteps('LAB-009', $ws, (string) $attempt['id'], [
            ['do' => 'transform', 'layer' => 'gold', 'table' => 'dim_store',
                'sql' => 'SELECT row_number() OVER (ORDER BY store_id) AS store_key, store_id, region FROM bronze.stores'],
            // Wrong keys: store_id + 100 does not exist in dim_store (orphans).
            ['do' => 'transform', 'layer' => 'gold', 'table' => 'fact_sales',
                'sql' => 'SELECT o.order_id, o.order_date, o.store_id + 100 AS store_key, i.quantity * i.unit_price AS line_total '
                    . 'FROM bronze.order_items i JOIN bronze.orders o ON o.order_id = i.order_id'],
            ['do' => 'create_model', 'name' => 'ventas', 'definition' => [
                'fact' => 'gold.fact_sales',
                'relationships' => [['table' => 'gold.dim_store', 'fact_column' => 'store_key', 'column' => 'store_key']],
                'measures' => [['name' => 'ingresos', 'agg' => 'avg', 'column' => 'line_total']],
                'dimensions' => [['name' => 'region', 'table' => 'gold.dim_store', 'column' => 'region']],
            ]],
            ['do' => 'create_dashboard', 'name' => 'panel-ventas', 'model' => 'ventas', 'definition' => [
                'widgets' => [['id' => 'k', 'type' => 'kpi', 'title' => 'Ingresos', 'measures' => ['ingresos']]],
            ]],
        ]);
        $byTask = self::feedback($this->submitAndGrade($this->student, (string) $attempt['id']));
        self::assertStringContainsString('cuya clave (store_key) no existe en gold.dim_store', $byTask['t1']);
        self::assertStringContainsString('Falta una medida: sum(line_total)', $byTask['t2']);
        self::assertStringContainsString('panel-ventas', $byTask['t3']);
    }

    /**
     * @param array<string, mixed> $graded
     * @return array<string, string> task key => feedback ('' when passed)
     */
    private static function feedback(array $graded): array
    {
        $out = [];
        foreach ($graded['tasks'] as $task) {
            $out[$task['key']] = $task['result']['passed'] ? '' : (string) $task['result']['feedback'];
        }
        return $out;
    }

    private function applySolution(string $code, string $workspaceId, string $attemptId): void
    {
        $file = LabImporter::labsDir() . "/$code/solution/solution.json";
        $solution = json_decode((string) file_get_contents($file), true);
        self::assertIsArray($solution['steps'] ?? null, "$code has no solution.json");
        $this->applySteps($code, $workspaceId, $attemptId, $solution['steps']);
    }

    /** @param list<array<string, mixed>> $steps */
    private function applySteps(string $code, string $workspaceId, string $attemptId, array $steps): void
    {
        foreach ($steps as $i => $step) {
            $r = match ($step['do']) {
                'create_resource' => $this->as($this->student, 'POST', "/api/v1/workspaces/$workspaceId/resources", array_intersect_key(
                    $step,
                    array_flip(['type', 'name', 'config', 'tags'])
                )),
                'delete_resource' => $this->as(
                    $this->student,
                    'DELETE',
                    '/api/v1/resources/' . $this->resourceByName($this->student, $workspaceId, $step['name'])['id']
                ),
                'upload_sample' => $this->uploadAs(
                    $this->student,
                    $workspaceId,
                    $step['name'],
                    (string) file_get_contents(LabImporter::samplesDir() . '/' . $step['sample']),
                    basename($step['sample'])
                ),
                'ingest' => $this->as(
                    $this->student,
                    'POST',
                    '/api/v1/datasets/' . $this->datasetByName($this->student, $workspaceId, 'raw', $step['name'])['id'] . '/ingest',
                    ['table_name' => $step['table']]
                ),
                'transform' => $this->as($this->student, 'POST', "/api/v1/workspaces/$workspaceId/transforms", [
                    'sql' => $step['sql'], 'layer' => $step['layer'], 'table' => $step['table'],
                ]),
                'answer' => $this->as($this->student, 'POST', "/api/v1/lab-attempts/$attemptId/answers", [
                    'task_key' => $step['task'],
                    'sql' => $step['sql'],
                ]),
                'create_container' => $this->as(
                    $this->student,
                    'POST',
                    '/api/v1/resources/' . $this->resourceByName($this->student, $workspaceId, $step['storage'])['id'] . '/containers',
                    ['name' => $step['name']]
                ),
                'set_lifecycle' => $this->as(
                    $this->student,
                    'PATCH',
                    '/api/v1/containers/' . $this->containerId($workspaceId, $step['storage'], $step['container']),
                    ['lifecycle' => $step['lifecycle']]
                ),
                'upload_object' => $this->uploadObject($workspaceId, $step),
                'create_notebook' => $this->as($this->student, 'POST', "/api/v1/workspaces/$workspaceId/notebooks", [
                    'name' => $step['name'], 'cells' => $step['cells'],
                ]),
                'run_notebook' => $this->as(
                    $this->student,
                    'POST',
                    '/api/v1/notebooks/' . $this->resourceByName($this->student, $workspaceId, $step['name'])['id'] . '/runs',
                    []
                ),
                'create_model' => $this->as($this->student, 'POST', "/api/v1/workspaces/$workspaceId/semantic-models", [
                    'name' => $step['name'], 'definition' => $step['definition'],
                ]),
                'create_dashboard' => $this->as($this->student, 'POST', "/api/v1/workspaces/$workspaceId/dashboards", [
                    'name' => $step['name'], 'model_id' => $this->modelId($workspaceId, $step['model']), 'definition' => $step['definition'],
                ]),
                'create_pipeline' => $this->as($this->student, 'POST', "/api/v1/workspaces/$workspaceId/pipelines", [
                    'name' => $step['name'], 'definition' => $step['definition'],
                ]),
                'run_pipeline' => $this->as(
                    $this->student,
                    'POST',
                    '/api/v1/pipelines/' . $this->pipelineId($workspaceId, $step['name']) . '/runs',
                    []
                ),
                default => self::fail("Unknown solution step {$step['do']}"),
            };
            self::assertContains($r->status, [200, 201, 202, 204], "$code step $i ({$step['do']}): {$r->body}");
            $this->runJobs();
        }
    }

    private function containerId(string $workspaceId, string $storage, string $name): string
    {
        $resource = $this->resourceByName($this->student, $workspaceId, $storage)['id'];
        foreach ($this->as($this->student, 'GET', "/api/v1/resources/$resource/containers")->decoded()['data'] as $container) {
            if ($container['name'] === $name) {
                return (string) $container['id'];
            }
        }
        self::fail("Container $name not found");
    }

    private function modelId(string $workspaceId, string $name): string
    {
        foreach ($this->as($this->student, 'GET', "/api/v1/workspaces/$workspaceId/semantic-models")->decoded()['data'] as $model) {
            if ($model['name'] === $name) {
                return (string) $model['id'];
            }
        }
        self::fail("Semantic model $name not found");
    }

    private function pipelineId(string $workspaceId, string $name): string
    {
        foreach ($this->as($this->student, 'GET', "/api/v1/workspaces/$workspaceId/pipelines")->decoded()['data'] as $pipeline) {
            if ($pipeline['name'] === $name) {
                return (string) $pipeline['id'];
            }
        }
        self::fail("Pipeline $name not found");
    }

    /** @param array<string, mixed> $step */
    private function uploadObject(string $workspaceId, array $step): \EduCloud\Core\Response
    {
        $fields = ['key' => $step['key'], 'tier' => $step['tier'] ?? 'hot'];
        if (isset($step['metadata'])) {
            $fields['metadata'] = (string) json_encode($step['metadata']);
        }
        $source = (string) file_get_contents(LabImporter::samplesDir() . '/' . $step['sample']);
        $jar = $this->cookieJar;
        $this->cookieJar = [];
        try {
            return $this->upload(
                '/api/v1/containers/' . $this->containerId($workspaceId, $step['storage'], $step['container']) . '/objects',
                $fields,
                ['file' => [$this->fixtureFile($source), basename($step['sample'])]],
                ['Authorization' => 'Bearer ' . $this->student]
            );
        } finally {
            $this->cookieJar = $jar;
        }
    }
}
