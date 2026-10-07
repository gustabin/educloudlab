<?php

declare(strict_types=1);

namespace EduCloud\Modules\Labs;

/**
 * Pure scoring: a task passes when all its checks pass; a passed task earns its points minus the penalties of the
 * hints revealed for it (never below 0); failed tasks earn 0. Check ids are "<task key>#<check index>".
 */
final class Scoring
{
    /**
     * @param array<string, mixed> $definition
     * @param array<string, array{passed: bool, feedback: string, evidence?: array<string, mixed>}> $checkResults by check id
     * @param array<string, float> $penalties by task key
     * @return array{
     *     tasks: list<array{task_key: string, passed: bool, points: float, feedback: string|null, evidence: array<string, mixed>}>,
     *     score: float,
     *     all_passed: bool
     * }
     */
    public static function score(array $definition, array $checkResults, array $penalties): array
    {
        $tasks = [];
        $score = 0.0;
        $allPassed = true;
        foreach ($definition['tasks'] as $task) {
            $key = (string) $task['key'];
            $passed = true;
            $messages = [];
            $evidence = [];
            foreach ($task['checks'] as $i => $check) {
                $id = $key . '#' . $i;
                $result = $checkResults[$id] ?? ['passed' => false, 'feedback' => 'No se pudo evaluar esta comprobación.', 'evidence' => []];
                $evidence[] = ['check' => $i, 'type' => $check['type'], 'passed' => $result['passed']] + ($result['evidence'] ?? []);
                if (!$result['passed']) {
                    $passed = false;
                    $messages[] = self::message($check, $result);
                }
            }
            $points = $passed ? max(0.0, (float) $task['points'] - ($penalties[$key] ?? 0.0)) : 0.0;
            $score += $points;
            $allPassed = $allPassed && $passed;
            $tasks[] = [
                'task_key' => $key,
                'passed' => $passed,
                'points' => $points,
                'feedback' => $messages === [] ? null : implode(' ', array_slice(array_values(array_unique($messages)), 0, 3)),
                'evidence' => ['checks' => $evidence],
            ];
        }
        return ['tasks' => $tasks, 'score' => $score, 'all_passed' => $allPassed];
    }

    /**
     * The author's feedback explains *what* is wrong in pedagogical terms; when the student's own SQL failed or is
     * missing, the technical message is more useful, so it wins.
     *
     * @param array<string, mixed> $check
     * @param array<string, mixed> $result
     */
    private static function message(array $check, array $result): string
    {
        $evidence = $result['evidence'] ?? [];
        $studentProblem = $check['type'] === 'query_result_matches' && !isset($check['actual_sql'])
            && (isset($evidence['error']) || ($evidence['answered'] ?? true) === false);
        if (!$studentProblem && isset($check['feedback'])) {
            return (string) $check['feedback'];
        }
        return (string) $result['feedback'];
    }
}
