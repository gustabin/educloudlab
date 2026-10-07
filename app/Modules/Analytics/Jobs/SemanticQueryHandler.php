<?php

declare(strict_types=1);

namespace EduCloud\Modules\Analytics\Jobs;

use EduCloud\Core\App;
use EduCloud\Core\Format;
use EduCloud\Modules\Analytics\AnalyticsRepository;
use EduCloud\Modules\Jobs\JobHandler;
use RuntimeException;

/**
 * semantic_query (M9): runs the compiled model queries of an exploration or a dashboard render (op "semantic",
 * read-only, no file access) and stores the results for 24 h (meta/results/{query}.json).
 */
final class SemanticQueryHandler implements JobHandler
{
    private AnalyticsRepository $repo;

    public function __construct(private readonly App $app)
    {
        $this->repo = new AnalyticsRepository($app->db());
    }

    public function prepare(array $job): array
    {
        $query = $this->load($job);
        $request = Format::jsonColumn($query['request']);
        return ['op' => 'semantic', 'args' => [
            'lakehouse_path' => $this->app->storage()->lakehouseFile((string) $job['tenant_public_id'], (string) $job['workspace_public_id']),
            'model' => $request['model'] ?? [],
            'queries' => $request['queries'] ?? [],
        ]];
    }

    public function succeeded(array $job, array $data): array
    {
        $query = $this->load($job);
        $results = [];
        foreach ((array) ($data['results'] ?? []) as $key => $result) {
            // Only what the UI needs, re-shaped server-side (never the runner payload as-is).
            if (!is_string($key) || !is_array($result)) {
                continue;
            }
            $results[$key] = isset($result['error'])
                ? ['error' => ['code' => (string) ($result['error']['code'] ?? 'ERROR'), 'message' => (string) ($result['error']['message'] ?? '')]]
                : [
                    'columns' => array_values((array) ($result['columns'] ?? [])),
                    'rows' => array_values((array) ($result['rows'] ?? [])),
                    'truncated' => (bool) ($result['truncated'] ?? false),
                ];
        }
        $storage = $this->app->storage();
        $file = $storage->queryResultFile((string) $job['tenant_public_id'], (string) $job['workspace_public_id'], (string) $query['public_id']);
        $storage->ensureDir(dirname($file));
        file_put_contents($file, json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        $elapsed = (int) ($data['elapsed_ms'] ?? 0);
        $this->repo->finishQuery((int) $job['tenant_id'], (int) $query['id'], 'succeeded', $elapsed, null, null);
        return ['queries' => count($results), 'elapsed_ms' => $elapsed];
    }

    public function failed(array $job, string $errorCode, string $safeMessage): void
    {
        $query = $this->load($job);
        $status = match ($errorCode) {
            'TIMEOUT', 'QUERY_TIMEOUT' => 'timed_out',
            'CANCELLED' => 'cancelled',
            default => 'failed',
        };
        $this->repo->finishQuery((int) $job['tenant_id'], (int) $query['id'], $status, null, $errorCode, $safeMessage);
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function load(array $job): array
    {
        $payload = json_decode((string) $job['payload'], true);
        $query = $this->repo->findQueryForJob((int) $job['tenant_id'], (int) ($payload['query_id'] ?? 0));
        if ($query === null || $job['workspace_public_id'] === null || (int) $query['workspace_id'] !== (int) $job['workspace_id']) {
            throw new RuntimeException('Semantic query job references missing data');
        }
        return $query;
    }
}
