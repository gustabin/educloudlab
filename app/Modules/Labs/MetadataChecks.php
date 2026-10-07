<?php

declare(strict_types=1);

namespace EduCloud\Modules\Labs;

use EduCloud\Core\Format;

/**
 * Metadata checks (resource_exists, resource_deleted, dataset_exists) evaluated from the control-plane database
 * at validation time - the actual state, never anything the client sends.
 */
final class MetadataChecks
{
    /** @var list<array<string, mixed>>|null */
    private ?array $resources = null;
    /** @var list<array<string, mixed>>|null */
    private ?array $datasets = null;

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
