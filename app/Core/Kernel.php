<?php

declare(strict_types=1);

namespace EduCloud\Core;

use EduCloud\Http\Middleware\Authenticate;
use EduCloud\Http\Middleware\Authorize;
use EduCloud\Http\Middleware\CsrfProtection;
use EduCloud\Http\Middleware\Middleware;
use EduCloud\Http\Middleware\RateLimit;
use EduCloud\Http\Middleware\RateLimitUser;
use EduCloud\Http\Middleware\ResolveTenant;
use EduCloud\Http\Middleware\SecurityHeaders;
use EduCloud\Modules\Observability\RequestMetrics;
use Throwable;

/**
 * HTTP kernel: Router → global middleware → route middleware → handler → Response.
 * Route middleware order (from route options):
 *   RateLimit (IP) → Authenticate → CsrfProtection → RateLimitUser (unsafe methods) → ResolveTenant → Authorize.
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
        $startedAt = microtime(true);
        $route = '_unmatched'; // route NAME for metrics (M11b): never the URL, which carries ids

        $core = function (Request $request) use (&$route): Response {
            try {
                $match = $this->app->router->match($request->method, $request->path);
                $route = (string) ($match['route']['options']['name'] ?? '_unnamed');
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
        RequestMetrics::record($this->app, $route, $request->method, $response->status, $startedAt);
        return $response;
    }

    /**
     * @param array<string, mixed> $options
     * @return list<Middleware>
     */
    private function routeMiddleware(array $options): array
    {
        $stack = [];
        $rate = $options['rate'] ?? null;
        if ($rate !== null && $rate !== []) {
            $stack[] = new RateLimit($this->app->rateLimiter(), array_values((array) $rate));
        }
        $auth = (string) ($options['auth'] ?? 'none');
        $stack[] = new Authenticate($this->app, $auth);
        $stack[] = new CsrfProtection($this->app, ($options['csrf'] ?? true) !== false);
        $user = !in_array($auth, ['none', 'external'], true); // routes that resolve a user account
        if ($user) {
            $stack[] = new RateLimitUser($this->app->rateLimiter(), 'write_user');
            $stack[] = new ResolveTenant($this->app);
        }
        $permission = $options['permission'] ?? null;
        if (is_string($permission) && $permission !== '') {
            if (!$user) {
                throw new \LogicException('A route with a permission must require authentication');
            }
            $stack[] = new Authorize($this->app->config, $permission);
        }
        return $stack;
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
