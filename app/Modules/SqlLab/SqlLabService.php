<?php

declare(strict_types=1);

namespace EduCloud\Modules\SqlLab;

use EduCloud\Core\App;
use EduCloud\Core\Auth\Policy;
use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Exceptions\ApiException;
use EduCloud\Core\Exceptions\ConflictException;
use EduCloud\Core\Exceptions\ForbiddenException;
use EduCloud\Core\Exceptions\NotFoundException;
use EduCloud\Core\Exceptions\QuotaExceededException;
use EduCloud\Core\Format;
use EduCloud\Core\Pagination;
use EduCloud\Core\Request;
use EduCloud\Modules\Datasets\DatasetRepository;
use EduCloud\Modules\Datasets\DatasetService;
use EduCloud\Modules\Jobs\JobController;
use EduCloud\Modules\Jobs\JobRepository;
use EduCloud\Modules\Workspaces\WorkspaceService;

/**
 * SQL Lab use cases. Student SQL is stored and executed only in the execution plane's sandbox (worker/ops/sql_ops.py);
 * PHP validates sizes/permissions, records history and enqueues jobs. Never executes SQL against MySQL or DuckDB itself.
 */
final class SqlLabService
{
    private QueryRepository $queries;
    private JobRepository $jobs;
    private DatasetRepository $datasets;
    private WorkspaceService $workspaces;
    private Policy $policy;

    public function __construct(private readonly App $app)
    {
        $db = $app->db();
        $this->queries = new QueryRepository($db);
        $this->jobs = new JobRepository($db);
        $this->datasets = new DatasetRepository($db);
        $this->workspaces = new WorkspaceService($app);
        $this->policy = new Policy($app->config);
    }

    /**
     * @param callable(): string $validSql validates the input after visibility has been resolved
     * @return array<string, mixed>
     */
    public function run(Request $request, TenantContext $ctx, string $workspacePublicId, callable $validSql): array
    {
        $ws = $this->workspaces->findOrFail($ctx, $workspacePublicId);
        if (!$this->policy->canModify($ctx, (int) $ws['owner_user_id'], 'execute')) {
            throw new ForbiddenException();
        }
        $sql = $validSql();
        $this->assertLakehouse($ctx, (int) $ws['id']);

        $created = $this->app->db()->transaction(function () use ($ctx, $ws, $sql): array {
            $this->lockUserAndCheckJobs($ctx);
            $query = $this->queries->create($ctx, (int) $ws['id'], $sql);
            // Interactive: higher priority (1) than background ingestion (5).
            $timeout = (int) $this->app->config->get('execution.timeouts.sql_query', 20);
            $job = $this->jobs->create($ctx, (int) $ws['id'], 'sql_query', ['query_id' => $query['id']], $timeout, 1);
            $this->queries->attachJob($ctx->tenantId, $query['id'], $job['id']);
            return $query;
        });
        $this->app->audit()->record($request, 'sql.query', 'success', $ctx->tenantId, $ctx->userId, 'query', $created['public_id'], [
            'workspace' => $workspacePublicId,
            'length' => mb_strlen($sql),
        ]);
        return $this->get($ctx, $created['public_id']);
    }

    /** @return array<string, mixed> query incl. result when it succeeded */
    public function get(TenantContext $ctx, string $queryPublicId): array
    {
        $row = $this->queries->findVisible($ctx, $queryPublicId, $this->policy->seesWholeTenant($ctx));
        if ($row === null) {
            throw new NotFoundException('La consulta no existe.');
        }
        $out = self::present($row);
        if ($out['status'] === 'succeeded') {
            $file = $this->app->storage()->queryResultFile($ctx->tenantPublicId, (string) $row['workspace_public_id'], (string) $row['public_id']);
            $result = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            $out['result'] = is_array($result) ? $result : null;
            $out['result_expired'] = !is_array($result);
        }
        return $out;
    }

    /** @return array{items: list<array<string, mixed>>, meta: array<string, int>} */
    public function history(TenantContext $ctx, string $workspacePublicId, Pagination $page): array
    {
        $ws = $this->workspaces->findOrFail($ctx, $workspacePublicId);
        $rows = $this->queries->history($ctx, (int) $ws['id'], $page->perPage, $page->offset());
        return [
            'items' => array_map([self::class, 'present'], $rows),
            'meta' => $page->meta($this->queries->countHistory($ctx, (int) $ws['id'])),
        ];
    }

