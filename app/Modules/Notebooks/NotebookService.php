<?php

declare(strict_types=1);

namespace EduCloud\Modules\Notebooks;

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
use EduCloud\Modules\Jobs\JobRepository;
use EduCloud\Modules\Resources\ResourceRepository;
use EduCloud\Modules\Resources\ResourceTypes;
use EduCloud\Modules\Workspaces\WorkspaceService;

/**
 * Notebooks (M8, ADR-010): CRUD of notebook resources and runs. Runs execute every code cell in a fresh Docker
 * sandbox via the dispatcher (job notebook_run). With execution.notebooks.mode != 'docker' notebooks can be edited
 * but not run (409 NOTEBOOKS_DISABLED): the read-only demonstration mode required until the isolation suite passes.
 */
final class NotebookService
{
    private NotebookRepository $repo;
    private WorkspaceService $workspaces;
    private Policy $policy;

    public function __construct(private readonly App $app)
    {
        $this->repo = new NotebookRepository($app->db());
        $this->workspaces = new WorkspaceService($app);
        $this->policy = new Policy($app->config);
    }

    public function mode(): string
    {
        return (string) $this->app->config->get('execution.notebooks.mode', 'demo');
    }

    /**
     * Validates cells: [{id, type: markdown|code, source}], unique ids, count and size limits.
     *
     * @return list<array{id: string, type: string, source: string}>
     */
    public function validCells(mixed $cells): array
    {
        $max = (int) $this->app->config->get('execution.notebooks.max_cells', 50);
        $maxChars = (int) $this->app->config->get('execution.notebooks.max_cell_chars', 20000);
        if (!is_array($cells) || !array_is_list($cells) || $cells === [] || count($cells) > $max) {
            throw new ValidationException([['field' => 'cells', 'code' => 'list', 'message' => "Indica entre 1 y $max celdas."]]);
        }
        $out = [];
        $ids = [];
        $problems = [];
        foreach ($cells as $i => $cell) {
            $id = is_array($cell) ? ($cell['id'] ?? null) : null;
            $type = is_array($cell) ? ($cell['type'] ?? null) : null;
            $source = is_array($cell) ? ($cell['source'] ?? null) : null;
            if (!is_array($cell) || array_diff(array_keys($cell), ['id', 'type', 'source']) !== []) {
                $problems[] = ['field' => "cells/$i", 'code' => 'object', 'message' => 'Cada celda es {id, type, source}.'];
                continue;
            }
            if (!is_string($id) || preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/D', $id) !== 1 || isset($ids[$id])) {
                $problems[] = ['field' => "cells/$i/id", 'code' => 'id', 'message' => 'Identificador de celda no válido o repetido.'];
            }
            if (!in_array($type, ['markdown', 'code'], true)) {
                $problems[] = ['field' => "cells/$i/type", 'code' => 'in', 'message' => 'El tipo es markdown o code.'];
            }
            if (!is_string($source) || mb_strlen($source) > $maxChars || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $source) === 1) {
                $message = "Texto de hasta $maxChars caracteres, sin caracteres de control.";
                $problems[] = ['field' => "cells/$i/source", 'code' => 'source', 'message' => $message];
            }
            $ids[(string) $id] = true;
            $out[] = ['id' => (string) $id, 'type' => (string) $type, 'source' => str_replace("\r\n", "\n", (string) $source)];
        }
        if ($problems !== []) {
            throw new ValidationException(array_slice($problems, 0, 20));
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function list(TenantContext $ctx, string $workspacePublicId): array
    {
        $ws = $this->workspaces->findOrFail($ctx, $workspacePublicId);
        return array_map([self::class, 'present'], $this->repo->listForWorkspace($ctx, (int) $ws['id']));
    }

    /** @return array<string, mixed> */
    public function findOrFail(TenantContext $ctx, string $publicId): array
    {
        $row = $this->repo->findVisible($ctx, $publicId, $this->policy->seesWholeTenant($ctx));
        if ($row === null) {
            throw new NotFoundException('El notebook no existe.');
        }
        return $row;
    }

    /**
     * @param array{name: string, cells: mixed} $input
     * @return array<string, mixed>
     */
    public function create(Request $request, TenantContext $ctx, string $workspacePublicId, array $input): array
    {
        $ws = $this->workspaces->findOrFail($ctx, $workspacePublicId);
        $this->workspaces->assertCan($request, $ctx, $ws, 'create');
        if ($this->mode() === 'off') {
            throw new ApiException(409, 'NOTEBOOKS_DISABLED', 'Los notebooks están desactivados en este servidor.');
        }
        $cells = $this->validCells($input['cells']);
        $resources = new ResourceRepository($this->app->db());
        $limit = (int) $this->app->config->get('quotas.resources_per_workspace', 20);
        $created = $this->app->db()->transaction(function () use ($ctx, $ws, $input, $cells, $resources, $limit): array {
            $this->lockActiveWorkspace($ctx, (int) $ws['id']);
            if ($resources->countActive($ctx, (int) $ws['id']) >= $limit) {
                throw new QuotaExceededException("Este workspace ya tiene el máximo de $limit recursos.");
            }
            if ($resources->nameTaken($ctx, (int) $ws['id'], 'notebook', $input['name'])) {
                throw new ConflictException('Ya existe un notebook con ese nombre en el workspace.');
            }
            $owner = (int) $ws['owner_user_id'];
            $resource = $resources->create($ctx, (int) $ws['id'], $owner, 'notebook', $input['name'], ResourceTypes::REGIONS[0], [], []);
            $resources->transition($ctx, $resource['id'], 'provisioning', 'active');
            $this->repo->create($ctx, (int) $ws['id'], $resource['id'], $cells);
            return $resource;
        });
        $this->audit($request, $ctx, 'notebook.create', 'notebook', $created['public_id']);
        return self::present($this->findOrFail($ctx, $created['public_id']));
    }

    /**
     * @param array{name?: string, cells?: mixed} $input
     * @return array<string, mixed>
     */
    public function update(Request $request, TenantContext $ctx, string $publicId, array $input): array
    {
        $row = $this->findOrFail($ctx, $publicId);
        $this->assertCan($request, $ctx, $row, 'update');
        $cells = array_key_exists('cells', $input) ? $this->validCells($input['cells']) : null;
        $resources = new ResourceRepository($this->app->db());
        $this->app->db()->transaction(function () use ($ctx, $row, $input, $cells, $resources): void {
            $this->lockActiveWorkspace($ctx, (int) $row['workspace_id']);
            if (isset($input['name']) && $input['name'] !== $row['name']) {
                if ($resources->nameTaken($ctx, (int) $row['workspace_id'], 'notebook', $input['name'], (int) $row['resource_id'])) {
                    throw new ConflictException('Ya existe un notebook con ese nombre en el workspace.');
                }
                $resources->update($ctx, (int) $row['resource_id'], $input['name'], [], []);
            }
            if ($cells !== null) {
                $this->repo->updateCells($ctx, (int) $row['id'], $cells);
            }
        });
        $this->audit($request, $ctx, 'notebook.update', 'notebook', $publicId, ['fields' => array_keys($input)]);
        return self::present($this->findOrFail($ctx, $publicId));
    }

    public function delete(Request $request, TenantContext $ctx, string $publicId): void
    {
        $row = $this->findOrFail($ctx, $publicId);
        $this->assertCan($request, $ctx, $row, 'delete');
        $resources = new ResourceRepository($this->app->db());
        $this->app->db()->transaction(function () use ($ctx, $row, $resources): void {
            $this->lockActiveWorkspace($ctx, (int) $row['workspace_id']);
            $fresh = $this->repo->findVisible($ctx, (string) $row['public_id'], true);
            if (in_array($fresh['last_run_status'] ?? null, ['queued', 'running'], true)) {
                throw new ApiException(409, 'NOTEBOOK_RUNNING', 'El notebook se está ejecutando. Cancélalo o espera a que termine.');
            }
            $resources->transition($ctx, (int) $row['resource_id'], 'active', 'deleting');
            $resources->transition($ctx, (int) $row['resource_id'], 'deleting', 'deleted');
        });
        $this->audit($request, $ctx, 'notebook.delete', 'notebook', $publicId);
    }

    /** @return array<string, mixed> the queued run (202) */
    public function run(Request $request, TenantContext $ctx, string $publicId): array
    {
        $row = $this->findOrFail($ctx, $publicId);
        $this->assertCan($request, $ctx, $row, 'execute');
        if ($this->mode() !== 'docker') {
            throw new ApiException(409, 'NOTEBOOKS_DISABLED', 'La ejecución de notebooks no está activada en este servidor (modo demostración).');
        }
        $jobs = new JobRepository($this->app->db());
        $created = $this->app->db()->transaction(function () use ($ctx, $row, $jobs): array {
            $this->app->db()->select('SELECT id FROM memberships WHERE tenant_id = ? AND user_id = ? FOR UPDATE', [$ctx->tenantId, $ctx->userId]);
            $this->lockActiveWorkspace($ctx, (int) $row['workspace_id']);
            $max = (int) $this->app->config->get('quotas.active_jobs_per_user', 3);
            if ($jobs->countActiveForUser($ctx, $ctx->userId) >= $max) {
                throw new QuotaExceededException('Tienes demasiados trabajos en curso. Espera a que terminen.');
            }
            $fresh = $this->repo->findVisible($ctx, (string) $row['public_id'], true);
            if ($fresh === null) {
                throw new NotFoundException('El notebook no existe.');
            }
            if (in_array($fresh['last_run_status'], ['queued', 'running'], true)) {
                throw new ApiException(409, 'NOTEBOOK_RUNNING', 'Este notebook ya se está ejecutando.');
            }
            $run = $this->repo->createRun($ctx, (int) $fresh['id'], (int) $fresh['version'], Format::jsonColumn($fresh['cells']));
            $timeout = (int) $this->app->config->get('execution.timeouts.notebook_run', 120);
            $job = $jobs->create($ctx, (int) $fresh['workspace_id'], 'notebook_run', ['run_id' => $run['id']], $timeout, 3);
            $this->repo->attachJob($ctx->tenantId, $run['id'], $job['id']);
            return $run;
        });
        $this->audit($request, $ctx, 'notebook.run', 'notebook', $publicId, ['run' => $created['public_id']]);
        return $this->getRun($ctx, $created['public_id']);
    }

    /** @return list<array<string, mixed>> */
    public function runs(TenantContext $ctx, string $publicId): array
    {
        $row = $this->findOrFail($ctx, $publicId);
        return array_map([self::class, 'presentRun'], $this->repo->runs($ctx, (int) $row['id'], 10));
    }

    /** @return array<string, mixed> */
    public function getRun(TenantContext $ctx, string $runPublicId): array
    {
        return self::presentRun($this->findRunOrFail($ctx, $runPublicId));
    }

    /** @return array<string, mixed> */
    public function cancel(Request $request, TenantContext $ctx, string $runPublicId): array
    {
        $run = $this->findRunOrFail($ctx, $runPublicId);
        if (!$this->policy->canModify($ctx, (int) $run['owner_user_id'], 'execute')) {
            throw new ForbiddenException();
        }
        $outcome = $run['job_id'] === null ? null : (new JobRepository($this->app->db()))->cancel($ctx->tenantId, (int) $run['job_id']);
        if ($outcome === null) {
            throw new ApiException(409, 'RUN_FINISHED', 'Esta ejecución ya terminó.');
        }
        if ($outcome === 'cancelled') {
            $message = 'La ejecución se canceló antes de empezar.';
            $this->repo->finishRun($ctx->tenantId, (int) $run['id'], 'cancelled', null, null, 'CANCELLED', $message, null);
        }
        $this->audit($request, $ctx, 'notebook.cancel', 'notebook_run', $runPublicId, ['outcome' => $outcome]);
        return $this->getRun($ctx, $runPublicId);
    }

    /** @return array<string, mixed> */
    private function findRunOrFail(TenantContext $ctx, string $runPublicId): array
    {
        $run = $this->repo->findRunVisible($ctx, $runPublicId, $this->policy->seesWholeTenant($ctx));
        if ($run === null) {
            throw new NotFoundException('La ejecución no existe.');
        }
        return $run;
    }

    /** @param array<string, mixed> $row */
    private function assertCan(Request $request, TenantContext $ctx, array $row, string $permission): void
    {
        if (!$this->policy->canModify($ctx, (int) $row['owner_user_id'], $permission)) {
            $id = (string) $row['public_id'];
            $this->app->audit()->record($request, 'notebook.' . $permission, 'denied', $ctx->tenantId, $ctx->userId, 'notebook', $id);
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
    public static function present(array $row): array
    {
        return [
            'id' => (string) $row['public_id'],
            'workspace_id' => (string) $row['workspace_public_id'],
            'name' => (string) $row['name'],
            'version' => (int) $row['version'],
            'cells' => Format::jsonColumn($row['cells']),
            'last_run_status' => $row['last_run_status'] === null ? null : (string) $row['last_run_status'],
            'created_at' => Format::isoUtc((string) $row['created_at']),
            'updated_at' => Format::isoUtc((string) $row['updated_at']),
        ];
    }

    /**
     * @param array<string, mixed> $run
     * @return array<string, mixed>
     */
    public static function presentRun(array $run): array
    {
        $artifacts = $run['artifacts'] === null ? [] : Format::jsonColumn($run['artifacts']);
        return [
            'id' => (string) $run['public_id'],
            'notebook' => ['id' => (string) $run['notebook_public_id'], 'name' => (string) $run['notebook_name']],
            'status' => (string) $run['status'],
            'cancel_requested' => (int) ($run['job_cancel_requested'] ?? 0) === 1 && in_array($run['status'], ['queued', 'running'], true),
            'notebook_version' => (int) $run['notebook_version'],
            'outputs' => $run['outputs'] === null ? [] : Format::jsonColumn($run['outputs']),
            'artifacts' => $artifacts === [] ? new \stdClass() : $artifacts,
            'error' => $run['error_code'] === null ? null : ['code' => (string) $run['error_code'], 'message' => (string) $run['error_message']],
            'duration_ms' => $run['duration_ms'] === null ? null : (int) $run['duration_ms'],
            'created_at' => Format::isoUtc((string) $run['created_at']),
            'finished_at' => Format::isoUtc($run['finished_at'] === null ? null : (string) $run['finished_at']),
        ];
    }
}
