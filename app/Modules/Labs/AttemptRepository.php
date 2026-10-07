<?php

declare(strict_types=1);

namespace EduCloud\Modules\Labs;

use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Db;
use EduCloud\Core\Ulid;

/**
 * Lab attempts, hint usage, saved answers and per-submission task results. Tenant-scoped for the API;
 * the *ForJob methods are unscoped lookups for the trusted dispatcher (payloads carry internal ids).
 */
final class AttemptRepository
{
    private const SELECT = "SELECT a.*, l.code AS lab_code, l.version AS lab_version, l.title AS lab_title, l.definition,
               w.public_id AS workspace_public_id, w.status AS workspace_status, w.expires_at AS workspace_expires_at,
               u.public_id AS user_public_id, u.display_name AS user_name,
               co.public_id AS course_public_id, co.title AS course_title
          FROM lab_attempts a
          JOIN labs l ON l.id = a.lab_id
          JOIN users u ON u.id = a.user_id
          LEFT JOIN workspaces w ON w.tenant_id = a.tenant_id AND w.id = a.workspace_id
          LEFT JOIN courses co ON co.tenant_id = a.tenant_id AND co.id = a.course_id";

    /** Attempts that still hold a usable environment (resubmission allowed). */
    private const OPEN = "a.status IN ('in_progress', 'validating', 'completed') AND w.status = 'active'";

    public function __construct(private readonly Db $db)
    {
    }

    /** @return array{id: int, public_id: string} */
    public function create(TenantContext $ctx, string $publicId, int $labId, int $workspaceId, int $maxScore, ?int $courseId = null): array
    {
        $id = $this->db->insert(
            'INSERT INTO lab_attempts (public_id, tenant_id, user_id, lab_id, course_id, workspace_id, max_score) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$publicId, $ctx->tenantId, $ctx->userId, $labId, $courseId, $workspaceId, $maxScore]
        );
        return ['id' => $id, 'public_id' => $publicId];
    }

    public static function newPublicId(): string
    {
        return Ulid::generate();
    }

