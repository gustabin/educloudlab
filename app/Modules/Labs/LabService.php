<?php

declare(strict_types=1);

namespace EduCloud\Modules\Labs;

use EduCloud\Core\App;
use EduCloud\Core\Auth\Policy;
use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Exceptions\ApiException;
use EduCloud\Core\Exceptions\ForbiddenException;
use EduCloud\Core\Exceptions\NotFoundException;
use EduCloud\Core\Exceptions\QuotaExceededException;
use EduCloud\Core\Exceptions\ValidationException;
use EduCloud\Core\Format;
use EduCloud\Core\Request;
use EduCloud\Core\Ulid;
use EduCloud\Modules\Datasets\DatasetRepository;
use EduCloud\Modules\Jobs\JobRepository;
use EduCloud\Modules\Resources\ResourceRepository;
use EduCloud\Modules\Resources\ResourceTypes;
use EduCloud\Modules\Workspaces\WorkspaceRepository;
use Throwable;

/**
 * Lab Engine use cases (M6). Starting a lab creates an attempt with its own lab workspace (purpose = lab) and runs
 * the declarative setup; submitting enqueues a "validate" job that grades the ACTUAL state of that workspace.
 * Scores are computed only by the dispatcher (ValidateHandler); nothing the client sends is ever trusted as a result.
 *
 * Visibility: the attempt's owner, plus tenant-wide roles (org_admin, platform admin) read-only.
 * Mutations (answers, hints, submit, abandon) are reserved to the owner.
 */
final class LabService
{
    private LabRepository $labs;
    private AttemptRepository $attempts;
    private Policy $policy;

    public function __construct(private readonly App $app)
    {
        $this->labs = new LabRepository($app->db());
        $this->attempts = new AttemptRepository($app->db());
        $this->policy = new Policy($app->config);
    }

    /** @return list<array<string, mixed>> published labs with the caller's attempt summary */
    public function catalog(TenantContext $ctx): array
    {
        $mine = $this->attempts->summaryByLab($ctx);
        $out = [];
        foreach ($this->labs->listPublished() as $row) {
            $definition = Format::jsonColumn($row['definition']);
            $attempt = $mine[(string) $row['code']] ?? null;
            $out[] = self::presentLab($row, $definition) + [
                'task_count' => count($definition['tasks'] ?? []),
                'my_attempt' => $attempt === null ? null : [
                    'id' => (string) $attempt['public_id'],
                    'status' => (string) $attempt['status'],
                    'open' => (bool) $attempt['_open'],
                    'best_score' => (float) $attempt['best_score_any'],
                ],
            ];
        }
        return $out;
    }

