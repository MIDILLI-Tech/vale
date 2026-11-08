<?php

declare(strict_types=1);

/**
 * Cache configuration.
 */

return [
    'driver' => env('CACHE_DRIVER', 'file'),

    // Default TTL in seconds (1 hour)
    'ttl' => (int) env('CACHE_TTL', 3600),

    // Flat-file cache storage path
    'path' => dirname(__DIR__) . '/storage/cache/data',

    // Page cache
    'page' => [
        'enabled' => env('PAGE_CACHE_ENABLED', true)
            && !env('APP_DEBUG', false)
            && env('APP_ENV', 'production') !== 'dev',
        'path'    => dirname(__DIR__) . '/storage/cache/pages',
        'ttl'     => (int) env('PAGE_CACHE_TTL', env('CACHE_TTL', 86400)), // default 24h

        // URL patterns that are NEVER cached
        'exclude' => [
            '/admin*',
            '/api/*',
            '/cart*',
            '/checkout*',
            '/pay/*',
            '/account*',
            '/login',
            '/register',
            '/logout',
        ],
    ],

    // Twig compiled templates
    'views' => dirname(__DIR__) . '/storage/cache/views',
];
