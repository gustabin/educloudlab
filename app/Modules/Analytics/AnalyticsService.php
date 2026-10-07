<?php

declare(strict_types=1);

namespace EduCloud\Modules\Analytics;

use EduCloud\Core\App;
use EduCloud\Core\Auth\Policy;
use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Exceptions\ApiException;
use EduCloud\Core\Exceptions\ConflictException;
use EduCloud\Core\Exceptions\ForbiddenException;
use EduCloud\Core\Exceptions\NotFoundException;
use EduCloud\Core\Exceptions\QuotaExceededException;
use EduCloud\Core\Exceptions\ValidationException;
use EduCloud\Core\Format;
use EduCloud\Core\Request;
use EduCloud\Modules\Datasets\DatasetRepository;
use EduCloud\Modules\Jobs\JobRepository;
use EduCloud\Modules\Resources\ResourceRepository;
use EduCloud\Modules\Resources\ResourceTypes;
use EduCloud\Modules\Workspaces\WorkspaceService;

/**
 * Semantic models and dashboards (M9). Models and dashboards are workspace resources; querying them enqueues one
 * `semantic_query` job (202) whose result is kept for 24 h. Students never write the query SQL.
 */
final class AnalyticsService
{
    public const TYPES = ['model' => 'semantic_model', 'dashboard' => 'dashboard'];

    private AnalyticsRepository $repo;
    private WorkspaceService $workspaces;
    private Policy $policy;

    public function __construct(private readonly App $app)
    {
        $this->repo = new AnalyticsRepository($app->db());
        $this->workspaces = new WorkspaceService($app);
        $this->policy = new Policy($app->config);
    }

    // ------------------------------------------------------------------------------------------------ catalog

    /**
     * Ready silver/gold tables of the workspace with their columns (from metadata, no engine call).
     *
     * @return array<string, array<string, string>> "layer.table" => [column => type]
     */
    public function catalog(TenantContext $ctx, int $workspaceId): array
    {
        $out = [];
        foreach ((new DatasetRepository($this->app->db()))->listForWorkspace($ctx, $workspaceId) as $row) {
            if (!in_array($row['layer'], ['silver', 'gold'], true) || $row['status'] !== 'active' || $row['table_name'] === null) {
                continue;
            }
            $columns = [];
            foreach (Format::jsonColumn($row['schema_json']) as $c) {
                $columns[(string) $c['name']] = (string) $c['type'];
            }
            $out[$row['layer'] . '.' . $row['table_name']] = $columns;
        }
        return $out;
    }

    // ------------------------------------------------------------------------------------------------ models

    /** @return list<array<string, mixed>> */
    public function models(TenantContext $ctx, string $workspacePublicId): array
    {
        $ws = $this->workspaces->findOrFail($ctx, $workspacePublicId);
        return array_map([self::class, 'presentModel'], $this->repo->models($ctx, (int) $ws['id']));
    }

    /** @return array<string, mixed> */
    public function findModel(TenantContext $ctx, string $publicId): array
    {
        $row = $this->repo->findModel($ctx, $publicId, $this->policy->seesWholeTenant($ctx));
        if ($row === null) {
            throw new NotFoundException('El modelo semántico no existe.');
        }
        return $row;
    }

    /**
     * Validation for the editor: never saves or executes anything.
     *
     * @return array{valid: bool, measures: int, dimensions: int}
     */
    public function validateModel(TenantContext $ctx, string $workspacePublicId, mixed $definition): array
    {
        $ws = $this->workspaces->findOrFail($ctx, $workspacePublicId);
        $model = AnalyticsDefinition::model($definition, $this->catalog($ctx, (int) $ws['id']));
        return ['valid' => true, 'measures' => count($model['measures']), 'dimensions' => count($model['dimensions'] ?? [])];
    }