    /**
     * Starts a lab, or returns the caller's open attempt for it.
     *
     * @param callable(): string $validCode
     * @return array{attempt: array<string, mixed>, created: bool}
     */
    public function start(Request $request, TenantContext $ctx, callable $validCode): array
    {
        $code = $validCode();
        $lab = $this->labs->findPublishedByCode($code);
        if ($lab === null) {
            throw new NotFoundException('El laboratorio no existe.');
        }
        $existing = $this->attempts->findOpenForLab($ctx, $code);
        if ($existing !== null) {
            return ['attempt' => $this->present($ctx, $existing), 'created' => false];
        }
        $definition = Format::jsonColumn($lab['definition']);
        $this->assertCanStart($ctx, $definition);

        $copied = [];
        try {
            $created = $this->app->db()->transaction(function () use ($ctx, $lab, $definition, $code, &$copied): ?array {
                $this->app->db()->select('SELECT id FROM memberships WHERE tenant_id = ? AND user_id = ? FOR UPDATE', [$ctx->tenantId, $ctx->userId]);
                if ($this->attempts->findOpenForLab($ctx, $code) !== null) {
                    return null; // a concurrent request won the race
                }
                $this->assertCanStart($ctx, $definition);
                // Setup enqueues several jobs: no new lab while this user's queue is busy (M6 gate: start/abandon loops).
                $maxJobs = (int) $this->app->config->get('quotas.active_jobs_per_user', 3);
                if ((new JobRepository($this->app->db()))->countActiveForUser($ctx, $ctx->userId) >= $maxJobs) {
                    throw new QuotaExceededException(
                        'Tienes trabajos en curso (por ejemplo, la preparación de otro laboratorio). Espera unos segundos.'
                    );
                }
                $attemptId = AttemptRepository::newPublicId();
                $name = mb_substr($code . ' · ' . (string) $lab['title'], 0, 72) . ' #' . substr($attemptId, -5);
                $ttl = (int) $definition['cleanup']['workspace_ttl_days'];
                $ws = (new WorkspaceRepository($this->app->db()))->create($ctx, $name, mb_substr((string) $lab['summary'], 0, 500), 'lab', $ttl);
                $attempt = $this->attempts->create($ctx, $attemptId, (int) $lab['id'], $ws['id'], LabDefinition::maxScore($definition));
                // Hints seen in earlier attempts of this lab stay seen (and penalised): restarting does not reset them.
                $this->attempts->carryOverHints($ctx, $attempt['id'], $code);
                $this->runSetup($ctx, $ws, $definition['setup'] ?? [], $copied);
                return $attempt;
            });
        } catch (Throwable $e) {
            foreach ($copied as $file) {
                $this->app->storage()->delete($file);
            }
            throw $e;
        }
        if ($created === null) {
            $existing = $this->attempts->findOpenForLab($ctx, $code);
            if ($existing === null) {
                throw new ApiException(409, 'CONFLICT', 'No se pudo iniciar el laboratorio. Inténtalo de nuevo.');
            }
            return ['attempt' => $this->present($ctx, $existing), 'created' => false];
        }
        $this->app->audit()->record($request, 'lab.start', 'success', $ctx->tenantId, $ctx->userId, 'lab_attempt', $created['public_id'], [
            'lab' => $code . '@' . $lab['version'],
        ]);
        return ['attempt' => $this->get($ctx, $created['public_id']), 'created' => true];
    }

    /** @return array<string, mixed> */
    public function get(TenantContext $ctx, string $publicId): array
    {
        return $this->present($ctx, $this->findOrFail($ctx, $publicId));
    }

    /** @return array<string, mixed> */
    public function findOrFail(TenantContext $ctx, string $publicId): array
    {
        $row = $this->attempts->findVisible($ctx, $publicId, $this->policy->seesWholeTenant($ctx));
        if ($row === null) {
            throw new NotFoundException('El intento no existe.');
        }
        return $row;
    }

    /**
     * Reveals a hint (in order). Its penalty is recorded once and applied to the task's points when it passes.
     *
     * @param callable(): array{task_key: string, hint_index: int} $validInput
     * @return array<string, mixed>
     */
    public function revealHint(Request $request, TenantContext $ctx, string $publicId, callable $validInput): array
    {
        $row = $this->findOwned($request, $ctx, $publicId, 'hint');
        $input = $validInput();
        $task = $this->task($row, $input['task_key']);
        $hints = $task['hints'] ?? [];
        $index = $input['hint_index'];
        if (!isset($hints[$index])) {
            throw new ValidationException([['field' => 'hint_index', 'code' => 'in', 'message' => 'Esa pista no existe.']]);
        }
        $newly = $this->app->db()->transaction(function () use ($ctx, $row, $task, $index, $hints): bool {
            $this->assertOpen($this->attempts->lockStatus($ctx, (int) $row['id']), $row, ['in_progress', 'completed']);
            $used = $this->attempts->usedHints($ctx->tenantId, (int) $row['id'])[(string) $task['key']] ?? [];
            if ($index > 0 && !in_array($index - 1, $used, true)) {
                throw new ApiException(409, 'HINT_ORDER', 'Revela primero las pistas anteriores de este ejercicio.');
            }
            return $this->attempts->recordHint($ctx, (int) $row['id'], (string) $task['key'], $index, (int) $hints[$index]['penalty']);
        });
        if ($newly) {
            $this->app->audit()->record($request, 'lab.hint', 'success', $ctx->tenantId, $ctx->userId, 'lab_attempt', $publicId, [
                'task' => $task['key'],
                'hint' => $index,
            ]);
        }
        return [
            'task_key' => (string) $task['key'],
            'index' => $index,
            'text' => (string) $hints[$index]['text'],
            'penalty' => (int) $hints[$index]['penalty'],
            'newly_revealed' => $newly,
        ];
    }

