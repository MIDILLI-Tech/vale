<?php

/**
 * Router script for PHP built-in development server.
 *
 * Usage: php -S localhost:8089 -t public public/router.php
 *
 * The built-in server skips URLs that look like static files (.xml, .txt etc.)
 * This router ensures ALL requests pass through the front controller unless
 * they correspond to an actual static file on disk.
 */

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// If the request is for an existing static file, serve it directly
if ($uri !== '/' && file_exists(__DIR__ . $uri)) {
    return false;
}

// Otherwise route everything through the front controller
require __DIR__ . '/index.php';
