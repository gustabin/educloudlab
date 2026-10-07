<?php

declare(strict_types=1);

namespace EduCloud\Modules\Labs;

use EduCloud\Core\Format;

/**
 * Metadata checks evaluated from the control-plane database at validation time - the actual state, never anything
 * the client sends: resources, datasets, object storage (containers, objects) and pipelines (M7).
 */
final class MetadataChecks
{
    /** @var list<array<string, mixed>>|null */
    private ?array $resources = null;
    /** @var list<array<string, mixed>>|null */
    private ?array $datasets = null;
    /** @var list<array<string, mixed>>|null */
    private ?array $containers = null;
    /** @var list<array<string, mixed>>|null */
    private ?array $pipelines = null;

    public function __construct(private readonly LabStateRepository $state, private readonly int $tenantId, private readonly int $workspaceId)
    {
    }

    /**
     * @param array<string, mixed> $check
     * @return array{passed: bool, feedback: string, evidence: array<string, mixed>}
     */
    public function evaluate(array $check): array
    {
        return match ($check['type']) {
            'resource_exists' => $this->resourceExists($check),
            'resource_deleted' => $this->resourceDeleted($check),
            'dataset_exists' => $this->datasetExists($check),
            'container_exists' => $this->containerExists($check),
            'object_exists' => $this->objectExists($check),
            'pipeline_has_nodes' => $this->pipelineHasNodes($check),
            'pipeline_run_succeeded' => $this->pipelineRunSucceeded($check),
            default => self::result(false, 'Comprobación no soportada.'),
        };
    }

    /**
     * @param array<string, mixed> $check
     * @return array{passed: bool, feedback: string, evidence: array<string, mixed>}
     */
    private function resourceExists(array $check): array
    {
        $label = self::resourceLabel($check);
        $candidates = array_filter($this->resources(), static fn (array $r): bool => $r['type'] === $check['resource']
            && $r['status'] === 'active' && (!isset($check['name']) || $r['name'] === $check['name']));
        if ($candidates === []) {
            return self::result(false, "No existe $label activo en el workspace.");
        }
        $configOk = false;
        foreach ($candidates as $r) {
            $config = self::contains(Format::jsonColumn($r['config']), $check['config'] ?? []);
            $configOk = $configOk || $config;
            if ($config && self::contains(Format::jsonColumn($r['tags']), $check['tags'] ?? [])) {
                return self::result(true, '');
            }
        }
        return $configOk
            ? self::result(false, "Existe $label, pero le faltan las etiquetas pedidas.")
            : self::result(false, "Existe $label, pero su configuración no es la pedida.");
    }

    /**
     * @param array<string, mixed> $check
     * @return array{passed: bool, feedback: string, evidence: array<string, mixed>}
     */
    private function resourceDeleted(array $check): array
    {
        $label = self::resourceLabel($check);
        $deleted = false;
        foreach ($this->resources() as $r) {
            if ($r['type'] !== $check['resource'] || $r['name'] !== $check['name']) {
                continue;
            }
            if (in_array($r['status'], ['provisioning', 'active', 'failed'], true)) {
                return self::result(false, "$label todavía existe: elimínalo.");
            }
            $deleted = true;
        }
        return $deleted ? self::result(true, '') : self::result(false, "No encontramos $label: créalo y después elimínalo.");
    }

    /**
     * @param array<string, mixed> $check
     * @return array{passed: bool, feedback: string, evidence: array<string, mixed>}
     */
    private function datasetExists(array $check): array
    {
        $layer = (string) $check['layer'];
        $label = $layer === 'raw' ? "el dataset raw {$check['name']}" : "la tabla $layer.{$check['name']}";
        foreach ($this->datasets() as $d) {
            $matches = $d['layer'] === $layer && ($layer === 'raw' ? $d['name'] === $check['name'] : $d['table_name'] === $check['name']);
            if (!$matches || $d['status'] === 'deleted' || $d['status'] === 'deleting') {
                continue;
            }
            if ($d['status'] === 'active' && $d['version_status'] === 'ready') {
                return self::result(true, '');
            }
            return self::result(false, ucfirst($label) . ' existe, pero no está lista (revisa si su procesamiento falló).');
        }
        return self::result(false, 'No existe ' . $label . '.');
    }

    /**
     * @param array<string, mixed> $check
     * @return array{passed: bool, feedback: string, evidence: array<string, mixed>}
     */
    private function containerExists(array $check): array
    {
        $container = $this->container($check);
        if ($container === null) {
            $where = isset($check['storage']) ? " en el almacenamiento {$check['storage']}" : '';
            return self::result(false, "No existe el contenedor {$check['name']}$where.");
        }
        if (isset($check['lifecycle']) && !self::contains(Format::jsonColumn($container['lifecycle']), $check['lifecycle'])) {
            return self::result(false, "El contenedor {$check['name']} existe, pero su política de ciclo de vida no es la pedida.");
        }
        return self::result(true, '');
    }

