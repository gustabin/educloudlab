<?php

declare(strict_types=1);

namespace EduCloud\Modules\SqlLab\Jobs;

use EduCloud\Core\App;
use EduCloud\Modules\Jobs\JobHandler;
use EduCloud\Modules\SqlLab\QueryRepository;
use RuntimeException;

/** sql_query: runs a student SELECT in the read-only sandbox and stores the result for 24 h. */
final class QueryHandler implements JobHandler
{
    private QueryRepository $queries;

    public function __construct(private readonly App $app)
    {
        $this->queries = new QueryRepository($app->db());
    }

    public function prepare(array $job): array
    {
        $query = $this->load($job);
        return ['op' => 'query', 'args' => [
            'lakehouse_path' => $this->app->storage()->lakehouseFile((string) $job['tenant_public_id'], (string) $job['workspace_public_id']),
            'sql' => (string) $query['sql_text'],
        ]];
    }

    public function succeeded(array $job, array $data): array
    {
        $query = $this->load($job);
        $storage = $this->app->storage();
        $file = $storage->queryResultFile((string) $job['tenant_public_id'], (string) $job['workspace_public_id'], (string) $query['public_id']);
        $storage->ensureDir(dirname($file));
        $result = [
            'columns' => array_values((array) ($data['columns'] ?? [])),
            'rows' => array_values((array) ($data['rows'] ?? [])),
            'truncated' => (bool) ($data['truncated'] ?? false),
        ];
        file_put_contents($file, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        $rows = (int) ($data['row_count'] ?? 0);
        $elapsed = (int) ($data['elapsed_ms'] ?? 0);
        $this->queries->markResult((int) $job['tenant_id'], (int) $query['id'], 'succeeded', $elapsed, $rows, null);
        return ['row_count' => $rows, 'truncated' => $result['truncated'], 'elapsed_ms' => $elapsed];
    }

    public function failed(array $job, string $errorCode, string $safeMessage): void
    {
        $query = $this->load($job);
        $status = in_array($errorCode, ['TIMEOUT', 'QUERY_TIMEOUT'], true) ? 'timed_out' : 'failed';
        $this->queries->markResult((int) $job['tenant_id'], (int) $query['id'], $status, null, null, $errorCode);
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function load(array $job): array
    {
        $payload = json_decode((string) $job['payload'], true);
        $query = $this->queries->findForJob((int) $job['tenant_id'], (int) ($payload['query_id'] ?? 0));
        if ($query === null || $job['workspace_public_id'] === null || (int) $query['workspace_id'] !== (int) $job['workspace_id']) {
            throw new RuntimeException('Query job references missing data');
        }
        return $query;
    }
}
