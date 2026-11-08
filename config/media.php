<?php

declare(strict_types=1);

/**
 * Media / upload configuration.
 *
 * Uploadable extensions and MIME types are derived from the single-source
 * catalog in reference-data.php (`media_file_types`). Add new types there.
 */

$referenceData = require __DIR__ . '/reference-data.php';

$mediaFileTypeMap  = $referenceData['media_file_types'] ?? [];
$allowedExtensions = [];
$allowedMimeTypes  = [];
foreach ($mediaFileTypeMap as $ext => $acceptedMimes) {
    $allowedExtensions[] = $ext;
    foreach ((array) $acceptedMimes as $mime) {
        $allowedMimeTypes[$mime] = true;
    }
}

return [
    'driver' => env('STORAGE_DRIVER', 'local'),

    'upload_dir' => dirname(__DIR__) . '/storage/uploads',

    'max_file_size' => 0, // 0 = use PHP upload_max_filesize

    // Extension => accepted content detections (string or list) for the
    // extension ↔ MIME consistency check; extensions not listed here keep
    // whatever allowed_extensions permits.
    'file_type_map' => $mediaFileTypeMap,

    'allowed_extensions' => $allowedExtensions,

    'allowed_mime_types' => array_keys($allowedMimeTypes),

    'thumbnails' => [
        'driver' => 'gd',
        'sizes'  => [
            'small'  => ['width' => 150,  'height' => 150],
            'medium' => ['width' => 300,  'height' => 300],
            'large'  => ['width' => 1024, 'height' => 1024],
        ],
    ],

    // ── Multi-upload & Security ──────────────────────────────────────────

    // Maximum number of files per batch upload request (0 = use PHP max_file_uploads)
    'max_file_count' => 0,

    // Maximum total size of a single batch (0 = follow server post_max_size)
    'max_total_size' => 0,

    // Maximum uploads per user per minute (rate limit)
    'rate_limit' => 10,

    // Re-encode images on upload to strip EXIF metadata and prevent image bombs
    'enable_image_sanitize' => true,

    // Maximum pixel area for image bombs (e.g. 50M pixels = ~7071×7071)
    'max_image_pixels' => 50000000,
];
