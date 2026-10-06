<?php

declare(strict_types=1);

/** @var array<string, string> $env */

return [
    // Strict CSP: every script and style is served from /assets (vendored, no CDN; ADR-011).
    'csp' => implode('; ', [
        "default-src 'self'",
        "script-src 'self'",
        "style-src 'self'",
        "img-src 'self' data:",
        "font-src 'self'",
        "connect-src 'self'",
        "object-src 'none'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'none'",
    ]),
    'headers' => [
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'X-Frame-Options' => 'DENY',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
        'Cross-Origin-Opener-Policy' => 'same-origin',
    ],
    'hsts' => 'max-age=31536000; includeSubDomains',
];
