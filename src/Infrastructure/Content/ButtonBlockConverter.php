<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Content;

/**
 * Converts external button blocks into Vale's [link_button_group] / [link_button] shortcodes.
 *
 * Supported sources:
 *   - WordPress Gutenberg: <!-- wp:buttons -->…<!-- /wp:buttons --> and standalone <!-- wp:button -->…<!-- /wp:button -->
 *   - Ghost: <div class="kg-card kg-button-card">…</div>
 */
final class ButtonBlockConverter
{
    /**
     * Convert WordPress Gutenberg button blocks inside post/page content.
     */
    public static function convertWordPress(string $body): string
    {
        // wp:buttons group (may contain multiple wp:button children)
        $body = preg_replace_callback(
            '/<!--\s*wp:buttons\s*-->.*?<!--\s*\/wp:buttons\s*-->/is',
            [self::class, 'convertWpButtonsGroup'],
            $body
        );

        // Standalone wp:button (single button, not wrapped in wp:buttons)
        $body = preg_replace_callback(
            '/<!--\s*wp:button\s*-->.*?<!--\s*\/wp:button\s*-->/is',
            [self::class, 'convertWpButtonStandalone'],
            $body
        );

        return $body;
    }

    /**
     * Convert Ghost kg-button-card blocks.
     */
    public static function convertGhost(string $body): string
    {
        $body = preg_replace_callback(
            '/<div\s+class="kg-card\s+kg-button-card">.*?<\/div>/is',
            [self::class, 'convertGhostCard'],
            $body
        );

        return $body;
    }

    // ── WordPress ──────────────────────────────────────────────────────

    private static function convertWpButtonsGroup(array $matches): string
    {
        $html      = $matches[0];
        $alignment = self::extractWpAlignment($html);

        $buttons = [];
        preg_match_all(
            '/<div\s+class="wp-block-button">\s*<a\s+class="wp-block-button__link"([^>]*)>(.*?)<\/a>\s*<\/div>/is',
            $html,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $attrStr            = $match[1];
            $text               = self::cleanText($match[2]);
            $attrs              = self::parseAnchorAttrs($attrStr);
            $attrs['alignment'] = $alignment;
            $buttons[]          = self::buildLinkButtonShortcode($attrs, $text);
        }

        if ($buttons === []) {
            return $html;
        }

        return self::buildGroupShortcode($buttons, 'vertical', '12', $alignment);
    }

    private static function convertWpButtonStandalone(array $matches): string
    {
        $html = $matches[0];
        // Wrap the same conversion as a single-button group
        $group = self::convertWpButtonsGroup([$html]);
        if ($group === $html) {
            return $html;
        }
        return $group;
    }

    // ── Ghost ──────────────────────────────────────────────────────────

    private static function convertGhostCard(array $matches): string
    {
        $html = $matches[0];
        preg_match(
            '/<a\s+class="kg-button"([^>]*)>(.*?)<\/a>/is',
            $html,
            $match
        );

        if (!$match) {
            return $html;
        }

        $text               = self::cleanText($match[2]);
        $attrs              = self::parseAnchorAttrs($match[1]);
        $attrs['alignment'] = $attrs['alignment'] ?? 'center';

        $button    = self::buildLinkButtonShortcode($attrs, $text);
        $alignment = $attrs['alignment'];

        return self::buildGroupShortcode([$button], 'vertical', '12', $alignment);
    }

    // ── Shared helpers ─────────────────────────────────────────────────

    private static function extractWpAlignment(string $html): string
    {
        if (preg_match('/is-content-justification-(left|center|right)/', $html, $m)) {
            return $m[1];
        }

        return 'center';
    }

    private static function parseAnchorAttrs(string $attrStr): array
    {
        $attrs = [];

        if (preg_match('/href="([^"]*)"/', $attrStr, $m)) {
            $attrs['href'] = $m[1];
        }

        if (preg_match('/target="([^"]*)"/', $attrStr, $m)) {
            $attrs['target'] = $m[1];
        }

        if (preg_match('/style="([^"]*)"/', $attrStr, $m)) {
            $styleAttrs = self::parseCssStyle($m[1]);
            $attrs      = array_merge($attrs, $styleAttrs);
        }

        return $attrs;
    }

