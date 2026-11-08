<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Content;

/**
 * Builds and applies URL rewrites for WordPress media references inside
 * imported content (post/page bodies, excerpts, product descriptions).
 *
 * Two replacement layers are used:
 *  1. exact — a specific attachment URL maps to the actual stored local file.
 *     This matters when the local file could not keep its original name
 *     (path collision) or when the WordPress install lived in a subdirectory.
 *  2. base  — each distinct "…/wp-content/uploads/" base maps to the local
 *     uploads folder (root or subdirectory installs), which also rewrites
 *     references to WordPress size variants (-300x200.jpg, -scaled, srcset
 *     entries) that share the same base URL.
 *
 * New URLs are prefixed with the site's base path (from APP_URL) so content
 * works when Vale is installed in a subdirectory (e.g. /vale/uploads/…).
 */
final class WpMediaUrlRewriter
{
    public const LOCAL_BASE = '/uploads/';

    /**
     * @param array<int, array{url: string, path: string}> $attachments
     * @param string                                       $basePath Site base path (e.g. "/vale") from APP_URL path
     *
     * @return array{exact: array<string, string>, bases: array<string, string>}
     */
    public static function plan(array $attachments, string $basePath = ''): array
    {
        $exact = [];
        $bases = [];
        $base  = rtrim($basePath, '/') . '/uploads/';

        foreach ($attachments as $attachment) {
            $url  = trim((string) ($attachment['url'] ?? ''));
            $path = ltrim(str_replace('\\', '/', (string) ($attachment['path'] ?? '')), '/');
            if ($url === '' || $path === '' || str_starts_with(strtolower($url), 'data:')) {
                continue;
            }

            $exact[$url] = $base . $path;

            // Works for root installs and subdirectory installs alike
            // (e.g. https://host/blog/wp-content/uploads/…).
            $needle = '/wp-content/uploads/';
            $pos    = stripos($url, $needle);
            if ($pos !== false) {
                $bases[substr($url, 0, $pos + strlen($needle))] = $base;
            }
        }

        // Most specific strings first so an attachment URL wins over its base
        // when both are applied to the same subject.
        uksort($exact, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        uksort($bases, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return ['exact' => $exact, 'bases' => $bases];
    }

    /**
     * Strip a WordPress image size suffix, mapping a resized variant back to
     * its original file (e.g. ".../photo-1024x490.png" → ".../photo.png",
     * ".../photo-scaled.png" → ".../photo.png").
     *
     * Returns null when the URL carries no size suffix. Query strings and
     * fragments are preserved.
     */
    public static function originalForVariant(string $url): ?string
    {
        $q      = strpos($url, '?');
        $h      = strpos($url, '#');
        $suffix = '';
        if ($q !== false || $h !== false) {
            $cut    = min($q === false ? PHP_INT_MAX : $q, $h === false ? PHP_INT_MAX : $h);
            $suffix = substr($url, $cut);
            $url    = substr($url, 0, $cut);
        }

        if (!preg_match('#^(.*)-(\d+x\d+|scaled)(\.[A-Za-z0-9]+)$#', $url, $m)) {
            return null;
        }

        return $m[1] . $m[3] . $suffix;
    }

    /**
     * Applies the two replacement layers sequentially (exact first, then base).
     *
     * @param array<string, string> $exact
     * @param array<string, string> $bases
     */
    public static function apply(string $content, array $exact, array $bases): string
    {
        if ($exact !== []) {
            $content = str_replace(array_keys($exact), array_values($exact), $content);
        }
        if ($bases !== []) {
            $content = str_replace(array_keys($bases), array_values($bases), $content);
        }

        return $content;
    }
}
