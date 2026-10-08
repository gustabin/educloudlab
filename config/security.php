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

    // Browser sessions (server-side, table `sessions`).
    'session' => [
        'cookie' => 'ecsid',
        'idle_timeout' => 30 * 60,          // seconds of inactivity before the session ends
        'absolute_lifetime' => 8 * 3600,    // hard limit regardless of activity
        'touch_interval' => 60,             // minimum seconds between last_activity updates
    ],

    // Passwords: Argon2id (no 72-byte truncation). Length-based policy (NIST SP 800-63B), no composition rules.
    'password' => [
        'min_length' => 12,
        'max_length' => 128,
    ],

    // Soft lockout after consecutive failed logins: failures => lock seconds (largest matching threshold applies).
    // Deliberately short and non-escalating to limit targeted denial of service; the owner is emailed
    // when it triggers and a password reset clears it. Brute force is mainly stopped by the rate limits.
    'lockout' => [
        10 => 15 * 60,
    ],

    'tokens' => [
        'email_verify_ttl' => 24 * 3600,
        'password_reset_ttl' => 3600,
    ],

    // API clients (ADR-003). Keys: "kid:base64key[,kid:base64key...]"; the first key signs, all keys verify.
    'jwt' => [
        'keys' => $env['JWT_KEYS'] ?? '',
        'issuer' => $env['JWT_ISSUER'] ?? '',
        'audience' => $env['JWT_AUDIENCE'] ?? 'educloud-api',
        'access_ttl' => 15 * 60,
        'refresh_ttl' => 30 * 24 * 3600,
        'leeway' => 30,
    ],

    // Fixed-window rate limits: limit hits per window (seconds).
    'rate_limits' => [
        'auth_ip' => ['limit' => 30, 'window' => 15 * 60],          // all auth endpoints, per client IP
        'login_account' => ['limit' => 10, 'window' => 15 * 60],    // per (email address, client IP)
        'register_ip' => ['limit' => 10, 'window' => 3600],
        'email_account' => ['limit' => 3, 'window' => 3600],        // verification / reset emails per address
        'token_refresh_ip' => ['limit' => 60, 'window' => 15 * 60],
        'write_user' => ['limit' => 60, 'window' => 60],            // unsafe API calls per authenticated user
        'course_join_ip' => ['limit' => 30, 'window' => 15 * 60],   // course join codes, per client IP
        'course_join_user' => ['limit' => 10, 'window' => 15 * 60], // course join codes, per user (guessing codes)
        'metrics_ip' => ['limit' => 30, 'window' => 60],            // GET /metrics scrapes, per client IP
    ],
];
