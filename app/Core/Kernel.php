<?php

declare(strict_types=1);

namespace EduCloud\Core;

use EduCloud\Http\Middleware\Middleware;
use EduCloud\Http\Middleware\SecurityHeaders;
use Throwable;

/**
 * HTTP kernel: Router → global middleware → route middleware → handler → Response.
 * Route-level middleware (RateLimit, Authenticate, ResolveTenant, Authorize, Csrf) is added in M2/M3
 * by mapping route options to middleware instances in routeMiddleware().
 */
final class Kernel
{
    private ErrorHandler $errors;

    public function __construct(private readonly App $app)
    {
        $this->errors = new ErrorHandler($app);
    }

    public function handle(Request $request): Response
    {
        $this->app->logger->setRequestId($request->requestId);

        $core = function (Request $request): Response {
            try {
                $match = $this->app->router->match($request->method, $request->path);
                $request = $request
                    ->withAttribute('route_params', $match['params'])
                    ->withAttribute('route_options', $match['route']['options']);

                $handler = $match['route']['handler'];
                $app = $this->app;
                $pipeline = $this->pipeline(
                    $this->routeMiddleware($match['route']['options']),
                    static fn (Request $r): Response => $handler($r, $app)
                );
                $response = $pipeline($request);
                $response->indexable = (bool) $match['route']['options']['public'] && $response->status < 400;
                return $response;
            } catch (Throwable $e) {
                return $this->errors->handle($e, $request);
            }
        };

        // Global middleware wraps everything, including error responses.
        $global = [new SecurityHeaders($this->app->config)];
        try {
            $response = $this->pipeline($global, $core)($request);
        } catch (Throwable $e) {
            $response = $this->errors->handle($e, $request);
        }

        if ($request->method === 'HEAD') {
            $response->body = '';
        }
        return $response;
    }

    /**
     * @param array<string, mixed> $options
     * @return list<Middleware>
     */
    private function routeMiddleware(array $options): array
    {
        return [];
    }

    /**
     * @param list<Middleware>          $middleware
     * @param callable(Request): Response $last
     * @return callable(Request): Response
     */
    private function pipeline(array $middleware, callable $last): callable
    {
        $next = $last;
        foreach (array_reverse($middleware) as $mw) {
            $next = static fn (Request $r): Response => $mw->process($r, $next);
        }
        return $next;
    }
}