    /**
     * @param callable(): array{name: string, definition: mixed} $validInput
     * @return array<string, mixed>
     */
    public function createModel(Request $request, TenantContext $ctx, string $workspacePublicId, callable $validInput): array
    {
        $ws = $this->workspaces->findOrFail($ctx, $workspacePublicId);
        $this->workspaces->assertCan($request, $ctx, $ws, 'create');
        $input = $validInput();
        $model = AnalyticsDefinition::model($input['definition'], $this->catalog($ctx, (int) $ws['id']));
        $resource = $this->createResource($ctx, $ws, 'semantic_model', $input['name'], function (int $resourceId) use ($ctx, $ws, $model): void {
            $this->repo->createModel($ctx, (int) $ws['id'], $resourceId, $model);
        });
        $this->audit($request, $ctx, 'analytics.model_create', 'semantic_model', $resource);
        return self::presentModel($this->findModel($ctx, $resource));
    }

    /**
     * @param callable(): array{name?: string, definition?: mixed} $validInput
     * @return array<string, mixed>
     */
    public function updateModel(Request $request, TenantContext $ctx, string $publicId, callable $validInput): array
    {
        $row = $this->findModel($ctx, $publicId);
        $this->assertCan($request, $ctx, $row, 'update', 'semantic_model');
        $input = $validInput();
        $model = array_key_exists('definition', $input)
            ? AnalyticsDefinition::model($input['definition'], $this->catalog($ctx, (int) $row['workspace_id']))
            : null;
        $this->app->db()->transaction(function () use ($ctx, $row, $input, $model): void {
            $this->lockActiveWorkspace($ctx, (int) $row['workspace_id']);
            if ($model !== null) {
                // Under the workspace lock that every dashboard write takes: each dashboard on this model stays valid.
                foreach ($this->repo->dashboardsOfModel($ctx, (int) $row['id']) as $dashboard) {
                    try {
                        AnalyticsDefinition::dashboard(Format::jsonColumn($dashboard['definition']), $model);
                    } catch (ValidationException) {
                        $message = "El dashboard «{$dashboard['name']}» usa medidas o dimensiones que este cambio elimina.";
                        throw new ApiException(409, 'MODEL_IN_USE', $message);
                    }
                }
            }
            $this->rename($ctx, $row, 'semantic_model', $input['name'] ?? null);
            if ($model !== null) {
                $this->repo->updateModel($ctx, (int) $row['id'], $model);
            }
        });
        $this->audit($request, $ctx, 'analytics.model_update', 'semantic_model', $publicId, ['fields' => array_keys($input)]);
        return self::presentModel($this->findModel($ctx, $publicId));
    }

    public function deleteModel(Request $request, TenantContext $ctx, string $publicId): void
    {
        $row = $this->findModel($ctx, $publicId);
        $this->assertCan($request, $ctx, $row, 'delete', 'semantic_model');
        $this->app->db()->transaction(function () use ($ctx, $row): void {
            $this->lockActiveWorkspace($ctx, (int) $row['workspace_id']);
            if ($this->repo->dashboardsOfModel($ctx, (int) $row['id']) !== []) {
                throw new ApiException(409, 'MODEL_IN_USE', 'Hay dashboards que usan este modelo: elimínalos primero.');
            }
            $this->deleteResource($ctx, (int) $row['resource_id']);
        });
        $this->audit($request, $ctx, 'analytics.model_delete', 'semantic_model', $publicId);
    }

    /**
     * Ad-hoc query over a model (explore): measures by dimensions with filters. 202 + polling.
     *
     * @return array<string, mixed> the semantic query
     */
    public function explore(Request $request, TenantContext $ctx, string $modelPublicId, mixed $body): array
    {
        $row = $this->findModel($ctx, $modelPublicId);
        $this->assertCan($request, $ctx, $row, 'execute', 'semantic_model');
        $model = Format::jsonColumn($row['definition']);
        $query = AnalyticsDefinition::exploreRequest($body, $model);
        return $this->enqueue($request, $ctx, $row, null, 'explore', $model, [['key' => 'q'] + $query]);
    }

