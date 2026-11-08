<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Content;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Builds the summary shown whenever a post appears as a card or as a short
 * description (blog listings, RSS <description>, meta descriptions).
 *
 * Resolution order:
 *   1. manual excerpt (plain text, rendered escaped)
 *   2. content before the <!--more--> marker (raw HTML)
 *   3. auto-generated summary of the first N words (raw HTML, formatting kept)
 *
 * The auto step only keeps whole block elements and turns the block that
 * crosses the word budget into plain text, so the markup can never end up
 * unbalanced. Media, embeds and tables are dropped from the summary.
 */
final class ContentSummary
{
    public const KIND_EXCERPT = 'excerpt';
    public const KIND_MORE    = 'more';
    public const KIND_AUTO    = 'auto';
    public const KIND_EMPTY   = 'empty';

    private const NOISE_ELEMENTS = 'script|style|iframe|video|audio|svg|form|table|figure|noscript|object|embed|canvas';
    private const VOID_ELEMENTS  = 'img|hr|br|source|track|wbr|input';

    private const BLOCK_ELEMENTS   = ['p', 'ul', 'ol', 'blockquote'];
    private const HEADING_ELEMENTS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];
    private const SKIP_ELEMENTS    = [
        'pre', 'table', 'figure', 'form', 'svg', 'script', 'style', 'iframe',
        'video', 'audio', 'object', 'embed', 'noscript', 'canvas',
    ];

    private const WORD_PATTERN = '/[\p{L}\p{N}\p{M}]+(?:[\'’][\p{L}\p{N}\p{M}]+)*/u';

    /**
     * Card teaser. `html` is raw markup for the `more`/`auto` kinds and plain
     * text for the `excerpt` kind (callers must escape it).
     *
     * @return array{html: string, kind: string, label: ?string}
     */
    public static function blockTeaser(?string $body, ?string $excerpt, int $words = 55): array
    {
        $excerpt = trim((string) $excerpt);
        if ($excerpt !== '') {
            return ['html' => $excerpt, 'kind' => self::KIND_EXCERPT, 'label' => null];
        }

        $body  = (string) $body;
        $split = ContentMarkerNormalizer::splitOnMore($body);
        if ($split['has_more']) {
            return ['html' => $split['teaser'], 'kind' => self::KIND_MORE, 'label' => $split['label']];
        }

        if (trim($body) === '' || $words < 1) {
            return ['html' => '', 'kind' => self::KIND_EMPTY, 'label' => null];
        }

        $html = self::autoHtml($body, $words);
        if ($html === '') {
            return ['html' => '', 'kind' => self::KIND_EMPTY, 'label' => null];
        }

        return ['html' => $html, 'kind' => self::KIND_AUTO, 'label' => null];
    }

    /**
     * Plain-text summary for RSS descriptions and meta descriptions.
     */
    public static function plainTeaser(?string $body, ?string $excerpt, int $maxChars = 160): string
    {
        $excerpt = trim((string) $excerpt);

        if ($excerpt !== '') {
            return self::truncateChars(self::toPlainText($excerpt), $maxChars);
        }

        $body   = (string) $body;
        $split  = ContentMarkerNormalizer::splitOnMore($body);
        $source = $split['has_more'] ? $split['teaser'] : $body;

        return self::truncateChars(self::toPlainText($source), $maxChars);
    }

    private static function autoHtml(string $body, int $words): string
    {
        $blocks = self::extractBlocks(self::stripNoise($body));
        if ($blocks === []) {
            return '';
        }

        $html = '';
        $used = 0;

        foreach ($blocks as $block) {
            $text  = self::toPlainText($block);
            $count = self::countWords($text);
            if ($count === 0) {
                continue;
            }

            $remaining = $words - $used;
            if ($count <= $remaining) {
                $html .= $block;
                $used += $count;
                continue;
            }

            if ($remaining > 0) {
                $cut = self::truncateWords($text, $remaining);
                if ($cut !== '') {
                    $html .= '<p>' . self::escape($cut) . '&hellip;</p>';
                }
            }

            return $html;
        }

        return $html;
    }

    private static function stripNoise(string $html): string
    {
        $html = preg_replace('/<!--.*?-->/s', ' ', $html)                                          ?? $html;
        $html = preg_replace('#<(' . self::NOISE_ELEMENTS . ')\b[^>]*>.*?</\1\s*>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<(?:' . self::VOID_ELEMENTS . ')\b[^>]*/?>#i', ' ', $html)          ?? $html;

        return preg_replace('/\[\/?[a-z][a-z0-9_-]*(?:\s[^\]]*)?\]/', ' ', $html) ?? $html;
    }

    /**
     * @return list<string>
     */
    private static function extractBlocks(string $html): array
    {
        if (!class_exists(DOMDocument::class)) {
            return self::extractBlocksByPattern($html);
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded   = $document->loadHTML(
            '<?xml encoding="UTF-8"?><div id="vl-summary-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = null;
        if ($loaded) {
            $root = $document->getElementById('vl-summary-root');
            if (!$root instanceof DOMElement && $document->documentElement instanceof DOMElement) {
                $root = $document->documentElement;
            }
        }

        if (!$root instanceof DOMElement) {
            return self::extractBlocksByPattern($html);
        }

        $blocks = [];
        self::collectBlocks($root, $document, $blocks);

        return $blocks;
    }

    /**
     * @param list<string> $blocks
     */
    private static function collectBlocks(DOMNode $node, DOMDocument $document, array &$blocks): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $text = trim((string) $child->nodeValue);
                if ($text !== '') {
                    $blocks[] = '<p>' . self::escape($text) . '</p>';
                }
                continue;
            }

            if (!$child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->nodeName);

            if (in_array($tag, self::SKIP_ELEMENTS, true)) {
                continue;
            }

            if (in_array($tag, self::HEADING_ELEMENTS, true)) {
                $inner = '';
                foreach ($child->childNodes as $headingChild) {
                    $inner .= $document->saveHTML($headingChild) ?: '';
                }
                if (trim(strip_tags($inner)) !== '') {
                    $blocks[] = '<p><strong>' . $inner . '</strong></p>';
                }
                continue;
            }

            if (in_array($tag, self::BLOCK_ELEMENTS, true)) {
                $block = $document->saveHTML($child);
                if ($block !== false && trim($block) !== '') {
                    $blocks[] = $block;
                }
                continue;
            }

            self::collectBlocks($child, $document, $blocks);
        }
    }

    /**
     * @return list<string>
     */
    private static function extractBlocksByPattern(string $html): array
    {
        $blocks = [];

        if (preg_match_all('#<(p|ul|ol|blockquote|h[1-6])\b[^>]*>.*?</\1>#is', $html, $matches)) {
            $blocks = array_values(array_filter(array_map('trim', $matches[0]), static fn (string $block): bool => $block !== ''));
        }

        if ($blocks === []) {
            $text = self::toPlainText($html);
            if ($text !== '') {
                $blocks[] = '<p>' . self::escape($text) . '</p>';
            }
        }

        return $blocks;
    }

    private static function toPlainText(string $html): string
    {
        $text = self::stripNoise($html);
        $text = preg_replace('#<br\s*/?>#i', ' ', $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xC2\xA0", ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private static function countWords(string $text): int
    {
        return (int) preg_match_all(self::WORD_PATTERN, $text);
    }

    private static function truncateWords(string $text, int $limit): string
    {
        if ($limit < 1) {
            return '';
        }

        if (!preg_match_all(self::WORD_PATTERN, $text, $matches, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        if (count($matches[0]) <= $limit) {
            return $text;
        }

        [$word, $offset] = $matches[0][$limit - 1];

        return rtrim(substr($text, 0, $offset + strlen($word)), " \t\n\r\0\x0B.,;:!?");
    }

    private static function truncateChars(string $text, int $maxChars): string
    {
        if ($maxChars < 1 || $text === '') {
            return '';
        }

        if (mb_strlen($text, 'UTF-8') <= $maxChars) {
            return $text;
        }

        $cut      = mb_substr($text, 0, $maxChars, 'UTF-8');
        $lastGap  = mb_strrpos($cut, ' ', 0, 'UTF-8');
        if ($lastGap !== false && $lastGap > (int) ($maxChars * 0.6)) {
            $cut = mb_substr($cut, 0, $lastGap, 'UTF-8');
        }

        return rtrim($cut, " \t\n\r\0\x0B.,;:!?") . '…';
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
