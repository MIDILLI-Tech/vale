<?php

/**
 * Vale — Entry Point.
 * Forwards all requests to public/index.php.
 */

$dir = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
if ($dir === '/' || $dir === '\\') {
    $dir = '';
}

// Serve static files directly (uploads, assets)
// uploads -> public/uploads symlink, fallback to storage/uploads
$staticMap = [
    'uploads' => ['public/uploads', 'storage/uploads'],
    'assets'  => ['public/assets'],
];
$reqPath = $_SERVER['REQUEST_URI'] ?? '/';
if (($pos = strpos($reqPath, '?')) !== false) {
    $reqPath = substr($reqPath, 0, $pos);
}
$reqPath = rawurldecode($reqPath);
$reqPath = str_replace('\\', '/', $reqPath);
$reqPath = preg_replace('#/+#', '/', $reqPath) ?? $reqPath;
$reqPath = ltrim($reqPath, '/');

// Strip base path (e.g. "test/" from "test/uploads/...")
if ($dir !== '' && str_starts_with($reqPath, ltrim($dir, '/'))) {
    $reqPath = substr($reqPath, strlen(ltrim($dir, '/')));
    $reqPath = ltrim($reqPath, '/');
}

foreach ($staticMap as $sDir => $searchDirs) {
    if (str_starts_with($reqPath, $sDir . '/') || $reqPath === $sDir) {
        $filePath = null;
        $relative = ltrim(substr($reqPath, strlen($sDir)), '/');
        foreach ($searchDirs as $searchDir) {
            $root = realpath(__DIR__ . '/' . $searchDir);
            if ($root === false || !is_dir($root)) {
                continue;
            }
            $candidate = $root . '/' . $relative;
            $resolved  = realpath($candidate);
            if ($resolved === false || !is_file($resolved)) {
                continue;
            }
            if (!str_starts_with($resolved . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR)) {
                continue;
            }
            $filePath = $resolved;
            break;
        }
        if ($filePath && file_exists($filePath)) {
            if ($sDir === 'uploads') {
                // Shared with the public/ entry point: same headers and the
                // same refusal to stream source or markup.
                require_once __DIR__ . '/bootstrap/serve-upload.php';
                // Dotfiles and hidden directories are configuration by
                // convention, not media: same refusal as the public/ front
                // controller and the web-server paths.
                if (vl_upload_path_is_hidden($relative)) {
                    http_response_code(403);
                    exit;
                }
                vl_serve_upload($filePath);
            }
            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            // Serving types live in reference-data.php (pure array file, safe
            // to require before the framework boots). The validation catalog
            // (media_file_types) accepts multiple detections per extension;
            // this map is the single Content-Type the browser receives.
            $mimeTypes = [];
            $refFile   = __DIR__ . '/config/reference-data.php';
            if (is_file($refFile)) {
                $refData   = require $refFile;
                $mimeTypes = is_array($refData['media_serve_types'] ?? null)
                    ? $refData['media_serve_types']
                    : [];
            }
            if (isset($mimeTypes[$ext])) {
                header('Content-Type: ' . $mimeTypes[$ext]);
            }
            // Uploads may be served by the web server directly (Apache/nginx
            // static config) — nosniff here covers the PHP fallback path so
            // unknown extensions cannot be sniffed into an executable type.
            header('X-Content-Type-Options: nosniff');
            header('Content-Length: ' . filesize($filePath));
            readfile($filePath);
            exit;
        }
    }
}
if ($dir === '/' || $dir === '\\') {
    $dir = '';
}

$uri = $_SERVER['REQUEST_URI'] ?? '/';

// DEBUG: signal that root index.php runs
header('X-Debug-Index: RAN-uri=' . rawurlencode($uri) . '-dir=' . rawurlencode($dir ?? ''));

// Strip query string
if (($pos = strpos($uri, '?')) !== false) {
    $uri = substr($uri, 0, $pos);
}

// Strip leading slash
$uri = ltrim($uri, '/');

// Strip base path
if ($dir !== '' && str_starts_with($uri, ltrim($dir, '/'))) {
    $uri = substr($uri, strlen(ltrim($dir, '/')));
}
$uri = ltrim($uri, '/');

// Strip trailing slash (Slim routes don't use them)
$uri = rtrim($uri, '/');

// If empty or root, serve index
if ($uri === '') {
    $_SERVER['REQUEST_URI'] = '/';
} else {
    $_SERVER['REQUEST_URI'] = '/' . $uri;
}
// Debug to file (only when APP_DEBUG=true in .env)
if (getenv('APP_DEBUG') === 'true' || ($_ENV['APP_DEBUG'] ?? '') === 'true'
                                   || (file_exists(__DIR__ . '/.env') && preg_match('/^APP_DEBUG\s*=\s*true\s*$/m', file_get_contents(__DIR__ . '/.env')))) {
    @file_put_contents(__DIR__ . '/storage/logs/vl_debug.log', date('H:i:s') . ' SlimURI=' . $_SERVER['REQUEST_URI'] . ' $uri=' . var_export($uri, true) . PHP_EOL, FILE_APPEND);
}

$_SERVER['SCRIPT_NAME'] = $dir . '/public/index.php';
$_SERVER['PHP_SELF']    = $dir . '/public/index.php';

require __DIR__ . '/public/index.php';
