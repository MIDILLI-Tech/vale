<?php

declare(strict_types=1);

namespace ValeApp\Application\Plugin;

/**
 * Resolves a request path to a file inside content/plugins/<slug>/assets/.
 *
 * Default deny: only the extensions in CONTENT_TYPES are served, and the
 * resolved file must be a real file inside the assets/ directory — traversal,
 * symlink escapes and dotfiles are refused. Anything else returns null so the
 * caller can answer 404.
 */
final class PluginAssetResolver
{
    /** @var array<string, string> */
    private const CONTENT_TYPES = [
        'css'   => 'text/css',
        'js'    => 'application/javascript',
        'mjs'   => 'application/javascript',
        'json'  => 'application/json',
        'map'   => 'application/json',
        'txt'   => 'text/plain',
        'vtt'   => 'text/vtt',
        'svg'   => 'image/svg+xml',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'webp'  => 'image/webp',
        'avif'  => 'image/avif',
        'ico'   => 'image/x-icon',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'otf'   => 'font/otf',
        'eot'   => 'application/vnd.ms-fontobject',
        'mp4'   => 'video/mp4',
        'webm'  => 'video/webm',
        'mp3'   => 'audio/mpeg',
        'wasm'  => 'application/wasm',
    ];

    /** Types that need an explicit charset to render correctly. */
    private const TEXTUAL = [
        'text/css',
        'text/plain',
        'text/vtt',
        'application/javascript',
        'application/json',
        'image/svg+xml',
    ];

    /**
     * @return array{path:string, content_type:string, size:int}|null
     */
    public function resolve(?string $pluginDirectory, string $relativePath): ?array
    {
        if ($pluginDirectory === null || $pluginDirectory === '') {
            return null;
        }

        $relativePath = str_replace('\\', '/', $relativePath);
        if ($relativePath === '' || strlen($relativePath) > 255 || str_contains($relativePath, "\0")) {
            return null;
        }

        $segments = explode('/', $relativePath);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_starts_with($segment, '.')) {
                return null;
            }
        }

        $assetsRoot = realpath($pluginDirectory . '/assets');
        if ($assetsRoot === false || !is_dir($assetsRoot)) {
            return null;
        }

        $file = realpath($assetsRoot . '/' . implode('/', $segments));
        if ($file === false || !is_file($file)) {
            return null;
        }

        if (!str_starts_with($file, $assetsRoot . DIRECTORY_SEPARATOR)) {
            return null;
        }

        $contentType = self::CONTENT_TYPES[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? null;
        if ($contentType === null) {
            return null;
        }

        if (in_array($contentType, self::TEXTUAL, true)) {
            $contentType .= '; charset=UTF-8';
        }

        return [
            'path'         => $file,
            'content_type' => $contentType,
            'size'         => (int) filesize($file),
        ];
    }
}