    /**
     * Lakehouse catalog from metadata (no engine call): ready tables per layer with their columns.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function catalog(TenantContext $ctx, string $workspacePublicId): array
    {
        $ws = $this->workspaces->findOrFail($ctx, $workspacePublicId);
        $catalog = ['bronze' => [], 'silver' => [], 'gold' => []];
        foreach ($this->datasets->listForWorkspace($ctx, (int) $ws['id']) as $row) {
            if ($row['layer'] === 'raw' || $row['status'] !== 'active' || $row['table_name'] === null) {
                continue;
            }
            $catalog[(string) $row['layer']][] = [
                'dataset_id' => (string) $row['public_id'],
                'table' => (string) $row['table_name'],
                'qualified_name' => $row['layer'] . '.' . $row['table_name'],
                'row_count' => $row['row_count'] === null ? null : (int) $row['row_count'],
                'columns' => array_map(
                    static fn (array $c): array => ['name' => (string) $c['name'], 'type' => (string) $c['type']],
                    Format::jsonColumn($row['schema_json'])
                ),
            ];
        }
        return $catalog;
    }

    /**
     * Materialises a SELECT as silver.<table> or gold.<table>.
     *
     * @param callable(): array{sql: string, layer: string, table: string} $validInput
     * @return array{dataset: array<string, mixed>, job: array<string, mixed>}
     */
    public function transform(Request $request, TenantContext $ctx, string $workspacePublicId, callable $validInput): array
    {
        $ws = $this->workspaces->findOrFail($ctx, $workspacePublicId);
        $this->workspaces->assertCan($request, $ctx, $ws, 'create');
        $input = $validInput();
        $this->assertLakehouse($ctx, (int) $ws['id']);
        (new DatasetService($this->app))->assertLakehouseHasRoom($ctx, (string) $ws['public_id']);

        $created = $this->app->db()->transaction(function () use ($ctx, $ws, $input): array {
            $this->lockUserAndCheckJobs($ctx);
            $this->app->db()->select('SELECT id FROM workspaces WHERE tenant_id = ? AND id = ? FOR UPDATE', [$ctx->tenantId, (int) $ws['id']]);
            $max = (int) $this->app->config->get('quotas.datasets_per_workspace', 50);
            if ($this->datasets->countForWorkspace($ctx, (int) $ws['id']) >= $max) {
                throw new QuotaExceededException("Este workspace ya tiene el máximo de $max datasets.");
            }
            if ($this->datasets->nameTaken($ctx, (int) $ws['id'], $input['layer'], $input['table'])) {
                throw new ConflictException("Ya existe la tabla {$input['layer']}.{$input['table']}. Elimínala o usa otro nombre.");
            }
            $dataset = $this->datasets->createDataset(
                $ctx,
                (int) $ws['id'],
                (int) $ws['owner_user_id'],
                $input['table'],
                $input['layer'],
                $input['table'],
                ['sql' => $input['sql']],
            );
            $version = $this->datasets->createVersion($ctx, $dataset['dataset_id'], 'table', null, null, 0, null);
            $job = $this->jobs->create($ctx, (int) $ws['id'], 'transform', [
                'dataset_id' => $dataset['dataset_id'],
                'version_id' => $version['id'],
            ], (int) $this->app->config->get('execution.timeouts.transform', 90), 3);
            return ['dataset' => $dataset, 'job' => $job];
        });

        $target = $input['layer'] . '.' . $input['table'];
        $this->app->audit()->record($request, 'sql.transform', 'success', $ctx->tenantId, $ctx->userId, 'dataset', $created['dataset']['public_id'], [
            'target' => $target,
        ]);
        $dataset = (new DatasetService($this->app))->findOrFail($ctx, $created['dataset']['public_id']);
        $job = $this->jobs->findVisible($ctx, $created['job']['public_id'], true);
        return ['dataset' => DatasetService::present($dataset), 'job' => $job === null ? [] : JobController::present($job)];
    }

    private function assertLakehouse(TenantContext $ctx, int $workspaceId): void
    {
        if (!$this->datasets->hasActiveLakehouse($ctx, $workspaceId)) {
            throw new ApiException(409, 'LAKEHOUSE_REQUIRED', 'Crea un recurso lakehouse en este workspace para usar el SQL Lab.');
        }
    }

    private function lockUserAndCheckJobs(TenantContext $ctx): void
    {
        $this->app->db()->select('SELECT id FROM memberships WHERE tenant_id = ? AND user_id = ? FOR UPDATE', [$ctx->tenantId, $ctx->userId]);
        $max = (int) $this->app->config->get('quotas.active_jobs_per_user', 3);
        if ($this->jobs->countActiveForUser($ctx, $ctx->userId) >= $max) {
            throw new QuotaExceededException('Tienes demasiados trabajos en curso. Espera a que terminen.');
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function present(array $row): array
    {
        $jobStatus = $row['job_status'] === null ? null : (string) $row['job_status'];
        $status = match ($jobStatus) {
            'queued', 'running' => $jobStatus,
            'succeeded' => 'succeeded',
            'timed_out' => 'timed_out',
            'failed', 'cancelled' => $jobStatus,
            default => (string) $row['status'],
        };
        return [
            'id' => (string) $row['public_id'],
            'workspace_id' => (string) $row['workspace_public_id'],
            'sql' => (string) $row['sql_text'],
            'status' => $status,
            'duration_ms' => $row['duration_ms'] === null ? null : (int) $row['duration_ms'],
            'row_count' => $row['row_count'] === null ? null : (int) $row['row_count'],
            'error' => $row['error_code'] === null && !in_array($status, ['failed', 'timed_out'], true)
                ? null
                : ['code' => (string) ($row['error_code'] ?? 'SQL_ERROR'), 'message' => (string) ($row['safe_message'] ?? '')],
            'created_at' => Format::isoUtc((string) $row['created_at']),
        ];
    }
}
