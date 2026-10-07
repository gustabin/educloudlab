<?php

declare(strict_types=1);

namespace EduCloud\Modules\Analytics;

use EduCloud\Core\Exceptions\ValidationException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator as SchemaValidator;

/**
 * Semantic models and dashboards (M9): JSON Schema + rules the schema cannot express, checked against the real
 * workspace catalog (tables and columns). The runner re-validates every identifier when it compiles a query.
 */
final class AnalyticsDefinition
{
    /**
     * @param array<string, array<string, string>> $catalog "layer.table" => [column => type] (ready silver/gold tables)
     * @return array<string, mixed> the normalised model
     * @throws ValidationException with one detail per problem (field = JSON pointer, e.g. "definition/measures/1/column")
     */
    public static function model(mixed $definition, array $catalog): array
    {
        $model = self::schema($definition, 'semantic_model.schema.json');
        $problems = [];
        $fact = (string) $model['fact'];
        $columns = static fn (string $table): ?array => $catalog[$table] ?? null;
        if ($columns($fact) === null) {
            $problems[] = ['definition/fact', 'missing', "La tabla $fact no existe en el lakehouse (o no está lista)."];
        }

        $related = [];
        foreach ($model['relationships'] ?? [] as $i => $rel) {
            $table = (string) $rel['table'];
            if ($table === $fact || isset($related[$table])) {
                $problems[] = ["definition/relationships/$i/table", 'duplicate', "La tabla $table ya está relacionada (o es la tabla de hechos)."];
                continue;
            }
            $related[$table] = true;
            if ($columns($table) === null) {
                $problems[] = ["definition/relationships/$i/table", 'missing', "La tabla $table no existe en el lakehouse."];
                continue;
            }
            if ($columns($fact) !== null && !isset($catalog[$fact][$rel['fact_column']])) {
                $problems[] = ["definition/relationships/$i/fact_column", 'missing', "La tabla $fact no tiene la columna {$rel['fact_column']}."];
            }
            if (!isset($catalog[$table][$rel['column']])) {
                $problems[] = ["definition/relationships/$i/column", 'missing', "La tabla $table no tiene la columna {$rel['column']}."];
            }
        }

        $names = [];
        $base = [];
        foreach ($model['measures'] as $i => $m) {
            if (isset($names[$m['name']])) {
                $problems[] = ["definition/measures/$i/name", 'duplicate', "El nombre {$m['name']} está repetido."];
            }
            $names[$m['name']] = true;
            if (isset($m['agg']) === isset($m['ratio'])) {
                $message = 'Cada medida tiene "agg" (agregación) o "ratio" (división de dos medidas), no ambos.';
                $problems[] = ["definition/measures/$i", 'structure', $message];
                continue;
            }
            if (isset($m['agg'])) {
                $base[$m['name']] = true;
                $column = $m['column'] ?? null;
                if ($column === null || $column === '*') {
                    if ($m['agg'] !== 'count') {
                        $message = 'Esta medida necesita una columna de la tabla de hechos (solo count admite *).';
                        $problems[] = ["definition/measures/$i/column", 'required', $message];
                    }
                } elseif ($columns($fact) !== null && !isset($catalog[$fact][$column])) {
                    $problems[] = ["definition/measures/$i/column", 'missing', "La tabla $fact no tiene la columna $column."];
                }
            } elseif (isset($m['column'])) {
                $problems[] = ["definition/measures/$i/column", 'structure', 'Una medida ratio no usa "column".'];
            }
        }
        foreach ($model['measures'] as $i => $m) {
            foreach ($m['ratio'] ?? [] as $j => $operand) {
                if (!isset($base[$operand])) {
                    $problems[] = ["definition/measures/$i/ratio/$j", 'missing', "«{$operand}» debe ser una medida con agregación del modelo."];
                }
            }
        }

        foreach ($model['dimensions'] ?? [] as $i => $d) {
            if (isset($names[$d['name']])) {
                $problems[] = ["definition/dimensions/$i/name", 'duplicate', "El nombre {$d['name']} está repetido."];
            }
            $names[$d['name']] = true;
            $table = (string) ($d['table'] ?? $fact);
            if ($table !== $fact && !isset($related[$table])) {
                $problems[] = ["definition/dimensions/$i/table", 'relationship', "La tabla $table no tiene una relación con la tabla de hechos."];
                continue;
            }
            if ($columns($table) !== null && !isset($catalog[$table][$d['column']])) {
                $problems[] = ["definition/dimensions/$i/column", 'missing', "La tabla $table no tiene la columna {$d['column']}."];
            } elseif (isset($d['grain'], $catalog[$table][$d['column']]) && !self::isDate($catalog[$table][$d['column']])) {
                $message = "La columna {$d['column']} no es una fecha: quita grain o usa una columna DATE.";
                $problems[] = ["definition/dimensions/$i/grain", 'type', $message];
            }
        }
        if ($problems !== []) {
            throw self::fail($problems);
        }
        return $model;
    }

