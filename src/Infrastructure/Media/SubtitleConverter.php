<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Media;

/**
 * SubRip (.srt/.sub) → WebVTT converter.
 *
 * Browsers only accept WebVTT in <track>, so subtitle uploads are
 * normalized to .vtt at ingest time (MediaUploadService + MediaScanService).
 * Pure string in / string out — no I/O, easy to unit test.
 */
final class SubtitleConverter
{
    private const MAX_CUES = 10000;

    /**
     * Convert SubRip content to WebVTT. Returns null when the input is not
     * recognizable SRT (caller then keeps the original file untouched).
     */
    public static function convertSrtToVtt(string $srt): ?string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $srt);
        if (str_contains($normalized, "\0")) {
            return null;
        }
        // Strip UTF-8 BOM so the first cue number parses cleanly.
        $normalized = preg_replace('/^\xEF\xBB\xBF/', '', $normalized) ?? $normalized;
        $normalized = trim($normalized);
        if ($normalized === '' || strlen($normalized) > 5 * 1024 * 1024) {
            return null;
        }

        $blocks = preg_split('/\n{2,}/', $normalized);
        if ($blocks === false || $blocks === []) {
            return null;
        }

        $cues  = [];
        foreach ($blocks as $block) {
            $lines = array_values(array_filter(
                explode("\n", trim($block)),
                static fn (string $l): bool => trim($l) !== '',
            ));
            if ($lines === []) {
                continue;
            }
            // Optional numeric cue identifier first line.
            if (ctype_digit(trim($lines[0]))) {
                array_shift($lines);
            }
            if (count($lines) < 2 || !str_contains($lines[0], '-->')) {
                continue;
            }
            $timing = self::convertTiming($lines[0]);
            if ($timing === null) {
                continue;
            }
            $cues[] = $timing . "\n" . implode("\n", array_slice($lines, 1));
            if (count($cues) >= self::MAX_CUES) {
                break;
            }
        }

        if ($cues === []) {
            return null;
        }

        return "WEBVTT\n\n" . implode("\n\n", $cues) . "\n";
    }

    /**
     * "00:00:01,000 --> 00:00:04,000" → "00:00:01.000 --> 00:00:04.000".
     * Returns null when neither side looks like an SRT timestamp.
     */
    private static function convertTiming(string $line): ?string
    {
        $parts = explode('-->', $line);
        if (count($parts) !== 2) {
            return null;
        }
        $start = self::convertTimestamp(trim($parts[0]));
        $end   = self::convertTimestamp(trim(explode(' ', trim($parts[1]))[0]));
        if ($start === null || $end === null) {
            return null;
        }

        return $start . ' --> ' . $end;
    }

    private static function convertTimestamp(string $ts): ?string
    {
        if (!preg_match('/^\d{2,}:\d{2}:\d{2}[,.]\d{3}$/', $ts)) {
            return null;
        }

        return str_replace(',', '.', $ts);
    }
}