    /**
     * Saves the SQL answer of an exercise task. It is only stored here; the validate job re-executes it in the sandbox.
     *
     * @param callable(): array{task_key: string, sql: string} $validInput
     * @return array<string, mixed>
     */
    public function saveAnswer(Request $request, TenantContext $ctx, string $publicId, callable $validInput): array
    {
        $row = $this->findOwned($request, $ctx, $publicId, 'answer');
        $input = $validInput();
        $task = $this->task($row, $input['task_key']);
        if (($task['answer'] ?? false) !== true) {
            throw new ValidationException([['field' => 'task_key', 'code' => 'in', 'message' => 'Este ejercicio no admite respuestas SQL.']]);
        }
        $this->app->db()->transaction(function () use ($ctx, $row, $task, $input): void {
            $this->assertOpen($this->attempts->lockStatus($ctx, (int) $row['id']), $row, ['in_progress', 'completed']);
            $this->attempts->saveAnswer($ctx, (int) $row['id'], (string) $task['key'], $input['sql']);
            $this->extendExpiry($ctx, $row);
        });
        return ['task_key' => (string) $task['key'], 'sql' => $input['sql']];
    }

    /**
     * Enqueues the grading of the attempt (202). Results arrive through GET /lab-attempts/{id}.
     *
     * @param callable(): void $validInput rejects any field: there is nothing a client could claim
     * @return array<string, mixed>
     */
    public function submit(Request $request, TenantContext $ctx, string $publicId, callable $validInput): array
    {
        $row = $this->findOwned($request, $ctx, $publicId, 'submit');
        $validInput();
        $jobs = new JobRepository($this->app->db());
        $this->app->db()->transaction(function () use ($ctx, $row, $jobs): void {
            $this->assertOpen($this->attempts->lockStatus($ctx, (int) $row['id']), $row, ['in_progress', 'completed']);
            $max = (int) $this->app->config->get('quotas.active_jobs_per_user', 3);
            if ($jobs->countActiveForUser($ctx, $ctx->userId) >= $max) {
                throw new QuotaExceededException(
                    'Tienes demasiados trabajos en curso (por ejemplo, la preparación del laboratorio). Espera unos segundos.'
                );
            }
            $submission = $this->attempts->startValidation($ctx, (int) $row['id']);
            $jobs->create($ctx, (int) $row['workspace_id'], 'validate', [
                'attempt_id' => (int) $row['id'],
                'submission_no' => $submission,
            ], (int) $this->app->config->get('execution.timeouts.validate', 150));
            $this->extendExpiry($ctx, $row);
        });
        $this->app->audit()->record($request, 'lab.submit', 'success', $ctx->tenantId, $ctx->userId, 'lab_attempt', $publicId);
        return $this->get($ctx, $publicId);
    }

