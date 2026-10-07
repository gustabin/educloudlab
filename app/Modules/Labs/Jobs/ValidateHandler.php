<?php

declare(strict_types=1);

namespace EduCloud\Modules\Labs\Jobs;

use EduCloud\Core\App;
use EduCloud\Core\Format;
use EduCloud\Modules\Jobs\JobHandler;
use EduCloud\Modules\Labs\AttemptRepository;
use EduCloud\Modules\Labs\LabDefinition;
use EduCloud\Modules\Labs\LabStateRepository;
use EduCloud\Modules\Labs\MetadataChecks;
use EduCloud\Modules\Labs\Scoring;
use RuntimeException;

/**
 * validate: grades one lab submission from the ACTUAL state of the attempt's workspace.
 *  - metadata checks: evaluated here from the database (same moment the data checks run);
 *  - data checks: sent to the runner (op "validate") together with the student's saved SQL answers;
 *  - scoring + task results are written when the runner answers.
 * The payload carries only internal ids (attempt, submission number); nothing comes from the client.
 */
final class ValidateHandler implements JobHandler
{
    private AttemptRepository $attempts;
    /** @var array<string, array{passed: bool, feedback: string, evidence: array<string, mixed>}>|null */
    private ?array $metadataResults = null;

    public function __construct(private readonly App $app)
    {
        $this->attempts = new AttemptRepository($app->db());
    }

    public function prepare(array $job): ?array
    {
        [$attempt, $definition] = $this->load($job);
        $this->metadataResults = $this->evaluateMetadata($job, $definition);

        $answers = $this->attempts->answers((int) $job['tenant_id'], (int) $attempt['id']);
        $checks = [];
        foreach ($definition['tasks'] as $task) {
            foreach ($task['checks'] as $i => $check) {
                if (!in_array($check['type'], LabDefinition::DATA_CHECKS, true)) {
                    continue;
                }
                unset($check['feedback']);
                $check['id'] = $task['key'] . '#' . $i;
                if ($check['type'] === 'query_result_matches' && !isset($check['actual_sql'])) {
                    $check['student_sql'] = $answers[(string) $task['key']] ?? null;
                }
                $checks[] = $check;
            }
        }
        if ($checks === []) {
            return null;
        }
        $storage = $this->app->storage();
        return ['op' => 'validate', 'args' => [
            'lakehouse_path' => $storage->lakehouseFile((string) $job['tenant_public_id'], (string) $job['workspace_public_id']),
            'checks' => $checks,
        ]];
    }

    public function succeeded(array $job, array $data): array
    {
        [$attempt, $definition] = $this->load($job);
        $results = $this->metadataResults ?? $this->evaluateMetadata($job, $definition);
        foreach ((array) ($data['results'] ?? []) as $r) {
            if (!is_array($r) || !isset($r['id']) || isset($results[(string) $r['id']])) {
                continue; // a runner result can never override a metadata verdict
            }
            $results[(string) $r['id']] = [
                'passed' => ($r['passed'] ?? false) === true,
                'feedback' => mb_substr((string) ($r['feedback'] ?? ''), 0, 300),
                'evidence' => is_array($r['evidence'] ?? null) ? $r['evidence'] : [],
            ];
        }
        $tenantId = (int) $job['tenant_id'];
        $scored = Scoring::score($definition, $results, $this->attempts->hintPenalties($tenantId, (int) $attempt['id']));
        $this->attempts->recordSubmission(
            $tenantId,
            (int) $attempt['id'],
            $this->submissionNo($job),
            $scored['tasks'],
            $scored['score'],
            $scored['all_passed']
        );
        return [
            'score' => $scored['score'],
            'max_score' => (float) $attempt['max_score'],
            'passed_tasks' => count(array_filter($scored['tasks'], static fn (array $t): bool => $t['passed'])),
        ];
    }

    public function failed(array $job, string $errorCode, string $safeMessage): void
    {
        $payload = json_decode((string) $job['payload'], true);
        $this->attempts->recordValidationError((int) $job['tenant_id'], (int) ($payload['attempt_id'] ?? 0), $errorCode, $safeMessage);
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $definition
     * @return array<string, array{passed: bool, feedback: string, evidence: array<string, mixed>}>
     */
    private function evaluateMetadata(array $job, array $definition): array
    {
        $checker = new MetadataChecks(new LabStateRepository($this->app->db()), (int) $job['tenant_id'], (int) $job['workspace_id']);
        $results = [];
        foreach ($definition['tasks'] as $task) {
            foreach ($task['checks'] as $i => $check) {
                if (in_array($check['type'], LabDefinition::METADATA_CHECKS, true)) {
                    $results[$task['key'] . '#' . $i] = $checker->evaluate($check);
                }
            }
        }
        return $results;
    }

    /** @param array<string, mixed> $job */
    private function submissionNo(array $job): int
    {
        return (int) (json_decode((string) $job['payload'], true)['submission_no'] ?? 0);
    }

    /**
     * @param array<string, mixed> $job
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function load(array $job): array
    {
        $payload = json_decode((string) $job['payload'], true);
        $attempt = $this->attempts->findForJob((int) $job['tenant_id'], (int) ($payload['attempt_id'] ?? 0));
        if (
            $attempt === null || $job['workspace_public_id'] === null || $attempt['workspace_id'] === null
            || (int) $attempt['workspace_id'] !== (int) $job['workspace_id'] || (int) ($payload['submission_no'] ?? 0) < 1
        ) {
            throw new RuntimeException('Validate job references missing data');
        }
        $definition = Format::jsonColumn($attempt['definition']);
        if (!is_array($definition['tasks'] ?? null)) {
            throw new RuntimeException('Lab definition is invalid');
        }
        return [$attempt, $definition];
    }
}
