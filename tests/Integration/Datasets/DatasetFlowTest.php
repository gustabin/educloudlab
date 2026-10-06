<?php

declare(strict_types=1);

namespace EduCloud\Tests\Integration\Datasets;

use EduCloud\Modules\Jobs\Maintenance;
use EduCloud\Tests\Support\ApiActors;
use EduCloud\Tests\Support\AuthHelpers;
use EduCloud\Tests\Support\DatasetHelpers;
use EduCloud\Tests\TestCase;

/** End-to-end through the real execution plane: PHP API → jobs → dispatcher → Python/DuckDB runner. */
final class DatasetFlowTest extends TestCase
{
    use AuthHelpers;
    use ApiActors;
    use DatasetHelpers;

    private string $ana = '';
    private string $ws = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireRunner();
        $this->resetDatabase();
        $this->ana = $this->actor('ana@test.example');
        $this->ws = (string) $this->createWorkspace($this->ana)['id'];
    }

    public function testUploadProfileIngestPreviewAndDelete(): void
    {
        // 1. Upload → raw dataset + queued profile job (202).
        $up = $this->uploadAs($this->ana, $this->ws, 'customers', self::customersCsv(), '../../Clientes 2026.csv');
        self::assertSame(202, $up->status, $up->body);
        $raw = $up->decoded()['data']['dataset'];
        self::assertSame(['raw', 'provisioning', 'queued'], [$raw['layer'], $raw['status'], $up->decoded()['data']['job']['status']]);
        self::assertSame('Clientes 2026.csv', $raw['version']['original_name'], 'client path components are stripped');

        // 2. Dispatcher runs the profile job in the runner.
        self::assertSame(1, $this->runJobs());
        $raw = $this->dataset($this->ana, $raw['id']);
        self::assertSame('active', $raw['status']);
        self::assertSame(3, $raw['version']['row_count']);
        self::assertSame(5, $raw['version']['column_count']);
        self::assertSame(['BIGINT', 'VARCHAR', 'VARCHAR', 'DATE', 'DOUBLE'], array_column($raw['columns'], 'type'));
        $job = $this->as($this->ana, 'GET', '/api/v1/jobs/' . $up->decoded()['data']['job']['id'])->decoded()['data'];
        self::assertSame('succeeded', $job['status']);
        self::assertSame(3, $job['result']['row_count']);

        $preview = $this->as($this->ana, 'GET', "/api/v1/datasets/{$raw['id']}/preview")->decoded()['data'];
        self::assertSame(['Customer ID', 'Full Name', 'E-mail', 'Signup Date', 'amount'], $preview['columns']);
        self::assertSame('Ana García', $preview['rows'][0][1]);
        self::assertSame('=SUM(A1:A2)', $preview['rows'][2][1], 'formula-like values are kept as plain data');

        // Raw file lives under the generated key, outside the web root.
        $files = glob($this->storagePath . '/t/*/w/*/raw/*.csv') ?: [];
        self::assertCount(1, $files);
        self::assertMatchesRegularExpression('#/raw/[0-9A-Z]{26}\.csv$#', $files[0]);

        // 3. Ingest needs a lakehouse resource.
        $noLake = $this->as($this->ana, 'POST', "/api/v1/datasets/{$raw['id']}/ingest", ['table_name' => 'customers']);
        self::assertSame(409, $noLake->status);
        self::assertSame('LAKEHOUSE_REQUIRED', $noLake->decoded()['error']['code']);
        $this->createResource($this->ana, $this->ws, 'lakehouse', 'retail');

        $ing = $this->as($this->ana, 'POST', "/api/v1/datasets/{$raw['id']}/ingest", ['table_name' => 'customers']);
        self::assertSame(202, $ing->status, $ing->body);
        self::assertSame(1, $this->runJobs());
        $bronze = $this->dataset($this->ana, $ing->decoded()['data']['dataset']['id']);
        $actual = [$bronze['layer'], $bronze['table'], $bronze['status'], $bronze['version']['row_count']];
        self::assertSame(['bronze', 'bronze.customers', 'active', 3], $actual);
        self::assertSame(['customer_id', 'full_name', 'e_mail', 'signup_date', 'amount'], array_column($bronze['columns'], 'name'));
        self::assertSame('Customer ID', $bronze['columns'][0]['source_name']);
        self::assertFileExists((string) (glob($this->storagePath . '/t/*/w/*/lakehouse.duckdb')[0] ?? ''));

        $again = $this->as($this->ana, 'POST', "/api/v1/datasets/{$raw['id']}/ingest", ['table_name' => 'customers']);
        self::assertSame(409, $again->status, 'bronze table name taken');
        $fromBronze = $this->as($this->ana, 'POST', "/api/v1/datasets/{$bronze['id']}/ingest", ['table_name' => 'otra']);
        self::assertSame(409, $fromBronze->status, 'only raw can be ingested');
        self::assertSame(422, $this->as($this->ana, 'POST', "/api/v1/datasets/{$raw['id']}/ingest", ['table_name' => 'Bad Name'])->status);

        $list = $this->as($this->ana, 'GET', "/api/v1/workspaces/{$this->ws}/datasets")->decoded()['data'];
        self::assertSame(['raw', 'bronze'], array_column($list, 'layer'));

        // 4. Generic resource API refuses to touch datasets.
        self::assertSame(409, $this->as($this->ana, 'DELETE', "/api/v1/resources/{$raw['id']}")->status);

        // 5. Delete raw and bronze through cleanup jobs.
        self::assertSame(202, $this->as($this->ana, 'DELETE', "/api/v1/datasets/{$raw['id']}")->status);
        self::assertSame(202, $this->as($this->ana, 'DELETE', "/api/v1/datasets/{$bronze['id']}")->status);
        self::assertSame(2, $this->runJobs());
        self::assertSame(404, $this->as($this->ana, 'GET', "/api/v1/datasets/{$raw['id']}")->status);
        self::assertSame([], glob($this->storagePath . '/t/*/w/*/raw/*.csv') ?: []);
        self::assertSame(2, (int) $this->app()->db()->scalar("SELECT COUNT(*) FROM resources WHERE type = 'dataset' AND status = 'deleted'"));
        self::assertSame(['dataset.upload', 'dataset.ingest', 'dataset.delete'], array_values(array_unique(array_column(
            $this->app()->db()->select("SELECT action FROM audit_logs WHERE action LIKE 'dataset.%' ORDER BY id"),
            'action'
        ))));
    }

    public function testMalformedCsvFailsWithSafeMessage(): void
    {
        $up = $this->uploadAs($this->ana, $this->ws, 'broken', "a,b\n1,2\n3,4,5\n");
        self::assertSame(202, $up->status);
        $this->runJobs();
        $ds = $this->dataset($this->ana, $up->decoded()['data']['dataset']['id']);
        self::assertSame('failed', $ds['status']);
        self::assertSame('CSV_PARSE_ERROR', $ds['error']['code']);
        self::assertStringNotContainsString($this->storagePath, $ds['error']['message']);
        self::assertStringNotContainsString(':\\', $ds['error']['message']);
        self::assertSame(409, $this->as($this->ana, 'GET', "/api/v1/datasets/{$ds['id']}/preview")->status);
        self::assertSame(202, $this->as($this->ana, 'DELETE', "/api/v1/datasets/{$ds['id']}")->status, 'failed datasets can be deleted');
    }

    public function testDuplicateRawNameAndQuotas(): void
    {
        self::assertSame(202, $this->uploadAs($this->ana, $this->ws, 'ventas', "a\n1\n")->status);
        self::assertSame(409, $this->uploadAs($this->ana, $this->ws, 'ventas', "a\n1\n")->status);

        $this->app = null;
        $this->app();
        $this->app = \EduCloud\Core\App::create(
            $this->app()->config->with('quotas.storage_bytes_per_user', 10),
            testDatabase: true
        );
        $r = $this->uploadAs($this->ana, $this->ws, 'grande', "columna\n123456789\n");
        self::assertSame(409, $r->status);
        self::assertSame('QUOTA_EXCEEDED', $r->decoded()['error']['code']);
    }

    public function testRunnerTimeoutKillsTheProcessAndFailsTheJob(): void
    {
        $up = $this->uploadAs($this->ana, $this->ws, 'lento', self::customersCsv());
        $this->app()->db()->execute('UPDATE jobs SET timeout_s = 0');
        $this->runJobs();
        $job = $this->as($this->ana, 'GET', '/api/v1/jobs/' . $up->decoded()['data']['job']['id'])->decoded()['data'];
        self::assertSame('timed_out', $job['status']);
        self::assertSame('TIMEOUT', $job['error']['code']);
        self::assertSame('failed', $this->dataset($this->ana, $up->decoded()['data']['dataset']['id'])['status']);
        self::assertSame([], glob($this->storagePath . '/jobs/*') ?: [], 'runner I/O files are removed');
    }

    public function testDeletedWorkspaceStorageIsReleasedBySchedulerAndStaleJobsFail(): void
    {
        $this->uploadAs($this->ana, $this->ws, 'tmp', self::customersCsv());
        $this->runJobs();
        self::assertNotSame([], glob($this->storagePath . '/t/*/w/*') ?: []);

        self::assertSame(204, $this->as($this->ana, 'DELETE', "/api/v1/workspaces/{$this->ws}")->status);
        $result = (new Maintenance($this->app()))->run();
        self::assertSame(1, $result['workspaces_released']);
        self::assertSame([], glob($this->storagePath . '/t/*/w/*') ?: []);
        self::assertSame(0, (int) $this->app()->db()->scalar("SELECT COUNT(*) FROM resources WHERE status = 'deleting'"));

        // A job left 'running' by a crashed dispatcher is failed by the scheduler.
        $ws2 = (string) $this->createWorkspace($this->ana, 'Segundo')['id'];
        $up = $this->uploadAs($this->ana, $ws2, 'colgado', self::customersCsv());
        $this->app()->db()->execute("UPDATE jobs SET status = 'running', heartbeat_at = UTC_TIMESTAMP(3) - INTERVAL 1 HOUR WHERE status = 'queued'");
        self::assertSame(1, (new Maintenance($this->app()))->run()['stale_jobs_failed']);
        self::assertSame('failed', $this->dataset($this->ana, $up->decoded()['data']['dataset']['id'])['status']);
    }
}
