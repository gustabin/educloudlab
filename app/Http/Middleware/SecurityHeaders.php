<?php

declare(strict_types=1);

namespace EduCloud\Http\Middleware;

use EduCloud\Core\Config;
use EduCloud\Core\Request;
use EduCloud\Core\Response;

/**
 * Adds security headers to every response (including error responses).
 * Pages are noindex unless the matched route is explicitly marked 'public' (SEO pages only).
 */
final class SecurityHeaders implements Middleware
{
    public function __construct(private readonly Config $config)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $response = $next($request);

        foreach ((array) $this->config->get('security.headers', []) as $name => $value) {
            $response->withHeader((string) $name, (string) $value);
        }
        $response->withHeader('Content-Security-Policy', (string) $this->config->get('security.csp'));
        if ($request->secure) {
            $response->withHeader('Strict-Transport-Security', (string) $this->config->get('security.hsts'));
        }
        if (!$response->indexable) {
            $response->withHeader('X-Robots-Tag', 'noindex, nofollow');
        }
        $response->withHeader('X-Request-Id', $request->requestId);

        return $response;
    }
}