    private static function parseCssStyle(string $style): array
    {
        $attrs = [];
        $parts = explode(';', $style);

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            [$key, $value] = explode(':', $part, 2) + [1 => ''];
            $key           = trim($key);
            $value         = trim($value);

            switch ($key) {
                case 'background-color':
                    $attrs['bgColor'] = $value;
                    break;
                case 'color':
                    $attrs['textColor'] = $value;
                    break;
                case 'border':
                    $parsed = self::parseBorder($value);
                    $attrs  = array_merge($attrs, $parsed);
                    break;
                case 'border-radius':
                    $attrs['borderRadius'] = $value;
                    break;
                case 'padding':
                    $attrs['padding'] = $value;
                    break;
                case 'border-style':
                    $attrs['borderStyle'] = $value;
                    break;
                case 'border-width':
                    $attrs['borderWidth'] = $value;
                    break;
                case 'border-color':
                    $attrs['borderColor'] = $value;
                    break;
            }
        }

        return $attrs;
    }

    private static function parseBorder(string $value): array
    {
        $result = [
            'borderWidth' => '0px',
            'borderStyle' => 'none',
            'borderColor' => '#000000',
        ];

        $parts = preg_split('/\s+/', trim($value));
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            if (preg_match('/^(\d+(?:\.\d+)?)(px|em|rem|%|pt|)$/', $part)) {
                $result['borderWidth'] = $part;
            } elseif (in_array($part, ['none', 'solid', 'dashed', 'dotted', 'double', 'groove', 'ridge', 'inset', 'outset', 'hidden'], true)) {
                $result['borderStyle'] = $part;
            } elseif (preg_match('/^#([0-9a-fA-F]{3,8})$/', $part) || preg_match('/^(rgb|hsl)/', $part)) {
                $result['borderColor'] = $part;
            }
        }

        return $result;
    }

    private static function buildLinkButtonShortcode(array $attrs, string $text): string
    {
        $href         = $attrs['href']         ?? '';
        $target       = $attrs['target']       ?? '_self';
        $bgColor      = $attrs['bgColor']      ?? '#000000';
        $textColor    = $attrs['textColor']    ?? '#ffffff';
        $borderWidth  = $attrs['borderWidth']  ?? '0px';
        $borderStyle  = $attrs['borderStyle']  ?? 'none';
        $borderColor  = $attrs['borderColor']  ?? '#000000';
        $borderRadius = $attrs['borderRadius'] ?? '4px';
        $padding      = $attrs['padding']      ?? '10px 20px';
        $alignment    = $attrs['alignment']    ?? 'center';
        $fullWidth    = $attrs['fullWidth']    ?? 'false';

        $parts   = [];
        $parts[] = 'href="' . $href . '"';
        if ($target !== '_self') {
            $parts[] = 'target="' . $target . '"';
        }
        if ($bgColor !== '#000000') {
            $parts[] = 'bgColor="' . $bgColor . '"';
        }
        if ($textColor !== '#ffffff') {
            $parts[] = 'textColor="' . $textColor . '"';
        }
        if ($borderWidth !== '0px') {
            $parts[] = 'borderWidth="' . $borderWidth . '"';
        }
        if ($borderStyle !== 'none') {
            $parts[] = 'borderStyle="' . $borderStyle . '"';
        }
        if ($borderColor !== '#000000') {
            $parts[] = 'borderColor="' . $borderColor . '"';
        }
        if ($borderRadius !== '4px') {
            $parts[] = 'borderRadius="' . $borderRadius . '"';
        }
        if ($padding !== '10px 20px') {
            $parts[] = 'padding="' . $padding . '"';
        }
        if ($alignment !== 'center') {
            $parts[] = 'alignment="' . $alignment . '"';
        }
        if ($fullWidth === 'true') {
            $parts[] = 'fullWidth="true"';
        }

        return '[link_button ' . implode(' ', $parts) . ']' . $text . '[/link_button]';
    }

    private static function buildGroupShortcode(array $buttons, string $layout, string $gap, string $alignment): string
    {
        $attrParts   = [];
        $attrParts[] = 'layout="' . $layout . '"';
        $attrParts[] = 'gap="' . $gap . '"';
        if ($alignment !== 'center') {
            $attrParts[] = 'alignment="' . $alignment . '"';
        }

        return '[link_button_group ' . implode(' ', $attrParts) . ']' . "\n"
            . implode("\n", $buttons) . "\n"
            . '[/link_button_group]';
    }

    private static function cleanText(string $text): string
    {
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim($text);
    }
}