    /**
     * @param array<string, mixed> $check
     * @return array{passed: bool, feedback: string, evidence: array<string, mixed>}
     */
    private function objectExists(array $check): array
    {
        $container = $this->container(['name' => $check['container']]);
        if ($container === null) {
            return self::result(false, "No existe el contenedor {$check['container']}.");
        }
        $byKey = isset($check['key']);
        $matching = array_values(array_filter(
            $this->state->objects($this->tenantId, (int) $container['id']),
            static fn (array $o): bool => $byKey
                ? $o['object_key'] === $check['key']
                : str_starts_with((string) $o['object_key'], (string) $check['prefix'])
        ));
        $label = $byKey ? "el objeto {$check['key']}" : "objetos con el prefijo {$check['prefix']}";
        $needed = $byKey ? 1 : (int) ($check['min_count'] ?? 1);
        if (count($matching) < $needed) {
            return $byKey
                ? self::result(false, "No existe $label en el contenedor {$check['container']}.")
                : self::result(false, "Se esperaban al menos $needed $label en {$check['container']}; hay " . count($matching) . '.');
        }
        foreach ($matching as $o) {
            if (isset($check['metadata']) && !self::contains(Format::jsonColumn($o['metadata']), $check['metadata'])) {
                return self::result(false, ucfirst($label) . ': faltan los metadatos pedidos.');
            }
            if (isset($check['tier']) && $o['tier'] !== $check['tier']) {
                return self::result(false, ucfirst($label) . ": el nivel de acceso debe ser {$check['tier']}.");
            }
        }
        return self::result(true, '');
    }

    /**
     * @param array<string, mixed> $check
     * @return array{passed: bool, feedback: string, evidence: array<string, mixed>}
     */
    private function pipelineHasNodes(array $check): array
    {
        $candidates = $this->pipelinesNamed($check);
        if ($candidates === []) {
            return self::result(false, self::missingPipeline($check));
        }
        foreach ($candidates as $p) {
            $types = array_column(Format::jsonColumn($p['definition'])['nodes'] ?? [], 'type');
            if (array_diff($check['node_types'], $types) === []) {
                return self::result(true, '');
            }
        }
        $label = isset($check['pipeline']) ? "El pipeline {$check['pipeline']}" : 'Ningún pipeline';
        return self::result(false, "$label no tiene todos los pasos pedidos: " . implode(', ', $check['node_types']) . '.');
    }

    /**
     * @param array<string, mixed> $check
     * @return array{passed: bool, feedback: string, evidence: array<string, mixed>}
     */
    private function pipelineRunSucceeded(array $check): array
    {
        $candidates = $this->pipelinesNamed($check);
        if ($candidates === []) {
            return self::result(false, self::missingPipeline($check));
        }
        $label = isset($check['pipeline']) ? "el pipeline {$check['pipeline']}" : 'tu pipeline';
        $of = isset($check['pipeline']) ? "del pipeline {$check['pipeline']}" : 'de tu pipeline';
        $ran = false;
        foreach ($candidates as $p) {
            if ($p['run_status'] === null) {
                continue;
            }
            $ran = true;
            $output = $p['output_layer'] !== null ? $p['output_layer'] . '.' . $p['output_table'] : null;
            if ($p['run_status'] === 'succeeded' && (!isset($check['output']) || $output === $check['output'])) {
                return self::result(true, '');
            }
        }
        if (!$ran) {
            return self::result(false, "Ejecuta $label: todavía no tiene ejecuciones.");
        }
        return isset($check['output'])
            ? self::result(false, "La última ejecución $of no terminó bien o no escribió {$check['output']}.")
            : self::result(false, "La última ejecución $of no terminó bien.");
    }

    /**
     * @param array<string, mixed> $check
     * @return array<string, mixed>|null
     */
    private function container(array $check): ?array
    {
        $this->containers ??= $this->state->containers($this->tenantId, $this->workspaceId);
        foreach ($this->containers as $c) {
            if ($c['name'] === $check['name'] && (!isset($check['storage']) || $c['storage_name'] === $check['storage'])) {
                return $c;
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $check
     * @return list<array<string, mixed>>
     */
    private function pipelinesNamed(array $check): array
    {
        $this->pipelines ??= $this->state->pipelines($this->tenantId, $this->workspaceId);
        return array_values(array_filter(
            $this->pipelines,
            static fn (array $p): bool => !isset($check['pipeline']) || $p['name'] === $check['pipeline']
        ));
    }

    /** @param array<string, mixed> $check */
    private static function missingPipeline(array $check): string
    {
        return isset($check['pipeline']) ? "No existe el pipeline {$check['pipeline']}." : 'No hay ningún pipeline en el workspace.';
    }

    /**
     * Subset match: every expected key exists with the same value.
     *
     * @param array<mixed> $actual
     * @param array<mixed> $expected
     */
    private static function contains(array $actual, array $expected): bool
    {
        foreach ($expected as $key => $value) {
            if (!array_key_exists($key, $actual) || $actual[$key] !== $value) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string, mixed> $check */
    private static function resourceLabel(array $check): string
    {
        $type = $check['resource'] === 'lakehouse' ? 'un lakehouse' : 'un almacenamiento';
        return isset($check['name']) ? "$type llamado {$check['name']}" : $type;
    }

    /**
     * @param array<string, mixed> $evidence
     * @return array{passed: bool, feedback: string, evidence: array<string, mixed>}
     */
    private static function result(bool $passed, string $feedback, array $evidence = []): array
    {
        return ['passed' => $passed, 'feedback' => $feedback, 'evidence' => $evidence];
    }

    /** @return list<array<string, mixed>> */
    private function resources(): array
    {
        return $this->resources ??= $this->state->resources($this->tenantId, $this->workspaceId);
    }

    /** @return list<array<string, mixed>> */
    private function datasets(): array
    {
        return $this->datasets ??= $this->state->datasets($this->tenantId, $this->workspaceId);
    }
}
