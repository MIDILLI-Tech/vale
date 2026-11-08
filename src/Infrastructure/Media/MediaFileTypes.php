<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Media;

/**
 * Shared upload guards for the media file type catalog.
 *
 * The extension → MIME catalog lives in config/reference-data.php
 * (`media_file_types`) and reaches config/media.php as `file_type_map`.
 * This class adds the two checks that must NOT be configurable:
 * a hard extension blocklist and the extension ↔ detected-MIME match.
 */
final class MediaFileTypes
{
    /**
     * Extensions that can execute or configure the server when an uploads
     * directory is served directly by Apache/nginx. Blocked in code — a
     * misconfigured catalog or settings value cannot re-enable them.
     */
    public const BLOCKED_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phtml', 'phar',
        'htaccess', 'htpasswd', 'ini',
        'cgi', 'pl', 'py', 'rb', 'lua',
        'sh', 'bash', 'zsh', 'ksh', 'csh',
        'exe', 'dll', 'so', 'bat', 'cmd', 'com', 'msi',
        'vbs', 'vbe', 'jse', 'ws', 'wsf', 'ps1',
        'asp', 'aspx', 'ashx', 'asmx', 'cer',
        'jsp', 'jspx', 'shtml', 'shtm', 'xht', 'xhtml',
        'html', 'htm',
    ];

    public static function isBlockedExtension(string $extension): bool
    {
        return in_array(strtolower($extension), self::BLOCKED_EXTENSIONS, true);
    }

    /**
     * Whether the detected content MIME type is acceptable for the extension.
     *
     * Extensions missing from the catalog keep the previous "no opinion"
     * behavior — allowed_extensions has already gated them, so the check is
     * skipped rather than failing every custom configuration.
     *
     * @param array<string, string|array<int, string>> $fileTypeMap
     */
    public static function matchesDetectedMime(
        string $extension,
        string $detectedMime,
        array $fileTypeMap,
    ): bool {
        $ext = strtolower($extension);
        if (!array_key_exists($ext, $fileTypeMap)) {
            return true;
        }

        return in_array($detectedMime, (array) $fileTypeMap[$ext], true);
    }
}