    /**
     * @param array<string, mixed> $model validated semantic model
     * @return array<string, mixed> the normalised dashboard
     */
    public static function dashboard(mixed $definition, array $model): array
    {
        $dashboard = self::schema($definition, 'dashboard.schema.json');
        $measures = array_column($model['measures'], null, 'name');
        $dimensions = array_column($model['dimensions'] ?? [], null, 'name');
        $problems = [];
        $ids = [];
        foreach ($dashboard['widgets'] as $i => $w) {
            if (isset($ids[$w['id']])) {
                $problems[] = ["definition/widgets/$i/id", 'duplicate', "El id {$w['id']} está repetido."];
            }
            $ids[$w['id']] = true;
            foreach ($w['measures'] as $j => $m) {
                if (!isset($measures[$m])) {
                    $problems[] = ["definition/widgets/$i/measures/$j", 'missing', "El modelo no tiene la medida $m."];
                }
            }
            $dimension = $w['dimension'] ?? null;
            if ($dimension !== null && !isset($dimensions[$dimension])) {
                $problems[] = ["definition/widgets/$i/dimension", 'missing', "El modelo no tiene la dimensión $dimension."];
            }
            if (in_array($w['type'], ['bar', 'line'], true) && $dimension === null) {
                $problems[] = ["definition/widgets/$i/dimension", 'required', 'Los gráficos de barras y líneas necesitan una dimensión.'];
            }
            if ($w['type'] === 'kpi' && $dimension !== null) {
                $problems[] = ["definition/widgets/$i/dimension", 'structure', 'Un KPI muestra un único valor: quita la dimensión.'];
            }
            if (isset($w['order']) && !in_array($w['order']['by'], [...$w['measures'], $dimension], true)) {
                $problems[] = ["definition/widgets/$i/order/by", 'missing', 'Ordena por una medida o la dimensión del propio widget.'];
            }
        }
        $date = $dashboard['date_filter']['dimension'] ?? null;
        if ($date !== null && !isset($dimensions[$date]['grain'])) {
            $problems[] = ['definition/date_filter/dimension', 'type', 'El filtro de fechas usa una dimensión de fecha (con grain) del modelo.'];
        }
        $seen = [];
        foreach ($dashboard['filters'] ?? [] as $i => $f) {
            if (!isset($dimensions[$f['dimension']]) || isset($seen[$f['dimension']])) {
                $message = "Filtro no válido: {$f['dimension']} no es una dimensión del modelo o está repetido.";
                $problems[] = ["definition/filters/$i/dimension", 'missing', $message];
            }
            $seen[$f['dimension']] = true;
        }
        if ($problems !== []) {
            throw self::fail($problems);
        }
        return $dashboard;
    }

