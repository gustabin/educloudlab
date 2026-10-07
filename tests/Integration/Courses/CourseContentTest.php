<?php

declare(strict_types=1);

namespace EduCloud\Tests\Integration\Courses;

use EduCloud\Core\Response;
use EduCloud\Modules\Jobs\Maintenance;
use EduCloud\Tests\Support\ApiActors;
use EduCloud\Tests\Support\AuthHelpers;
use EduCloud\Tests\Support\LabHelpers;
use EduCloud\Tests\TestCase;

/** Course modules, lessons, completion and notifications (M10b). */
final class CourseContentTest extends TestCase
{
    use AuthHelpers;
    use ApiActors;
    use LabHelpers;

    /** @var array{id: int, public_id: string} */
    private array $org = ['id' => 0, 'public_id' => ''];
    private string $course = '';
    /** @var callable(string, string, array<string, mixed>|null=): Response */
    private $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetDatabase();
        $this->importLabs();
        $this->org = $this->createOrgTenant('Universidad');
        foreach (['titular' => 'instructor', 'otroprof' => 'instructor', 'lector' => 'read_only'] as $user => $role) {
            $this->createVerifiedUser("$user@test.example");
            $this->addMember($this->org['id'], "$user@test.example", $role);
        }
        $this->teacher = $this->inOrg('titular');
        $created = ($this->teacher)('POST', '/api/v1/courses', ['code' => 'DATA-201', 'title' => 'Datos II']);
        self::assertSame(201, $created->status, $created->body);
        $this->course = (string) $created->decoded()['data']['id'];
        self::assertSame(201, ($this->teacher)('POST', "/api/v1/courses/{$this->course}/labs", ['lab_code' => 'LAB-004'])->status);
    }

    private function inOrg(string $user): callable
    {
        $csrf = $this->sessionIn("$user@test.example", $this->org['public_id']);
        $jar = $this->cookieJar;
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

    /** Publishes the course and enrolls a student through a join code; returns a session sender in the organization. */
    private function student(string $email = 'ana@test.example'): callable
    {
        if (($this->teacher)('GET', "/api/v1/courses/{$this->course}")->decoded()['data']['status'] === 'draft') {
            self::assertSame(200, ($this->teacher)('PATCH', "/api/v1/courses/{$this->course}", ['status' => 'published'])->status);
        }
        $code = (string) ($this->teacher)('POST', "/api/v1/courses/{$this->course}/join-code", [])->decoded()['data']['join_code'];
        $token = $this->actor($email);
        self::assertSame(200, $this->as($token, 'POST', '/api/v1/courses/join', ['code' => $code])->status);
        // Joining created the organization membership: act through a browser session in that tenant.
        return $this->inOrg(explode('@', $email)[0]);
    }

    /** @return array{module: string, lesson: string} */
    private function content(): array
    {
        $m = ($this->teacher)('POST', "/api/v1/courses/{$this->course}/modules", ['title' => 'Fundamentos', 'summary' => 'Primeros pasos']);
        self::assertSame(201, $m->status, $m->body);
        $module = (string) $m->decoded()['data']['id'];
        $l = ($this->teacher)('POST', "/api/v1/course-modules/$module/lessons", [
            'title' => 'Qué es un data lake', 'body_md' => "## Capas\n\nraw → bronze → silver → gold", 'estimated_minutes' => 15,
            'lab_code' => 'LAB-004',
        ]);
        self::assertSame(201, $l->status, $l->body);
        return ['module' => $module, 'lesson' => (string) $l->decoded()['data']['id']];
    }

    public function testStaffBuildsContentAndStudentsSeeOnlyWhatIsPublished(): void
    {
        ['module' => $module, 'lesson' => $lesson] = $this->content();
        $second = ($this->teacher)('POST', "/api/v1/course-modules/$module/lessons", ['title' => 'Capa bronze', 'body_md' => 'Texto']);
        $lesson2 = (string) $second->decoded()['data']['id'];

        // Validation and the lab link.
        $body = ['title' => 'x', 'body_md' => str_repeat('a', 50001), 'lab_code' => 'LAB-001'];
        $bad = ($this->teacher)('POST', "/api/v1/course-modules/$module/lessons", $body);
        self::assertSame(422, $bad->status);
        self::assertEqualsCanonicalizing(['title', 'body_md'], array_column($bad->decoded()['error']['details'], 'field'));
        $notAssigned = ($this->teacher)('PATCH', "/api/v1/lessons/$lesson2", ['lab_code' => 'LAB-001']);
        self::assertSame(422, $notAssigned->status);
        self::assertSame('lab_code', $notAssigned->decoded()['error']['details'][0]['field']);

        $ana = $this->student();
        // Everything is a draft: the student sees no module and no lesson (404, like a missing id).
        self::assertSame([], $ana('GET', "/api/v1/courses/{$this->course}/modules")->decoded()['data']);
        self::assertSame(404, $ana('GET', "/api/v1/lessons/$lesson")->status);

        self::assertSame(200, ($this->teacher)('PATCH', "/api/v1/lessons/$lesson", ['status' => 'published'])->status);
        self::assertSame(404, $ana('GET', "/api/v1/lessons/$lesson")->status, 'module still a draft');
        self::assertSame(200, ($this->teacher)('PATCH', "/api/v1/course-modules/$module", ['status' => 'published'])->status);

        $tree = $ana('GET', "/api/v1/courses/{$this->course}/modules")->decoded()['data'];
        self::assertSame(['Fundamentos'], array_column($tree, 'title'));
        self::assertSame(['Qué es un data lake'], array_column($tree[0]['lessons'], 'title'), 'the draft lesson stays hidden');
        self::assertNull($tree[0]['status'], 'students do not see editorial status');
        self::assertSame(404, $ana('GET', "/api/v1/lessons/$lesson2")->status);

        $view = $ana('GET', "/api/v1/lessons/$lesson")->decoded()['data'];
        self::assertStringContainsString('<h2>Capas</h2>', $view['html']);
        self::assertNull($view['body_md'], 'students get the rendered HTML only');
        self::assertSame(['code' => 'LAB-004', 'title' => 'Construye un data lake'], $view['lab']);
        self::assertNull($view['next_id']);
        self::assertSame($lesson2, ($this->teacher)('GET', "/api/v1/lessons/$lesson")->decoded()['data']['next_id']);

        // Students cannot manage content (403 for every id, route-level permission).
        self::assertSame(403, $ana('PATCH', "/api/v1/lessons/$lesson", ['title' => 'Hackeado'])->status);
        self::assertSame(403, $ana('POST', "/api/v1/courses/{$this->course}/modules", ['title' => 'Otro'])->status);
    }

    public function testOrderingAndDeletionGuards(): void
    {
        $ids = [];
        foreach (['Uno', 'Dos', 'Tres'] as $title) {
            $ids[] = (string) ($this->teacher)('POST', "/api/v1/courses/{$this->course}/modules", ['title' => $title])->decoded()['data']['id'];
        }
        self::assertSame(200, ($this->teacher)('PATCH', "/api/v1/course-modules/{$ids[2]}", ['position' => 1])->status);
        $tree = ($this->teacher)('GET', "/api/v1/courses/{$this->course}/modules")->decoded()['data'];
        self::assertSame(['Tres', 'Uno', 'Dos'], array_column($tree, 'title'));
        self::assertSame([1, 2, 3], array_column($tree, 'position'));
        self::assertSame(422, ($this->teacher)('PATCH', "/api/v1/course-modules/{$ids[0]}", ['position' => 0])->status);
        self::assertSame(422, ($this->teacher)('PATCH', "/api/v1/course-modules/{$ids[0]}", [])->status);

        ($this->teacher)('POST', "/api/v1/course-modules/{$ids[0]}/lessons", ['title' => 'Lección', 'body_md' => 'x']);
        $r = ($this->teacher)('DELETE', "/api/v1/course-modules/{$ids[0]}");
        self::assertSame(409, $r->status);
        self::assertSame('MODULE_NOT_EMPTY', $r->decoded()['error']['code']);
        self::assertSame(204, ($this->teacher)('DELETE', "/api/v1/course-modules/{$ids[1]}")->status);

        // An instructor who does not teach this course sees nothing (404).
        $other = $this->inOrg('otroprof');
        self::assertSame(404, $other('GET', "/api/v1/courses/{$this->course}/modules")->status);
        self::assertSame(404, $other('PATCH', "/api/v1/course-modules/{$ids[0]}", ['title' => 'Hackeado'])->status);

        // A read_only member: no permission to manage content (403 whatever the id) and no visibility (404).
        $lector = $this->inOrg('lector');
        self::assertSame(403, $lector('PATCH', "/api/v1/course-modules/{$ids[0]}", ['title' => 'Hackeado'])->status);
        self::assertSame(403, $lector('POST', "/api/v1/courses/{$this->course}/modules", ['title' => 'Intruso'])->status);
        self::assertSame(404, $lector('GET', "/api/v1/courses/{$this->course}/modules")->status);
        $tree = ($this->teacher)('GET', "/api/v1/courses/{$this->course}/modules")->decoded()['data'];
        self::assertSame(['Tres', 'Uno'], array_column($tree, 'title'));
    }

    public function testCompletionFeedsTheProgressGrid(): void
    {
        ['module' => $module, 'lesson' => $lesson] = $this->content();
        ($this->teacher)('PATCH', "/api/v1/course-modules/$module", ['status' => 'published']);
        ($this->teacher)('PATCH', "/api/v1/lessons/$lesson", ['status' => 'published']);
        $ana = $this->student();

        $done = $ana('POST', "/api/v1/lessons/$lesson/complete", []);
        self::assertSame(200, $done->status, $done->body);
        self::assertTrue($done->decoded()['data']['completed']);
        self::assertSame(200, $ana('POST', "/api/v1/lessons/$lesson/complete", [])->status, 'idempotent');
        self::assertSame(409, ($this->teacher)('POST', "/api/v1/lessons/$lesson/complete", [])->status, 'staff do not track progress');

        $grid = ($this->teacher)('GET', "/api/v1/courses/{$this->course}/progress")->decoded()['data'];
        self::assertSame(1, $grid['lessons_total']);
        self::assertSame(1, $grid['students'][0]['lessons_completed']);

        self::assertFalse($ana('DELETE', "/api/v1/lessons/$lesson/complete")->decoded()['data']['completed']);
        $grid = ($this->teacher)('GET', "/api/v1/courses/{$this->course}/progress")->decoded()['data'];
        self::assertSame(0, $grid['students'][0]['lessons_completed']);
    }

    public function testNotificationsOnPublishAndDueReminders(): void
    {
        ['module' => $module, 'lesson' => $lesson] = $this->content();
        $ana = $this->student();
        ($this->teacher)('PATCH', "/api/v1/lessons/$lesson", ['status' => 'published']);
        self::assertSame(0, (int) $this->app()->db()->scalar('SELECT COUNT(*) FROM notifications'), 'module still a draft');

        ($this->teacher)('PATCH', "/api/v1/course-modules/$module", ['status' => 'published']);
        $list = $ana('GET', '/api/v1/notifications');
        self::assertSame(1, $list->decoded()['meta']['unread']);
        $n = $list->decoded()['data'][0];
        self::assertSame(['lesson_published', 'Nueva lección: Qué es un data lake', false], [$n['kind'], $n['title'], $n['read']]);
        self::assertStringEndsWith("/app/lessons/$lesson", (string) $n['url']);

        // Unpublishing and publishing again does not notify twice.
        ($this->teacher)('PATCH', "/api/v1/course-modules/$module", ['status' => 'draft']);
        ($this->teacher)('PATCH', "/api/v1/course-modules/$module", ['status' => 'published']);
        self::assertSame(1, (int) $this->app()->db()->scalar('SELECT COUNT(*) FROM notifications'));

        // Only the owner can read their notification (404 for anyone else).
        $titular = $this->teacher;
        self::assertSame(404, $titular('POST', "/api/v1/notifications/{$n['id']}/read", [])->status);
        self::assertSame([], $titular('GET', '/api/v1/notifications')->decoded()['data']);
        self::assertSame(204, $ana('POST', "/api/v1/notifications/{$n['id']}/read", [])->status);
        self::assertSame(0, $ana('GET', '/api/v1/notifications')->decoded()['meta']['unread']);

        // Due within 24 h and not completed: one reminder for the lesson and one for the course lab.
        $soon = gmdate('Y-m-d H:i:s', time() + 6 * 3600);
        $this->app()->db()->execute('UPDATE course_lessons SET due_at = ?', [$soon]);
        $this->app()->db()->execute('UPDATE course_labs SET due_at = ?', [$soon]);
        $result = (new Maintenance($this->app()))->run()['notifications'];
        self::assertSame(['lesson_reminders' => 1, 'lab_reminders' => 1, 'purged' => 0], $result);
        self::assertSame(['lesson_reminders' => 0, 'lab_reminders' => 0, 'purged' => 0], (new Maintenance($this->app()))->run()['notifications']);
        $kinds = array_column($ana('GET', '/api/v1/notifications?unread=1')->decoded()['data'], 'kind');
        self::assertEqualsCanonicalizing(['lesson_due', 'lab_due'], $kinds);

        self::assertSame(2, $ana('POST', '/api/v1/notifications/read-all', [])->decoded()['data']['marked']);
        $this->app()->db()->execute('UPDATE notifications SET read_at = UTC_TIMESTAMP(3) - INTERVAL 91 DAY');
        self::assertSame(3, (new Maintenance($this->app()))->run()['notifications']['purged']);
    }

    public function testOptionalFieldsCanBeClearedAndUnassignedLabsAreUnlinked(): void
    {
        ['module' => $module, 'lesson' => $lesson] = $this->content();
        $set = ($this->teacher)('PATCH', "/api/v1/lessons/$lesson", ['due_at' => '2026-12-01']);
        self::assertSame('2026-12-01T23:59:59.000Z', $set->decoded()['data']['due_at']);
        $cleared = ($this->teacher)('PATCH', "/api/v1/lessons/$lesson", ['due_at' => null, 'estimated_minutes' => null, 'lab_code' => '']);
        self::assertSame(200, $cleared->status, $cleared->body);
        self::assertSame([null, null, null], [$cleared->decoded()['data']['due_at'], $cleared->decoded()['data']['estimated_minutes'],
            $cleared->decoded()['data']['lab']]);
        self::assertNull(($this->teacher)('PATCH', "/api/v1/course-modules/$module", ['summary' => null])->decoded()['data']['summary']);

        // Unassigning a lab from the course unlinks it from its lessons (lessons only link assigned labs).
        ($this->teacher)('PATCH', "/api/v1/lessons/$lesson", ['lab_code' => 'LAB-004']);
        self::assertSame(204, ($this->teacher)('DELETE', "/api/v1/courses/{$this->course}/labs/LAB-004")->status);
        self::assertNull(($this->teacher)('GET', "/api/v1/lessons/$lesson")->decoded()['data']['lab']);

        // read_only members cannot record progress (same permission as starting a lab).
        self::assertSame(403, $this->inOrg('lector')('POST', "/api/v1/lessons/$lesson/complete", [])->status);
    }

    public function testArchivedCoursesDoNotNotify(): void
    {
        ['module' => $module, 'lesson' => $lesson] = $this->content();
        $this->student();
        self::assertSame(200, ($this->teacher)('PATCH', "/api/v1/courses/{$this->course}", ['status' => 'archived'])->status);
        ($this->teacher)('PATCH', "/api/v1/lessons/$lesson", ['status' => 'published']);
        ($this->teacher)('PATCH', "/api/v1/course-modules/$module", ['status' => 'published']);
        self::assertSame(0, (int) $this->app()->db()->scalar('SELECT COUNT(*) FROM notifications'));
        // Reopening the course makes the lesson visible: the students are notified then.
        self::assertSame(200, ($this->teacher)('PATCH', "/api/v1/courses/{$this->course}", ['status' => 'published'])->status);
        self::assertSame(1, (int) $this->app()->db()->scalar('SELECT COUNT(*) FROM notifications'));
    }

    public function testLessonMarkdownIsSafeAndPagesRender(): void
    {
        ['module' => $module, 'lesson' => $lesson] = $this->content();
        $payload = "<script>alert(1)</script>\n\n[clic](javascript:alert(2)) <img src=x onerror=alert(3)>";
        self::assertSame(200, ($this->teacher)('PATCH', "/api/v1/lessons/$lesson", ['body_md' => $payload, 'status' => 'published'])->status);
        ($this->teacher)('PATCH', "/api/v1/course-modules/$module", ['status' => 'published']);
        self::assertSame(422, ($this->teacher)('PATCH', "/api/v1/lessons/$lesson", ['body_md' => "a\x07b"])->status);

        $this->student('bea@test.example');
        $this->cookieJar = [];
        $csrf = $this->sessionIn('bea@test.example', $this->org['public_id']);
        self::assertNotSame('', $csrf);
        $page = $this->request('GET', "/app/lessons/$lesson");
        self::assertSame(200, $page->status);
        self::assertStringNotContainsString('<script>alert(1)', $page->body);
        self::assertStringNotContainsString('<img src=x', $page->body);
        self::assertStringNotContainsString('javascript:alert', $page->body);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $page->body);
        self::assertStringContainsString('id="lesson-complete"', $page->body);

        $coursePage = $this->request('GET', "/app/courses/{$this->course}");
        self::assertStringContainsString('id="course-content"', $coursePage->body);
        self::assertStringContainsString("/app/lessons/$lesson", $coursePage->body);
        self::assertStringContainsString('id="ec-notifications"', $coursePage->body, 'notification bell in the layout');
    }
}
