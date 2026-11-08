<?php

declare(strict_types=1);

namespace ValeApp\Domain\Content;

final class DiffHelper
{
    private const LCS_CELL_BUDGET = 262144;

    /**
     * Compute line-by-line diff between two plain-text strings.
     * Returns an array of lines, each with:
     * - 'type': 'same', 'add', 'del', 'change'
     * - 'old': old line content
     * - 'new': new line content
     */
    public static function diff(?string $oldStr, ?string $newStr): array
    {
        $oldLines = $oldStr !== null && $oldStr !== '' ? explode("\n", $oldStr) : [];
        $newLines = $newStr !== null && $newStr !== '' ? explode("\n", $newStr) : [];

        return self::computeDiff($oldLines, $newLines);
    }

    /**
     * Compute a readable diff between two HTML strings by reducing each
     * document to one line per block element (tags stripped).
     */
    public static function diffHtml(?string $oldHtml, ?string $newHtml): array
    {
        return self::computeDiff(self::htmlToLines($oldHtml), self::htmlToLines($newHtml));
    }

    public static function hasChanges(array $lines): bool
    {
        foreach ($lines as $line) {
            if ($line['type'] !== 'same') {
                return true;
            }
        }

        return false;
    }

    /**
     * Reduce an HTML fragment to plain text lines, one per block-level
     * element (p, headings, list items, blockquote, pre, table rows...).
     * Plain text input falls back to newline splitting.
     *
     * @return string[]
     */
    public static function htmlToLines(?string $html): array
    {
        if ($html === null || trim($html) === '') {
            return [];
        }

        $text = $html;
        if (str_contains($html, '<')) {
            $text = self::htmlToPlainText($html);
        }

        $lines = [];
        foreach (explode("\n", $text) as $raw) {
            $collapsed = preg_replace('/[ \t\x{00A0}]+/u', ' ', $raw) ?? $raw;
            $line      = trim($collapsed);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * Reduce an HTML fragment to human-readable text, one block per line.
     * Page-break and more markers survive as [nextpage] / [more].
     */
    public static function toReadableText(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $prepared = str_ireplace(
            ['<!--nextpage-->', '<!--more-->'],
            ["\n[nextpage]\n", "\n[more]\n"],
            $html,
        );

        return implode("\n", self::htmlToLines($prepared));
    }

    private static function htmlToPlainText(string $html): string
    {
        $blockOpen = '#<\s*(?:p|div|h[1-6]|li|blockquote|pre|tr|figcaption|td|th)(?:\s[^>]*)?>#i';

        $text = preg_replace($blockOpen, "\n", $html)                                                         ?? $html;
        $text = preg_replace('#<br\s*/?>#i', "\n", $text)                                                     ?? $text;
        $text = preg_replace('#</\s*(?:p|div|h[1-6]|li|blockquote|pre|tr|figcaption|td|th)\s*>#i', '', $text) ?? $text;

        return html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Render pre-computed diff lines as HTML. Changed lines get word-level
     * highlighting for the differing region.
     */
    public static function renderLines(array $lines): string
    {
        $html = '';

        foreach ($lines as $line) {
            switch ($line['type']) {
                case 'add':
                    $html .= self::lineHtml('vl-diff-add', '+', htmlspecialchars($line['new'], ENT_QUOTES, 'UTF-8'));
                    break;
                case 'del':
                    $html .= self::lineHtml('vl-diff-del', '-', htmlspecialchars($line['old'], ENT_QUOTES, 'UTF-8'));
                    break;
                case 'change':
                    [$delInner, $addInner] = self::wordDiff((string) $line['old'], (string) $line['new']);
                    $html .= self::lineHtml('vl-diff-del', '-', $delInner)
                        . self::lineHtml('vl-diff-add', '+', $addInner);
                    break;
                default:
                    $sameText = htmlspecialchars((string) $line['old'], ENT_QUOTES, 'UTF-8');
                    $html .= self::lineHtml('vl-diff-same', '', $sameText);
            }
        }

        return $html;
    }

    private static function lineHtml(string $class, string $marker, string $inner): string
    {
        return '<div class="vl-diff-line ' . $class . '"><span class="vl-diff-marker">' . $marker . '</span> '
            . $inner . '</div>';
    }

    /**
     * Render a plain-text diff as HTML.
     */
    public static function toHtml(?string $oldStr, ?string $newStr): string
    {
        return self::renderLines(self::diff($oldStr, $newStr));
    }

    /**
     * Render an HTML-aware diff as HTML.
     */
    public static function toHtmlFromHtml(?string $oldHtml, ?string $newHtml): string
    {
        return self::renderLines(self::diffHtml($oldHtml, $newHtml));
    }

    private static function computeDiff(array $oldLines, array $newLines): array
    {
        [$head, $oldMid, $newMid, $tail] = self::trimCommonEdges($oldLines, $newLines);
        $ops                             = [];

        foreach ($head as $line) {
            $ops[] = ['same', $line];
        }

        if ($oldMid === [] && $newMid === []) {
            // fully aligned
        } elseif ($oldMid === []) {
            foreach ($newMid as $line) {
                $ops[] = ['add', $line];
            }
        } elseif ($newMid === []) {
            foreach ($oldMid as $line) {
                $ops[] = ['del', $line];
            }
        } elseif (count($oldMid) * count($newMid) > self::LCS_CELL_BUDGET) {
            $max = max(count($oldMid), count($newMid));
            for ($i = 0; $i < $max; $i++) {
                $o = $oldMid[$i] ?? null;
                $n = $newMid[$i] ?? null;
                if ($o !== null && $n !== null && $o === $n) {
                    $ops[] = ['same', $o];
                } elseif ($o === null) {
                    $ops[] = ['add', $n ?? ''];
                } elseif ($n === null) {
                    $ops[] = ['del', $o];
                } else {
                    $ops[] = ['change', $o, $n];
                }
            }
        } else {
            foreach (self::lcsOps($oldMid, $newMid) as $op) {
                $ops[] = $op;
            }
        }

        foreach ($tail as $line) {
            $ops[] = ['same', $line];
        }

        return self::pairRuns($ops);
    }

    /**
     * @return array{0: array, 1: array, 2: array, 3: array} head, old middle, new middle, tail
     */
    private static function trimCommonEdges(array $old, array $new): array
    {
        $oldCount = count($old);
        $newCount = count($new);

        $start = 0;
        while ($start < $oldCount && $start < $newCount && $old[$start] === $new[$start]) {
            $start++;
        }

        $endOld = $oldCount;
        $endNew = $newCount;
        while ($endOld > $start && $endNew > $start && $old[$endOld - 1] === $new[$endNew - 1]) {
            $endOld--;
            $endNew--;
        }

        return [
            array_slice($old, 0, $start),
            array_slice($old, $start, $endOld - $start),
            array_slice($new, $start, $endNew - $start),
            array_slice($old, $endOld),
        ];
    }

    /**
     * Longest-common-subsequence walk over the middle sections.
     */
    private static function lcsOps(array $old, array $new): array
    {
        $n  = count($old);
        $m  = count($new);
        $dp = [];

        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $dp[$i][$j] = $old[$i] === $new[$j]
                    ? ($dp[$i + 1][$j + 1] ?? 0) + 1
                    : max($dp[$i + 1][$j] ?? 0, $dp[$i][$j + 1] ?? 0);
            }
        }

        $ops   = [];
        $i     = 0;
        $j     = 0;
        while ($i < $n && $j < $m) {
            if ($old[$i] === $new[$j]) {
                $ops[] = ['same', $old[$i]];
                $i++;
                $j++;
            } elseif (($dp[$i + 1][$j] ?? 0) >= ($dp[$i][$j + 1] ?? 0)) {
                $ops[] = ['del', $old[$i]];
                $i++;
            } else {
                $ops[] = ['add', $new[$j]];
                $j++;
            }
        }
        while ($i < $n) {
            $ops[] = ['del', $old[$i]];
            $i++;
        }
        while ($j < $m) {
            $ops[] = ['add', $new[$j]];
            $j++;
        }

        return $ops;
    }

    /**
     * Coalesce del/add runs into 'change' pairs so word-level highlighting
     * can be applied to matched lines.
     */
    private static function pairRuns(array $ops): array
    {
        $result = [];
        $count  = count($ops);
        $i      = 0;

        while ($i < $count) {
            if ($ops[$i][0] === 'del' || $ops[$i][0] === 'add') {
                $dels = [];
                $adds = [];
                while (
                    $i < $count
                    && ($ops[$i][0] === 'del' || $ops[$i][0] === 'add')
                ) {
                    if ($ops[$i][0] === 'del') {
                        $dels[] = $ops[$i][1];
                    } else {
                        $adds[] = $ops[$i][1];
                    }
                    $i++;
                }
                $paired = min(count($dels), count($adds));
                for ($k = 0; $k < $paired; $k++) {
                    $result[] = ['type' => 'change', 'old' => $dels[$k], 'new' => $adds[$k]];
                }
                for ($k = $paired; $k < count($dels); $k++) {
                    $result[] = ['type' => 'del', 'old' => $dels[$k], 'new' => ''];
                }
                for ($k = $paired; $k < count($adds); $k++) {
                    $result[] = ['type' => 'add', 'old' => '', 'new' => $adds[$k]];
                }
                continue;
            }

            [$type, $payload] = $ops[$i];
            $result[]         = match ($type) {
                'add'   => ['type' => 'add', 'old' => '', 'new' => $payload],
                'del'   => ['type' => 'del', 'old' => $payload, 'new' => ''],
                default => ['type' => 'same', 'old' => $payload, 'new' => $payload],
            };
            $i++;
        }

        return $result;
    }

    /**
     * Highlight the changed word range between two lines.
     *
     * @return array{0: string, 1: string} escaped del-side and add-side HTML
     */
    private static function wordDiff(string $oldLine, string $newLine): array
    {
        $splitFlags = PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY;
        $oldTokens  = preg_split('/(\s+)/u', $oldLine, -1, $splitFlags) ?: [];
        $newTokens  = preg_split('/(\s+)/u', $newLine, -1, $splitFlags) ?: [];

        $oldCount = count($oldTokens);
        $newCount = count($newTokens);

        $prefix = 0;
        while (
            $prefix    < $oldCount
            && $prefix < $newCount
            && $oldTokens[$prefix] === $newTokens[$prefix]
        ) {
            $prefix++;
        }

        $suffix = 0;
        while (
            $suffix    < $oldCount    - $prefix
            && $suffix < $newCount    - $prefix
            && $oldTokens[$oldCount - 1 - $suffix] === $newTokens[$newCount - 1 - $suffix]
        ) {
            $suffix++;
        }

        $oldLen    = max(0, count($oldTokens) - $prefix - $suffix);
        $newLen    = max(0, count($newTokens) - $prefix - $suffix);
        $oldMiddle = implode('', array_slice($oldTokens, $prefix, $oldLen));
        $newMiddle = implode('', array_slice($newTokens, $prefix, $newLen));

        return [
            self::renderSide($oldTokens, $prefix, $suffix, $oldMiddle, 'vl-w-del'),
            self::renderSide($newTokens, $prefix, $suffix, $newMiddle, 'vl-w-add'),
        ];
    }

    private static function renderSide(
        array $tokens,
        int $prefixLen,
        int $suffixLen,
        string $middle,
        string $class,
    ): string {
        $trimmed   = trim($middle);
        $headSlice = implode('', array_slice($tokens, 0, $prefixLen));
        $tailSlice = implode('', array_slice($tokens, count($tokens) - $suffixLen));

        $headText = htmlspecialchars($headSlice, ENT_QUOTES, 'UTF-8');
        $tailText = htmlspecialchars($tailSlice, ENT_QUOTES, 'UTF-8');

        $spanHtml = '';
        if ($trimmed !== '') {
            $spanHtml = '<span class="' . $class . '">' . htmlspecialchars($trimmed, ENT_QUOTES, 'UTF-8') . '</span>';
        }

        return $headText . $spanHtml . $tailText;
    }
}
