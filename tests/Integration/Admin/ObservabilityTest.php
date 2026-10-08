<?php

declare(strict_types=1);

namespace EduCloud\Tests\Integration\Admin;

use EduCloud\Core\App;
use EduCloud\Modules\Jobs\Maintenance;
use EduCloud\Modules\Observability\Heartbeat;
use EduCloud\Tests\Support\ApiActors;
use EduCloud\Tests\Support\AuthHelpers;
use EduCloud\Tests\Support\DatasetHelpers;
use EduCloud\Tests\TestCase;

/** Observability (M11b): request metrics by route name, heartbeats and health, log lookup, retention. */
final class ObservabilityTest extends TestCase
{
    use AuthHelpers;
    use ApiActors;
    use DatasetHelpers;

    private string $admin = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetDatabase();
        $this->createVerifiedUser('root@test.example');
        $this->app()->db()->execute("UPDATE users SET is_platform_admin = 1 WHERE email = 'root@test.example'");
        $this->admin = $this->actor('root@test.example');
    }

    private function withConfig(string $key, mixed $value): void
    {
        $this->app = App::create($this->app()->config->with($key, $value), testDatabase: true);
    }

    public function testOnlyPlatformAdminsSeeObservability(): void
    {
        $ana = $this->actor('ana@test.example');
        foreach (['/api/v1/admin/health', '/api/v1/admin/metrics', '/api/v1/admin/logs'] as $path) {
            $this->cookieJar = [];
            self::assertSame(401, $this->request('GET', $path)->status, "$path anonymous");
            self::assertSame(403, $this->as($ana, 'GET', $path)->status, $path);
            self::assertSame(200, $this->as($this->admin, 'GET', $path)->status, $path);
        }
    }

    public function testRequestsAreRecordedByRouteNameNeverByUrl(): void
    {
        $ana = $this->actor('ana@test.example');
        $ws = (string) $this->createWorkspace($ana)['id'];
        self::assertSame(200, $this->as($ana, 'GET', "/api/v1/workspaces/$ws")->status);
        self::assertSame(404, $this->as($ana, 'GET', '/api/v1/workspaces/' . strtoupper(\EduCloud\Core\Ulid::generate()))->status);
        self::assertSame(404, $this->request('GET', "/api/v1/no-such-thing/$ws")->status);

        $rows = $this->app()->db()->select('SELECT route, method, status_class, requests, le_50 + le_100 + le_250 + le_500 + le_1000 + le_2500
            + le_5000 + le_inf AS histogram FROM request_metrics');
        $routes = array_column($rows, 'route');
        self::assertContains('workspaces.show', $routes);
        self::assertContains('workspaces.store', $routes);
        self::assertContains('_unmatched', $routes);
        foreach ($rows as $row) {
            self::assertSame((int) $row['requests'], (int) $row['histogram'], 'every request is in exactly one bucket');
            self::assertStringNotContainsString($ws, (string) $row['route']);
        }
        $show = array_values(array_filter($rows, static fn (array $r): bool => $r['route'] === 'workspaces.show'));
        self::assertEqualsCanonicalizing([2, 4], array_map('intval', array_column($show, 'status_class')));

        $report = $this->as($this->admin, 'GET', '/api/v1/admin/metrics?window=1h')->decoded()['data'];
        self::assertGreaterThanOrEqual(5, $report['http']['requests']);
        self::assertNotNull($report['http']['p95_ms']);
        self::assertContains('workspaces.show', array_column($report['http']['busiest_routes'], 'route'));
        self::assertNotEmpty($report['http']['series']);
        self::assertStringNotContainsString($ws, json_encode($report, JSON_THROW_ON_ERROR));
        self::assertSame(422, $this->as($this->admin, 'GET', '/api/v1/admin/metrics?window=30d')->status);
    }

    public function testRecordingCanBeDisabledAndSlowRequestsAreLogged(): void
    {
        $this->withConfig('observability.request_metrics', false);
        $this->withConfig('observability.slow_request_ms', -1);
        $before = (int) $this->app()->db()->scalar('SELECT COALESCE(SUM(requests), 0) FROM request_metrics');
        self::assertSame(200, $this->request('GET', '/api/v1/health')->status);
        self::assertSame($before, (int) $this->app()->db()->scalar('SELECT COALESCE(SUM(requests), 0) FROM request_metrics'));
        $slow = array_values(array_filter($this->logLines(), static fn (string $l): bool => str_contains($l, '"slow_request"')));
        self::assertNotEmpty($slow);
        self::assertStringContainsString('"route":"system.health"', $slow[0]);
    }

    public function testJobMetricsPerType(): void
    {
        $ana = $this->actor('ana@test.example');
        $ws = (string) $this->createWorkspace($ana)['id'];
        self::assertSame(202, $this->uploadAs($ana, $ws, 'a1', "x\n1\n")->status);
        self::assertSame(202, $this->uploadAs($ana, $ws, 'a2', "x\n1\n")->status);
        $db = $this->app()->db();
        $type = (string) $db->scalar('SELECT type FROM jobs LIMIT 1');
        // One job waited 2 s and ran 1.5 s; the other failed after waiting 4 s and running 0.5 s.
        $db->execute("UPDATE jobs SET status = 'succeeded', queued_at = UTC_TIMESTAMP(3) - INTERVAL 10 SECOND,
            started_at = UTC_TIMESTAMP(3) - INTERVAL 8 SECOND, finished_at = UTC_TIMESTAMP(3) - INTERVAL 6500000 MICROSECOND
            ORDER BY id LIMIT 1");
        $db->execute("UPDATE jobs SET status = 'failed', queued_at = UTC_TIMESTAMP(3) - INTERVAL 10 SECOND,
            started_at = UTC_TIMESTAMP(3) - INTERVAL 6 SECOND, finished_at = UTC_TIMESTAMP(3) - INTERVAL 5500000 MICROSECOND
            WHERE status = 'queued'");

        $jobs = $this->as($this->admin, 'GET', '/api/v1/admin/metrics')->decoded()['data']['jobs'];
        $row = array_values(array_filter($jobs, static fn (array $j): bool => $j['type'] === $type))[0];
        self::assertSame(2, $row['count']);
        self::assertSame(1, $row['failed']);
        self::assertSame(0.5, $row['failure_rate']);
        self::assertEqualsWithDelta(2000, $row['wait_p50_ms'], 5);
        self::assertEqualsWithDelta(4000, $row['wait_p95_ms'], 5);
        self::assertEqualsWithDelta(1500, $row['run_p95_ms'], 5);
    }

    public function testHealthReportsMissingStaleAndBackloggedComponents(): void
    {
        $this->app()->db()->execute('DELETE FROM email_outbox'); // registration emails of the test users
        $health = $this->as($this->admin, 'GET', '/api/v1/admin/health')->decoded()['data'];
        $byName = array_column($health['components'], null, 'name');
        self::assertSame('ok', $byName['database']['status']);
        self::assertSame('down', $byName['dispatcher']['status'], 'no heartbeat yet');
        self::assertSame('ok', $byName['mailer']['status'], 'not running, but nothing to send');
        self::assertSame('down', $health['status']);
        self::assertStringNotContainsString($this->storagePath, json_encode($health, JSON_THROW_ON_ERROR), 'no paths');

        (new Heartbeat($this->app(), 'dispatcher'))->tick(['processed' => 3, 'path' => 'C:/x'], true);
        (new Heartbeat($this->app(), 'scheduler'))->tick(['stale_jobs_failed' => 0], true);
        $byName = array_column($this->as($this->admin, 'GET', '/api/v1/admin/health')->decoded()['data']['components'], null, 'name');
        self::assertSame('ok', $byName['dispatcher']['status']);
        self::assertSame(['processed' => 3], $byName['dispatcher']['details']);
        self::assertSame('ok', $byName['scheduler']['status']);

        $db = $this->app()->db();
        $db->execute("UPDATE component_heartbeats SET last_seen_at = UTC_TIMESTAMP(3) - INTERVAL 10 MINUTE WHERE component = 'dispatcher'");
        $db->execute(
            "INSERT INTO email_outbox (to_email, template, send_after) VALUES ('x@test.example', 'verify_email', UTC_TIMESTAMP(3) - INTERVAL 1 HOUR)"
        );
        $byName = array_column($this->as($this->admin, 'GET', '/api/v1/admin/health')->decoded()['data']['components'], null, 'name');
        self::assertSame('down', $byName['dispatcher']['status'], 'stale heartbeat');
        self::assertSame('down', $byName['mailer']['status'], 'mail is waiting and no mailer is running');
        self::assertSame(1, $byName['mailer']['outbox_pending']);
    }

    public function testNotebookWorkerHealthFollowsTheMode(): void
    {
        $this->withConfig('execution.notebooks.mode', 'demo');
        $byName = array_column($this->as($this->admin, 'GET', '/api/v1/admin/health')->decoded()['data']['components'], null, 'name');
        self::assertSame('disabled', $byName['dispatcher_notebooks']['status']);

        $this->withConfig('execution.notebooks.mode', 'docker');
        (new Heartbeat($this->app(), 'dispatcher_notebooks'))->tick(['processed' => 0, 'docker' => false], true);
        $byName = array_column($this->as($this->admin, 'GET', '/api/v1/admin/health')->decoded()['data']['components'], null, 'name');
        self::assertSame('warning', $byName['dispatcher_notebooks']['status'], 'running, but the sandbox is unavailable');
    }

    public function testLogLookupNeverReturnsTheContext(): void
    {
        $rid = \EduCloud\Core\Ulid::generate();
        $this->app()->logger->setRequestId($rid);
        $this->app()->logger->info('lookup_probe', ['student_note' => 'zzz-private-value']);
        $this->app()->logger->error('lookup_failure', ['detail' => 'zzz-private-value']);
        $this->app()->logger->setRequestId('');

        $byRequest = $this->as($this->admin, 'GET', "/api/v1/admin/logs?request_id=$rid");
        self::assertSame(['lookup_failure', 'lookup_probe'], array_column($byRequest->decoded()['data'], 'event'), 'newest first');
        self::assertSame(['ts', 'level', 'event', 'request_id'], array_keys($byRequest->decoded()['data'][0]));
        self::assertStringNotContainsString('zzz-private-value', $byRequest->body);

        $errors = $this->as($this->admin, 'GET', '/api/v1/admin/logs?level=error')->decoded()['data'];
        self::assertContains('lookup_failure', array_column($errors, 'event'));
        self::assertNotContains('lookup_probe', array_column($errors, 'event'));
        self::assertSame(422, $this->as($this->admin, 'GET', '/api/v1/admin/logs?request_id=../../etc')->status);
        self::assertSame(422, $this->as($this->admin, 'GET', '/api/v1/admin/logs?level=debug')->status);
    }

    public function testRetentionPurgesOldMetricsAndOnlyAppLogs(): void
    {
        $db = $this->app()->db();
        $db->execute("INSERT INTO request_metrics (bucket_start, route, method, status_class, requests, le_50)
            VALUES (UTC_TIMESTAMP() - INTERVAL 20 DAY, 'system.health', 'GET', 2, 1, 1)");
        $logs = $this->app()->logger->directory();
        if (!is_dir($logs)) {
            mkdir($logs, 0700, true);
        }
        file_put_contents($logs . '/app-2020-01-01.log', "{}\n");
        file_put_contents($logs . '/notes-2020-01-01.log', "keep\n");
        $this->app()->logger->info('today'); // today's log stays

        $result = (new Maintenance($this->app()))->run();
        self::assertSame(1, $result['metrics_purged']);
        self::assertSame(1, $result['logs_removed']);
        self::assertFileDoesNotExist($logs . '/app-2020-01-01.log');
        self::assertFileExists($logs . '/notes-2020-01-01.log');
        self::assertFileExists($logs . '/app-' . gmdate('Y-m-d') . '.log');
    }
}
