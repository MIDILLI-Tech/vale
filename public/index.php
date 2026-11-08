<?php

declare(strict_types=1);

/**
 * Vale — Front Controller.
 *
 * All HTTP requests are routed through this file via .htaccess rewrite.
 * This is the ONLY PHP file inside the public/ web root.
 */

// Bot probes for WordPress endpoints (xmlrpc.php, …) — answer 404 without
// booting the app. Works on any web server, with or without .htaccess.
$probePath = strtolower((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/'));
if (basename($probePath) === 'xmlrpc.php') {
    http_response_code(404);
    exit;
}

// Uploads are written to storage/uploads (config/media.php), outside this web
// root. Apache/nginx serve them directly in the Docker images, and when the
// document root is the project root the root index.php resolves them there.
// With the document root set to public/ and no public/uploads symlink, nothing
// else can reach them, so stream the file here before the framework boots.
// Keep this behaviour in sync with the resolver in the root index.php.
$mediaPrefix = '/uploads/';
$mediaPath   = rawurldecode((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/'));
if (str_starts_with($mediaPath, $mediaPrefix)) {
    $mediaRelative = substr($mediaPath, strlen($mediaPrefix));
    $mediaRoot     = realpath(dirname(__DIR__) . '/storage/uploads');
    if ($mediaRoot !== false && $mediaRelative !== '') {
        require_once dirname(__DIR__) . '/bootstrap/serve-upload.php';
        // Dotfiles are configuration by convention: refuse them here so they
        // cannot be pulled out of storage/uploads through this resolver
        // (vl_serve_upload re-checks the basename as a second layer).
        if (vl_upload_path_is_hidden($mediaRelative)) {
            http_response_code(403);
            exit;
        }
        $mediaFile = realpath($mediaRoot . '/' . $mediaRelative);
        if ($mediaFile !== false
            && is_file($mediaFile)
            && str_starts_with($mediaFile . DIRECTORY_SEPARATOR, $mediaRoot . DIRECTORY_SEPARATOR)) {
            vl_serve_upload($mediaFile);
        }
    }
}

$lockFile = dirname(__DIR__) . '/storage/installed.lock';

require dirname(__DIR__) . '/bootstrap/not-installed.php';

if (!file_exists($lockFile)) {
    vl_handle_not_installed();
}

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->run();
