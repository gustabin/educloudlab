<?php

declare(strict_types=1);

namespace EduCloud\Modules\Labs;

use EduCloud\Core\App;

/**
 * Imports labs/LAB-xxx directories into the catalog (scripts/labs-import.php, tests).
 * A version is immutable: re-importing the same code+version with different content is an error (bump "version").
 */
final class LabImporter
{
    private LabRepository $labs;

    public function __construct(private readonly App $app)
    {
        $this->labs = new LabRepository($app->db());
    }

    public static function labsDir(): string
    {
        return dirname(__DIR__, 3) . '/labs';
    }

    public static function samplesDir(): string
    {
        return dirname(__DIR__, 3) . '/public/assets/datasets';
    }

    /**
     * @param list<string>|null $dirs lab directories (default: every labs/LAB-*)
     * @return list<array{lab: string, status: string, errors: list<string>}> status: imported|unchanged|invalid|conflict|valid
     */
    public function import(?array $dirs = null, bool $dryRun = false): array
    {
        $dirs ??= glob(self::labsDir() . '/LAB-*', GLOB_ONLYDIR) ?: [];
        sort($dirs);
        $report = [];
        foreach ($dirs as $dir) {
            $report[] = $this->importOne(rtrim(str_replace('\\', '/', $dir), '/'), $dryRun);
        }
        return $report;
    }

    /** @return array{lab: string, status: string, errors: list<string>} */
    private function importOne(string $dir, bool $dryRun): array
    {
        $name = basename($dir);
        ['definition' => $definition, 'errors' => $errors] = LabDefinition::load($dir, self::samplesDir());
        if ($errors !== []) {
            return ['lab' => $name, 'status' => 'invalid', 'errors' => $errors];
        }
        $canonical = (string) json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $checksum = hash('sha256', $canonical, true);
        $label = $definition['code'] . '@' . $definition['version'];
        if ($dryRun) {
            return ['lab' => $label, 'status' => 'valid', 'errors' => []];
        }
        $existing = $this->labs->findVersion((string) $definition['code'], (string) $definition['version']);
        if ($existing !== null) {
            if (!hash_equals((string) $existing['checksum'], $checksum)) {
                return ['lab' => $label, 'status' => 'conflict', 'errors' => ['content changed: bump "version" in lab.json']];
            }
            if ((int) $existing['is_current'] !== 1) {
                $this->labs->makeCurrent((int) $existing['id'], (string) $definition['code']);
            }
            return ['lab' => $label, 'status' => 'unchanged', 'errors' => []];
        }
        $this->labs->insertVersion($definition, LabDefinition::maxScore($definition), $checksum);
        return ['lab' => $label, 'status' => 'imported', 'errors' => []];
    }
}
