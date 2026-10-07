<?php

declare(strict_types=1);

namespace EduCloud\Tests\Unit\Labs;

use EduCloud\Modules\Labs\Scoring;
use PHPUnit\Framework\TestCase;

final class ScoringTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function definition(): array
    {
        return ['tasks' => [
            ['key' => 't1', 'points' => 30, 'checks' => [
                ['type' => 'dataset_exists', 'feedback' => 'Crea la tabla.'],
                ['type' => 'row_count'],
            ]],
            ['key' => 't2', 'points' => 20, 'answer' => true, 'checks' => [
                ['type' => 'query_result_matches', 'expected_sql' => 'SELECT 1', 'feedback' => 'No coincide.'],
            ]],
            ['key' => 't3', 'points' => 10, 'checks' => [['type' => 'unique']]],
        ]];
    }

    public function testAllPassedEarnsFullPointsMinusPenalties(): void
    {
        $results = ['t1#0' => self::ok(), 't1#1' => self::ok(), 't2#0' => self::ok(), 't3#0' => self::ok()];
        $out = Scoring::score(self::definition(), $results, ['t1' => 5.0, 't3' => 25.0]);
        self::assertSame(25.0 + 20.0 + 0.0, $out['score'], 'penalties reduce a passed task, never below 0');
        self::assertTrue($out['all_passed']);
        self::assertNull($out['tasks'][0]['feedback']);
    }

    public function testATaskPassesOnlyWhenEveryCheckPasses(): void
    {
        $results = ['t1#0' => self::ok(), 't1#1' => self::failed('3 filas'), 't2#0' => self::ok(), 't3#0' => self::ok()];
        $out = Scoring::score(self::definition(), $results, ['t1' => 5.0]);
        self::assertSame(30.0, $out['score']);
        self::assertFalse($out['tasks'][0]['passed']);
        self::assertSame(0.0, $out['tasks'][0]['points'], 'hint penalties do not make failed tasks negative');
        self::assertSame('3 filas', $out['tasks'][0]['feedback']);
        self::assertFalse($out['all_passed']);
    }

    public function testMissingResultsFailAndNothingIsAssumed(): void
    {
        $out = Scoring::score(self::definition(), [], []);
        self::assertSame(0.0, $out['score']);
        self::assertSame('Crea la tabla. No se pudo evaluar esta comprobación.', $out['tasks'][0]['feedback']);
    }

    public function testAuthorFeedbackWinsExceptWhenTheStudentSqlItselfFailed(): void
    {
        $mismatch = Scoring::score(self::definition(), ['t2#0' => self::failed('Los valores no coinciden.')], []);
        self::assertSame('No coincide.', $mismatch['tasks'][1]['feedback']);

        $error = self::failed('Tu consulta falló: Error de sintaxis', ['error' => 'SQL_SYNTAX_ERROR']);
        $syntax = Scoring::score(self::definition(), ['t2#0' => $error], []);
        self::assertSame('Tu consulta falló: Error de sintaxis', $syntax['tasks'][1]['feedback']);

        $missing = Scoring::score(self::definition(), ['t2#0' => self::failed('Guarda una respuesta', ['answered' => false])], []);
        self::assertSame('Guarda una respuesta', $missing['tasks'][1]['feedback']);
    }

    /** @return array{passed: bool, feedback: string, evidence: array<string, mixed>} */
    private static function ok(): array
    {
        return ['passed' => true, 'feedback' => '', 'evidence' => []];
    }

    /**
     * @param array<string, mixed> $evidence
     * @return array{passed: bool, feedback: string, evidence: array<string, mixed>}
     */
    private static function failed(string $feedback, array $evidence = []): array
    {
        return ['passed' => false, 'feedback' => $feedback, 'evidence' => $evidence];
    }
}
