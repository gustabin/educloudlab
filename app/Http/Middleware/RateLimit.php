<?php

declare(strict_types=1);

namespace EduCloud\Http\Middleware;

use EduCloud\Core\RateLimiter;
use EduCloud\Core\Request;
use EduCloud\Core\Response;

/** Applies the route's IP-based rate-limit policies (route option 'rate': list of policy names). */
final class RateLimit implements Middleware
{
    /** @param list<string> $policies */
    public function __construct(private readonly RateLimiter $limiter, private readonly array $policies)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        foreach ($this->policies as $policy) {
            $this->limiter->hit($policy, 'ip|' . $request->ip);
        }
        return $next($request);
    }
}
