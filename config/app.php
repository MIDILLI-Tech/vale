<?php

declare(strict_types=1);

/**
 * General application configuration.
 */

return [
    'name'     => 'Vale',
    'env'      => env('APP_ENV', 'production'),
    'debug'    => env('APP_DEBUG', false),
    'url'      => env('APP_URL', 'http://localhost'),
    'key'      => env('APP_KEY', ''),
    'locale'   => env('APP_LOCALE', 'en'),

    // Max records sent to the browser for client-side search before switching
    // to server-side search/pagination (admin lists, editor pickers, account orders)
    'client_side_search_fetch_limit' => max(1, (int) env('CLIENT_SIDE_SEARCH_FETCH_LIMIT', 100)),

    // Supported locales for i18n
    'locales' => ['en', 'tr', 'de'],

    // Paths
    'paths' => [
        'root'      => dirname(__DIR__),
        'public'    => dirname(__DIR__) . '/public',
        'storage'   => dirname(__DIR__) . '/storage',
        'themes'    => dirname(__DIR__) . '/themes',
        'resources' => dirname(__DIR__) . '/resources',
    ],
];