    /**
     * Owner, tenant-wide roles, or - with $courseReviewer (role has 'review') - staff of the attempt's course
     * (course owner or active instructor enrollment): instructor visibility is course-scoped (M10a).
     *
     * @return array<string, mixed>|null
     */
    public function findVisible(TenantContext $ctx, string $publicId, bool $wholeTenant, bool $courseReviewer = false): ?array
    {
        $sql = self::SELECT . ' WHERE a.tenant_id = ? AND a.public_id = ?';
        $params = [$ctx->tenantId, $publicId];
        if (!$wholeTenant && !$courseReviewer) {
            $sql .= ' AND a.user_id = ?';
            $params[] = $ctx->userId;
        } elseif (!$wholeTenant) {
            $sql .= " AND (a.user_id = ? OR (co.id IS NOT NULL AND (co.owner_user_id = ? OR EXISTS (
                          SELECT 1 FROM enrollments e WHERE e.tenant_id = co.tenant_id AND e.course_id = co.id
                             AND e.user_id = ? AND e.role = 'instructor' AND e.status = 'active'))))";
            array_push($params, $ctx->userId, $ctx->userId, $ctx->userId);
        }
        return $this->db->selectOne($sql, $params);
    }

    /** @return array<string, mixed>|null the caller's open attempt for a lab code */
    public function findOpenForLab(TenantContext $ctx, string $labCode): ?array
    {
        return $this->db->selectOne(
            self::SELECT . ' WHERE a.tenant_id = ? AND a.user_id = ? AND l.code = ? AND ' . self::OPEN . ' ORDER BY a.id DESC LIMIT 1',
            [$ctx->tenantId, $ctx->userId, $labCode]
        );
    }

    public function countInProgress(TenantContext $ctx): int
    {
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM lab_attempts a JOIN workspaces w ON w.tenant_id = a.tenant_id AND w.id = a.workspace_id
              WHERE a.tenant_id = ? AND a.user_id = ? AND a.status IN ('in_progress', 'validating') AND w.status = 'active'",
            [$ctx->tenantId, $ctx->userId]
        );
    }

    /**
     * The caller's most relevant attempt per lab code (open first, then the best finished one).
     *
     * @return array<string, array<string, mixed>> lab code => attempt row
     */
    public function summaryByLab(TenantContext $ctx): array
    {
        $rows = $this->db->select(
            self::SELECT . ' WHERE a.tenant_id = ? AND a.user_id = ? ORDER BY a.id DESC',
            [$ctx->tenantId, $ctx->userId]
        );
        $out = [];
        $best = [];
        foreach ($rows as $row) {
            $code = (string) $row['lab_code'];
            $open = in_array($row['status'], ['in_progress', 'validating', 'completed'], true) && $row['workspace_status'] === 'active';
            if (!isset($out[$code]) || ($open && !$out[$code]['_open'])) {
                $out[$code] = $row + ['_open' => $open];
            }
            $best[$code] = max($best[$code] ?? 0.0, (float) $row['best_score']);
        }
        foreach ($out as $code => $row) {
            $out[$code]['best_score_any'] = $best[$code];
        }
        return $out;
    }

    /** Row lock on the attempt (serialises submit/answer/hint/abandon). Returns the locked status. */
    public function lockStatus(TenantContext $ctx, int $id): ?string
    {
        $status = $this->db->scalar('SELECT status FROM lab_attempts WHERE tenant_id = ? AND id = ? FOR UPDATE', [$ctx->tenantId, $id]);
        return $status === null ? null : (string) $status;
    }

    /** Marks the attempt as validating and returns the new submission number. */
    public function startValidation(TenantContext $ctx, int $id): int
    {
        $this->db->execute(
            "UPDATE lab_attempts SET status = 'validating', submissions = submissions + 1, last_submitted_at = UTC_TIMESTAMP(3),
                    last_error_code = NULL, last_error_message = NULL
              WHERE tenant_id = ? AND id = ?",
            [$ctx->tenantId, $id]
        );
        return (int) $this->db->scalar('SELECT submissions FROM lab_attempts WHERE tenant_id = ? AND id = ?', [$ctx->tenantId, $id]);
    }

    public function setStatus(TenantContext $ctx, int $id, string $status): void
    {
        $this->db->execute('UPDATE lab_attempts SET status = ? WHERE tenant_id = ? AND id = ?', [$status, $ctx->tenantId, $id]);
    }

    // ------------------------------------------------------------------------------------------------ hints & answers

    /** @return array<string, list<int>> task key => revealed hint indexes */
    public function usedHints(int $tenantId, int $attemptId): array
    {
        $out = [];
        foreach (
            $this->db->select(
                'SELECT task_key, hint_index FROM lab_hint_usage WHERE tenant_id = ? AND attempt_id = ? ORDER BY task_key, hint_index',
                [$tenantId, $attemptId]
            ) as $row
        ) {
            $out[(string) $row['task_key']][] = (int) $row['hint_index'];
        }
        return $out;
    }

    /** @return array<string, float> task key => total penalty of revealed hints */
    public function hintPenalties(int $tenantId, int $attemptId): array
    {
        $out = [];
        foreach (
            $this->db->select(
                'SELECT task_key, SUM(penalty) AS total FROM lab_hint_usage WHERE tenant_id = ? AND attempt_id = ? GROUP BY task_key',
                [$tenantId, $attemptId]
            ) as $row
        ) {
            $out[(string) $row['task_key']] = (float) $row['total'];
        }
        return $out;
    }

    /** Records a revealed hint once (primary key); returns false when it was already revealed. */
    public function recordHint(TenantContext $ctx, int $attemptId, string $taskKey, int $index, int $penalty): bool
    {
        $inserted = $this->db->execute(
            'INSERT IGNORE INTO lab_hint_usage (tenant_id, attempt_id, task_key, hint_index, penalty) VALUES (?, ?, ?, ?, ?)',
            [$ctx->tenantId, $attemptId, $taskKey, $index, $penalty]
        ) === 1;
        if ($inserted) {
            $this->db->execute('UPDATE lab_attempts SET hints_used = hints_used + 1 WHERE tenant_id = ? AND id = ?', [$ctx->tenantId, $attemptId]);
        }
        return $inserted;
    }

    /**
     * Copies the hints revealed in the caller's earlier attempts of the same lab (same tenant) into a new attempt,
     * so abandoning and restarting cannot be used to read hints without their penalty.
     */
    public function carryOverHints(TenantContext $ctx, int $attemptId, string $labCode): void
    {
        $this->db->execute(
            'INSERT IGNORE INTO lab_hint_usage (tenant_id, attempt_id, task_key, hint_index, penalty)
             SELECT ?, ?, h.task_key, h.hint_index, MAX(h.penalty)
               FROM lab_hint_usage h
               JOIN lab_attempts a ON a.tenant_id = h.tenant_id AND a.id = h.attempt_id
               JOIN labs l ON l.id = a.lab_id
              WHERE h.tenant_id = ? AND a.user_id = ? AND l.code = ? AND a.id <> ?
              GROUP BY h.task_key, h.hint_index',
            [$ctx->tenantId, $attemptId, $ctx->tenantId, $ctx->userId, $labCode, $attemptId]
        );
        $this->db->execute(
            'UPDATE lab_attempts SET hints_used = (SELECT COUNT(*) FROM lab_hint_usage WHERE tenant_id = ? AND attempt_id = ?)
              WHERE tenant_id = ? AND id = ?',
            [$ctx->tenantId, $attemptId, $ctx->tenantId, $attemptId]
        );
    }

    /** @return array<string, string> task key => saved SQL */
    public function answers(int $tenantId, int $attemptId): array
    {
        $out = [];
        foreach (
            $this->db->select(
                'SELECT task_key, answer_sql FROM lab_task_answers WHERE tenant_id = ? AND attempt_id = ?',
                [$tenantId, $attemptId]
            ) as $row
        ) {
            $out[(string) $row['task_key']] = (string) $row['answer_sql'];
        }
        return $out;
    }

    public function saveAnswer(TenantContext $ctx, int $attemptId, string $taskKey, string $sql): void
    {
        $this->db->execute(
            'INSERT INTO lab_task_answers (tenant_id, attempt_id, task_key, answer_sql) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE answer_sql = VALUES(answer_sql)',
            [$ctx->tenantId, $attemptId, $taskKey, $sql]
        );
    }

    // ------------------------------------------------------------------------------------------------ results

    /** @return list<array<string, mixed>> task results of one submission */
    public function results(int $tenantId, int $attemptId, int $submissionNo): array
    {
        return $this->db->select(
            'SELECT task_key, passed, points, feedback, evidence, created_at FROM lab_task_results
              WHERE tenant_id = ? AND attempt_id = ? AND submission_no = ? ORDER BY id',
            [$tenantId, $attemptId, $submissionNo]
        );
    }

    // ------------------------------------------------------------------------------------------------ dispatcher

    /** @return array<string, mixed>|null */
    public function findForJob(int $tenantId, int $attemptId): ?array
    {
        return $this->db->selectOne(self::SELECT . ' WHERE a.tenant_id = ? AND a.id = ?', [$tenantId, $attemptId]);
    }

    /**
     * Stores one submission's results and the resulting score (best score kept; completed once every task passed).
     *
     * @param list<array{task_key: string, passed: bool, points: float, feedback: string|null, evidence: array<string, mixed>}> $tasks
     */
    public function recordSubmission(int $tenantId, int $attemptId, int $submissionNo, array $tasks, float $score, bool $allPassed): void
    {
        $this->db->transaction(function () use ($tenantId, $attemptId, $submissionNo, $tasks, $score, $allPassed): void {
            foreach ($tasks as $task) {
                $this->db->execute(
                    'INSERT INTO lab_task_results (tenant_id, attempt_id, submission_no, task_key, passed, points, feedback, evidence)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE passed = VALUES(passed), points = VALUES(points),
                                             feedback = VALUES(feedback), evidence = VALUES(evidence)',
                    [
                        $tenantId, $attemptId, $submissionNo, $task['task_key'], $task['passed'] ? 1 : 0, $task['points'],
                        $task['feedback'] === null ? null : mb_substr($task['feedback'], 0, 500),
                        (string) json_encode($task['evidence'], JSON_UNESCAPED_UNICODE),
                    ]
                );
            }
            $this->db->execute(
                "UPDATE lab_attempts
                    SET score = ?, best_score = GREATEST(best_score, ?),
                        completed_at = IF(? = 1 AND completed_at IS NULL, UTC_TIMESTAMP(3), completed_at),
                        status = IF(? = 1 OR completed_at IS NOT NULL, 'completed', 'in_progress')
                  WHERE tenant_id = ? AND id = ? AND status = 'validating'",
                [$score, $score, $allPassed ? 1 : 0, $allPassed ? 1 : 0, $tenantId, $attemptId]
            );
        });
    }

    public function recordValidationError(int $tenantId, int $attemptId, string $code, string $message): void
    {
        $this->db->execute(
            "UPDATE lab_attempts
                SET status = IF(completed_at IS NULL, 'in_progress', 'completed'), last_error_code = ?, last_error_message = ?
              WHERE tenant_id = ? AND id = ? AND status = 'validating'",
            [mb_substr($code, 0, 40), mb_substr($message, 0, 300), $tenantId, $attemptId]
        );
    }

    // ------------------------------------------------------------------------------------------------ scheduler

    /**
     * Open attempts whose lab workspace expired (inactivity TTL).
     *
     * @return list<array<string, mixed>>
     */
    public function findExpired(int $limit = 50): array
    {
        return $this->db->select(
            "SELECT a.id, a.tenant_id, a.workspace_id, a.status FROM lab_attempts a
               JOIN workspaces w ON w.tenant_id = a.tenant_id AND w.id = a.workspace_id
              WHERE w.status = 'active' AND w.purpose = 'lab' AND w.expires_at IS NOT NULL AND w.expires_at < UTC_TIMESTAMP(3)
                AND a.status IN ('in_progress', 'completed')
              LIMIT " . max(1, $limit)
        );
    }

    public function markExpired(int $tenantId, int $attemptId): void
    {
        $this->db->execute(
            "UPDATE lab_attempts SET status = 'expired' WHERE tenant_id = ? AND id = ? AND status = 'in_progress'",
            [$tenantId, $attemptId]
        );
    }
}
