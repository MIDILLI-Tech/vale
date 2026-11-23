<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Pwa;

final class PwaIconGenerator
{
    private const MAX_SOURCE_BYTES  = 10485760;
    private const MAX_SOURCE_PIXELS = 20000000;

    public function supports(string $logoUrl): bool
    {
        return $this->localSource($logoUrl) !== null;
    }

    public function render(string $logoUrl, int $size, string $background): ?string
    {
        if (!extension_loaded('gd') || !in_array($size, [180, 192, 512], true)) {
            return null;
        }

        $source = $this->localSource($logoUrl);
        if ($source === null || (int) @filesize($source) > self::MAX_SOURCE_BYTES) {
            return null;
        }

        $info = @getimagesize($source);
        if ($info === false || $info[0] * $info[1] > self::MAX_SOURCE_PIXELS) {
            return null;
        }
        if (!in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
            return null;
        }

        $cacheDir = storage_path('cache/pwa');
        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
            return null;
        }

        $cacheKey  = sha1($source . '|' . (int) @filemtime($source) . '|' . $size . '|' . $background);
        $cacheFile = $cacheDir . '/icon-' . $size . '-' . substr($cacheKey, 0, 16) . '.png';
        if (is_file($cacheFile)) {
            $cached = @file_get_contents($cacheFile);

            return $cached !== false ? $cached : null;
        }

        $sourceImage = @imagecreatefromstring((string) @file_get_contents($source));
        if ($sourceImage === false) {
            return null;
        }

        $width  = imagesx($sourceImage);
        $height = imagesy($sourceImage);
        $side   = min($width, $height);
        $srcX   = (int) (($width - $side) / 2);
        $srcY   = (int) (($height - $side) / 2);

        $canvas = imagecreatetruecolor($size, $size);
        if ($canvas === false) {
            return null;
        }

        [$red, $green, $blue] = $this->rgb($background);
        imagefilledrectangle(
            $canvas,
            0,
            0,
            $size,
            $size,
            imagecolorallocate($canvas, $red, $green, $blue),
        );

        $inner  = (int) round($size * 0.8);
        $offset = intdiv($size - $inner, 2);
        imagecopyresampled($canvas, $sourceImage, $offset, $offset, $srcX, $srcY, $inner, $inner, $side, $side);

        ob_start();
        imagepng($canvas, null, 9);
        $png = (string) ob_get_clean();
        if ($png === '') {
            return null;
        }

        $temporary = $cacheFile . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temporary, $png) !== false) {
            @rename($temporary, $cacheFile);
        }

        return $png;
    }

    private function localSource(string $logoUrl): ?string
    {
        $parsed = parse_url($logoUrl);
        if ($parsed === false || empty($parsed['path'])) {
            return null;
        }

        if (isset($parsed['host'])) {
            $appHost = strtolower((string) parse_url((string) env('APP_URL', ''), PHP_URL_HOST));
            if ($appHost === '' || strtolower((string) $parsed['host']) !== $appHost) {
                return null;
            }
        }

        $path = rawurldecode((string) $parsed['path']);
        $base = rtrim(__app_base_path(), '/');
        if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
            $path = substr($path, strlen($base));
        }
        $path = '/' . ltrim($path, '/');

        if (str_starts_with($path, '/uploads/')) {
            $root = realpath(storage_path('uploads'));
            $file = realpath(storage_path('uploads/' . substr($path, strlen('/uploads/'))));
        } elseif (str_starts_with($path, '/assets/')) {
            $root = realpath(public_path());
            $file = realpath(public_path(ltrim($path, '/')));
        } else {
            return null;
        }

        if ($root === false || $file === false || !is_file($file)) {
            return null;
        }
        if (!str_starts_with($file . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $file;
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) === 8) {
            $hex = substr($hex, 0, 6);
        }
        if (preg_match('/^[0-9a-f]{6}$/i', $hex) !== 1) {
            $hex = 'ffffff';
        }

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }
}
