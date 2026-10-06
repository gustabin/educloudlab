<?php

declare(strict_types=1);

namespace EduCloud\Modules\System;

use EduCloud\Core\App;
use EduCloud\Core\Request;
use EduCloud\Core\Response;
use Throwable;

/** Liveness/readiness probe. Reveals no versions, hostnames or error details. */
final class HealthController
{
    public function __construct(private readonly App $app)
    {
    }

    public function show(Request $request): Response
    {
        $database = 'ok';
        try {
            $this->app->db()->scalar('SELECT 1');
        } catch (Throwable $e) {
            $database = 'unavailable';
            $this->app->logger->error('health_db_unavailable', ['exception' => get_class($e)]);
        }

        $meta = ['request_id' => $request->requestId];
        if ($database !== 'ok') {
            return Response::error(503, 'SERVICE_UNAVAILABLE', 'El servicio no está disponible temporalmente.', [], $meta);
        }
        return Response::json(['status' => 'ok', 'checks' => ['database' => $database]], 200, '', $meta);
    }
}
