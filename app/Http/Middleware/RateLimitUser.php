<?php

declare(strict_types=1);

namespace EduCloud\Http\Middleware;

use EduCloud\Core\Auth\AuthUser;
use EduCloud\Core\RateLimiter;
use EduCloud\Core\Request;
use EduCloud\Core\Response;

/** Per-user throttle for state-changing requests of authenticated users (abuse / runaway scripts). */
final class RateLimitUser implements Middleware
{
    public function __construct(private readonly RateLimiter $limiter, private readonly string $policy)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $user = $request->attribute('user');
        if ($request->isUnsafeMethod() && $user instanceof AuthUser) {
            $this->limiter->hit($this->policy, 'user|' . $user->publicId);
        }
        return $next($request);
    }
}
