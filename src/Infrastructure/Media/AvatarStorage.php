<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Media;

use Psr\Http\Message\UploadedFileInterface;

/**
 * Stores user profile pictures under <upload_dir>/avatars.
 *
 * Files are never taken from the client verbatim: each upload is decoded,
 * centre-cropped to a square, resized and re-encoded, which strips EXIF/GPS
 * metadata and neutralises polyglot payloads.
 */
final class AvatarStorage
{
    public const MAX_BYTES = 1048576;

    public const DIMENSION = 256;

    public const DIRECTORY = 'avatars';

    public function __construct(
        private readonly string $uploadDir,
        private readonly bool $webpEnabled = false,
    ) {
    }

    /**
     * Per-file upload ceiling enforced by PHP (upload_max_filesize) in kilobytes.
     * Returns 0 when the server sets no limit.
     */
    public static function serverMaxKilobytes(): int
    {
        $bytes = MediaUploadService::parsePhpLimit((string) ini_get('upload_max_filesize'));

        return $bytes > 0 ? (int) floor($bytes / 1024) : 0;
    }

    /**
     * Configured avatar limit clamped to 64–10240 KB and never above the server
     * upload ceiling — a larger avatar cannot arrive in the first place.
     */
    public static function maxKilobytes(int $configuredKb): int
    {
        $configured = max(64, min(10240, $configuredKb));
        $server     = self::serverMaxKilobytes();

        return $server > 0 ? min($configured, $server) : $configured;
    }

    /**
     * @return string Relative path stored on the user row, e.g. "avatars/ab12….jpg".
     * @throws \RuntimeException With a user-facing message when the file is rejected.
     */
    public function store(UploadedFileInterface $file, int $maxBytes = self::MAX_BYTES): string
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new \RuntimeException(__('The image could not be uploaded.'));
        }

        $size = $file->getSize();
        if ($size === null || $size <= 0) {
            throw new \RuntimeException(__('The uploaded file is empty.'));
        }

        if ($size > $maxBytes) {
            throw new \RuntimeException(__('The image exceeds the maximum allowed size.'));
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'vl_avatar_');
        if ($temporaryPath === false) {
            throw new \RuntimeException(__('The image could not be uploaded.'));
        }

        try {
            $file->moveTo($temporaryPath);

            return $this->storeFromPath($temporaryPath);
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    /**
     * Delete a locally stored avatar. Remote (SSO) URLs and anything outside
     * the avatars directory are left untouched.
     */
    public function delete(?string $value): void
    {
        if ($value === null || !preg_match('#^' . self::DIRECTORY . '/[A-Za-z0-9._-]+$#', $value)) {
            return;
        }

        $path = rtrim($this->uploadDir, '/\\') . '/' . $value;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Resolve the stored value (local relative path or SSO URL) to a public URL.
     */
    public static function url(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $value) === 1) {
            return $value;
        }

        return site_url('/uploads/' . ltrim($value, '/'));
    }

    /**
     * Version marker for a locally stored avatar (file mtime). Remote provider
     * URLs and missing files return null — they never get a version suffix.
     */
    public function version(?string $value): ?string
    {
        if ($value === null || preg_match('#^https?://#i', trim($value)) === 1) {
            return null;
        }

        $path = rtrim($this->uploadDir, '/\\') . '/' . ltrim(trim($value), '/');
        if (!is_file($path)) {
            return null;
        }

        $mtime = filemtime($path);

        return $mtime === false ? null : (string) $mtime;
    }

    /**
     * Public URL with a `?v=` cache-buster for local files, so a replaced
     * picture is fetched again instead of coming from the browser cache.
     */
    public function versionedUrl(?string $value): ?string
    {
        $url = self::url($value);
        if ($url === null) {
            return null;
        }

        $version = $this->version($value);

        return $version === null ? $url : $url . '?v=' . $version;
    }

    /**
     * @internal Exposed for reuse and unit tests; callers should use store().
     */
    public function storeFromPath(string $sourcePath): string
    {
        $info = @getimagesize($sourcePath);
        if ($info === false) {
            throw new \RuntimeException(__('Please upload a JPEG or PNG image.'));
        }

        $source = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($sourcePath) ?: null,
            IMAGETYPE_PNG  => @imagecreatefrompng($sourcePath) ?: null,
            default        => null,
        };

        if ($source === null) {
            throw new \RuntimeException(__('Please upload a JPEG or PNG image.'));
        }

        $width   = imagesx($source);
        $height  = imagesy($source);
        $edge    = min($width, $height);
        $offsetX = (int) floor(($width - $edge) / 2);
        $offsetY = (int) floor(($height - $edge) / 2);

        $target = imagecreatetruecolor(self::DIMENSION, self::DIMENSION);
        if ($target === false) {
            throw new \RuntimeException(__('The image could not be processed.'));
        }

        if ($info[2] === IMAGETYPE_PNG) {
            imagealphablending($target, false);
            imagesavealpha($target, true);
        }

        imagecopyresampled(
            $target,
            $source,
            0,
            0,
            $offsetX,
            $offsetY,
            self::DIMENSION,
            self::DIMENSION,
            $edge,
            $edge,
        );

        $directory = rtrim($this->uploadDir, '/\\') . '/' . self::DIRECTORY;

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException(__('The image could not be saved.'));
        }

        $base = $directory . '/' . bin2hex(random_bytes(16));

        if ($this->webpEnabled && function_exists('imagewebp')) {
            imagealphablending($target, false);
            imagesavealpha($target, true);
            if (imagewebp($target, $base . '.webp', 85) === true) {
                return self::DIRECTORY . '/' . basename($base . '.webp');
            }
        }

        $extension = $info[2]   === IMAGETYPE_PNG ? 'png' : 'jpg';
        $saved     = $extension === 'png'
            ? imagepng($target, $base . '.png', 8)
            : imagejpeg($target, $base . '.jpg', 85);

        if ($saved === false) {
            throw new \RuntimeException(__('The image could not be saved.'));
        }

        return self::DIRECTORY . '/' . basename($base . '.' . $extension);
    }
}