    /** Abandons the attempt and releases its workspace. A completed attempt keeps its status and best score. */
    public function abandon(Request $request, TenantContext $ctx, string $publicId): void
    {
        $row = $this->findOwned($request, $ctx, $publicId, 'abandon');
        $this->app->db()->transaction(function () use ($ctx, $row): void {
            $status = $this->assertOpen($this->attempts->lockStatus($ctx, (int) $row['id']), $row, ['in_progress', 'completed']);
            if ($status === 'in_progress') {
                $this->attempts->setStatus($ctx, (int) $row['id'], 'abandoned');
            }
            WorkspaceRepository::softDelete($this->app->db(), $ctx->tenantId, (int) $row['workspace_id']);
            (new JobRepository($this->app->db()))->cancelQueuedForWorkspace($ctx->tenantId, (int) $row['workspace_id']);
        });
        $this->app->audit()->record($request, 'lab.abandon', 'success', $ctx->tenantId, $ctx->userId, 'lab_attempt', $publicId);
    }

    // ------------------------------------------------------------------------------------------------ internals

    /** @param array<string, mixed> $definition */
    private function assertCanStart(TenantContext $ctx, array $definition): void
    {
        $max = (int) $this->app->config->get('quotas.active_lab_attempts_per_user', 3);
        if ($this->attempts->countInProgress($ctx) >= $max) {
            throw new QuotaExceededException("Ya tienes $max laboratorios en curso. Termina o abandona alguno para empezar otro.");
        }
        $bytes = 0;
        foreach ($definition['setup'] ?? [] as $action) {
            if ($action['action'] === 'load_sample') {
                $bytes += (int) filesize(LabImporter::samplesDir() . '/' . $action['sample']);
            }
        }
        $maxBytes = (int) $this->app->config->get('quotas.storage_bytes_per_user');
        if ($bytes > 0 && (new DatasetRepository($this->app->db()))->storageUsedBy($ctx, $ctx->userId) + $bytes > $maxBytes) {
            throw new QuotaExceededException('No tienes espacio de almacenamiento suficiente para los datos del laboratorio.');
        }
    }

    /**
     * Declarative setup (closed set of actions, validated at import). Heavy work goes to the execution plane.
     *
     * @param array{id: int, public_id: string} $ws
     * @param list<array<string, mixed>>         $actions
     * @param list<string>                       $copied  raw files written (removed by the caller on failure)
     */
    private function runSetup(TenantContext $ctx, array $ws, array $actions, array &$copied): void
    {
        $resources = new ResourceRepository($this->app->db());
        $datasets = new DatasetRepository($this->app->db());
        $jobs = new JobRepository($this->app->db());
        $storage = $this->app->storage();
        $config = $this->app->config;
        $managed = ['lab_setup' => true];

        foreach ($actions as $action) {
            if ($action['action'] === 'create_resource') {
                $type = (string) $action['type'];
                $created = $resources->create(
                    $ctx,
                    $ws['id'],
                    $ctx->userId,
                    $type,
                    (string) $action['name'],
                    ResourceTypes::REGIONS[0],
                    ResourceTypes::normaliseConfig($type, null),
                    []
                );
                $resources->transition($ctx, $created['id'], 'provisioning', 'active');
                continue;
            }
            // load_sample: copy the platform sample into the workspace's raw storage, profile it, optionally ingest it.
            $source = LabImporter::samplesDir() . '/' . $action['sample'];
            $key = Ulid::generate();
            $target = $storage->rawFile($ctx->tenantPublicId, $ws['public_id'], $key);
            $storage->ensureDir(dirname($target));
            if (!is_file($source) || !copy($source, $target)) {
                throw new \RuntimeException('Lab sample could not be copied');
            }
            $copied[] = $target;
            $size = (int) filesize($target);
            $raw = $datasets->createDataset($ctx, $ws['id'], $ctx->userId, (string) $action['name'], 'raw', null, $managed);
            $sha = (string) hash_file('sha256', $target, true);
            $rawVersion = $datasets->createVersion($ctx, $raw['dataset_id'], 'csv', $key, basename((string) $action['sample']), $size, $sha);
            $jobs->create($ctx, $ws['id'], 'profile', [
                'dataset_id' => $raw['dataset_id'],
                'version_id' => $rawVersion['id'],
            ], (int) $config->get('execution.timeouts.profile', 60));
            if (isset($action['ingest_to'])) {
                $table = substr((string) $action['ingest_to'], strlen('bronze.'));
                $bronze = $datasets->createDataset($ctx, $ws['id'], $ctx->userId, $table, 'bronze', $table, $managed);
                $bronzeVersion = $datasets->createVersion($ctx, $bronze['dataset_id'], 'table', null, null, 0, null);
                $jobs->create($ctx, $ws['id'], 'ingest', [
                    'source_version_id' => $rawVersion['id'],
                    'target_dataset_id' => $bronze['dataset_id'],
                    'target_version_id' => $bronzeVersion['id'],
                ], (int) $config->get('execution.timeouts.ingest', 120));
            }
        }
    }

