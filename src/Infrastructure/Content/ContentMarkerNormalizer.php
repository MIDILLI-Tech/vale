<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Content;

/**
 * Normalizes page-break / read-more markers found in imported content
 * (most notably WordPress) to the canonical formats used by Vale:
 *
 *   <!--nextpage-->                      or  <!--nextpage title="..."-->
 *   <!--more-->                          or  <!--more text="..."-->
 *
 * Accepts common source variants such as extra whitespace, mixed case,
 * WordPress-style plain-text more labels ("<!--more Keep reading -->")
 * and <p>-wrapped markers produced by rich-text editors.
 */
final class ContentMarkerNormalizer
{
    private const NEXTPAGE_PATTERN       = '/<!--\s*nextpage(?:\s+title\s*=\s*(["\']|&quot;)(.*?)\1)?\s*-->/i';
    private const MORE_PATTERN           = '/<!--\s*more(?:\s+(.*?))?\s*-->/is';
    private const WRAPPED_MARKER_PATTERN = '/<p\b[^>]*>\s*(<!--(?:nextpage|more)(?:\s+[^>]*?)?-->)\s*<\/p>/i';

    public static function normalize(string $body): string
    {
        return self::unwrapWrappedMarkers(self::normalizeMore(self::normalizeNextPage($body)));
    }

    public static function normalizeNextPage(string $body): string
    {
        $normalized = preg_replace_callback(
            self::NEXTPAGE_PATTERN,
            static function (array $m): string {
                $title = isset($m[2]) ? trim((string) $m[2]) : '';
                if ($title === '') {
                    return '<!--nextpage-->';
                }
                return '<!--nextpage title="' . self::escapeAttr($title) . '"-->';
            },
            $body
        );

        return $normalized ?? $body;
    }

    public static function normalizeMore(string $body): string
    {
        $normalized = preg_replace_callback(
            self::MORE_PATTERN,
            static function (array $m): string {
                $raw = isset($m[1]) ? trim((string) $m[1]) : '';
                if ($raw === '') {
                    return '<!--more-->';
                }
                if (preg_match('/^text\s*=\s*(["\']|&quot;)(.*)\1$/is', $raw, $attr)) {
                    $label = trim((string) $attr[2]);
                    return $label === '' ? '<!--more-->' : '<!--more text="' . self::escapeAttr($label) . '"-->';
                }
                return '<!--more text="' . self::escapeAttr($raw) . '"-->';
            },
            $body
        );

        return $normalized ?? $body;
    }

    /**
     * Splits a body on the more marker.
     *
     * @return array{teaser: string, label: ?string, has_more: bool}
     */
    public static function splitOnMore(?string $body): array
    {
        $body ??= '';
        if ($body === '') {
            return ['teaser' => '', 'label' => null, 'has_more' => false];
        }

        if (!preg_match('/<!--\s*more(?:\s+text\s*=\s*(["\']|&quot;)(.*?)(?:["\']|&quot;))?\s*-->/is', $body, $m, PREG_OFFSET_CAPTURE)) {
            return ['teaser' => $body, 'label' => null, 'has_more' => false];
        }

        $label = isset($m[2]) && $m[2][1] !== -1 ? html_entity_decode(trim((string) $m[2][0]), ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;

        return [
            'teaser'   => substr($body, 0, (int) $m[0][1]),
            'label'    => $label !== null && $label !== '' ? $label : null,
            'has_more' => true,
        ];
    }

    private static function unwrapWrappedMarkers(string $body): string
    {
        $unwrapped = preg_replace(self::WRAPPED_MARKER_PATTERN, '$1', $body);

        return $unwrapped ?? $body;
    }

    private static function escapeAttr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8', false);
    }
}
