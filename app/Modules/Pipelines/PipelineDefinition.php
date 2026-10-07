<?php

declare(strict_types=1);

namespace EduCloud\Modules\Pipelines;

use EduCloud\Core\Exceptions\ValidationException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator as SchemaValidator;

/**
 * Pipeline definitions (ADR-009): JSON Schema (pipeline.schema.json) + structural rules the schema cannot express.
 * The runner re-validates every node when it compiles it (defence in depth).
 */
final class PipelineDefinition
{
    /**
     * @param mixed $definition decoded JSON (associative arrays)
     * @return array<string, mixed> the normalised definition
     * @throws ValidationException with one detail per problem (field = JSON pointer, e.g. "definition/nodes/2/op")
     */
    public static function validate(mixed $definition): array
    {
        if (!is_array($definition) || array_is_list($definition)) {
            throw self::fail([['definition', 'object', 'La definición debe ser un objeto JSON con "nodes".']]);
        }
        $object = json_decode((string) json_encode($definition, JSON_UNESCAPED_UNICODE));
        $validator = new SchemaValidator();
        $validator->setMaxErrors(10);
        $result = $validator->validate($object, (string) file_get_contents(__DIR__ . '/pipeline.schema.json'));
        if (!$result->isValid()) {
            $details = [];
            foreach ((new ErrorFormatter())->format($result->error(), true) as $pointer => $messages) {
                foreach ((array) $messages as $message) {
                    $details[] = ['definition' . ($pointer === '' ? '' : $pointer), 'schema', (string) $message];
                }
            }
            throw self::fail(array_slice($details, 0, 10));
        }

        $nodes = $definition['nodes'];
        $problems = [];
        if ($nodes[0]['type'] !== 'source') {
            $problems[] = ['definition/nodes/0/type', 'structure', 'El primer nodo debe ser de tipo source.'];
        }
        $last = count($nodes) - 1;
        if ($nodes[$last]['type'] !== 'output') {
            $problems[] = ["definition/nodes/$last/type", 'structure', 'El último nodo debe ser de tipo output.'];
        }
        $ids = [];
        foreach ($nodes as $i => $node) {
            if (in_array($node['type'], ['source', 'output'], true) && $i !== 0 && $i !== $last) {
                $where = $node['type'] === 'source' ? 'principio.' : 'final.';
                $problems[] = ["definition/nodes/$i/type", 'structure', "Solo puede haber un nodo {$node['type']} y debe ir al $where"];
            }
            if (isset($ids[$node['id']])) {
                $problems[] = ["definition/nodes/$i/id", 'duplicate', "El id «{$node['id']}» está repetido."];
            }
            $ids[$node['id']] = true;
            foreach ($node['conditions'] ?? [] as $j => $c) {
                $needsValue = !in_array($c['op'], ['is_null', 'not_null'], true);
                $isList = in_array($c['op'], ['in', 'not_in'], true);
                if ($needsValue && !array_key_exists('value', $c)) {
                    $problems[] = ["definition/nodes/$i/conditions/$j/value", 'required', 'Esta condición necesita un valor.'];
                } elseif ($needsValue && $isList !== is_array($c['value'])) {
                    $message = $isList ? 'in/not_in necesitan una lista de valores.' : 'El valor debe ser un único valor.';
                    $problems[] = ["definition/nodes/$i/conditions/$j/value", 'type', $message];
                }
            }
            foreach ($node['measures'] ?? [] as $j => $m) {
                if (($m['column'] ?? null) === null && $m['fn'] !== 'count' || (($m['column'] ?? null) === '*' && $m['fn'] !== 'count')) {
                    $problems[] = ["definition/nodes/$i/measures/$j/column", 'required', 'Esta medida necesita una columna (solo count admite *).'];
                }
            }
            foreach ($node['rules'] ?? [] as $j => $r) {
                $needs = ['not_null' => 'column', 'range' => 'column', 'accepted_values' => 'column', 'unique' => 'columns'][$r['rule']] ?? null;
                if ($needs !== null && !isset($r[$needs])) {
                    $problems[] = ["definition/nodes/$i/rules/$j/$needs", 'required', "La regla {$r['rule']} necesita $needs."];
                }
                if ($r['rule'] === 'accepted_values' && !isset($r['values'])) {
                    $problems[] = ["definition/nodes/$i/rules/$j/values", 'required', 'accepted_values necesita values.'];
                }
                if (in_array($r['rule'], ['range', 'row_count'], true) && !isset($r['min']) && !isset($r['max'])) {
                    $problems[] = ["definition/nodes/$i/rules/$j", 'required', "La regla {$r['rule']} necesita min y/o max."];
                }
            }
        }
        if ($problems !== []) {
            throw self::fail($problems);
        }
        $definition['retries'] ??= 0;
        return $definition;
    }

    /**
     * @param array<string, mixed> $definition
     * @return array{layer: string, table: string}
     */
    public static function output(array $definition): array
    {
        $last = $definition['nodes'][count($definition['nodes']) - 1];
        return ['layer' => (string) $last['layer'], 'table' => (string) $last['table']];
    }

    /**
     * @param array<string, mixed> $definition
     * @return list<string> raw dataset names read by source nodes
     */
    public static function rawInputs(array $definition): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (array $n): ?string => $n['type'] === 'source' && isset($n['raw']) ? (string) $n['raw'] : null,
            $definition['nodes']
        ))));
    }

    /** @param list<array{0: string, 1: string, 2: string}> $problems */
    private static function fail(array $problems): ValidationException
    {
        return new ValidationException(array_map(
            static fn (array $p): array => ['field' => $p[0], 'code' => $p[1], 'message' => $p[2]],
            $problems
        ));
    }
}
