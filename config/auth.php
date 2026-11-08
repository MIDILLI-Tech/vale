<?php

declare(strict_types=1);

/**
 * Authentication & security configuration.
 */

return [
    'session' => [
        'lifetime'   => (int) env('SESSION_LIFETIME', 120), // minutes
        // Secure-by-default: cookies are only sent over HTTPS unless the
        // environment explicitly opts out (local plain-HTTP development).
        'secure'     => env('SESSION_SECURE', !in_array(env('APP_ENV', 'production'), ['local', 'dev'], true)),
        'httponly'   => env('SESSION_HTTPONLY', true),
        'samesite'   => env('SESSION_SAMESITE', 'Lax'),
    ],

    'password' => [
        'algo'       => PASSWORD_BCRYPT,
        'cost'       => 12,
        'min_length' => 8,
    ],

    'csrf' => [
        'token_name'  => '_csrf_token',
        'header_name' => 'X-CSRF-Token',
    ],

    'rate_limit' => [
        'login'        => (int) env('RATE_LIMIT_LOGIN', 5),  // per minute
        'form'         => (int) env('RATE_LIMIT_FORM', 3),   // per minute
        'api'          => (int) env('RATE_LIMIT_API', 60),   // per minute
        // Scanner probes (404/405 responses) per window before the IP is
        // throttled. Only error responses count, so normal traffic never
        // consumes this budget. Sessions holding a vl_session cookie are
        // exempt (see ProbeRateLimitMiddleware).
        'probe'        => (int) env('RATE_LIMIT_PROBE', 60),
        'probe_window' => (int) env('RATE_LIMIT_PROBE_WINDOW', 600), // seconds
        'storage_path' => dirname(__DIR__) . '/storage/cache/ratelimit',
    ],

    'remember_me' => [
        'enabled'  => true,
        'lifetime' => 30 * 24 * 60, // 30 days in minutes
    ],
];
