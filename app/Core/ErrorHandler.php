<?php

declare(strict_types=1);

namespace EduCloud\Core;

use EduCloud\Core\Exceptions\ApiException;
use EduCloud\Core\Exceptions\MethodNotAllowedException;
use EduCloud\Core\Exceptions\RateLimitedException;
use Throwable;

/**
 * Converts any Throwable into a safe response.
 * Clients only ever see a stable error code, a user-safe message and the request id.
 * Internal details (class, message, file, line) go to the log, never to the response.
 */
final class ErrorHandler
{
    public function __construct(private readonly App $app)
    {
    }

    public function handle(Throwable $e, Request $request): Response
    {
        $meta = ['request_id' => $request->requestId];

        if ($e instanceof ApiException) {
            if ($e->status >= 500) {
                $this->log($e, $request);
            }
            $response = $request->isApi()
                ? Response::error($e->status, $e->errorCode, $e->getMessage(), $e->details, $meta)
                : $this->htmlError($e->status, $e->getMessage(), $request);

            if ($e instanceof MethodNotAllowedException) {
                $response->withHeader('Allow', implode(', ', $e->allowed));
            }
            if ($e instanceof RateLimitedException) {
                $response->withHeader('Retry-After', (string) $e->retryAfter);
            }
            return $response;
        }

        $this->log($e, $request);
        $message = 'Ocurrió un error interno. Si persiste, informa el código de solicitud.';
        return $request->isApi()
            ? Response::error(500, 'INTERNAL_ERROR', $message, [], $meta)
            : $this->htmlError(500, $message, $request);
    }

    private function log(Throwable $e, Request $request): void
    {
        $this->app->logger->error('unhandled_exception', [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile() . ':' . $e->getLine(),
            'method' => $request->method,
            'path' => $request->path,
        ]);
    }

    private function htmlError(int $status, string $message, Request $request): Response
    {
        $titles = [
            401 => 'Inicia sesión para continuar',
            403 => 'Acceso denegado',
            404 => 'Página no encontrada',
            405 => 'Método no permitido',
            429 => 'Demasiadas solicitudes',
        ];
        try {
            $html = $this->app->view()->render('errors/error', [
                'status' => $status,
                'title' => $titles[$status] ?? 'Algo salió mal',
                'message' => $message,
                'requestId' => $request->requestId,
            ]);
        } catch (Throwable) {
            $html = '<!doctype html><meta charset="utf-8"><title>Error</title><p>Error ' . $status . '</p>';
        }
        return Response::html($html, $status);
    }
}
