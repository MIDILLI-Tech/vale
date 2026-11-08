<?php

declare(strict_types=1);

/**
 * Stream a file from storage/uploads when the web server could not.
 *
 * Uploads live outside public/ (see config/media.php). Apache/nginx serve them
 * directly in the Docker images, and the project-root entry point resolves them
 * there; when the document root is public/ and no public/uploads symlink
 * exists, the request reaches PHP instead. Both entry points call this helper
 * so the response contract stays identical.
 *
 * Callers are responsible for resolving the path and keeping it inside
 * storage/uploads.
 */
/**
 * Content-Type for an uploaded file, or null when it must not be served.
 *
 * Media is validated on write, but files can also be dropped into
 * storage/uploads by hand and registered with `vale media:scan`. Code and
 * markup are refused outright; unknown extensions download as binary data so
 * browsers cannot sniff them into a renderable or executable type.
 */
function vl_upload_content_type(string $extension): ?string
{
    $extension = strtolower($extension);

    if (in_array($extension, [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phar',
        'cgi', 'pl', 'py', 'sh', 'html', 'htm', 'xhtml', 'shtml',
    ], true)) {
        return null;
    }

    // Serving types live in reference-data.php (pure array file, safe to
    // require before the framework boots).
    $mimeTypes = [];
    $reference = dirname(__DIR__) . '/config/reference-data.php';
    if (is_file($reference)) {
        $referenceData = require $reference;
        $mimeTypes     = is_array($referenceData['media_serve_types'] ?? null)
            ? $referenceData['media_serve_types']
            : [];
    }

    return $mimeTypes[$extension] ?? 'application/octet-stream';
}

/**
 * Whether a media path reaches a hidden file or directory.
 *
 * Dotfiles are configuration by convention (.env, .htaccess, .git): never
 * serve one, even if it was dropped into storage/uploads by hand. The web
 * server paths enforce the same rule (Apache `FilesMatch "^\.">`, nginx
 * dotfile location). Accepts a path relative to storage/uploads — callers
 * pass that form; a trailing basename check in vl_serve_upload() covers
 * anything that arrives already resolved.
 */
function vl_upload_path_is_hidden(string $relativePath): bool
{
    return preg_match('#(^|/)\.#', $relativePath) === 1;
}

function vl_serve_upload(string $filePath): never
{
    // Defense in depth: a resolved path whose final segment is a dotfile is
    // configuration, not media, whatever extension it carries.
    if (str_starts_with(basename($filePath), '.')) {
        http_response_code(403);
        exit;
    }

    $contentType = vl_upload_content_type(pathinfo($filePath, PATHINFO_EXTENSION));
    if ($contentType === null) {
        http_response_code(403);
        exit;
    }

    header('Content-Type: ' . $contentType);
    // The web-server paths set the same header; without it unknown extensions
    // could be sniffed into an executable type.
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
    exit;
}
