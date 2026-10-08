<?php

declare(strict_types=1);

namespace EduCloud\Tests\Unit\Labs;

use EduCloud\Modules\Labs\LabDefinition;
use EduCloud\Modules\Labs\LabImporter;
use PHPUnit\Framework\TestCase;

/** lab.json schema + semantic rules: only the closed set of declarative actions/checks is accepted (ADR-008). */
final class LabDefinitionTest extends TestCase
{
    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            array_map('unlink', glob($this->dir . '/*') ?: []);
            rmdir($this->dir);
        }
        parent::tearDown();
    }

    public function testShippedLabsAreValid(): void
    {
        $labs = glob(LabImporter::labsDir() . '/LAB-*', GLOB_ONLYDIR) ?: [];
        self::assertCount(10, $labs);
        foreach ($labs as $dir) {
            $result = LabDefinition::load($dir, LabImporter::samplesDir());
            self::assertSame([], $result['errors'], basename($dir));
            self::assertSame(100, LabDefinition::maxScore($result['definition']), basename($dir) . ' max score');
            foreach ($result['definition']['tasks'] as $task) {
                self::assertNotSame('', $task['instructions_md'], basename($dir) . ' ' . $task['key']);
            }
        }
    }

    /** @return iterable<string, array{callable(array<string, mixed>): array<string, mixed>, string}> */
    public static function invalidDefinitions(): iterable
    {
        yield 'unknown check type' => [static function (array $d): array {
            $d['tasks'][0]['checks'][0] = ['type' => 'shell', 'command' => 'whoami'];
            return $d;
        }, '/tasks/0/checks/0/type'];
        yield 'extra property (embedded code)' => [static function (array $d): array {
            $d['tasks'][0]['checks'][0]['php'] = 'system("id")';
            return $d;
        }, '/tasks/0/checks/0'];
        yield 'table outside the medallion layers' => [static function (array $d): array {
            $d['tasks'][0]['checks'][0] = ['type' => 'row_count', 'table' => 'main.users', 'op' => 'eq', 'value' => 1];
            return $d;
        }, '/tasks/0/checks/0/table'];
        yield 'unknown setup action' => [static function (array $d): array {
            $d['setup'] = [['action' => 'run_script', 'path' => '/etc/passwd']];
            return $d;
        }, '/setup/0/action'];
        yield 'sample path traversal' => [static function (array $d): array {
            $d['setup'] = [['action' => 'load_sample', 'sample' => '../../.env', 'name' => 'x']];
            return $d;
        }, '/setup/0/sample'];
        yield 'bad code' => [static function (array $d): array {
            $d['code'] = 'LAB-1';
            return $d;
        }, '/code'];
        yield 'duplicate task key' => [static function (array $d): array {
            $d['tasks'][1]['key'] = $d['tasks'][0]['key'];
            return $d;
        }, 'duplicate key'];
        yield 'hint penalties exceed points' => [static function (array $d): array {
            $d['tasks'][0]['hints'] = [['text' => 'mucho', 'penalty' => 50], ['text' => 'más', 'penalty' => 50]];
            return $d;
        }, 'hint penalties exceed'];
        yield 'answer flag without an answer check' => [static function (array $d): array {
            $d['tasks'][0]['answer'] = true;
            return $d;
        }, '"answer": true'];
        yield 'answer check without answer flag' => [static function (array $d): array {
            $d['tasks'][0]['checks'][] = ['type' => 'query_result_matches', 'expected_sql' => 'SELECT 1'];
            return $d;
        }, '"answer": true'];
        yield 'missing sample' => [static function (array $d): array {
            $d['downloads'] = [['sample' => 'retail/nope.csv']];
            return $d;
        }, 'does not exist'];
        yield 'ingest without lakehouse' => [static function (array $d): array {
            $d['setup'] = [['action' => 'load_sample', 'sample' => 'retail/stores.csv', 'name' => 'stores', 'ingest_to' => 'bronze.stores']];
            return $d;
        }, 'needs an earlier create_resource lakehouse'];
        yield 'between with a single value' => [static function (array $d): array {
            $d['tasks'][0]['checks'][0] = ['type' => 'row_count', 'table' => 'bronze.t', 'op' => 'between', 'value' => 3];
            return $d;
        }, 'between needs'];
    }

    /**
     * @dataProvider invalidDefinitions
     * @param callable(array<string, mixed>): array<string, mixed> $mutate
     */
    public function testInvalidDefinitionsAreRejected(callable $mutate, string $expected): void
    {
        $base = json_decode((string) file_get_contents(LabImporter::labsDir() . '/LAB-001/lab.json'), true);
        $errors = $this->loadTemp($mutate($base), (string) file_get_contents(LabImporter::labsDir() . '/LAB-001/instructions.es.md'));
        self::assertNotSame([], $errors);
        self::assertStringContainsString($expected, implode("\n", $errors));
    }

    public function testInstructionsMustCoverEveryTaskAndNothingElse(): void
    {
        $base = json_decode((string) file_get_contents(LabImporter::labsDir() . '/LAB-001/lab.json'), true);
        $errors = $this->loadTemp($base, "Intro\n\n## t1\nUno\n\n## t2\nDos\n\n## t3\nTres\n\n## t9\nSobra\n");
        self::assertContains('tasks[3]: instructions.es.md has no "## t4" section', $errors);
        self::assertContains('instructions.es.md: section "## t9" does not match any task', $errors);
    }

    /**
     * @param array<string, mixed> $definition
     * @return list<string>
     */
    private function loadTemp(array $definition, string $instructions): array
    {
        $this->dir = str_replace('\\', '/', sys_get_temp_dir()) . '/ec-lab-' . bin2hex(random_bytes(4)) . '/LAB-001';
        mkdir($this->dir, 0700, true);
        file_put_contents($this->dir . '/lab.json', (string) json_encode($definition));
        file_put_contents($this->dir . '/instructions.es.md', $instructions);
        $errors = LabDefinition::load($this->dir, LabImporter::samplesDir())['errors'];
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
        rmdir(dirname($this->dir));
        $this->dir = '';
        return $errors;
    }
}
