<?php

declare(strict_types=1);

namespace EduCloud\Tests\Integration\Usage;

use EduCloud\Modules\Jobs\Maintenance;
use EduCloud\Tests\Support\ApiActors;
use EduCloud\Tests\Support\AuthHelpers;
use EduCloud\Tests\Support\DatasetHelpers;
use EduCloud\Tests\Support\LabHelpers;
use EduCloud\Tests\TestCase;

/** Storage quota incl. lakehouse tables, usage metering and the lab expiry warning (M11a). */
final class UsageQuotaTest extends TestCase
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
        $this->ana = $this->actor('ana@test.example');
    }

    /** Pretends the workspace lakehouse holds $bytes (sparse file: instant, no real disk usage). */
    private function fakeLakehouse(string $workspaceId, int $bytes): void
    {
        $tenant = (string) $this->as($this->ana, 'GET', '/api/v1/auth/me')->decoded()['data']['tenant']['id'];
        $file = $this->app()->storage()->lakehouseFile($tenant, $workspaceId);
        $this->app()->storage()->ensureDir(dirname($file));
        $h = fopen($file, 'c');
        self::assertNotFalse($h);
        ftruncate($h, $bytes);
        fclose($h);
    }

    public function testLakehouseTablesCountTowardsTheStorageQuota(): void
    {
        $ws = (string) $this->createWorkspace($this->ana)['id'];
        $quota = (int) $this->app()->config->get('quotas.storage_bytes_per_user');
        $usage = $this->as($this->ana, 'GET', '/api/v1/usage')->decoded()['data'];
        self::assertSame(['raw_bytes' => 0, 'lakehouse_bytes' => 0, 'used_bytes' => 0, 'quota_bytes' => $quota], $usage['storage']);

        $this->fakeLakehouse($ws, $quota - 10);
        self::assertSame($quota - 10, $this->as($this->ana, 'GET', '/api/v1/usage')->decoded()['data']['storage']['lakehouse_bytes']);
        $upload = $this->uploadAs($this->ana, $ws, 'clientes', self::customersCsv());
        self::assertSame(409, $upload->status, 'a 140-byte upload no longer fits');
        self::assertStringContainsString('lakehouse', $upload->decoded()['error']['message']);
        self::assertSame(0, (int) $this->app()->db()->scalar('SELECT COUNT(*) FROM datasets'));

        // Transforms (size unknown up front) are refused once the quota is reached.
        $this->createResource($this->ana, $ws, 'lakehouse', 'lago');
        $this->fakeLakehouse($ws, $quota);
        $t = $this->as($this->ana, 'POST', "/api/v1/workspaces/$ws/transforms", ['sql' => 'SELECT 1 AS x', 'layer' => 'silver', 'table' => 'x']);
        self::assertSame(409, $t->status, $t->body);
        self::assertSame('QUOTA_EXCEEDED', $t->decoded()['error']['code']);

        $this->importLabs();
        $lab = $this->as($this->ana, 'POST', '/api/v1/lab-attempts', ['lab_code' => 'LAB-003']);
        self::assertSame(409, $lab->status, 'lab samples do not fit either');
        self::assertSame(0, (int) $this->app()->db()->scalar('SELECT COUNT(*) FROM lab_attempts'));

        // The scheduler caches the gauge for reporting.
        $result = (new Maintenance($this->app()))->run();
        self::assertSame(1, $result['storage_gauges_refreshed']);
        $gauge = "SELECT value FROM usage_counters WHERE metric = 'storage_bytes' AND period = 'total'";
        self::assertSame($quota, (int) $this->app()->db()->scalar($gauge));
    }

    public function testJobsAreMeteredPerMonth(): void
    {
        $this->requireRunner();
        $ws = (string) $this->createWorkspace($this->ana)['id'];
        $this->createResource($this->ana, $ws, 'lakehouse', 'lago');
        $raw = $this->uploadAs($this->ana, $ws, 'clientes', self::customersCsv())->decoded()['data']['dataset']['id'];
        $this->runJobs();
        $this->as($this->ana, 'POST', "/api/v1/datasets/$raw/ingest", ['table_name' => 'clientes']);
        $this->runJobs();
        $this->as($this->ana, 'POST', "/api/v1/workspaces/$ws/queries", ['sql' => 'SELECT count(*) FROM bronze.clientes']);
        $this->runJobs();

        $usage = $this->as($this->ana, 'GET', '/api/v1/usage')->decoded()['data'];
        self::assertSame(['period' => gmdate('Y-m'), 'jobs' => 3, 'job_seconds' => $usage['month']['job_seconds'], 'queries' => 1], $usage['month']);
        self::assertGreaterThanOrEqual(3, $usage['month']['job_seconds']);
        self::assertGreaterThan(0, $usage['storage']['raw_bytes']);
        self::assertGreaterThan(0, $usage['storage']['lakehouse_bytes'], 'bronze table counted');
        self::assertSame(
            $usage['storage']['used_bytes'],
            (int) $this->app()->db()->scalar("SELECT value FROM usage_counters WHERE metric = 'storage_bytes' AND period = 'total'"),
            'gauge refreshed by the dispatcher after the ingest'
        );
        self::assertStringContainsString('Tu uso', $this->sessionDashboard());
    }

    public function testLabOwnersAreWarnedOnceBeforeExpiry(): void
    {
        $this->importLabs();
        $attempt = $this->startLab($this->ana, 'LAB-001');
        $db = $this->app()->db();
        $db->execute("UPDATE workspaces SET expires_at = UTC_TIMESTAMP(3) + INTERVAL 2 DAY WHERE purpose = 'lab'");

        $maintenance = new Maintenance($this->app());
        self::assertSame(1, $maintenance->run()['lab_expiry_warnings']);
        self::assertSame(0, $maintenance->run()['lab_expiry_warnings'], 'only once');
        $payload = json_decode((string) $db->scalar("SELECT payload FROM email_outbox WHERE template = 'lab_expiry_warning'"), true);
        self::assertStringEndsWith('/app/lab-attempts/' . $attempt['id'], $payload['url']);
        self::assertSame('Fundamentos de workspaces y recursos en la nube', $payload['lab']);

        // Activity extends the expiry and re-arms the warning.
        self::assertSame(202, $this->as($this->ana, 'POST', '/api/v1/lab-attempts/' . $attempt['id'] . '/submit', [])->status);
        self::assertNull($db->scalar("SELECT expiry_warned_at FROM workspaces WHERE purpose = 'lab'"));
        self::assertSame(0, $maintenance->run()['lab_expiry_warnings'], 'expiry is 14 days away again');
    }

    private function sessionDashboard(): string
    {
        $this->cookieJar = [];
        $this->login('ana@test.example');
        $r = $this->request('GET', '/app');
        self::assertSame(200, $r->status);
        return $r->body;
    }
}
