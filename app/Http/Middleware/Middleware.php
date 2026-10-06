<?php

declare(strict_types=1);

namespace EduCloud\Http\Middleware;

use EduCloud\Core\Request;
use EduCloud\Core\Response;

interface Middleware
{
    /** @param callable(Request): Response $next */
    public function process(Request $request, callable $next): Response;
}
