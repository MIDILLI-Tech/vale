<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Content;

/**
 * Deliberately tiny markdown subset for public profile bios: paragraphs,
 * **bold**, *italic*, _italic_ and http(s) links. The input is escaped before
 * any markup is added, so the renderer cannot emit untrusted HTML.
 */
final class MiniMarkdown
{
    public static function render(?string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim((string) $text));
        if ($text === '') {
            return '';
        }

        $html = '';
        foreach (preg_split('/\n{2,}/', $text) ?: [] as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            $html .= '<p>' . self::inline($paragraph) . '</p>';
        }

        return $html;
    }

    private static function inline(string $text): string
    {
        $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Links first, so emphasis inside the label still renders.
        $text = preg_replace_callback(
            '/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/i',
            static fn (array $m): string => '<a href="' . $m[2] . '" rel="nofollow noopener" target="_blank">' . $m[1] . '</a>',
            $text,
        ) ?? $text;

        $text = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text)               ?? $text;
        $text = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/s', '<em>$1</em>', $text) ?? $text;
        $text = preg_replace('/_(.+?)_/s', '<em>$1</em>', $text)                             ?? $text;

        return nl2br($text, false);
    }
}