    // ------------------------------------------------------------------------------------------------ dashboards

    /** @return list<array<string, mixed>> */
    public function dashboards(TenantContext $ctx, string $workspacePublicId): array
    {
        $ws = $this->workspaces->findOrFail($ctx, $workspacePublicId);
        return array_map([self::class, 'presentDashboard'], $this->repo->dashboards($ctx, (int) $ws['id']));
    }

    /** @return array<string, mixed> */
    public function findDashboard(TenantContext $ctx, string $publicId): array
    {
        $row = $this->repo->findDashboard($ctx, $publicId, $this->policy->seesWholeTenant($ctx));
        if ($row === null) {
            throw new NotFoundException('El dashboard no existe.');
        }
        return $row;
    }

    /**
     * @param callable(): array{name: string, model_id: string, definition: mixed} $validInput
     * @return array<string, mixed>
     */
    public function createDashboard(Request $request, TenantContext $ctx, string $workspacePublicId, callable $validInput): array
    {
        $ws = $this->workspaces->findOrFail($ctx, $workspacePublicId);
        $this->workspaces->assertCan($request, $ctx, $ws, 'create');
        $input = $validInput();
        $model = $this->modelOfWorkspace($ctx, $input['model_id'], (int) $ws['id']);
        $dashboard = AnalyticsDefinition::dashboard($input['definition'], Format::jsonColumn($model['definition']));
        $insert = function (int $resourceId) use ($ctx, $ws, $model, $input): void {
            $definition = $this->revalidate($ctx, (int) $model['id'], $input['definition']);
            $this->repo->createDashboard($ctx, (int) $ws['id'], $resourceId, (int) $model['id'], $definition);
        };
        $resource = $this->createResource($ctx, $ws, 'dashboard', $input['name'], $insert);
        $this->audit($request, $ctx, 'analytics.dashboard_create', 'dashboard', $resource);
        return self::presentDashboard($this->findDashboard($ctx, $resource));
    }

    /**
     * @param callable(): array{name?: string, model_id?: string, definition?: mixed} $validInput
     * @return array<string, mixed>
     */
    public function updateDashboard(Request $request, TenantContext $ctx, string $publicId, callable $validInput): array
    {
        $row = $this->findDashboard($ctx, $publicId);
        $this->assertCan($request, $ctx, $row, 'update', 'dashboard');
        $input = $validInput();
        $model = isset($input['model_id'])
            ? $this->modelOfWorkspace($ctx, $input['model_id'], (int) $row['workspace_id'])
            : ['id' => $row['model_id'], 'definition' => $row['model_definition']];
        $raw = $input['definition'] ?? Format::jsonColumn($row['definition']);
        AnalyticsDefinition::dashboard($raw, Format::jsonColumn($model['definition'])); // early, precise 422
        $this->app->db()->transaction(function () use ($ctx, $row, $input, $model, $raw): void {
            $this->lockActiveWorkspace($ctx, (int) $row['workspace_id']);
            $definition = $this->revalidate($ctx, (int) $model['id'], $raw);
            $this->rename($ctx, $row, 'dashboard', $input['name'] ?? null);
            $this->repo->updateDashboard($ctx, (int) $row['id'], (int) $model['id'], $definition);
        });
        $this->audit($request, $ctx, 'analytics.dashboard_update', 'dashboard', $publicId, ['fields' => array_keys($input)]);
        return self::presentDashboard($this->findDashboard($ctx, $publicId));
    }

    public function deleteDashboard(Request $request, TenantContext $ctx, string $publicId): void
    {
        $row = $this->findDashboard($ctx, $publicId);
        $this->assertCan($request, $ctx, $row, 'delete', 'dashboard');
        $this->app->db()->transaction(function () use ($ctx, $row): void {
            $this->lockActiveWorkspace($ctx, (int) $row['workspace_id']);
            $this->deleteResource($ctx, (int) $row['resource_id']);
        });
        $this->audit($request, $ctx, 'analytics.dashboard_delete', 'dashboard', $publicId);
    }