    /** @return array<string, mixed> attempt row, after checking the caller owns it (visible non-owners get 403) */
    private function findOwned(Request $request, TenantContext $ctx, string $publicId, string $action): array
    {
        $row = $this->findOrFail($ctx, $publicId);
        if ((int) $row['user_id'] !== $ctx->userId) {
            $this->app->audit()->record($request, 'lab.' . $action, 'denied', $ctx->tenantId, $ctx->userId, 'lab_attempt', $publicId);
            throw new ForbiddenException('Solo quien realiza el laboratorio puede hacer esto.');
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string>         $allowed
     */
    private function assertOpen(?string $status, array $row, array $allowed): string
    {
        if ($row['workspace_status'] !== 'active' || in_array($status, ['abandoned', 'expired', null], true)) {
            throw new ApiException(409, 'ATTEMPT_CLOSED', 'Este intento ya no está activo. Empieza el laboratorio de nuevo.');
        }
        if (!in_array($status, $allowed, true)) {
            throw new ApiException(409, 'ATTEMPT_VALIDATING', 'Se está validando tu intento. Espera a que termine.');
        }
        return (string) $status;
    }

    /** @param array<string, mixed> $row */
    private function extendExpiry(TenantContext $ctx, array $row): void
    {
        $ttl = (int) (Format::jsonColumn($row['definition'])['cleanup']['workspace_ttl_days'] ?? 14);
        (new WorkspaceRepository($this->app->db()))->extendExpiry($ctx, (int) $row['workspace_id'], $ttl);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function task(array $row, string $key): array
    {
        foreach (Format::jsonColumn($row['definition'])['tasks'] ?? [] as $task) {
            if ($task['key'] === $key) {
                return $task;
            }
        }
        throw new ValidationException([['field' => 'task_key', 'code' => 'in', 'message' => 'Ese ejercicio no existe en este laboratorio.']]);
    }

    /**
     * Student-facing representation. Never includes checks, expected SQL or solutions.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function present(TenantContext $ctx, array $row): array
    {
        $tenantId = (int) $row['tenant_id'];
        $attemptId = (int) $row['id'];
        $definition = Format::jsonColumn($row['definition']);
        $hints = $this->attempts->usedHints($tenantId, $attemptId);
        $isOwner = (int) $row['user_id'] === $ctx->userId;
        $answers = $this->attempts->answers($tenantId, $attemptId);
        $results = [];
        foreach ($this->latestResults($tenantId, $attemptId, (int) $row['submissions']) as $r) {
            $results[(string) $r['task_key']] = $r;
        }
        $wsActive = $row['workspace_status'] === 'active';
        $pending = $wsActive && $row['workspace_id'] !== null
            ? (new LabStateRepository($this->app->db()))->activeJobs($tenantId, (int) $row['workspace_id'])
            : 0;
        $validating = $row['status'] === 'validating';

        $tasks = [];
        foreach ($definition['tasks'] ?? [] as $task) {
            $key = (string) $task['key'];
            $revealed = $hints[$key] ?? [];
            $result = $results[$key] ?? null;
            $tasks[] = [
                'key' => $key,
                'title' => (string) $task['title'],
                'points' => (int) $task['points'],
                'instructions_md' => (string) ($task['instructions_md'] ?? ''),
                'answer' => ($task['answer'] ?? false) === true,
                'answer_sql' => $answers[$key] ?? null,
                'hints' => array_map(static fn (int $i, array $h): array => [
                    'index' => $i,
                    'penalty' => (int) $h['penalty'],
                    'text' => in_array($i, $revealed, true) ? (string) $h['text'] : null,
                ], array_keys($task['hints'] ?? []), $task['hints'] ?? []),
                'result' => $result === null ? null : [
                    'passed' => (bool) $result['passed'],
                    'points' => (float) $result['points'],
                    'feedback' => $result['feedback'] === null ? null : (string) $result['feedback'],
                ],
            ];
        }
        return [
            'id' => (string) $row['public_id'],
            'status' => (string) $row['status'],
            'score' => (float) $row['score'],
            'best_score' => (float) $row['best_score'],
            'max_score' => (float) $row['max_score'],
            'hints_used' => (int) $row['hints_used'],
            'submissions' => (int) $row['submissions'],
            'is_owner' => $isOwner,
            'student' => ['id' => (string) $row['user_public_id'], 'display_name' => (string) $row['user_name']],
            'error' => $row['last_error_code'] === null
                ? null
                : ['code' => (string) $row['last_error_code'], 'message' => (string) $row['last_error_message']],
            'lab' => self::presentLab(['code' => $row['lab_code'], 'version' => $row['lab_version'], 'title' => $row['lab_title']], $definition),
            'workspace' => $row['workspace_public_id'] === null ? null : [
                'id' => (string) $row['workspace_public_id'],
                'status' => (string) $row['workspace_status'],
                'expires_at' => Format::isoUtc($row['workspace_expires_at'] === null ? null : (string) $row['workspace_expires_at']),
            ],
            'environment' => ['ready' => $wsActive && ($pending === 0 || ($validating && $pending === 1)), 'pending_jobs' => $pending],
            'tasks' => $tasks,
            'started_at' => Format::isoUtc((string) $row['started_at']),
            'last_submitted_at' => Format::isoUtc($row['last_submitted_at'] === null ? null : (string) $row['last_submitted_at']),
            'completed_at' => Format::isoUtc($row['completed_at'] === null ? null : (string) $row['completed_at']),
        ];
    }

    /** @return list<array<string, mixed>> results of the most recent graded submission */
    private function latestResults(int $tenantId, int $attemptId, int $submissions): array
    {
        for ($n = $submissions; $n >= 1; $n--) {
            $rows = $this->attempts->results($tenantId, $attemptId, $n);
            if ($rows !== []) {
                return $rows;
            }
        }
        return [];
    }

    /**
     * @param array<string, mixed> $row          labs row (code, version, title, … )
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    public static function presentLab(array $row, array $definition): array
    {
        return [
            'code' => (string) $row['code'],
            'version' => (string) $row['version'],
            'slug' => (string) ($definition['slug'] ?? ''),
            'title' => (string) $row['title'],
            'summary' => (string) ($definition['summary'] ?? ''),
            'difficulty' => (string) ($definition['difficulty'] ?? ''),
            'estimated_minutes' => (int) ($definition['estimated_minutes'] ?? 0),
            'max_score' => LabDefinition::maxScore($definition),
            'objectives' => array_values(array_map('strval', $definition['objectives'] ?? [])),
            'prerequisites' => array_values(array_map('strval', $definition['prerequisites'] ?? [])),
            'downloads' => array_map(static fn (array $d): array => [
                'name' => basename((string) $d['sample']),
                'url' => asset('datasets/' . $d['sample']),
            ], $definition['downloads'] ?? []),
        ];
    }
}
