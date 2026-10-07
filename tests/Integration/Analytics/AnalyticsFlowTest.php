<?php

declare(strict_types=1);

namespace EduCloud\Tests\Integration\Analytics;

use EduCloud\Tests\Support\ApiActors;
use EduCloud\Tests\Support\AuthHelpers;
use EduCloud\Tests\Support\DatasetHelpers;
use EduCloud\Tests\TestCase;

/** Semantic models and dashboards (M9): validation against the catalog, explore, render with filters, guards. */
final class AnalyticsFlowTest extends TestCase
{
    use AuthHelpers;
    use ApiActors;
    use DatasetHelpers;

    private string $ana = '';
    private string $ws = '';

    private const MODEL = [
        'fact' => 'gold.fact_sales',
        'relationships' => [
            ['table' => 'gold.dim_store', 'fact_column' => 'store_key', 'column' => 'store_key'],
            ['table' => 'gold.dim_date', 'fact_column' => 'date_key', 'column' => 'date_key'],
        ],
        'measures' => [
            ['name' => 'ingresos', 'label' => 'Ingresos', 'agg' => 'sum', 'column' => 'amount', 'format' => 'currency'],
            ['name' => 'pedidos', 'agg' => 'count_distinct', 'column' => 'order_id'],
            ['name' => 'ticket_medio', 'ratio' => ['ingresos', 'pedidos']],
        ],
        'dimensions' => [
            ['name' => 'region', 'label' => 'Región', 'table' => 'gold.dim_store', 'column' => 'region'],
            ['name' => 'mes', 'table' => 'gold.dim_date', 'column' => 'full_date', 'grain' => 'month'],
            ['name' => 'dia', 'table' => 'gold.dim_date', 'column' => 'full_date', 'grain' => 'day'],
        ],
    ];