    /**
     * Renders every widget (and the values of the filter selectors) in one job. 202 + polling.
     *
     * @return array<string, mixed> the semantic query
     */
    public function render(Request $request, TenantContext $ctx, string $publicId, mixed $body): array
    {
        $row = $this->findDashboard($ctx, $publicId);
        $this->assertCan($request, $ctx, $row, 'execute', 'dashboard');
        $dashboard = Format::jsonColumn($row['definition']);
        $values = AnalyticsDefinition::renderValues($body, $dashboard);
        $model = Format::jsonColumn($row['model_definition']);
        $modelRow = ['id' => $row['model_id'], 'workspace_id' => $row['workspace_id'], 'workspace_public_id' => $row['workspace_public_id']];
        $queries = AnalyticsDefinition::renderQueries($dashboard, $values);
        return $this->enqueue($request, $ctx, $modelRow, (int) $row['id'], 'render', $model, $queries, $values);
    }

    // ------------------------------------------------------------------------------------------------ queries

    /** @return array<string, mixed> status, and the results once it succeeded */
    public function getQuery(TenantContext $ctx, string $publicId): array
    {
        $row = $this->repo->findQuery($ctx, $publicId, $this->policy->seesWholeTenant($ctx));
        if ($row === null) {
            throw new NotFoundException('La consulta no existe.');
        }
        $out = self::presentQuery($row);
        if ($row['status'] === 'succeeded') {
            $file = $this->app->storage()->queryResultFile($ctx->tenantPublicId, (string) $row['workspace_public_id'], (string) $row['public_id']);
            $result = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            $out['results'] = is_array($result) ? $result : null;
            $out['result_expired'] = !is_array($result);
        }
        return $out;
    }

    /**
     * @param array<string, mixed>       $modelRow {id, workspace_id, workspace_public_id}
     * @param array<string, mixed>       $model    validated model definition (snapshot for the job)
     * @param list<array<string, mixed>> $queries
     * @param array<string, mixed>       $values   render filter values (kept for display)
     * @return array<string, mixed>
     */
    private function enqueue(
        Request $request,
        TenantContext $ctx,
        array $modelRow,
        ?int $dashboardId,
        string $kind,
        array $model,
        array $queries,
        array $values = []
    ): array {
        $wsId = (int) $modelRow['workspace_id'];
        if (!(new DatasetRepository($this->app->db()))->hasActiveLakehouse($ctx, $wsId)) {
            throw new ApiException(409, 'LAKEHOUSE_REQUIRED', 'El workspace necesita un lakehouse con tablas para consultar el modelo.');
        }
        $jobs = new JobRepository($this->app->db());
        $create = function () use ($ctx, $modelRow, $dashboardId, $kind, $model, $queries, $values, $wsId, $jobs): array {
            $this->app->db()->select('SELECT id FROM memberships WHERE tenant_id = ? AND user_id = ? FOR UPDATE', [$ctx->tenantId, $ctx->userId]);
            $this->lockActiveWorkspace($ctx, $wsId);
            $max = (int) $this->app->config->get('quotas.active_jobs_per_user', 3);
            if ($jobs->countActiveForUser($ctx, $ctx->userId) >= $max) {
                throw new QuotaExceededException('Tienes demasiados trabajos en curso. Espera a que terminen.');
            }
            $query = $this->repo->createQuery($ctx, $wsId, (int) $modelRow['id'], $dashboardId, $kind, [
                'model' => $model,
                'queries' => $queries,
                'values' => $values === [] ? new \stdClass() : $values,
            ]);
            $timeout = (int) $this->app->config->get('execution.timeouts.semantic_query', 60);
            $job = $jobs->create($ctx, $wsId, 'semantic_query', ['query_id' => $query['id']], $timeout, 1);
            $this->repo->attachJob($ctx->tenantId, $query['id'], $job['id']);
            return $query;
        };
        $created = $this->app->db()->transaction($create);
        $this->audit($request, $ctx, 'analytics.' . $kind, 'semantic_query', $created['public_id'], ['queries' => count($queries)]);
        return $this->getQuery($ctx, $created['public_id']);
    }

