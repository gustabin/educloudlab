<?php

declare(strict_types=1);

namespace EduCloud\Tests\Integration\Courses;

use EduCloud\Core\Response;
use EduCloud\Tests\Support\ApiActors;
use EduCloud\Tests\Support\AuthHelpers;
use EduCloud\Tests\Support\DatasetHelpers;
use EduCloud\Tests\Support\LabHelpers;
use EduCloud\Tests\TestCase;

/**
 * Minimal courses (M10a): creation in an organization, join codes, lab assignments, course lab attempts and the
 * instructor progress grid with course-scoped review.
 */
final class CourseFlowTest extends TestCase
{
    use AuthHelpers;
    use ApiActors;
    use DatasetHelpers;
    use LabHelpers;

    /** @var array{id: int, public_id: string} */
    private array $org = ['id' => 0, 'public_id' => ''];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetDatabase();
        $this->importLabs();
        $this->org = $this->createOrgTenant('Universidad');
        $members = [
            'titular' => 'instructor',
            'otroprof' => 'instructor',
            'colega' => 'student',
            'lector' => 'read_only',
            'directora' => 'org_admin',
        ];
        foreach ($members as $user => $role) {
            $this->createVerifiedUser("$user@test.example");
            $this->addMember($this->org['id'], "$user@test.example", $role);
        }
    }

    /** Session in the organization; returns a sender bound to its CSRF token. */
    private function inOrg(string $user): callable
    {
        $csrf = $this->sessionIn("$user@test.example", $this->org['public_id']);
        $jar = $this->cookieJar; // each actor keeps its own browser cookies
        return function (string $method, string $path, ?array $body = null) use ($csrf, &$jar): Response {
            $previous = $this->cookieJar;
            $this->cookieJar = $jar;
            try {
                return $this->request($method, $path, $body, $method === 'GET' ? [] : ['X-CSRF-Token' => $csrf]);
            } finally {
                $jar = $this->cookieJar;
                $this->cookieJar = $previous;
            }
        };
    }

    /** @return array{0: string, 1: string} [course id, join code] - published course with LAB-001 assigned */
    private function publishedCourse(callable $teacher): array
    {
        $created = $teacher('POST', '/api/v1/courses', ['code' => 'DATA-101', 'title' => 'Ingeniería de datos', 'description' => 'Curso de prueba']);
        self::assertSame(201, $created->status, $created->body);
        $id = (string) $created->decoded()['data']['id'];
        self::assertSame(201, $teacher('POST', "/api/v1/courses/$id/labs", ['lab_code' => 'LAB-001', 'due_at' => '2026-12-15'])->status);
        self::assertSame(200, $teacher('PATCH', "/api/v1/courses/$id", ['status' => 'published'])->status);
        $code = $teacher('POST', "/api/v1/courses/$id/join-code", []);
        self::assertSame(201, $code->status, $code->body);
        return [$id, (string) $code->decoded()['data']['join_code']];
    }

    public function testInstructorCreatesAndManagesACourse(): void
    {
        $teacher = $this->inOrg('titular');
        $bad = $teacher('POST', '/api/v1/courses', ['code' => 'data 101', 'title' => 'x']);
        self::assertSame(422, $bad->status);
        self::assertEqualsCanonicalizing(['code', 'title'], array_column($bad->decoded()['error']['details'], 'field'));

        $r = $teacher('POST', '/api/v1/courses', ['code' => 'DATA-101', 'title' => 'Ingeniería de datos']);
        self::assertSame(201, $r->status, $r->body);
        $course = $r->decoded()['data'];
        self::assertSame(['draft', 'staff', true, 0], [$course['status'], $course['my_role'], $course['can_manage'], $course['student_count']]);
        self::assertSame(409, $teacher('POST', '/api/v1/courses', ['code' => 'DATA-101', 'title' => 'Otro'])->status, 'code unique per organization');

        $id = $course['id'];
        self::assertSame(409, $teacher('POST', "/api/v1/courses/$id/join-code", [])->status, 'drafts cannot be joined');
        $assign = $teacher('POST', "/api/v1/courses/$id/labs", ['lab_code' => 'LAB-004', 'due_at' => '2026-12-01']);
        self::assertSame(201, $assign->status, $assign->body);
        self::assertSame('2026-12-01T23:59:59.000Z', $assign->decoded()['data']['labs'][0]['due_at']);
        self::assertSame(409, $teacher('POST', "/api/v1/courses/$id/labs", ['lab_code' => 'LAB-004'])->status);
        self::assertSame(422, $teacher('POST', "/api/v1/courses/$id/labs", ['lab_code' => 'LAB-999'])->status);
        self::assertSame(422, $teacher('POST', "/api/v1/courses/$id/labs", ['lab_code' => 'LAB-003', 'due_at' => '2026-02-30'])->status);
        self::assertSame(204, $teacher('DELETE', "/api/v1/courses/$id/labs/LAB-004")->status);
        self::assertSame(404, $teacher('DELETE', "/api/v1/courses/$id/labs/LAB-004")->status);

        self::assertSame(200, $teacher('PATCH', "/api/v1/courses/$id", ['status' => 'published'])->status);
        self::assertSame(409, $teacher('PATCH', "/api/v1/courses/$id", ['status' => 'draft'])->status);
        $code = $teacher('POST', "/api/v1/courses/$id/join-code", []);
        self::assertSame(201, $code->status);
        self::assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{5}-[A-HJ-NP-Z2-9]{5}$/D', $code->decoded()['data']['join_code']);
        $stored = $this->app()->db()->selectOne('SELECT join_code_hash, join_enabled FROM courses');
        self::assertSame(32, strlen((string) $stored['join_code_hash']), 'only the hash is stored');
        self::assertStringNotContainsString(str_replace('-', '', $code->decoded()['data']['join_code']), (string) $stored['join_code_hash']);
        self::assertTrue($teacher('GET', "/api/v1/courses/$id")->decoded()['data']['join_enabled']);
        self::assertStringNotContainsString('join_code', $teacher('GET', "/api/v1/courses/$id")->body);

        self::assertSame(200, $teacher('GET', "/app/courses/$id")->status);
        self::assertSame(200, $teacher('GET', '/app/courses')->status);
    }

    public function testCoursesNeedAnOrganizationAndTheAssignPermission(): void
    {
        $this->cookieJar = [];
        $csrf = $this->login('titular@test.example'); // personal tenant
        $r = $this->request('POST', '/api/v1/courses', ['code' => 'X-1', 'title' => 'Personal'], ['X-CSRF-Token' => $csrf]);
        self::assertSame(409, $r->status);
        self::assertSame('ORGANIZATION_REQUIRED', $r->decoded()['error']['code']);

        $student = $this->inOrg('colega');
        self::assertSame(403, $student('POST', '/api/v1/courses', ['code' => 'X-1', 'title' => 'Alumno'])->status);
        $reader = $this->inOrg('lector');
        self::assertSame(403, $reader('POST', '/api/v1/courses', ['code' => 'X-1', 'title' => 'Lector'])->status);
        self::assertSame(0, (int) $this->app()->db()->scalar('SELECT COUNT(*) FROM courses'));
    }

    public function testStudentJoinsWithACodeFromTheirPersonalTenant(): void
    {
        [$courseId, $code] = $this->publishedCourse($this->inOrg('titular'));
        $ana = $this->actor('ana@test.example'); // personal tenant, not a member of the organization

        self::assertSame(422, $this->as($ana, 'POST', '/api/v1/courses/join', ['code' => 'AAAAA-AAAAA'])->status);
        $joined = $this->as($ana, 'POST', '/api/v1/courses/join', ['code' => strtolower(str_replace('-', ' ', $code))]);
        self::assertSame(200, $joined->status, $joined->body);
        self::assertSame($courseId, $joined->decoded()['data']['course']['id']);
        self::assertSame($this->org['public_id'], $joined->decoded()['data']['tenant']['id']);
        self::assertFalse($joined->decoded()['data']['already_enrolled']);
        self::assertSame('student', $this->app()->db()->scalar(
            'SELECT role FROM memberships WHERE tenant_id = ? AND user_id = ?',
            [$this->org['id'], $this->userId('ana@test.example')]
        ));
        self::assertTrue($this->as($ana, 'POST', '/api/v1/courses/join', ['code' => $code])->decoded()['data']['already_enrolled']);

        // An existing role is never downgraded or upgraded by joining.
        $colega = $this->actor('colega@test.example');
        self::assertSame(200, $this->as($colega, 'POST', '/api/v1/courses/join', ['code' => $code])->status);
        $this->actor('directora@test.example');
        $role = $this->app()->db()->scalar(
            'SELECT role FROM memberships WHERE tenant_id = ? AND user_id = ?',
            [$this->org['id'], $this->userId('directora@test.example')]
        );
        self::assertSame('org_admin', $role);

        // The owner cannot enroll as a student; rotating or disabling the code stops old codes.
        $titular = $this->actor('titular@test.example');
        self::assertSame(409, $this->as($titular, 'POST', '/api/v1/courses/join', ['code' => $code])->status);
        $teacher = $this->inOrg('titular');
        self::assertSame(201, $teacher('POST', "/api/v1/courses/$courseId/join-code", [])->status);
        $bea = $this->actor('bea@test.example');
        self::assertSame(422, $this->as($bea, 'POST', '/api/v1/courses/join', ['code' => $code])->status, 'rotated code');
        self::assertSame(2, (int) $teacher('GET', "/api/v1/courses/$courseId")->decoded()['data']['student_count']);
        self::assertSame(204, $teacher('DELETE', "/api/v1/courses/$courseId/join-code")->status);
        self::assertFalse($teacher('GET', "/api/v1/courses/$courseId")->decoded()['data']['join_enabled']);
    }

    public function testSuspendedMembersAndDroppedStudentsLookLikeAWrongCode(): void
    {
        [$courseId, $code] = $this->publishedCourse($this->inOrg('titular'));
        $ana = $this->actor('ana@test.example');
        self::assertSame(200, $this->as($ana, 'POST', '/api/v1/courses/join', ['code' => $code])->status);
        $this->app()->db()->execute("UPDATE enrollments SET status = 'dropped' WHERE user_id = ?", [$this->userId('ana@test.example')]);
        $dropped = $this->as($ana, 'POST', '/api/v1/courses/join', ['code' => $code]);
        self::assertSame(422, $dropped->status);
        $status = $this->app()->db()->scalar('SELECT status FROM enrollments WHERE user_id = ?', [$this->userId('ana@test.example')]);
        self::assertSame('dropped', $status);

        $colega = $this->actor('colega@test.example');
        $this->app()->db()->execute(
            "UPDATE memberships SET status = 'suspended' WHERE user_id = ? AND tenant_id = ?",
            [$this->userId('colega@test.example'), $this->org['id']]
        );
        $valid = $this->as($colega, 'POST', '/api/v1/courses/join', ['code' => $code]);
        $invalid = $this->as($colega, 'POST', '/api/v1/courses/join', ['code' => 'AAAAA-AAAAA']);
        self::assertSame([422, 422], [$valid->status, $invalid->status]);
        self::assertSame($invalid->decoded()['error'], $valid->decoded()['error'], 'no difference between a valid and a wrong code');
        self::assertSame('suspended', $this->app()->db()->scalar('SELECT status FROM memberships WHERE user_id = ? AND tenant_id = ?', [
            $this->userId('colega@test.example'), $this->org['id'],
        ]));
        self::assertNotEmpty($courseId);
    }

    public function testJoinCodeGuessingIsRateLimited(): void
    {
        $ana = $this->actor('ana@test.example');
        $statuses = [];
        for ($i = 0; $i < 11; $i++) {
            $statuses[] = $this->as($ana, 'POST', '/api/v1/courses/join', ['code' => sprintf('ZZZZZ-%05d', $i)])->status;
        }
        self::assertSame(array_fill(0, 10, 422), array_slice($statuses, 0, 10));
        self::assertSame(429, $statuses[10]);
        $failures = "SELECT COUNT(*) FROM audit_logs WHERE action = 'course.join' AND outcome = 'failure'";
        self::assertSame(10, (int) $this->app()->db()->scalar($failures));
    }

    public function testCourseLabAttemptsAndCourseScopedReview(): void
    {
        $teacher = $this->inOrg('titular');
        [$courseId, $code] = $this->publishedCourse($teacher);
        $ana = $this->actor('ana@test.example');
        $this->as($ana, 'POST', '/api/v1/courses/join', ['code' => $code]);

        $student = $this->inOrg('ana');
        self::assertSame(1, count($student('GET', '/api/v1/courses')->decoded()['data']));
        $view = $student('GET', "/api/v1/courses/$courseId")->decoded()['data'];
        self::assertSame(['student', false, null, null], [$view['my_role'], $view['can_manage'], $view['student_count'], $view['join_enabled']]);
        self::assertSame(422, $student('POST', '/api/v1/lab-attempts', ['lab_code' => 'LAB-004', 'course_id' => $courseId])->status, 'not assigned');
        self::assertSame(403, $student('GET', "/api/v1/courses/$courseId/progress")->status);
        self::assertSame(403, $student('PATCH', "/api/v1/courses/$courseId", ['title' => 'Mío'])->status);

        $start = $student('POST', '/api/v1/lab-attempts', ['lab_code' => 'LAB-001']);
        self::assertSame(201, $start->status, $start->body);
        $attempt = $start->decoded()['data'];
        self::assertSame(['id' => $courseId, 'title' => 'Ingeniería de datos'], $attempt['course'], 'attached to the only course assigning the lab');
        $ws = $attempt['workspace']['id'];
        $student('POST', "/api/v1/workspaces/$ws/resources", ['type' => 'storage', 'name' => 'datos-crudos', 'tags' => ['proyecto' => 'retail']]);
        self::assertSame(202, $student('POST', '/api/v1/lab-attempts/' . $attempt['id'] . '/submit', [])->status);
        $this->runJobs();

        $grid = $teacher('GET', "/api/v1/courses/$courseId/progress");
        self::assertSame(200, $grid->status, $grid->body);
        $data = $grid->decoded()['data'];
        self::assertSame(['LAB-001'], array_column($data['labs'], 'code'));
        self::assertCount(1, $data['students']);
        $cell = $data['students'][0]['labs']['LAB-001'];
        self::assertSame($attempt['id'], $cell['attempt_id']);
        self::assertEquals(25, $cell['best_score']);
        self::assertSame(3, $cell['failed_tasks']);
        self::assertEquals(['started' => 1, 'completed' => 0, 'average_best_score' => 25], $data['summary']['LAB-001']);
        self::assertSame(200, $teacher('GET', "/app/courses/$courseId/progress")->status);

        // Drill-down: the course's teacher reviews the attempt read-only; another instructor cannot see it.
        $review = $teacher('GET', '/api/v1/lab-attempts/' . $attempt['id']);
        self::assertSame(200, $review->status);
        self::assertFalse($review->decoded()['data']['is_owner']);
        self::assertSame(200, $teacher('GET', '/app/lab-attempts/' . $attempt['id'])->status);
        self::assertSame(403, $teacher('POST', '/api/v1/lab-attempts/' . $attempt['id'] . '/submit', [])->status);

        $other = $this->inOrg('otroprof');
        self::assertSame(404, $other('GET', '/api/v1/lab-attempts/' . $attempt['id'])->status);
        self::assertSame(404, $other('GET', "/api/v1/courses/$courseId/progress")->status);
        self::assertSame([], $other('GET', '/api/v1/courses')->decoded()['data']);

        $admin = $this->inOrg('directora');
        self::assertSame(200, $admin('GET', "/api/v1/courses/$courseId/progress")->status, 'org_admin sees every course');

        // A personal attempt (no course) stays private even from the course's teacher.
        $personal = $this->startLab($ana, 'LAB-003');
        self::assertNull($personal['course']);
        self::assertSame(404, $teacher('GET', '/api/v1/lab-attempts/' . $personal['id'])->status);
    }

    public function testArchivedAndDraftCourses(): void
    {
        $teacher = $this->inOrg('titular');
        [$courseId, $code] = $this->publishedCourse($teacher);
        $ana = $this->actor('ana@test.example');
        $this->as($ana, 'POST', '/api/v1/courses/join', ['code' => $code]);
        self::assertSame(200, $teacher('PATCH', "/api/v1/courses/$courseId", ['status' => 'archived'])->status);
        self::assertSame(422, $this->as($this->actor('bea@test.example'), 'POST', '/api/v1/courses/join', ['code' => $code])->status);

        $student = $this->inOrg('ana');
        self::assertSame(200, $student('GET', "/api/v1/courses/$courseId")->status, 'archived courses stay readable');
        $start = $student('POST', '/api/v1/lab-attempts', ['lab_code' => 'LAB-001', 'course_id' => $courseId]);
        self::assertSame(409, $start->status);
        self::assertSame('NOT_ENROLLED', $start->decoded()['error']['code']);

        $draft = $teacher('POST', '/api/v1/courses', ['code' => 'DRAFT-1', 'title' => 'Borrador']);
        $draftId = (string) $draft->decoded()['data']['id'];
        $this->app()->db()->execute(
            "INSERT INTO enrollments (tenant_id, course_id, user_id, role) SELECT tenant_id, id, ?, 'student' FROM courses WHERE public_id = ?",
            [$this->userId('ana@test.example'), $draftId]
        );
        self::assertSame(404, $student('GET', "/api/v1/courses/$draftId")->status, 'students never see drafts');
    }
}
