<?php

declare(strict_types=1);

namespace EduCloud\Tests\Integration\Labs;

use EduCloud\Modules\Jobs\Maintenance;
use EduCloud\Tests\Support\ApiActors;
use EduCloud\Tests\Support\AuthHelpers;
use EduCloud\Tests\Support\DatasetHelpers;
use EduCloud\Tests\Support\LabHelpers;
use EduCloud\Tests\TestCase;

/**
 * Lab Engine API (M6): catalog, attempts, hints, answers, submission and grading, abandon, expiry, quotas.
 * LAB-001 has only metadata checks, so these tests grade without the Python runner.
 */
final class LabEngineTest extends TestCase
{
    use AuthHelpers;
    use ApiActors;
    use DatasetHelpers;
    use LabHelpers;

    private string $ana = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetDatabase();
        $this->importLabs();
        $this->ana = $this->actor('ana@test.example');
    }

    public function testCatalogListsPublishedLabsWithoutChecksOrSolutions(): void
    {
        $r = $this->as($this->ana, 'GET', '/api/v1/labs');
        self::assertSame(200, $r->status);
        $codes = ['LAB-001', 'LAB-002', 'LAB-003', 'LAB-004', 'LAB-005', 'LAB-006', 'LAB-007', 'LAB-009'];
        self::assertSame($codes, array_column($r->decoded()['data'], 'code'));
        self::assertNull($r->decoded()['data'][0]['my_attempt']);
        foreach (['expected_sql', 'actual_sql', 'checks', 'setup', 'hints', 'bronze.order_items i'] as $secret) {
            self::assertStringNotContainsString($secret, $r->body, "catalog leaks $secret");
        }
        self::assertStringContainsString('/assets/datasets/retail/customers.csv', $r->body, 'LAB-004 download link');

        $html = $this->sessionPage('/app/labs');
        self::assertStringContainsString('data-lab-start="LAB-004"', $html);
    }

    public function testStartCreatesADedicatedLabWorkspaceOnceAndOutsideTheWorkspaceQuota(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->createWorkspace($this->ana, "General $i"); // general quota (5) exhausted
        }
        $attempt = $this->startLab($this->ana, 'LAB-001');
        self::assertSame('in_progress', $attempt['status']);
        self::assertEquals(100.0, $attempt['max_score']);
        self::assertSame(['t1', 't2', 't3', 't4'], array_column($attempt['tasks'], 'key'));
        self::assertNull($attempt['tasks'][0]['hints'][0]['text'], 'hints stay hidden until revealed');
        self::assertTrue($attempt['environment']['ready']);
        self::assertStringNotContainsString('datos-crudos', (string) json_encode(array_column($attempt['tasks'], 'hints')));

        $ws = $this->as($this->ana, 'GET', '/api/v1/workspaces/' . $attempt['workspace']['id'])->decoded()['data'];
        self::assertSame('lab', $ws['purpose']);
        self::assertNotNull($ws['expires_at']);
        self::assertStringStartsWith('LAB-001 · ', $ws['name']);

        $again = $this->startLab($this->ana, 'LAB-001', 200);
        self::assertSame($attempt['id'], $again['id'], 'starting again returns the open attempt');
        self::assertSame(1, (int) $this->app()->db()->scalar('SELECT COUNT(*) FROM lab_attempts'));

        $deleteWs = $this->as($this->ana, 'DELETE', '/api/v1/workspaces/' . $attempt['workspace']['id']);
        self::assertSame(409, $deleteWs->status, 'lab workspaces are released by abandoning');
        self::assertSame(404, $this->as($this->ana, 'POST', '/api/v1/lab-attempts', ['lab_code' => 'LAB-999'])->status);
        self::assertSame(422, $this->as($this->ana, 'POST', '/api/v1/lab-attempts', ['lab_code' => '../x'])->status);
    }

    public function testSetupLoadsSamplesIntoAManagedLakehouse(): void
    {
        $attempt = $this->startLab($this->ana, 'LAB-003');
        self::assertFalse($attempt['environment']['ready']);
        self::assertSame(8, $attempt['environment']['pending_jobs'], '4 samples × (profile + ingest)');
        $wsId = $attempt['workspace']['id'];
        $datasets = $this->as($this->ana, 'GET', "/api/v1/workspaces/$wsId/datasets")->decoded()['data'];
        self::assertCount(8, $datasets);
        self::assertSame('lago', $this->resourceByName($this->ana, $wsId, 'lago')['name']);

        $bronze = $this->datasetByName($this->ana, $wsId, 'bronze', 'orders');
        $r = $this->as($this->ana, 'DELETE', '/api/v1/datasets/' . $bronze['id']);
        self::assertSame(409, $r->status);
        self::assertSame('LAB_MANAGED', $r->decoded()['error']['code'], 'setup data cannot be swapped to game the checks');
    }

    public function testHintsAreRevealedInOrderAndPenalisedOnce(): void
    {
        $id = $this->startLab($this->ana, 'LAB-001')['id'];
        $hint = fn (string $task, int $index) => $this->as($this->ana, 'POST', "/api/v1/lab-attempts/$id/hints", [
            'task_key' => $task,
            'hint_index' => $index,
        ]);

        self::assertSame(409, $hint('t1', 1)->status, 'hint 2 needs hint 1 first');
        $first = $hint('t1', 0);
        self::assertSame(200, $first->status, $first->body);
        self::assertTrue($first->decoded()['data']['newly_revealed']);
        self::assertSame(3, $first->decoded()['data']['penalty']);
        self::assertFalse($hint('t1', 0)->decoded()['data']['newly_revealed'], 'revealing again is free');
        self::assertSame(200, $hint('t1', 1)->status);
        self::assertSame(422, $hint('t1', 7)->status);
        self::assertSame(422, $hint('t9', 0)->status);
        self::assertSame(422, $this->as($this->ana, 'POST', "/api/v1/lab-attempts/$id/hints", ['task_key' => 't1', 'hint_index' => '0'])->status);

        $attempt = $this->attempt($this->ana, $id);
        self::assertSame(2, $attempt['hints_used']);
        self::assertNotNull($attempt['tasks'][0]['hints'][1]['text']);
        self::assertSame(8.0, (float) $this->app()->db()->scalar('SELECT SUM(penalty) FROM lab_hint_usage'));
    }

    public function testMetadataLabIsGradedFromActualStateWithPenalties(): void
    {
        $attempt = $this->startLab($this->ana, 'LAB-001');
        $id = $attempt['id'];
        $ws = $attempt['workspace']['id'];

        $empty = $this->submitAndGrade($this->ana, $id);
        self::assertEquals(0.0, $empty['score']);
        self::assertSame('in_progress', $empty['status']);
        self::assertStringContainsString('datos-crudos', (string) $empty['tasks'][0]['result']['feedback']);

        // t1 done but with a wrong tag; t2 done; t3 missing; t4 created but not deleted.
        $this->as($this->ana, 'POST', "/api/v1/workspaces/$ws/resources", [
            'type' => 'storage', 'name' => 'datos-crudos', 'tags' => ['proyecto' => 'otro'],
        ]);
        $this->as($this->ana, 'POST', "/api/v1/workspaces/$ws/resources", [
            'type' => 'storage', 'name' => 'archivo-historico', 'config' => ['access_tier' => 'archive', 'redundancy' => 'zrs'],
        ]);
        $this->createResource($this->ana, $ws, 'storage', 'temporal');
        $this->as($this->ana, 'POST', "/api/v1/lab-attempts/$id/hints", ['task_key' => 't2', 'hint_index' => 0]);

        $partial = $this->submitAndGrade($this->ana, $id);
        self::assertSame([false, true, false, false], array_map(static fn (array $t): bool => $t['result']['passed'], $partial['tasks']));
        self::assertEquals(20.0, $partial['score'], 't2 (25) minus its hint penalty (5)');
        self::assertSame(
            'No encontramos un almacenamiento activo llamado datos-crudos con la etiqueta proyecto=retail.',
            $partial['tasks'][0]['result']['feedback'],
            'the author feedback explains what is missing'
        );
        self::assertStringContainsString('El almacenamiento temporal debe', (string) $partial['tasks'][3]['result']['feedback']);

        $raw = $this->resourceByName($this->ana, $ws, 'datos-crudos');
        $this->as($this->ana, 'PATCH', '/api/v1/resources/' . $raw['id'], ['tags' => ['proyecto' => 'retail']]);
        $this->createResource($this->ana, $ws, 'lakehouse', 'lago-retail');
        $lake = $this->resourceByName($this->ana, $ws, 'lago-retail');
        $this->as($this->ana, 'PATCH', '/api/v1/resources/' . $lake['id'], ['tags' => ['entorno' => 'dev']]);
        $temporal = $this->resourceByName($this->ana, $ws, 'temporal');
        self::assertSame(204, $this->as($this->ana, 'DELETE', '/api/v1/resources/' . $temporal['id'])->status);

        $done = $this->submitAndGrade($this->ana, $id);
        self::assertSame('completed', $done['status']);
        self::assertEquals(95.0, $done['score']);
        self::assertEquals(95.0, $done['best_score']);
        self::assertSame(3, $done['submissions']);

        // Breaking the state lowers the latest score but keeps the best one and the completed status.
        $this->as($this->ana, 'DELETE', '/api/v1/resources/' . $lake['id']);
        $worse = $this->submitAndGrade($this->ana, $id);
        self::assertEquals(70.0, $worse['score']);
        self::assertEquals(95.0, $worse['best_score']);
        self::assertSame('completed', $worse['status']);

        $catalog = $this->as($this->ana, 'GET', '/api/v1/labs')->decoded()['data'];
        self::assertEquals(['id' => $id, 'status' => 'completed', 'open' => true, 'best_score' => 95.0], $catalog[0]['my_attempt']);
    }

    public function testClientCannotClaimResultsOrScores(): void
    {
        $id = $this->startLab($this->ana, 'LAB-001')['id'];
        foreach ([['score' => 100], ['results' => ['t1' => true]], ['passed' => true, 'status' => 'completed']] as $fabricated) {
            $r = $this->as($this->ana, 'POST', "/api/v1/lab-attempts/$id/submit", $fabricated);
            self::assertSame(422, $r->status, $r->body);
        }
        self::assertSame(0, (int) $this->app()->db()->scalar("SELECT COUNT(*) FROM jobs WHERE type = 'validate'"));
        $graded = $this->submitAndGrade($this->ana, $id);
        self::assertEquals(0.0, $graded['score']);
        self::assertEquals(0.0, $graded['best_score']);
    }

    public function testSubmissionStateMachine(): void
    {
        $id = $this->startLab($this->ana, 'LAB-001')['id'];
        self::assertSame(202, $this->as($this->ana, 'POST', "/api/v1/lab-attempts/$id/submit", [])->status);
        $twice = $this->as($this->ana, 'POST', "/api/v1/lab-attempts/$id/submit", []);
        self::assertSame(409, $twice->status);
        self::assertSame('ATTEMPT_VALIDATING', $twice->decoded()['error']['code']);
        self::assertSame(409, $this->as($this->ana, 'DELETE', "/api/v1/lab-attempts/$id")->status, 'cannot abandon mid-validation');
        $this->runJobs();
        self::assertSame('in_progress', $this->attempt($this->ana, $id)['status']);
    }

    public function testAnswersAreStoredOnlyForExerciseTasks(): void
    {
        $id = $this->startLab($this->ana, 'LAB-004')['id'];
        $answer = fn (array $body) => $this->as($this->ana, 'POST', "/api/v1/lab-attempts/$id/answers", $body);
        self::assertSame(422, $answer(['task_key' => 't1', 'sql' => 'SELECT 1'])->status, 't1 is not an exercise');
        self::assertSame(422, $answer(['task_key' => 't4', 'sql' => str_repeat('x', 20001)])->status);
        self::assertSame(422, $answer(['task_key' => 't4'])->status);
        self::assertSame(200, $answer(['task_key' => 't4', 'sql' => 'SELECT 1'])->status);
        self::assertSame(200, $answer(['task_key' => 't4', 'sql' => "SELECT count(*) FROM bronze.customers WHERE email IS NULL"])->status);
        $attempt = $this->attempt($this->ana, $id);
        self::assertSame("SELECT count(*) FROM bronze.customers WHERE email IS NULL", $attempt['tasks'][3]['answer_sql']);
        self::assertSame(1, (int) $this->app()->db()->scalar('SELECT COUNT(*) FROM lab_task_answers'));
    }

    public function testAbandonReleasesTheWorkspaceAndAllowsAFreshStart(): void
    {
        $first = $this->startLab($this->ana, 'LAB-001');
        self::assertSame(204, $this->as($this->ana, 'DELETE', '/api/v1/lab-attempts/' . $first['id'])->status);
        $closed = $this->attempt($this->ana, $first['id']);
        self::assertSame('abandoned', $closed['status']);
        self::assertSame('deleted', $closed['workspace']['status']);
        self::assertSame(409, $this->as($this->ana, 'POST', '/api/v1/lab-attempts/' . $first['id'] . '/submit', [])->status);
        $hint = $this->as($this->ana, 'POST', '/api/v1/lab-attempts/' . $first['id'] . '/hints', ['task_key' => 't1', 'hint_index' => 0]);
        self::assertSame(409, $hint->status);
        self::assertSame(404, $this->as($this->ana, 'GET', '/api/v1/workspaces/' . $first['workspace']['id'])->status);

        $second = $this->startLab($this->ana, 'LAB-001');
        self::assertNotSame($first['id'], $second['id']);
    }

    public function testInProgressAttemptsAreCapped(): void
    {
        $this->startLab($this->ana, 'LAB-001');
        $this->startLab($this->ana, 'LAB-004');
        $this->startLab($this->ana, 'LAB-003');
        $this->app()->db()->execute("UPDATE jobs SET status = 'succeeded'"); // setup finished (no runner needed here)
        $r = $this->as($this->ana, 'POST', '/api/v1/lab-attempts', ['lab_code' => 'LAB-005']);
        self::assertSame(409, $r->status);
        self::assertSame('QUOTA_EXCEEDED', $r->decoded()['error']['code']);
        self::assertStringContainsString('laboratorios en curso', $r->decoded()['error']['message']);
        self::assertSame(3, (int) $this->app()->db()->scalar("SELECT COUNT(*) FROM workspaces WHERE purpose = 'lab'"));
    }

    public function testStartAbandonLoopsCannotFloodTheJobQueue(): void
    {
        // M6 gate: each LAB-003 start queues 8 setup jobs. A new start waits for the user's queue,
        // and abandoning cancels what is still queued.
        $first = $this->startLab($this->ana, 'LAB-003');
        $busy = $this->as($this->ana, 'POST', '/api/v1/lab-attempts', ['lab_code' => 'LAB-005']);
        self::assertSame(409, $busy->status);
        self::assertStringContainsString('trabajos en curso', $busy->decoded()['error']['message']);

        self::assertSame(204, $this->as($this->ana, 'DELETE', '/api/v1/lab-attempts/' . $first['id'])->status);
        self::assertSame(0, (int) $this->app()->db()->scalar("SELECT COUNT(*) FROM jobs WHERE status = 'queued'"));
        $cancelled = "SELECT COUNT(*) FROM jobs WHERE status = 'cancelled' AND error_code = 'WORKSPACE_DELETED'";
        self::assertSame(8, (int) $this->app()->db()->scalar($cancelled));
        self::assertSame(201, $this->as($this->ana, 'POST', '/api/v1/lab-attempts', ['lab_code' => 'LAB-005'])->status);
    }

    public function testDispatcherSkipsJobsOfDeletedWorkspaces(): void
    {
        $ws = (string) $this->createWorkspace($this->ana)['id'];
        self::assertSame(202, $this->uploadAs($this->ana, $ws, 'clientes', self::customersCsv())->status);
        self::assertSame(204, $this->as($this->ana, 'DELETE', "/api/v1/workspaces/$ws")->status);
        $this->runJobs();
        $job = $this->app()->db()->selectOne("SELECT status, error_code FROM jobs WHERE type = 'profile'");
        self::assertSame(['status' => 'cancelled', 'error_code' => 'WORKSPACE_DELETED'], $job);
    }

    public function testRestartingALabKeepsRevealedHintsAndTheirPenalty(): void
    {
        $first = $this->startLab($this->ana, 'LAB-001');
        $this->as($this->ana, 'POST', '/api/v1/lab-attempts/' . $first['id'] . '/hints', ['task_key' => 't2', 'hint_index' => 0]);
        $this->as($this->ana, 'DELETE', '/api/v1/lab-attempts/' . $first['id']);

        $second = $this->startLab($this->ana, 'LAB-001');
        self::assertSame(1, $second['hints_used']);
        self::assertNotNull($second['tasks'][1]['hints'][0]['text'], 'the hint stays revealed');
        self::assertEquals(5, $this->app()->db()->scalar(
            'SELECT penalty FROM lab_hint_usage h JOIN lab_attempts a ON a.id = h.attempt_id WHERE a.public_id = ?',
            [$second['id']]
        ));
    }

    public function testInactiveLabWorkspacesExpire(): void
    {
        $attempt = $this->startLab($this->ana, 'LAB-001');
        $this->createResource($this->ana, $attempt['workspace']['id'], 'storage', 'datos-crudos');
        $this->app()->db()->execute("UPDATE workspaces SET expires_at = UTC_TIMESTAMP(3) - INTERVAL 1 MINUTE WHERE purpose = 'lab'");
        $result = (new Maintenance($this->app()))->run();
        self::assertSame(1, $result['lab_attempts_expired']);
        self::assertSame(1, $result['workspaces_released']);
        $expired = $this->attempt($this->ana, $attempt['id']);
        self::assertSame('expired', $expired['status']);
        self::assertSame('deleted', $expired['workspace']['status']);
        self::assertSame(201, $this->as($this->ana, 'POST', '/api/v1/lab-attempts', ['lab_code' => 'LAB-001'])->status);
    }

    public function testRolesAndOwnership(): void
    {
        $org = $this->createOrgTenant('Instituto');
        $this->createVerifiedUser('lector@test.example');
        $this->createVerifiedUser('admin@test.example');
        $this->addMember($org['id'], 'ana@test.example', 'student');
        $this->addMember($org['id'], 'lector@test.example', 'read_only');
        $this->addMember($org['id'], 'admin@test.example', 'org_admin');

        $this->sessionIn('lector@test.example', $org['public_id']);
        $csrf = $this->csrfFromPage('/app/labs');
        self::assertSame(200, $this->request('GET', '/api/v1/labs')->status, 'read_only can browse the catalog');
        self::assertSame(403, $this->request('POST', '/api/v1/lab-attempts', ['lab_code' => 'LAB-001'], ['X-CSRF-Token' => $csrf])->status);

        $anaCsrf = $this->sessionIn('ana@test.example', $org['public_id']);
        $r = $this->request('POST', '/api/v1/lab-attempts', ['lab_code' => 'LAB-001'], ['X-CSRF-Token' => $anaCsrf]);
        self::assertSame(201, $r->status, $r->body);
        $id = (string) $r->decoded()['data']['id'];

        $adminCsrf = $this->sessionIn('admin@test.example', $org['public_id']);
        $seen = $this->request('GET', "/api/v1/lab-attempts/$id");
        self::assertSame(200, $seen->status, 'org admins can review attempts in their tenant');
        self::assertFalse($seen->decoded()['data']['is_owner']);
        self::assertSame(200, $this->request('GET', "/app/lab-attempts/$id")->status);
        $actions = [
            'submit' => [],
            'answers' => ['task_key' => 't1', 'sql' => 'SELECT 1'],
            'hints' => ['task_key' => 't1', 'hint_index' => 0],
        ];
        foreach ($actions as $action => $body) {
            $denied = $this->request('POST', "/api/v1/lab-attempts/$id/$action", $body, ['X-CSRF-Token' => $adminCsrf]);
            self::assertSame(403, $denied->status, "admin $action");
        }
        self::assertSame(403, $this->request('DELETE', "/api/v1/lab-attempts/$id", null, ['X-CSRF-Token' => $adminCsrf])->status);
        self::assertSame(0, (int) $this->app()->db()->scalar("SELECT COUNT(*) FROM jobs WHERE type = 'validate'"));
    }

    public function testAttemptPageRendersEscapedContentAndSafeMarkdown(): void
    {
        $id = $this->startLab($this->ana, 'LAB-003')['id'];
        $this->as($this->ana, 'POST', "/api/v1/lab-attempts/$id/answers", ['task_key' => 't1', 'sql' => '</textarea><script>alert(1)</script>']);
        $html = $this->sessionPage("/app/lab-attempts/$id");
        self::assertStringContainsString('&lt;/textarea&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('<code>bronze.products</code>', $html, 'instructions are rendered from Markdown');
        self::assertStringContainsString('<table>', $html);
        self::assertStringNotContainsString('expected_sql', $html);
        self::assertStringNotContainsString("category = 'Libros'", $html, 'the reference solution never reaches the page');
    }

    /** Logs ana in with a browser session (personal tenant) and returns the page HTML. */
    private function sessionPage(string $path): string
    {
        $this->cookieJar = [];
        $this->login('ana@test.example');
        $r = $this->request('GET', $path);
        self::assertSame(200, $r->status, $path);
        return $r->body;
    }
}