    // ------------------------------------------------------------------------------------------------ helpers

    /**
     * Validates a dashboard against the model's CURRENT definition, read under the workspace lock (race-free with
     * concurrent model updates and deletions, which take the same lock).
     *
     * @return array<string, mixed>
     */
    private function revalidate(TenantContext $ctx, int $modelId, mixed $definition): array
    {
        $model = $this->repo->activeModelDefinition($ctx, $modelId);
        if ($model === null) {
            throw new ValidationException([['field' => 'model_id', 'code' => 'missing', 'message' => 'El modelo semántico ya no existe.']]);
        }
        return AnalyticsDefinition::dashboard($definition, $model);
    }

    /** @return array<string, mixed> a model of the given workspace visible to the caller (422 otherwise) */
    private function modelOfWorkspace(TenantContext $ctx, string $modelPublicId, int $workspaceId): array
    {
        $model = $this->repo->findModel($ctx, $modelPublicId, $this->policy->seesWholeTenant($ctx));
        if ($model === null || (int) $model['workspace_id'] !== $workspaceId) {
            throw new ValidationException([
                ['field' => 'model_id', 'code' => 'missing', 'message' => 'Elige un modelo semántico de este workspace.'],
            ]);
        }
        return $model;
    }

    /**
     * Creates the resource row (+ the module row through $insert) under the workspace lock and quotas.
     *
     * @param array<string, mixed>   $ws
     * @param callable(int): void    $insert
     * @return string resource public id
     */
    private function createResource(TenantContext $ctx, array $ws, string $type, string $name, callable $insert): string
    {
        $resources = new ResourceRepository($this->app->db());
        $limit = (int) $this->app->config->get('quotas.resources_per_workspace', 20);
        return $this->app->db()->transaction(function () use ($ctx, $ws, $type, $name, $insert, $resources, $limit): string {
            $this->lockActiveWorkspace($ctx, (int) $ws['id']);
            if ($resources->countActive($ctx, (int) $ws['id']) >= $limit) {
                throw new QuotaExceededException("Este workspace ya tiene el máximo de $limit recursos.");
            }
            if ($resources->nameTaken($ctx, (int) $ws['id'], $type, $name)) {
                throw new ConflictException('Ya existe un recurso de este tipo con ese nombre en el workspace.');
            }
            $resource = $resources->create($ctx, (int) $ws['id'], (int) $ws['owner_user_id'], $type, $name, ResourceTypes::REGIONS[0], [], []);
            $resources->transition($ctx, $resource['id'], 'provisioning', 'active');
            $insert($resource['id']);
            return $resource['public_id'];
        });
    }

    /** @param array<string, mixed> $row */
    private function rename(TenantContext $ctx, array $row, string $type, ?string $name): void
    {
        if ($name === null || $name === $row['name']) {
            return;
        }
        $resources = new ResourceRepository($this->app->db());
        if ($resources->nameTaken($ctx, (int) $row['workspace_id'], $type, $name, (int) $row['resource_id'])) {
            throw new ConflictException('Ya existe un recurso de este tipo con ese nombre en el workspace.');
        }
        $resources->update($ctx, (int) $row['resource_id'], $name, [], []);
    }

    private function deleteResource(TenantContext $ctx, int $resourceId): void
    {
        $resources = new ResourceRepository($this->app->db());
        if (!$resources->transition($ctx, $resourceId, 'active', 'deleting')) {
            throw new ConflictException('El recurso cambió mientras se eliminaba. Inténtalo de nuevo.');
        }
        $resources->transition($ctx, $resourceId, 'deleting', 'deleted');
    }