    /**
     * Validates an exploration request against the model (the runner checks it again).
     *
     * @param array<string, mixed> $model
     * @return array<string, mixed>
     */
    public static function exploreRequest(mixed $body, array $model): array
    {
        if (!is_array($body)) {
            throw self::fail([['request', 'object', 'La consulta debe ser un objeto.']]);
        }
        $unknown = array_diff(array_keys($body), ['measures', 'dimensions', 'filters', 'order', 'limit']);
        if ($unknown !== []) {
            throw self::fail([[(string) reset($unknown), 'unknown_field', 'Campo no permitido.']]);
        }
        $measures = array_column($model['measures'], null, 'name');
        $dimensions = array_column($model['dimensions'] ?? [], null, 'name');
        $problems = [];
        $list = static function (string $field, mixed $value, array $known, int $max) use (&$problems): array {
            if ($value === null) {
                return [];
            }
            if (!is_array($value) || !array_is_list($value) || count($value) > $max || count(array_unique($value, SORT_REGULAR)) !== count($value)) {
                $problems[] = [$field, 'list', "Indica una lista (máximo $max, sin repetidos)."];
                return [];
            }
            foreach ($value as $i => $v) {
                if (!is_string($v) || !isset($known[$v])) {
                    $problems[] = ["$field/$i", 'missing', 'No existe en el modelo.'];
                }
            }
            return $value;
        };
        $out = [
            'measures' => $list('measures', $body['measures'] ?? null, $measures, 10),
            'dimensions' => $list('dimensions', $body['dimensions'] ?? null, $dimensions, 3),
        ];
        if ($out['measures'] === [] && $out['dimensions'] === []) {
            $problems[] = ['measures', 'required', 'Elige al menos una medida o una dimensión.'];
        }
        $filters = $body['filters'] ?? [];
        if (!is_array($filters) || !array_is_list($filters) || count($filters) > 10) {
            $problems[] = ['filters', 'list', 'Indica una lista de filtros (máximo 10).'];
            $filters = [];
        }
        foreach ($filters as $i => $f) {
            $ok = is_array($f) && is_string($f['dimension'] ?? null) && isset($dimensions[$f['dimension']])
                && in_array($f['op'] ?? null, ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in', 'between'], true)
                && array_key_exists('value', $f) && count($f) === 3 && self::filterValue($f['op'], $f['value']);
            if (!$ok) {
                $problems[] = ["filters/$i", 'filter', 'Filtro no válido: {dimension, op, value} con una dimensión del modelo.'];
            }
        }
        $out['filters'] = $filters;
        if (isset($body['order'])) {
            $order = $body['order'];
            $valid = is_array($order) && in_array($order['by'] ?? null, [...$out['measures'], ...$out['dimensions']], true)
                && in_array($order['dir'] ?? 'asc', ['asc', 'desc'], true) && array_diff(array_keys($order), ['by', 'dir']) === [];
            if (!$valid) {
                $problems[] = ['order', 'order', 'Ordena por una medida o dimensión de la consulta.'];
            }
            $out['order'] = $order;
        }
        $limit = $body['limit'] ?? 100;
        if (!is_int($limit) || $limit < 1 || $limit > 1000) {
            $problems[] = ['limit', 'range', 'El límite debe estar entre 1 y 1000.'];
        }
        $out['limit'] = $limit;
        if ($problems !== []) {
            throw self::fail($problems);
        }
        return $out;
    }

    /**
     * Builds the runner queries of a dashboard render: one per widget plus one per filter selector.
     *
     * @param array<string, mixed> $dashboard
     * @param array{date_from?: string|null, date_to?: string|null, filters?: array<string, list<string>>} $values
     * @return list<array<string, mixed>>
     */
    public static function renderQueries(array $dashboard, array $values): array
    {
        $global = [];
        $date = $dashboard['date_filter']['dimension'] ?? null;
        if ($date !== null && ($values['date_from'] ?? null) !== null) {
            $global[] = ['dimension' => $date, 'op' => 'gte', 'value' => $values['date_from']];
        }
        if ($date !== null && ($values['date_to'] ?? null) !== null) {
            $global[] = ['dimension' => $date, 'op' => 'lte', 'value' => $values['date_to']];
        }
        foreach ($values['filters'] ?? [] as $dimension => $selected) {
            if ($selected !== []) {
                $global[] = ['dimension' => $dimension, 'op' => 'in', 'value' => $selected];
            }
        }
        $queries = [];
        foreach ($dashboard['widgets'] as $w) {
            $q = ['key' => 'w_' . $w['id'], 'measures' => $w['measures'], 'filters' => $global, 'limit' => $w['limit'] ?? 50];
            if (isset($w['dimension'])) {
                $q['dimensions'] = [$w['dimension']];
            }
            if (isset($w['order'])) {
                $q['order'] = ['by' => $w['order']['by'], 'dir' => $w['order']['dir'] ?? 'asc'];
            }
            $queries[] = $q;
        }
        foreach ($dashboard['filters'] ?? [] as $f) {
            $queries[] = ['key' => 'f_' . $f['dimension'], 'dimensions' => [$f['dimension']], 'limit' => 200];
        }
        return $queries;
    }

    /**
     * Render filter values sent by the viewer (only the dashboard's declared filters are accepted).
     *
     * @param array<string, mixed> $dashboard
     * @return array{date_from: string|null, date_to: string|null, filters: array<string, list<string>>}
     */
    public static function renderValues(mixed $body, array $dashboard): array
    {
        $body = is_array($body) ? $body : [];
        $problems = [];
        $unknown = array_diff(array_keys($body), ['date_from', 'date_to', 'filters']);
        foreach ($unknown as $key) {
            $problems[] = [(string) $key, 'unknown_field', 'Campo no permitido.'];
        }
        $dates = [];
        foreach (['date_from', 'date_to'] as $key) {
            $value = $body[$key] ?? null;
            if ($value !== null && (!isset($dashboard['date_filter']) || !self::isIsoDate($value))) {
                $problems[] = [$key, 'date', 'Fecha no válida (AAAA-MM-DD) o el dashboard no tiene filtro de fechas.'];
                $value = null;
            }
            $dates[$key] = $value;
        }
        if ($dates['date_from'] !== null && $dates['date_to'] !== null && $dates['date_from'] > $dates['date_to']) {
            $problems[] = ['date_to', 'order', 'La fecha final es anterior a la inicial.'];
        }
        $declared = array_column($dashboard['filters'] ?? [], 'dimension');
        $filters = [];
        $raw = $body['filters'] ?? [];
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            $problems[] = ['filters', 'object', 'Los filtros son un objeto {dimensión: [valores]}.'];
            $raw = [];
        }
        foreach ($raw as $dimension => $selected) {
            $valid = in_array($dimension, $declared, true) && is_array($selected) && array_is_list($selected) && count($selected) <= 50;
            foreach ($valid ? $selected : [] as $v) {
                $valid = $valid && is_string($v) && mb_strlen($v) <= 200;
            }
            if (!$valid) {
                $problems[] = ["filters/$dimension", 'filter', 'Filtro no declarado en el dashboard o valores no válidos (máximo 50).'];
                continue;
            }
            $filters[(string) $dimension] = array_values($selected);
        }
        if ($problems !== []) {
            throw self::fail($problems);
        }
        return ['date_from' => $dates['date_from'], 'date_to' => $dates['date_to'], 'filters' => $filters];
    }

