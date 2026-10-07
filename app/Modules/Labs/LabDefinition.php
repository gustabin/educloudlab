<?php

declare(strict_types=1);

namespace EduCloud\Modules\Labs;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator as SchemaValidator;

/**
 * Loads and validates a lab directory (labs/LAB-xxx): lab.json against labs/schema/lab.schema.json, plus semantic
 * rules the schema cannot express. Instructions come from instructions.es.md: the text before the first "## " heading
 * is the lab introduction and every task needs a "## <task key>" section.
 *
 * The resulting definition is what gets stored in labs.definition; it never leaves the server as-is
 * (LabService::presentDefinition() strips checks and SQL).
 */
final class LabDefinition
{
    /** Check types evaluated by PHP from metadata (the rest run in the execution plane, op "validate"). */
    public const METADATA_CHECKS = [
        'resource_exists', 'resource_deleted', 'dataset_exists',
        'container_exists', 'object_exists', 'pipeline_has_nodes', 'pipeline_run_succeeded',
        'semantic_model_has', 'dashboard_has_widgets',
    ];
    public const DATA_CHECKS = [
        'table_has_columns', 'column_type', 'row_count', 'null_count', 'unique', 'value_range', 'query_result_matches', 'references',
    ];

    /**
     * @return array{definition: array<string, mixed>, errors: list<string>}
     */
    public static function load(string $labDir, string $samplesDir): array
    {
        $json = @file_get_contents($labDir . '/lab.json');
        if ($json === false) {
            return ['definition' => [], 'errors' => ['lab.json not found']];
        }
        $data = json_decode($json);
        if (!is_object($data)) {
            return ['definition' => [], 'errors' => ['lab.json is not a JSON object']];
        }
        $errors = self::schemaErrors($data);
        if ($errors !== []) {
            return ['definition' => [], 'errors' => $errors];
        }
        /** @var array<string, mixed> $definition */
        $definition = json_decode($json, true);
        $definition['status'] ??= 'published';
        $definition['prerequisites'] ??= [];
        $definition['downloads'] ??= [];
        $definition['setup'] ??= [];

        $markdown = @file_get_contents($labDir . '/instructions.es.md');
        if ($markdown === false) {
            return ['definition' => [], 'errors' => ['instructions.es.md not found']];
        }
        [$intro, $sections] = self::sections($markdown);
        $definition['intro_md'] = $intro;

        $errors = [];
        if (basename($labDir) !== $definition['code']) {
            $errors[] = "directory name must equal the lab code ({$definition['code']})";
        }
        $keys = [];
        $lakehouse = false;
        foreach ($definition['setup'] as $i => $action) {
            if ($action['action'] === 'create_resource' && $action['type'] === 'lakehouse') {
                $lakehouse = true;
            }
            if ($action['action'] === 'load_sample') {
                if (!is_file($samplesDir . '/' . $action['sample'])) {
                    $errors[] = "setup[$i]: sample {$action['sample']} does not exist";
                }
                if (isset($action['ingest_to']) && !$lakehouse) {
                    $errors[] = "setup[$i]: ingest_to needs an earlier create_resource lakehouse action";
                }
            }
        }
        foreach ($definition['downloads'] as $i => $download) {
            if (!is_file($samplesDir . '/' . $download['sample'])) {
                $errors[] = "downloads[$i]: sample {$download['sample']} does not exist";
            }
        }
        foreach ($definition['tasks'] as $i => &$task) {
            $key = (string) $task['key'];
            if (isset($keys[$key])) {
                $errors[] = "tasks[$i]: duplicate key $key";
            }
            $keys[$key] = true;
            if (!isset($sections[$key]) || trim($sections[$key]) === '') {
                $errors[] = "tasks[$i]: instructions.es.md has no \"## $key\" section";
            }
            $task['instructions_md'] = $sections[$key] ?? '';
            $task['answer'] ??= false;
            $task['hints'] ??= [];
            $penalties = 0;
            foreach ($task['hints'] as &$hint) {
                $hint['penalty'] ??= 0;
                $penalties += (int) $hint['penalty'];
            }
            unset($hint);
            if ($penalties > (int) $task['points']) {
                $errors[] = "tasks[$i]: hint penalties exceed the task points";
            }
            $usesAnswer = false;
            foreach ($task['checks'] as $j => $check) {
                if ($check['type'] === 'value_range' && !isset($check['min']) && !isset($check['max'])) {
                    $errors[] = "tasks[$i].checks[$j]: value_range needs min and/or max";
                }
                if (in_array($check['op'] ?? null, ['eq', 'gte', 'lte'], true) && is_array($check['value'])) {
                    $errors[] = "tasks[$i].checks[$j]: op {$check['op']} needs a single value";
                }
                if (($check['op'] ?? null) === 'between' && !is_array($check['value'])) {
                    $errors[] = "tasks[$i].checks[$j]: op between needs [min, max]";
                }
                if ($check['type'] === 'object_exists' && isset($check['key']) === isset($check['prefix'])) {
                    $errors[] = "tasks[$i].checks[$j]: object_exists needs exactly one of key or prefix";
                }
                if ($check['type'] === 'query_result_matches' && !isset($check['actual_sql'])) {
                    $usesAnswer = true;
                }
            }
            if ($usesAnswer !== $task['answer']) {
                $errors[] = "tasks[$i]: \"answer\": true is required exactly when a query_result_matches check has no actual_sql";
            }
        }
        unset($task);
        foreach (array_keys($sections) as $section) {
            if (!isset($keys[$section])) {
                $errors[] = "instructions.es.md: section \"## $section\" does not match any task";
            }
        }
        return ['definition' => $definition, 'errors' => $errors];
    }

    /** @param array<string, mixed> $definition */
    public static function maxScore(array $definition): int
    {
        return array_sum(array_map(static fn (array $t): int => (int) $t['points'], $definition['tasks'] ?? []));
    }

    /** @return list<string> */
    private static function schemaErrors(object $data): array
    {
        $validator = new SchemaValidator();
        $validator->setMaxErrors(20);
        $schema = (string) file_get_contents(dirname(__DIR__, 3) . '/labs/schema/lab.schema.json');
        $result = $validator->validate($data, $schema);
        if ($result->isValid()) {
            return [];
        }
        $errors = [];
        foreach ((new ErrorFormatter())->format($result->error(), true) as $pointer => $messages) {
            foreach ((array) $messages as $message) {
                $errors[] = ($pointer === '' ? '/' : $pointer) . ': ' . $message;
            }
        }
        return $errors;
    }

    /**
     * Splits markdown into the introduction and "## key" sections.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private static function sections(string $markdown): array
    {
        $markdown = str_replace("\r\n", "\n", $markdown);
        $parts = preg_split('/^## +([a-z][a-z0-9_]*)[ \t]*$/m', $markdown, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$markdown];
        $intro = trim((string) array_shift($parts));
        $sections = [];
        for ($i = 0; $i + 1 < count($parts); $i += 2) {
            $sections[(string) $parts[$i]] = trim((string) $parts[$i + 1]);
        }
        return [$intro, $sections];
    }
}
