<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Content;

/**
 * Pure helpers for extracting image references from Ghost export posts
 * and rewriting them to local uploads paths after download.
 */
final class GhostAssetExtractor
{
    /**
     * Raw image URLs referenced by a Ghost export post entry,
     * in order of appearance, deduplicated.
     *
     * @param array<string, mixed> $post
     * @return string[]
     */
    public static function extractImageUrls(array $post): array
    {
        $raw = [];

        foreach (['feature_image', 'og_image'] as $key) {
            if (!empty($post[$key]) && is_string($post[$key])) {
                $raw[] = trim($post[$key]);
            }
        }

        if (!empty($post['html']) && is_string($post['html'])) {
            if (preg_match_all('/<img\b[^>]*\bsrc=["\']([^"\']+)["\']/i', $post['html'], $matches)) {
                foreach ($matches[1] as $src) {
                    $raw[] = html_entity_decode(trim($src), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }

            // <img srcset> candidates (e.g. Ghost's /content/images/size/w600/…)
            // belong to the same media — collect them too so responsive variants
            // are downloaded and rewritten to local paths.
            if (preg_match_all('/\bsrcset\s*=\s*["\']([^"\']+)["\']/i', $post['html'], $sets)) {
                foreach ($sets[1] as $set) {
                    foreach (explode(',', $set) as $candidate) {
                        $parts      = preg_split('/\s+/', trim($candidate), 2);
                        $candidate  = $parts[0] ?? '';
                        if ($candidate !== '') {
                            $raw[] = html_entity_decode(trim($candidate), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        }
                    }
                }
            }
        }

        $seen = [];
        $out  = [];
        foreach ($raw as $url) {
            if ($url === '' || isset($seen[$url])) {
                continue;
            }
            if (str_starts_with(strtolower($url), 'data:')) {
                continue;
            }
            $seen[$url] = true;
            $out[]      = $url;
        }
        return $out;
    }

    /**
     * Resolves a raw reference to an absolute downloadable URL.
     * Returns null when the reference cannot (or should not) be downloaded.
     */
    public static function normalizeImageUrl(string $raw, ?string $baseUrl): ?string
    {
        $raw = trim($raw);
        if ($raw === '' || str_starts_with(strtolower($raw), 'data:')) {
            return null;
        }

        if (str_starts_with($raw, '//')) {
            return self::schemeOf($baseUrl) . ':' . $raw;
        }

        // Ghost replaces the site's own URL with a __GHOST_URL__ placeholder
        // in exported post HTML. Resolve it against the provided source URL.
        if (str_starts_with($raw, '__GHOST_URL__')) {
            if ($baseUrl === null) {
                return null;
            }
            return rtrim($baseUrl, '/') . substr($raw, strlen('__GHOST_URL__'));
        }

        if (preg_match('#^https?://#i', $raw)) {
            return $raw;
        }

        if (str_starts_with($raw, '/')) {
            if ($baseUrl === null) {
                return null;
            }
            $parts = parse_url($baseUrl);
            if (!isset($parts['host'])) {
                return null;
            }
            $origin = self::schemeOf($baseUrl) . '://' . $parts['host']
                . (isset($parts['port']) ? ':' . $parts['port'] : '');
            return $origin . $raw;
        }

        return null;
    }

    /**
     * Replaces known source URLs with their local /uploads/ counterparts.
     *
     * @param array<string, string> $replacements source URL => local path
     */
    public static function rewriteHtml(string $html, array $replacements): string
    {
        return str_replace(array_keys($replacements), array_values($replacements), $html);
    }

    private static function schemeOf(?string $baseUrl): string
    {
        return ($baseUrl !== null && str_starts_with($baseUrl, 'http://')) ? 'http' : 'https';
    }
}