    private static function filterValue(string $op, mixed $value): bool
    {
        $scalar = static fn (mixed $v): bool => $v === null || is_bool($v) || is_int($v) || is_float($v) || (is_string($v) && mb_strlen($v) <= 200);
        if (in_array($op, ['in', 'not_in'], true)) {
            return is_array($value) && array_is_list($value) && $value !== [] && count($value) <= 100 && array_filter($value, $scalar) === $value;
        }
        if ($op === 'between') {
            return is_array($value) && array_is_list($value) && count($value) === 2 && $value[0] !== null && $value[1] !== null
                && $scalar($value[0]) && $scalar($value[1]);
        }
        return $scalar($value);
    }

    private static function isIsoDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1
            && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4));
    }

    private static function isDate(string $type): bool
    {
        return (bool) preg_match('/^(DATE|TIMESTAMP)/i', $type);
    }

    /** @return array<string, mixed> */
    private static function schema(mixed $definition, string $file): array
    {
        if (!is_array($definition) || array_is_list($definition)) {
            throw self::fail([['definition', 'object', 'La definición debe ser un objeto JSON.']]);
        }
        $object = json_decode((string) json_encode($definition, JSON_UNESCAPED_UNICODE));
        $validator = new SchemaValidator();
        $validator->setMaxErrors(10);
        $result = $validator->validate($object, (string) file_get_contents(__DIR__ . '/' . $file));
        if (!$result->isValid()) {
            $details = [];
            foreach ((new ErrorFormatter())->format($result->error(), true) as $pointer => $messages) {
                foreach ((array) $messages as $message) {
                    $details[] = ['definition' . ($pointer === '' ? '' : $pointer), 'schema', (string) $message];
                }
            }
            throw self::fail(array_slice($details, 0, 10));
        }
        return $definition;
    }

    /** @param list<array{string, string, string}> $problems */
    private static function fail(array $problems): ValidationException
    {
        return new ValidationException(array_map(
            static fn (array $p): array => ['field' => $p[0], 'code' => $p[1], 'message' => $p[2]],
            array_slice($problems, 0, 20)
        ));
    }
}
