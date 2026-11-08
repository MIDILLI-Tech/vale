<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Media;

final class ImageProcessor
{
    // GD objects are refcounted since PHP 8.0 — do not call imagedestroy()
    // (deprecated in PHP 8.5); the GC releases GdImage instances on their own.
    /** GD truecolor decode ≈ 4.6 bytes/pixel plus codec buffers; rounded up. */
    private const DECODE_BYTES_PER_PIXEL = 5;

    /** Fixed decode buffer headroom on top of the pixel estimate. */
    private const DECODE_BASE_BYTES = 67108864; // 64 MiB

    /** Refuse to raise memory_limit beyond this ceiling, whatever the estimate. */
    private const MEMORY_LIMIT_CEILING = 4294967296; // 4 GiB

    /** SVGs are parsed in memory; anything beyond this is pathological input. */
    private const SVG_SANITIZE_MAX_BYTES = 16777216; // 16 MiB

    /**
     * @param array<string, array{width: int, height: int}> $sizes
     * @return array<string, string> Map of size name => relative path
     */
    public function generateThumbnails(string $sourcePath, string $destDir, string $baseName, array $sizes): array
    {
        $info = @getimagesize($sourcePath);
        if ($info === false) {
            return [];
        }

        $releaseDecodeBudget = $this->beginDecodeBudget($info[0], $info[1]);
        try {
            return $this->generateThumbnailSizes($sourcePath, $destDir, $baseName, $sizes, $info);
        } finally {
            $releaseDecodeBudget();
        }
    }

    /**
     * @param array<string, array{width: int, height: int}> $sizes
     * @param array{0: int, 1: int, 2: int}|array<mixed> $info
     * @return array<string, string> Map of size name => relative path
     */
    private function generateThumbnailSizes(string $sourcePath, string $destDir, string $baseName, array $sizes, array $info): array
    {
        $src = $this->loadImage($sourcePath, $info[2]);
        if ($src === null) {
            return [];
        }

        [$origW, $origH] = [$info[0], $info[1]];
        $thumbnails      = [];

        foreach ($sizes as $sizeName => $dim) {
            $maxW = $dim['width'];
            $maxH = $dim['height'];

            if ($origW <= $maxW && $origH <= $maxH) {
                continue;
            }

            $ratio = min($maxW / $origW, $maxH / $origH);
            $newW  = (int) round($origW * $ratio);
            $newH  = (int) round($origH * $ratio);

            $thumb = imagecreatetruecolor($newW, $newH);
            if ($thumb === false) {
                continue;
            }

            if (in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF, IMAGETYPE_AVIF], true)) {
                imagealphablending($thumb, false);
                imagesavealpha($thumb, true);
            }

            imagecopyresampled($thumb, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);

            $thumbFilename = pathinfo($baseName, PATHINFO_FILENAME) . "-{$sizeName}." . pathinfo($baseName, PATHINFO_EXTENSION);
            $thumbPath     = $destDir . '/' . $thumbFilename;

            $this->saveImage($thumb, $thumbPath, $info[2]);