    /** @param array<string, mixed> $row */
    private function assertCan(Request $request, TenantContext $ctx, array $row, string $permission, string $type): void
    {
        if (!$this->policy->canModify($ctx, (int) $row['owner_user_id'], $permission)) {
            $this->app->audit()->record($request, "analytics.$permission", 'denied', $ctx->tenantId, $ctx->userId, $type, (string) $row['public_id']);
            throw new ForbiddenException();
        }
        if ($row['workspace_status'] !== 'active') {
            throw new ApiException(409, 'CONFLICT', 'El workspace no está activo.');
        }
    }

    private function lockActiveWorkspace(TenantContext $ctx, int $workspaceId): void
    {
        $status = $this->app->db()->scalar('SELECT status FROM workspaces WHERE tenant_id = ? AND id = ? FOR UPDATE', [$ctx->tenantId, $workspaceId]);
        if ($status !== 'active') {
            throw new NotFoundException('El workspace no existe.');
        }
    }

    /** @param array<string, mixed> $meta */
    private function audit(Request $request, TenantContext $ctx, string $action, string $type, string $publicId, array $meta = []): void
    {
        $this->app->audit()->record($request, $action, 'success', $ctx->tenantId, $ctx->userId, $type, $publicId, $meta);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function presentModel(array $row): array
    {
        return [
            'id' => (string) $row['public_id'],
            'workspace_id' => (string) $row['workspace_public_id'],
            'name' => (string) $row['name'],
            'version' => (int) $row['version'],
            'definition' => Format::jsonColumn($row['definition']),
            'dashboard_count' => (int) $row['dashboard_count'],
            'created_at' => Format::isoUtc((string) $row['created_at']),
            'updated_at' => Format::isoUtc((string) $row['updated_at']),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function presentDashboard(array $row): array
    {
        $model = Format::jsonColumn($row['model_definition']);
        $labels = static fn (array $items): array => array_map(
            static fn (array $i): array => ['name' => (string) $i['name'], 'label' => (string) ($i['label'] ?? $i['name'])]
                + (isset($i['format']) ? ['format' => (string) $i['format']] : [])
                + (isset($i['grain']) ? ['grain' => (string) $i['grain']] : []),
            $items
        );
        return [
            'id' => (string) $row['public_id'],
            'workspace_id' => (string) $row['workspace_public_id'],
            'name' => (string) $row['name'],
            'version' => (int) $row['version'],
            'model' => [
                'id' => (string) $row['model_public_id'],
                'name' => (string) $row['model_name'],
                'measures' => $labels($model['measures'] ?? []),
                'dimensions' => $labels($model['dimensions'] ?? []),
            ],
            'definition' => Format::jsonColumn($row['definition']),
            'created_at' => Format::isoUtc((string) $row['created_at']),
            'updated_at' => Format::isoUtc((string) $row['updated_at']),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function presentQuery(array $row): array
    {
        $request = Format::jsonColumn($row['request']);
        return [
            'id' => (string) $row['public_id'],
            'kind' => (string) $row['kind'],
            'status' => (string) $row['status'],
            'model_id' => (string) $row['model_public_id'],
            'dashboard_id' => $row['dashboard_public_id'] === null ? null : (string) $row['dashboard_public_id'],
            'values' => ($request['values'] ?? []) === [] ? new \stdClass() : $request['values'],
            'error' => $row['error_code'] === null ? null : ['code' => (string) $row['error_code'], 'message' => (string) $row['error_message']],
            'duration_ms' => $row['duration_ms'] === null ? null : (int) $row['duration_ms'],
            'created_at' => Format::isoUtc((string) $row['created_at']),
            'finished_at' => Format::isoUtc($row['finished_at'] === null ? null : (string) $row['finished_at']),
        ];
    }
}
