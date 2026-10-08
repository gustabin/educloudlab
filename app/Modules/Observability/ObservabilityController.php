<?php

declare(strict_types=1);

namespace EduCloud\Modules\Observability;

use EduCloud\Core\App;
use EduCloud\Core\Request;
use EduCloud\Core\Response;
use EduCloud\Core\Validator;

/** Admin observability API (M11b): platform admins only (route permission 'platform_admin'). */
final class ObservabilityController
{
    public function __construct(private readonly App $app)
    {
    }

    public function health(Request $request): Response
    {
        return Response::json((new HealthService($this->app))->check(), 200, '', ['request_id' => $request->requestId]);
    }

    public function metrics(Request $request): Response
    {
        $q = Validator::validate($request->query, ['window' => ['in:' . implode(',', array_keys(MetricsService::WINDOWS))]]);
        $report = (new MetricsService($this->app))->report((string) ($q['window'] ?? '24h'));
        return Response::json($report, 200, '', ['request_id' => $request->requestId]);
    }

    public function logs(Request $request): Response
    {
        $q = Validator::validate($request->query, [
            'request_id' => ['ulid'],
            'level' => ['in:' . implode(',', LogReader::LEVELS)],
        ]);
        $requestId = isset($q['request_id']) ? (string) $q['request_id'] : null;
        $level = isset($q['level']) ? (string) $q['level'] : ($requestId === null ? 'warning' : null);
        $items = (new LogReader($this->app->logger->directory()))->recent($requestId, $level);
        return Response::json($items, 200, '', ['request_id' => $request->requestId, 'total' => count($items)]);
    }
}