    private const DASHBOARD = [
        'date_filter' => ['dimension' => 'dia'],
        'filters' => [['dimension' => 'region']],
        'widgets' => [
            ['id' => 'total', 'type' => 'kpi', 'title' => 'Ingresos', 'measures' => ['ingresos', 'pedidos']],
            ['id' => 'por_region', 'type' => 'bar', 'title' => 'Por región', 'measures' => ['ingresos'], 'dimension' => 'region',
                'order' => ['by' => 'ingresos', 'dir' => 'desc']],
            ['id' => 'por_mes', 'type' => 'line', 'title' => 'Por mes', 'measures' => ['ticket_medio'], 'dimension' => 'mes'],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireRunner();
        $this->resetDatabase();
        $this->ana = $this->actor('ana@test.example');
        $this->ws = (string) $this->createWorkspace($this->ana)['id'];
        $this->createResource($this->ana, $this->ws, 'lakehouse', 'lago');
        // Transforms need an existing lakehouse file: ingest a small bronze table first.
        $raw = $this->uploadAs($this->ana, $this->ws, 'clientes', self::customersCsv())->decoded()['data']['dataset']['id'];
        $this->runJobs();
        self::assertSame(202, $this->as($this->ana, 'POST', "/api/v1/datasets/$raw/ingest", ['table_name' => 'clientes'])->status);
        $this->runJobs();
        $tables = [
            'dim_store' => "SELECT * FROM (VALUES (1, 'Norte'), (2, 'Sur')) t(store_key, region)",
            'dim_date' => "SELECT * FROM (VALUES (20250105, DATE '2025-01-05'), (20250210, DATE '2025-02-10')) t(date_key, full_date)",
            'fact_sales' => "SELECT * FROM (VALUES (1, 1, 20250105, 100.0), (1, 1, 20250105, 50.0), (2, 2, 20250105, 30.0),"
                . " (3, 2, 20250210, 20.0)) t(order_id, store_key, date_key, amount)",
        ];
        foreach ($tables as $table => $sql) {
            $r = $this->as($this->ana, 'POST', "/api/v1/workspaces/{$this->ws}/transforms", ['sql' => $sql, 'layer' => 'gold', 'table' => $table]);
            self::assertSame(202, $r->status, $r->body);
            $this->runJobs();
        }
    }

    /** @return array<string, mixed> */
    private function createModel(array $definition = self::MODEL, string $name = 'ventas'): array
    {
        $r = $this->as($this->ana, 'POST', "/api/v1/workspaces/{$this->ws}/semantic-models", ['name' => $name, 'definition' => $definition]);
        self::assertSame(201, $r->status, $r->body);
        return $r->decoded()['data'];
    }

    /** @return array<string, mixed> finished semantic query */
    private function finish(\EduCloud\Core\Response $r): array
    {
        self::assertSame(202, $r->status, $r->body);
        self::assertSame('queued', $r->decoded()['data']['status']);
        $this->runJobs();
        $q = $this->as($this->ana, 'GET', '/api/v1/semantic-queries/' . $r->decoded()['data']['id']);
        self::assertSame(200, $q->status);
        return $q->decoded()['data'];
    }

    public function testModelsAreValidatedAgainstTheCatalogWithPreciseErrors(): void
    {
        $bad = self::MODEL;
        $bad['fact'] = 'gold.no_existe';
        $bad['relationships'][0]['column'] = 'falta';
        $bad['measures'][2]['ratio'] = ['ingresos', 'fantasma'];
        $bad['dimensions'][0]['grain'] = 'month';
        $bad['dimensions'][] = ['name' => 'suelta', 'table' => 'gold.otra', 'column' => 'x'];
        $r = $this->as($this->ana, 'POST', "/api/v1/workspaces/{$this->ws}/semantic-models/validate", ['definition' => $bad]);
        self::assertSame(422, $r->status, $r->body);
        $fields = array_column($r->decoded()['error']['details'], 'field');
        $expected = [
            'definition/fact', 'definition/relationships/0/column', 'definition/measures/2/ratio/1',
            'definition/dimensions/0/grain', 'definition/dimensions/3/table',
        ];
        foreach ($expected as $f) {
            self::assertContains($f, $fields);
        }

        $schema = self::MODEL;
        $schema['fact'] = 'bronze.orders';
        $schema['measures'][0]['agg'] = 'string_agg';
        $r = $this->as($this->ana, 'POST', "/api/v1/workspaces/{$this->ws}/semantic-models", ['name' => 'malo', 'definition' => $schema]);
        self::assertSame(422, $r->status);
        self::assertSame(0, (int) $this->app()->db()->scalar('SELECT COUNT(*) FROM semantic_models'));

        $ok = $this->as($this->ana, 'POST', "/api/v1/workspaces/{$this->ws}/semantic-models/validate", ['definition' => self::MODEL]);
        self::assertSame(['valid' => true, 'measures' => 3, 'dimensions' => 3], $ok->decoded()['data']);
    }

    public function testExploreReturnsMeasuresByDimension(): void
    {
        $model = $this->createModel();
        self::assertSame(0, $model['dashboard_count']);
        $q = $this->finish($this->as($this->ana, 'POST', "/api/v1/semantic-models/{$model['id']}/query", [
            'measures' => ['ingresos', 'ticket_medio'], 'dimensions' => ['region'], 'order' => ['by' => 'region'],
        ]));
        self::assertSame('succeeded', $q['status'], json_encode($q));
        self::assertSame('explore', $q['kind']);
        self::assertEquals([['Norte', 150.0, 150.0], ['Sur', 50.0, 25.0]], $q['results']['q']['rows']);
        self::assertSame(['dimension', 'measure', 'measure'], array_column($q['results']['q']['columns'], 'role'));

        $invalid = [
            ['measures' => ['fantasma']],
            ['measures' => []],
            ['measures' => ['ingresos'], 'filters' => [['dimension' => 'region', 'op' => 'like', 'value' => 'N%']]],
            ['measures' => ['ingresos'], 'limit' => 5000],
            ['measures' => ['ingresos'], 'sql' => 'SELECT 1'],
            ['measures' => ['ingresos'], 'filters' => [['dimension' => [1], 'op' => 'eq', 'value' => 1]]],
        ];
        foreach ($invalid as $body) {
            self::assertSame(422, $this->as($this->ana, 'POST', "/api/v1/semantic-models/{$model['id']}/query", $body)->status, json_encode($body));
        }
    }

    public function testDashboardRendersEveryWidgetWithFilters(): void
    {
        $model = $this->createModel();
        $bad = self::DASHBOARD;
        $bad['widgets'][1]['measures'] = ['fantasma'];
        $bad['widgets'][2]['dimension'] = null;
        unset($bad['widgets'][2]['dimension']);
        $bad['widgets'][0]['dimension'] = 'region';
        $body = ['name' => 'panel', 'model_id' => $model['id'], 'definition' => $bad];
        $r = $this->as($this->ana, 'POST', "/api/v1/workspaces/{$this->ws}/dashboards", $body);
        self::assertSame(422, $r->status);
        $fields = array_column($r->decoded()['error']['details'], 'field');
        self::assertContains('definition/widgets/1/measures/0', $fields);
        self::assertContains('definition/widgets/2/dimension', $fields);
        self::assertContains('definition/widgets/0/dimension', $fields);

        $body = ['name' => 'panel', 'model_id' => $model['id'], 'definition' => self::DASHBOARD];
        $r = $this->as($this->ana, 'POST', "/api/v1/workspaces/{$this->ws}/dashboards", $body);
        self::assertSame(201, $r->status, $r->body);
        $dashboard = $r->decoded()['data'];
        self::assertSame('Región', $dashboard['model']['dimensions'][0]['label']);

        $all = $this->finish($this->as($this->ana, 'POST', "/api/v1/dashboards/{$dashboard['id']}/render", []));
        self::assertSame('succeeded', $all['status'], json_encode($all));
        self::assertEquals([[200.0, 3]], $all['results']['w_total']['rows']);
        self::assertEquals([['Norte', 150.0], ['Sur', 50.0]], $all['results']['w_por_region']['rows']);
        self::assertEquals([['2025-01-01', 90.0], ['2025-02-01', 20.0]], $all['results']['w_por_mes']['rows']);
        self::assertSame([['Norte'], ['Sur']], $all['results']['f_region']['rows']);

        $filtered = $this->finish($this->as($this->ana, 'POST', "/api/v1/dashboards/{$dashboard['id']}/render", [
            'date_from' => '2025-02-01', 'date_to' => '2025-02-28', 'filters' => ['region' => ['Sur']],
        ]));
        self::assertEquals([[20.0, 1]], $filtered['results']['w_total']['rows']);
        self::assertSame(['date_from' => '2025-02-01', 'date_to' => '2025-02-28', 'filters' => ['region' => ['Sur']]], $filtered['values']);

        $invalid = [
            ['filters' => ['mes' => ['2025-01-01']]],
            ['date_from' => '2025-13-01'],
            ['date_from' => '2025-03-01', 'date_to' => '2025-01-01'],
            ['filters' => ['region' => 'Sur']],
            ['sql' => 'x'],
        ];
        foreach ($invalid as $body) {
            self::assertSame(422, $this->as($this->ana, 'POST', "/api/v1/dashboards/{$dashboard['id']}/render", $body)->status, json_encode($body));
        }
    }

    public function testModelChangesAndDeletionsKeepDashboardsConsistent(): void
    {
        $model = $this->createModel();
        $dashboard = $this->as($this->ana, 'POST', "/api/v1/workspaces/{$this->ws}/dashboards", [
            'name' => 'panel', 'model_id' => $model['id'], 'definition' => self::DASHBOARD,
        ])->decoded()['data'];

        $without = self::MODEL;
        array_pop($without['measures']); // ticket_medio is used by a widget
        $r = $this->as($this->ana, 'PATCH', "/api/v1/semantic-models/{$model['id']}", ['definition' => $without]);
        self::assertSame(409, $r->status);
        self::assertSame('MODEL_IN_USE', $r->decoded()['error']['code']);
        self::assertSame(409, $this->as($this->ana, 'DELETE', "/api/v1/semantic-models/{$model['id']}")->status);

        $renamed = $this->as($this->ana, 'PATCH', "/api/v1/semantic-models/{$model['id']}", ['name' => 'ventas-2025']);
        self::assertSame(200, $renamed->status);
        self::assertSame('ventas-2025', $renamed->decoded()['data']['name']);
        self::assertSame(1, $renamed->decoded()['data']['dashboard_count']);

        // The generic resources API refuses managed analytics resources.
        self::assertSame(409, $this->as($this->ana, 'DELETE', "/api/v1/resources/{$model['id']}")->status);
        self::assertSame(409, $this->as($this->ana, 'PATCH', "/api/v1/resources/{$dashboard['id']}", ['name' => 'otro nombre'])->status);

        // A model of another workspace cannot back a dashboard.
        $other = (string) $this->createWorkspace($this->ana, 'Otro')['id'];
        $body = ['name' => 'p', 'model_id' => $model['id'], 'definition' => self::DASHBOARD];
        $r = $this->as($this->ana, 'POST', "/api/v1/workspaces/$other/dashboards", $body);
        self::assertSame(422, $r->status);

        self::assertSame(204, $this->as($this->ana, 'DELETE', "/api/v1/dashboards/{$dashboard['id']}")->status);
        self::assertSame(204, $this->as($this->ana, 'DELETE', "/api/v1/semantic-models/{$model['id']}")->status);
        self::assertSame(404, $this->as($this->ana, 'GET', "/api/v1/semantic-models/{$model['id']}")->status);
    }

    public function testOtherTenantsSeeNothingAndPagesRender(): void
    {
        $model = $this->createModel();
        $dashboard = $this->as($this->ana, 'POST', "/api/v1/workspaces/{$this->ws}/dashboards", [
            'name' => 'panel', 'model_id' => $model['id'], 'definition' => self::DASHBOARD,
        ])->decoded()['data'];
        $query = $this->as($this->ana, 'POST', "/api/v1/dashboards/{$dashboard['id']}/render", [])->decoded()['data'];

        $eve = $this->actor('eve@test.example');
        self::assertSame(404, $this->as($eve, 'GET', "/api/v1/semantic-models/{$model['id']}")->status);
        self::assertSame(404, $this->as($eve, 'POST', "/api/v1/semantic-models/{$model['id']}/query", ['measures' => ['ingresos']])->status);
        self::assertSame(404, $this->as($eve, 'POST', "/api/v1/dashboards/{$dashboard['id']}/render", [])->status);
        self::assertSame(404, $this->as($eve, 'GET', "/api/v1/semantic-queries/{$query['id']}")->status);

        $this->cookieJar = [];
        $this->login('ana@test.example');
        $page = $this->request('GET', "/app/workspaces/{$this->ws}/analytics");
        self::assertSame(200, $page->status);
        self::assertStringContainsString('gold.fact_sales', $page->body);
        self::assertStringContainsString('vendor/chartjs/chart.umd.min.js', $page->body);
        $viewer = $this->request('GET', "/app/dashboards/{$dashboard['id']}");
        self::assertSame(200, $viewer->status);
        self::assertStringContainsString('data-widget="por_region"', $viewer->body);
        self::assertStringNotContainsString('<script>alert', $viewer->body);
    }

    public function testMarkupInNamesAndLabelsIsEscapedAndForeignModelsAreRefused(): void
    {
        $payload = '</script><script>alert(1)</script>';
        $model = self::MODEL;
        $model['measures'][0]['label'] = $payload;
        $created = $this->createModel($model);
        $definition = self::DASHBOARD;
        $definition['widgets'][0]['title'] = $payload;
        $r = $this->as($this->ana, 'POST', "/api/v1/workspaces/{$this->ws}/dashboards", [
            'name' => 'panel', 'model_id' => $created['id'], 'definition' => $definition,
        ]);
        self::assertSame(201, $r->status, $r->body);
        $dashboard = $r->decoded()['data'];

        $this->cookieJar = [];
        $this->login('ana@test.example');
        $viewer = $this->request('GET', "/app/dashboards/{$dashboard['id']}")->body;
        self::assertStringNotContainsString($payload, $viewer, 'neither the HTML nor the embedded JSON may contain raw markup');
        self::assertStringContainsString('&lt;/script&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $viewer);
        self::assertStringContainsString('\\u003C\\/script\\u003E', $viewer, 'JSON_HEX_TAG in the data block');

        // Another tenant cannot bind its dashboard to this model (same 422 as a nonexistent id: no enumeration).
        $eve = $this->actor('eve@test.example');
        $eveWs = (string) $this->createWorkspace($eve, 'Ajeno')['id'];
        $body = ['name' => 'robado', 'model_id' => $created['id'], 'definition' => self::DASHBOARD];
        $foreign = $this->as($eve, 'POST', "/api/v1/workspaces/$eveWs/dashboards", $body);
        $ghost = $this->as($eve, 'POST', "/api/v1/workspaces/$eveWs/dashboards", ['model_id' => \EduCloud\Core\Ulid::generate()] + $body);
        self::assertSame(422, $foreign->status);
        self::assertSame($ghost->decoded()['error'], $foreign->decoded()['error']);
    }
}