            $thumbnails[$sizeName] = $thumbFilename;
        }

        return $thumbnails;
    }

    /**
     * Re-encode an image to strip EXIF/metadata and prevent image bombs.
     * If the file exceeds maxPixels it will be rejected (GIF bomb protection).
     *
     * @throws \RuntimeException When the image is too large or cannot be processed.
     */
    public function sanitizeImage(string $sourcePath, string $destPath, int $maxPixels = 50000000): void
    {
        $info = @getimagesize($sourcePath);
        if ($info === false) {
            throw new \RuntimeException('Cannot read image dimensions.');
        }

        [$w, $h] = [$info[0], $info[1]];

        if ($w * $h > $maxPixels) {
            throw new \RuntimeException("Image dimensions ({$w}×{$h}) exceed the maximum allowed pixel area.");
        }

        $releaseDecodeBudget = $this->beginDecodeBudget($w, $h);
        try {
            switch ($info[2]) {
                case IMAGETYPE_JPEG:
                case IMAGETYPE_PNG:
                case IMAGETYPE_WEBP:
                    $src = $this->loadImage($sourcePath, $info[2]);
                    if ($src === null) {
                        throw new \RuntimeException('Failed to open image for sanitization.');
                    }
                    $this->saveImage($src, $destPath, $info[2]);
                    break;

                case IMAGETYPE_AVIF:
                    // GD without AVIF support cannot decode these — reject instead
                    // of storing an unsanitized file.
                    $src = $this->loadImage($sourcePath, $info[2]);
                    if ($src === null) {
                        throw new \RuntimeException('AVIF images are not supported by this GD build.');
                    }
                    $this->saveImage($src, $destPath, $info[2]);
                    break;

                    // GIF: re-encoding via GD collapses a multi-frame animation to a
                    // single static frame. Copy it as-is to preserve animation; the
                    // pixel-area (GIF bomb) check above still applies.
                case IMAGETYPE_GIF:
                    if ($sourcePath !== $destPath) {
                        copy($sourcePath, $destPath);
                    }
                    break;

                default:
                    if ($sourcePath !== $destPath) {
                        copy($sourcePath, $destPath);
                    }
                    break;
            }
        } finally {
            $releaseDecodeBudget();
        }
    }

    /**
     * GD decodes images into full in-memory truecolor (~4.6 bytes/pixel plus
     * codec buffers), so a legal image well under maxPixels can still fatally
     * blow the configured memory_limit. Temporarily raise the limit to the
     * (capped) estimate for the decode; the returned closure always restores
     * the original value. Nothing is changed when the current limit already
     * fits or is unlimited (-1).
     */
    private function beginDecodeBudget(int $width, int $height): \Closure
    {
        $original = ini_get('memory_limit');
        if ($original === false || $original === '-1') {
            return static function (): void {
            };
        }

        $needed = memory_get_usage(true)
            + $width * $height * self::DECODE_BYTES_PER_PIXEL
            + self::DECODE_BASE_BYTES;
        $limit  = (int) self::phpIniBytes($original);
        if ($needed <= $limit) {
            return static function (): void {
            };
        }

        $budget = min($needed, self::MEMORY_LIMIT_CEILING);
        if (@ini_set('memory_limit', (string) $budget) === false && @ini_get('memory_limit') !== (string) $budget) {
            // Raising the limit is not permitted in this SAPI — the decode will
            // either fit or fail with the original (clean, catchable) error.
            return static function (): void {
            };
        }

        return static function () use ($original): void {
            ini_set('memory_limit', $original);
        };
    }

    /**
     * Convert a php.ini shorthand size string (e.g. "512M", "-1") to bytes.
     */
    private static function phpIniBytes(string $value): int
    {
        $value = strtolower(trim($value));
        if ($value === '' || $value === '-1') {
            return PHP_INT_MAX;
        }
        if (preg_match('/^(\d+(?:\.\d+)?)\s*(g|m|k|b)?$/', $value, $m) === 1) {
            $multiplier = match ($m[2] ?? 'b') {
                'g'     => 1024 ** 3,
                'm'     => 1024 ** 2,
                'k'     => 1024,
                default => 1,
            };

            return (int) round((float) $m[1] * $multiplier);
        }

        return (int) $value;
    }

    /**
     * Sanitize SVG content using enshrined/svg-sanitize: strips scripts,
     * event handlers, external references and other XSS vectors while
     * keeping the vector artwork intact.
     */
    public function sanitizeSvg(string $sourcePath, string $destPath): void
    {
        // SVG sanitization parses the whole document in memory — cap the input
        // so a bomb-sized upload fails cleanly instead of exhausting memory.
        $content = file_get_contents($sourcePath, false, null, 0, self::SVG_SANITIZE_MAX_BYTES + 1);
        if ($content === false) {
            throw new \RuntimeException('Cannot read SVG file.');
        }
        if (strlen($content) > self::SVG_SANITIZE_MAX_BYTES) {
            throw new \RuntimeException('SVG file exceeds the 16 MB sanitization limit.');
        }

        $sanitizer = new \enshrined\svgSanitize\Sanitizer();
        $sanitizer->minify(true);
        $sanitizer->removeRemoteReferences(true);

        $clean = $sanitizer->sanitize($content);
        if ($clean === false) {
            throw new \RuntimeException('SVG sanitization failed — file rejected as unsafe.');
        }

        file_put_contents($destPath, $clean);
    }

    /**
     * Check whether an image exceeds the safe pixel limit (GIF bomb protection).
     * Does NOT load the full image into memory.
     */
    public function isGifBomb(string $sourcePath, int $maxPixels = 50000000): bool
    {
        $info = @getimagesize($sourcePath);
        if ($info === false) {
            return false;
        }
        return ($info[0] * $info[1]) > $maxPixels;
    }

    private function loadImage(string $path, int $type): ?\GdImage
    {
        if ($type === IMAGETYPE_AVIF) {
            return function_exists('imagecreatefromavif') ? @imagecreatefromavif($path) ?: null : null;
        }
        return match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path) ?: null,
            IMAGETYPE_PNG  => @imagecreatefrompng($path) ?: null,
            IMAGETYPE_GIF  => @imagecreatefromgif($path) ?: null,
            IMAGETYPE_WEBP => @imagecreatefromwebp($path) ?: null,
            default        => null,
        };
    }

    /**
     * Convert an image to WebP format.
     */
    public function convertToWebp(string $sourcePath, string $destPath, int $quality = 85): bool
    {
        $info = @getimagesize($sourcePath);
        if ($info === false) {
            return false;
        }

        // Never convert animated GIFs to WebP — WebP conversion via GD only
        // keeps the first frame, collapsing the animation to a static image.
        // The original GIF (which may be animated) is preserved instead.
        if ($info[2] === IMAGETYPE_GIF) {
            return false;
        }

        $releaseDecodeBudget = $this->beginDecodeBudget($info[0], $info[1]);
        try {
            $src = $this->loadImage($sourcePath, $info[2]);
            if ($src === null) {
                return false;
            }

            if (in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF, IMAGETYPE_AVIF], true)) {
                imagealphablending($src, false);
                imagesavealpha($src, true);
            }

            $result = imagewebp($src, $destPath, $quality);

            return $result;
        } finally {
            $releaseDecodeBudget();
        }
    }

    private function saveImage(\GdImage $img, string $path, int $type): void
    {
        match ($type) {
            IMAGETYPE_JPEG => imagejpeg($img, $path, 85),
            IMAGETYPE_PNG  => $this->preserveAlpha($img) && imagepng($img, $path, 8),
            IMAGETYPE_GIF  => $this->preserveAlpha($img) && imagegif($img, $path),
            IMAGETYPE_WEBP => $this->preserveAlpha($img) && imagewebp($img, $path, 85),
            IMAGETYPE_AVIF => function_exists('imageavif') ? ($this->preserveAlpha($img) && imageavif($img, $path)) : null,
            default        => null,
        };
    }

    private function preserveAlpha(\GdImage $img): bool
    {
        imagealphablending($img, false);
        return imagesavealpha($img, true);
    }
}
