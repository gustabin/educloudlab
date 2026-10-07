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

    private function applySolution(string $code, string $workspaceId, string $attemptId): void
    {
        $file = LabImporter::labsDir() . "/$code/solution/solution.json";
        $solution = json_decode((string) file_get_contents($file), true);
        self::assertIsArray($solution['steps'] ?? null, "$code has no solution.json");
        foreach ($solution['steps'] as $i => $step) {
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
                default => self::fail("Unknown solution step {$step['do']}"),
            };
            self::assertContains($r->status, [200, 201, 202, 204], "$code step $i ({$step['do']}): {$r->body}");
            $this->runJobs();
        }
    }
}
