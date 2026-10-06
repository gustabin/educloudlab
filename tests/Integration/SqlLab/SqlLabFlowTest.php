<?php

declare(strict_types=1);

namespace EduCloud\Tests\Integration\SqlLab;

use EduCloud\Core\App;
use EduCloud\Tests\Support\ApiActors;
use EduCloud\Tests\Support\AuthHelpers;
use EduCloud\Tests\Support\DatasetHelpers;
use EduCloud\Tests\TestCase;

/** SQL Lab end-to-end: API → jobs → dispatcher → sandboxed DuckDB runner → stored result. */
final class SqlLabFlowTest extends TestCase
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

    /** Lakehouse + bronze.customers ready. */
    private function seedBronze(): void
    {
        $this->createResource($this->ana, $this->ws, 'lakehouse', 'lago');
        $raw = $this->uploadAs($this->ana, $this->ws, 'customers', self::customersCsv())->decoded()['data']['dataset']['id'];
        $this->runJobs();
        self::assertSame(202, $this->as($this->ana, 'POST', "/api/v1/datasets/$raw/ingest", ['table_name' => 'customers'])->status);
        $this->runJobs();
    }

    /** @return array<string, mixed> finished query */
    private function query(string $sql): array
    {
        $r = $this->as($this->ana, 'POST', "/api/v1/workspaces/{$this->ws}/queries", ['sql' => $sql]);
        self::assertSame(202, $r->status, $r->body);
        self::assertSame('queued', $r->decoded()['data']['status']);
        $this->runJobs();
        $q = $this->as($this->ana, 'GET', '/api/v1/queries/' . $r->decoded()['data']['id']);
        self::assertSame(200, $q->status);
        return $q->decoded()['data'];
    }

    public function testQueryCatalogHistoryAndTransforms(): void
    {
        $this->seedBronze();

        $catalog = $this->as($this->ana, 'GET', "/api/v1/workspaces/{$this->ws}/catalog")->decoded()['data'];
        self::assertSame('bronze.customers', $catalog['bronze'][0]['qualified_name']);
        self::assertSame('customer_id', $catalog['bronze'][0]['columns'][0]['name']);
        self::assertSame([], $catalog['silver']);

        $q = $this->query("SELECT full_name, amount FROM bronze.customers WHERE e_mail IS NOT NULL ORDER BY customer_id");
        self::assertSame('succeeded', $q['status'], json_encode($q));
        self::assertSame(2, $q['row_count']);
        self::assertSame(['full_name', 'amount'], array_column($q['result']['columns'], 'name'));
        self::assertSame(['Ana García', 10.5], $q['result']['rows'][0]);
        self::assertIsInt($q['duration_ms']);

        // Transform to silver, then gold from silver.
        $t = $this->as($this->ana, 'POST', "/api/v1/workspaces/{$this->ws}/transforms", [
            'sql' => "SELECT customer_id, lower(e_mail) AS email, signup_date FROM bronze.customers WHERE e_mail IS NOT NULL;",
            'layer' => 'silver', 'table' => 'customers',
        ]);
        self::assertSame(202, $t->status, $t->body);
        $this->runJobs();
        $silver = $this->dataset($this->ana, $t->decoded()['data']['dataset']['id']);
        self::assertSame(['active', 'silver.customers', 2], [$silver['status'], $silver['table'], $silver['version']['row_count']]);
        self::assertStringContainsString('lower(e_mail)', (string) $silver['sql'], 'the defining SELECT is kept for transparency');

        $g = $this->as($this->ana, 'POST', "/api/v1/workspaces/{$this->ws}/transforms", [
            'sql' => 'SELECT count(*) AS customers FROM silver.customers', 'layer' => 'gold', 'table' => 'kpis',
        ]);
        $this->runJobs();
        self::assertSame('active', $this->dataset($this->ana, $g->decoded()['data']['dataset']['id'])['status']);
        $catalog = $this->as($this->ana, 'GET', "/api/v1/workspaces/{$this->ws}/catalog")->decoded()['data'];
        self::assertSame(['silver.customers'], array_column($catalog['silver'], 'qualified_name'));
        self::assertSame(['gold.kpis'], array_column($catalog['gold'], 'qualified_name'));
        self::assertSame(2, $this->query('SELECT customers FROM gold.kpis')['result']['rows'][0][0]);

        $transforms = "/api/v1/workspaces/{$this->ws}/transforms";
        self::assertSame(409, $this->as($this->ana, 'POST', $transforms, ['sql' => 'SELECT 1', 'layer' => 'gold', 'table' => 'kpis'])->status);
        self::assertSame(422, $this->as($this->ana, 'POST', $transforms, ['sql' => 'SELECT 1', 'layer' => 'bronze', 'table' => 'x'])->status);

        $history = $this->as($this->ana, 'GET', "/api/v1/workspaces/{$this->ws}/queries")->decoded();
        self::assertSame(2, $history['meta']['total']);
        self::assertSame('SELECT customers FROM gold.kpis', $history['data'][0]['sql']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function rejected(): iterable
    {
        yield 'write' => ['DELETE FROM bronze.customers', 'SQL_FORBIDDEN'];
        yield 'multi statement' => ['SELECT 1; DROP TABLE bronze.customers', 'SQL_FORBIDDEN'];
        yield 'server path leak' => ['SELECT path FROM duckdb_databases()', 'SQL_FORBIDDEN'];
        yield 'file read' => ["SELECT * FROM read_csv('C:/Windows/win.ini')", 'SQL_FORBIDDEN'];
        yield 'smuggled sql' => ["SELECT * FROM query('SELECT * FROM duckdb_settings()')", 'SQL_FORBIDDEN'];
        yield 'unknown table' => ['SELECT * FROM bronze.nada', 'SQL_CATALOG_ERROR'];
        yield 'syntax' => ['SELEC 1', 'SQL_SYNTAX_ERROR'];
    }

    /** @dataProvider rejected */
    public function testUnsafeOrInvalidSqlFailsSafely(string $sql, string $code): void
    {
        $this->seedBronze();
        $q = $this->query($sql);
        self::assertSame('failed', $q['status']);
        self::assertSame($code, $q['error']['code']);
        self::assertStringNotContainsString($this->storagePath, $q['error']['message']);
        self::assertStringNotContainsString(str_replace('/', '\\', $this->storagePath), $q['error']['message']);
        self::assertSame(3, $this->query('SELECT count(*) FROM bronze.customers')['result']['rows'][0][0], 'data untouched');
    }

    public function testLongQueriesAreInterrupted(): void
    {
        $this->seedBronze();
        $this->app = App::create($this->app()->config->with('execution.limits.sql_timeout_s', 1), testDatabase: true);
        $q = $this->query('SELECT count(*) FROM range(1000000000000) a');
        self::assertSame('timed_out', $q['status']);
        self::assertSame('QUERY_TIMEOUT', $q['error']['code']);
    }

    public function testLakehouseIsRequiredAndInputValidated(): void
    {
        $r = $this->as($this->ana, 'POST', "/api/v1/workspaces/{$this->ws}/queries", ['sql' => 'SELECT 1']);
        self::assertSame(409, $r->status);
        self::assertSame('LAKEHOUSE_REQUIRED', $r->decoded()['error']['code']);
        $this->createResource($this->ana, $this->ws, 'lakehouse', 'lago');
        self::assertSame(422, $this->as($this->ana, 'POST', "/api/v1/workspaces/{$this->ws}/queries", ['sql' => ''])->status);
        self::assertSame(422, $this->as($this->ana, 'POST', "/api/v1/workspaces/{$this->ws}/queries", ['sql' => str_repeat('x', 20001)])->status);

        // Lakehouse resource exists but nothing was ingested yet: clear runner error.
        $q = $this->query('SELECT 1');
        self::assertSame('NO_TABLES', $q['error']['code']);
    }

    public function testResultsExpireAndHistoryIsPersonal(): void
    {
        $this->seedBronze();
        $q = $this->query('SELECT 1 AS uno');
        foreach (glob($this->storagePath . '/t/*/w/*/meta/results/*.json') ?: [] as $file) {
            unlink($file);
        }
        $again = $this->as($this->ana, 'GET', '/api/v1/queries/' . $q['id'])->decoded()['data'];
        self::assertTrue($again['result_expired']);

        $org = $this->createOrgTenant('Org');
        $this->addMember($org['id'], 'ana@test.example', 'student');
        $this->actor('luis@test.example');
        $this->addMember($org['id'], 'luis@test.example', 'org_admin');
        $csrf = $this->sessionIn('ana@test.example', $org['public_id']);
        $ws = $this->request('POST', '/api/v1/workspaces', ['name' => 'Org ws'], ['X-CSRF-Token' => $csrf])->decoded()['data']['id'];
        $this->request('POST', "/api/v1/workspaces/$ws/resources", ['type' => 'lakehouse', 'name' => 'lago'], ['X-CSRF-Token' => $csrf]);
        $mine = $this->request('POST', "/api/v1/workspaces/$ws/queries", ['sql' => 'SELECT 1'], ['X-CSRF-Token' => $csrf])->decoded()['data']['id'];

        $this->sessionIn('luis@test.example', $org['public_id']);
        self::assertSame(0, $this->request('GET', "/api/v1/workspaces/$ws/queries")->decoded()['meta']['total'], 'history lists only own queries');
        self::assertSame(200, $this->request('GET', "/api/v1/queries/$mine")->status, 'org_admin may inspect a query by id');
    }

    public function testSqlLabPagesRender(): void
    {
        $this->createResource($this->ana, $this->ws, 'lakehouse', 'lago');
        $this->login('ana@test.example');
        $page = $this->request('GET', "/app/workspaces/{$this->ws}/sql");
        self::assertSame(200, $page->status);
        self::assertStringContainsString('id="sql-lab"', $page->body);
        self::assertStringContainsString('codemirror.js', $page->body);
        self::assertSame(200, $this->request('GET', '/app/sql')->status);
    }
}
