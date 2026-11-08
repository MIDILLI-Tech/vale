<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Media;

use Psr\SimpleCache\CacheInterface;
use ValeApp\Domain\Media\Entity\Media;
use ValeApp\Domain\Media\Repository\MediaRepositoryInterface;
use ValeApp\Domain\Shared\Exception\ValidationException;
use ValeApp\Domain\Shared\ValueObject\Slug;

class MediaUploadService
{
    public function __construct(
        private readonly MediaRepositoryInterface $mediaRepo,
        private readonly ImageProcessor $imageProcessor,
        private readonly array $config,
        private readonly ?CacheInterface $cache = null,
    ) {
    }

    public function upload(array $file, int $userId): Media
    {
        $result = $this->uploadBatch([$file], $userId);
        if (!empty($result['errors'])) {
            throw ValidationException::withMessages(['file' => $result['errors'][0]['error']]);
        }
        if (!empty($result['skipped'])) {
            throw ValidationException::withMessages(['file' => 'File skipped: ' . $result['skipped'][0]['reason']]);
        }
        return $result['uploaded'][0];
    }

    public function uploadBatch(array $files, int $userId, ?string $conflictMode = null): array
    {
        $uploaded    = [];
        $errors      = [];
        $skipped     = [];
        $overwritten = [];

        $conflictMode ??= $this->config['upload_conflict_mode'] ?? 'suffix';

        // ── Phase 1: Rate limit ───────────────────────────────────────────
        try {
            $this->checkRateLimit($userId);
        } catch (\RuntimeException $e) {
            return [ 'uploaded' => [], 'skipped' => [], 'overwritten' => [], 'errors' => [['file' => '*', 'error' => $e->getMessage()]] ];
        }

        // ── Phase 1b: File count ──────────────────────────────────────────
        $maxCount = $this->config['max_file_count'] ?? 0;
        if ($maxCount <= 0) {
            $maxCount = (int) ini_get('max_file_uploads');
        }
        if (count($files) > $maxCount) {
            $msg = "Maximum {$maxCount} files per batch.";
            return ['uploaded' => [], 'skipped' => [], 'overwritten' => [], 'errors' => [['file' => '*', 'error' => $msg]]];
        }

        // ── Phase 1c: Total batch size ────────────────────────────────────
        $totalSize = 0;
        foreach ($files as $file) {
            $totalSize += $file['size'] ?? 0;
        }
        $maxTotal = $this->maxBatchSizeBytes();
        if ($maxTotal > 0 && $totalSize > $maxTotal) {
            $msg = 'Total batch size exceeds limit.';
            return ['uploaded' => [], 'skipped' => [], 'overwritten' => [], 'errors' => [['file' => '*', 'error' => $msg]]];
        }

        // ── Phase 2: Per-file basic checks (before touching disk) ─────────
        $validFiles  = [];
        $maxFileSize = $this->config['max_file_size'] ?? 0;
        if ($maxFileSize <= 0) {
            $maxFileSize = self::parsePhpLimit(ini_get('upload_max_filesize'));
        }
        $allowedExts = $this->config['allowed_extensions'] ?? [];

        foreach ($files as $idx => $file) {
            $fileName = basename($file['name']);

            if ($file['error'] !== UPLOAD_ERR_OK) {
                $errors[] = ['file' => $fileName, 'error' => self::phpUploadErrorMessage($file['error'])];
                continue;
            }

            if ($file['size'] > $maxFileSize) {
                $errors[] = ['file' => $fileName, 'error' => 'File exceeds maximum upload size.'];
                continue;
            }

            if ($file['size'] <= 0) {
                $errors[] = ['file' => $fileName, 'error' => 'File appears to be empty.'];
                continue;
            }

            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($ext === ''
                || MediaFileTypes::isBlockedExtension($ext)
                || !in_array($ext, $allowedExts, true)
            ) {
                $errors[] = ['file' => $fileName, 'error' => 'File extension not allowed.'];
                continue;
            }

            if ($this->hasDoubleExtension($file['name'])) {
                $errors[] = ['file' => $fileName, 'error' => 'Double extension detected.'];
                continue;
            }

            $validFiles[] = $file;
        }

        if ($validFiles === []) {
            return ['uploaded' => $uploaded, 'skipped' => $skipped, 'overwritten' => $overwritten, 'errors' => $errors];
        }

        // ── Phase 3: Disk space check ────────────────────────────────────
        $uploadDir = rtrim($this->config['upload_dir'], '/');
        $freeBytes = @disk_free_space($uploadDir);
        // Require 2x the total size as safety margin (original files + thumbnails)
        if ($freeBytes !== false && ($totalSize * 2) > $freeBytes) {
            $err = ['file' => '*', 'error' => 'Insufficient disk space.'];
            return ['uploaded' => $uploaded, 'skipped' => $skipped, 'overwritten' => $overwritten, 'errors' => array_merge($errors, [$err])];
        }

        // ── Phase 4: Process each file ────────────────────────────────────
        $yearMonth = ($this->config['use_year_month_folders'] ?? true) ? date('Y/m') : '';
        $destBase  = $uploadDir . ($yearMonth !== '' ? '/' . $yearMonth : '');
        if (!is_dir($destBase) && !mkdir($destBase, 0o755, true)) {
            $err = ['file' => '*', 'error' => 'Cannot create upload directory.'];
            return ['uploaded' => $uploaded, 'skipped' => $skipped, 'overwritten' => $overwritten, 'errors' => array_merge($errors, [$err])];
        }

        foreach ($validFiles as $file) {
            $originalName = basename($file['name']);
            $ext          = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

            // ── Content hash (dedup below + replace fallback) ──────────
            $contentHash = @hash_file('sha256', $file['tmp_name']);
            if ($contentHash === false) {
                $contentHash = '';
            }

            // Content-based dedup only makes sense while files are stored under
            // random names. With original names kept, 'skip' is name-based (see
            // the file_exists check in the naming block) and hashes may repeat
            // legitimately — e.g. the same picture uploaded as kamil.jpg and
            // kamil-1.jpg via the suffix mode.
            if ($conflictMode === 'skip'
                && !($this->config['use_original_filename'] ?? false)
                && $contentHash !== ''
            ) {
                $existingByHash = $this->mediaRepo->findByContentHash($contentHash);
                if ($existingByHash !== null) {
                    $skipped[] = ['name' => $originalName, 'reason' => 'Duplicate content'];
                    continue;
                }
            }

            // ── Determine filename + destination path ───────────────────
            if ($this->config['use_original_filename'] ?? false) {
                $base      = Slug::fromString(pathinfo($originalName, PATHINFO_FILENAME))->value;
                $candidate = $base . ($ext !== '' ? '.' . $ext : '');
                $destPath  = $destBase . '/' . $candidate;

                if ($conflictMode === 'skip') {
                    if (file_exists($destPath)) {
                        $skipped[] = ['name' => $originalName, 'reason' => 'File already exists'];
                        continue;
                    }
                } elseif ($conflictMode !== 'replace') {
                    $n = 0;
                    while (file_exists($destPath)) {
                        $candidate = $base . '-' . (++$n) . ($ext !== '' ? '.' . $ext : '');
                        $destPath  = $destBase . '/' . $candidate;
                    }
                }
                $filename = $candidate;
            } else {
                $filename = bin2hex(random_bytes(16)) . '.' . $ext;
                $destPath = $destBase . '/' . $filename;
            }

            // ── Replace mode: find the record to update ─────────────────
            $existingMedia = null;

            if ($conflictMode === 'replace') {
                if ($this->config['use_original_filename'] ?? false) {
                    // Prefer the record stored at the exact destination path in
                    // the current month folder; otherwise fall back to the most
                    // recent record carrying the same stored file name in an
                    // older folder. Without this fallback a re-upload in a later
                    // month would create a duplicate record instead of replacing.
                    $candidatePath = ($yearMonth !== '' ? $yearMonth . '/' : '') . $filename;
                    $existingMedia = $this->mediaRepo->findByPath($candidatePath);
                    if ($existingMedia === null) {
                        foreach ($this->mediaRepo->findByOriginalName($originalName) as $byName) {
                            if (basename($byName->getPath()) === $filename) {
                                $existingMedia = $byName;
                                break;
                            }
                        }
                    }
                } else {
                    // With random names, match the record the user originally
                    // named this way (typical re-upload), else the record with
                    // identical content.
                    $byName = $this->mediaRepo->findByOriginalName($originalName);
                    if ($byName !== []) {
                        $existingMedia = $byName[0];
                    } elseif ($contentHash !== '') {
                        $existingMedia = $this->mediaRepo->findByContentHash($contentHash);
                    }
                }
            }

            // A replaced record keeps its own folder, so a re-upload in a later
            // month still overwrites the original file instead of forking a
            // duplicate into the current month folder. The main file itself is
            // overwritten by the rename below; stale derived files are removed
            // later, once the new content has passed validation.
            if ($existingMedia !== null) {
                $filename = basename($existingMedia->getPath());
                $destPath = $uploadDir . '/' . $existingMedia->getPath();
            }

            if (!$this->moveIntoPlace($file['tmp_name'], $destPath)) {
                $last     = error_get_last();
                $detail   = is_array($last) && isset($last['message']) ? (string) $last['message'] : '';
                $errors[] = [
                    'file'  => $originalName,
                    'error' => $detail !== ''
                        ? 'Failed to move uploaded file: ' . $detail
                        : 'Failed to move uploaded file.',
                ];
                continue;
            }
            @chmod($destPath, 0o644);

            // ── Subtitle normalization: browsers only accept WebVTT in ──
            // <track>, so convert uploaded SRT/SUB to .vtt before validation.
            // The stored filename, DB record and served file are all .vtt —
            // the original .srt bytes never reach disk under a served name.
            if (in_array($ext, ['srt', 'sub'], true)) {
                $srtContent = @file_get_contents($destPath);
                $vtt        = is_string($srtContent)
                    ? SubtitleConverter::convertSrtToVtt($srtContent)
                    : null;
                if ($vtt !== null) {
                    if (@file_put_contents($destPath, $vtt) === false) {
                        @unlink($destPath);
                        $errors[] = ['file' => $originalName, 'error' => 'Subtitle conversion failed.'];
                        continue;
                    }
                    // Rename on disk too: $destPath must keep pointing at a
                    // real file for the MIME check below. Skipped when the
                    // stored name already ends in .vtt (replace-mode re-upload
                    // over an existing .vtt record) — renaming a file onto
                    // itself is an error on Windows.
                    $newFilename = pathinfo($filename, PATHINFO_FILENAME) . '.vtt';
                    if (strcasecmp($newFilename, $filename) !== 0) {
                        $newDestPath = dirname($destPath) . '/' . $newFilename;
                        if (!@rename($destPath, $newDestPath)) {
                            @unlink($destPath);
                            $errors[] = ['file' => $originalName, 'error' => 'Subtitle conversion failed.'];
                            continue;
                        }
                        $filename = $newFilename;
                        $destPath = $newDestPath;
                    }
                    $originalName = pathinfo($originalName, PATHINFO_FILENAME) . '.vtt';
                    $ext          = 'vtt';
                }
            }

            // ── MIME detection via finfo (never trust client header) ──
            $detectedMime = $this->detectMimeType($destPath);
            $allowedMimes = $this->config['allowed_mime_types'] ?? [];
            if (!in_array($detectedMime, $allowedMimes, true)) {
                @unlink($destPath);
                $errors[] = ['file' => $originalName, 'error' => "File type {$detectedMime} not allowed."];
                continue;
            }

            // ── Extension ↔ MIME mismatch check ────────────────────────
            // Accepted detections per extension come from the media_file_types
            // catalog (config.media file_type_map); formats libmagic detects
            // inconsistently list several values there.
            if (!MediaFileTypes::matchesDetectedMime(
                $ext,
                $detectedMime,
                $this->config['file_type_map'] ?? [],
            )) {
                @unlink($destPath);
                $errors[] = ['file' => $originalName, "error" => 'File extension does not match content type.'];
                continue;
            }

            // ── Image sanitization (EXIF strip + bomb check) ──────────
            if ($this->config['enable_image_sanitize'] ?? true) {
                try {
                    if ($detectedMime === 'image/svg+xml') {
                        $this->imageProcessor->sanitizeSvg($destPath, $destPath);
                    } elseif (str_starts_with($detectedMime, 'image/') && $detectedMime !== 'image/svg+xml') {
                        $maxPixels = $this->config['max_image_pixels'] ?? 50000000;
                        $this->imageProcessor->sanitizeImage($destPath, $destPath, $maxPixels);
                    }
                } catch (\RuntimeException $e) {
                    @unlink($destPath);
                    $errors[] = ['file' => $originalName, 'error' => $e->getMessage()];
                    continue;
                }
            }

            // The new content is valid now: remove the replaced record's old
            // derived files (thumbnails/webp) so renditions of the old content
            // do not linger on disk when the new image no longer produces that
            // size. Deleting only after validation means a rejected replacement
            // never strips the original record of its files.
            if ($existingMedia !== null) {
                foreach ($existingMedia->getThumbnails() as $thumbPath) {
                    $this->deleteContained($uploadDir, $thumbPath);
                }
            }

            // Resolve the relative path the record will reference and the
            // directory that holds it plus its derived files. For replaced
            // records this follows the record's own folder rather than the
            // current month.
            $relativePath = ($yearMonth !== '' ? $yearMonth . '/' : '') . $filename;
            if ($existingMedia !== null) {
                $relativePath = $existingMedia->getPath();
            }
            $destRelDir = '';
            $lastSlash  = strrpos($relativePath, '/');
            if ($lastSlash !== false) {
                $destRelDir = substr($relativePath, 0, $lastSlash + 1);
            }
            $destAbsDir = $uploadDir . ($destRelDir === '' ? '' : '/' . rtrim($destRelDir, '/'));

            // If replace mode already found an existing media record earlier,
            // reuse it. Otherwise create a new one.
            if ($existingMedia !== null) {
                $existingMedia->setFilename($filename);
                $existingMedia->setOriginalName($originalName);
                $existingMedia->setMimeType($detectedMime);
                $existingMedia->setSize(filesize($destPath));
                $existingMedia->setThumbnails([]);
                $existingMedia->setContentHash($contentHash);
                $media         = $existingMedia;
                $overwritten[] = ['name' => $originalName, 'id' => $existingMedia->getId()];
            } else {
                $media = new Media(
                    $userId,
                    $filename,
                    $originalName,
                    $detectedMime,
                    filesize($destPath),
                    $relativePath,
                );
                $media->setContentHash($contentHash);
            }

            // WebP conversion
            $webpPath = null;
            if ($media->isImage() && $detectedMime !== 'image/webp' && $detectedMime !== 'image/svg+xml') {
                if ($this->config['enable_webp'] ?? false) {
                    $webpFilename = pathinfo($filename, PATHINFO_FILENAME) . '.webp';
                    $webpPath     = $destAbsDir . '/' . $webpFilename;
                    if ($this->imageProcessor->convertToWebp($destPath, $webpPath)) {
                        $media->setThumbnails(array_merge($media->getThumbnails(), [
                            'webp' => $destRelDir . $webpFilename,
                        ]));
                    }
                }
            }

            // Thumbnails
            if ($media->isImage() && $detectedMime !== 'image/svg+xml') {
                $thumbSizes = $this->config['thumbnails']['sizes'] ?? [];
                $thumbs     = $this->imageProcessor->generateThumbnails(
                    $destPath,
                    $destAbsDir,
                    $filename,
                    $thumbSizes,
                );

                $relThumbs = $media->getThumbnails();
                foreach ($thumbs as $sizeName => $thumbFile) {
                    $relThumbs[$sizeName] = $destRelDir . $thumbFile;
                }

                // Size variants derived from the WebP conversion so optimized
                // renditions exist for every size (webp_small/webp_medium/webp_large).
                if (isset($relThumbs['webp'])) {
                    $webpBase   = basename((string) $relThumbs['webp']);
                    $webpThumbs = $this->imageProcessor->generateThumbnails(
                        $destAbsDir . '/' . $webpBase,
                        $destAbsDir,
                        $webpBase,
                        $thumbSizes,
                    );
                    foreach ($webpThumbs as $sizeName => $thumbFile) {
                        $relThumbs['webp_' . $sizeName] = $destRelDir . $thumbFile;
                    }
                }

                $media->setThumbnails($relThumbs);
            }

            $media->setTitle(pathinfo($originalName, PATHINFO_FILENAME));
            $this->mediaRepo->save($media);

            $uploaded[] = $media;
        }

        // ── Bump rate limit counter ──────────────────────────────────
        if ($this->cache !== null && $uploaded !== []) {
            $key   = "upload_rate_limit_{$userId}";
            $count = (int) $this->cache->get($key, 0);
            $this->cache->set($key, $count + count($uploaded), 60);
        }

        return ['uploaded' => $uploaded, 'skipped' => $skipped, 'overwritten' => $overwritten, 'errors' => $errors];
    }

    public function deleteFile(Media $media): void
    {
        $baseDir = rtrim($this->config['upload_dir'], '/');

        $this->deleteContained($baseDir, $media->getPath());

        foreach ($media->getThumbnails() as $thumbPath) {
            $this->deleteContained($baseDir, $thumbPath);
        }
    }

    /**
     * Delete a file only if the stored relative path resolves inside the
     * uploads directory — protects against poisoned path values (e.g. via
     * import) escaping the media storage.
     */
    private function deleteContained(string $baseDir, string $relativePath): void
    {
        if ($relativePath === '' || str_contains($relativePath, '..')) {
            return;
        }

        $resolved = realpath($baseDir . '/' . $relativePath);
        $base     = realpath($baseDir);

        if ($resolved === false || $base === false || !str_starts_with($resolved, $base . DIRECTORY_SEPARATOR)) {
            return;
        }

        if (is_file($resolved)) {
            unlink($resolved);
        }
    }

    /**
     * Move an uploaded file to its destination. rename() is atomic but cannot
     * cross filesystems — PHP emulates the move via copy+chown+chmod+unlink
     * and emits "Operation not permitted" warnings when those sub-calls fail
     * (WSL/Docker: /tmp on ext4, storage on a bind mount). Comparing
     * stat()['dev'] picks the right strategy up front, so the noisy emulation
     * never runs.
     */
    private function moveIntoPlace(string $from, string $to): bool
    {
        $fromDev = @stat($from)['dev']        ?? null;
        $toDev   = @stat(dirname($to))['dev'] ?? null;

        if ($fromDev !== null && $toDev !== null && $fromDev === $toDev) {
            return @rename($from, $to) && is_file($to);
        }

        if (!@copy($from, $to) || !is_file($to)) {
            @unlink($to);
            return false;
        }

        $fromSize = @filesize($from);
        $toSize   = @filesize($to);
        if ($fromSize !== false && $toSize !== false && $fromSize !== $toSize) {
            @unlink($to);
            return false;
        }

        @unlink($from);
        return true;
    }

    private function checkRateLimit(int $userId): void
    {
        if ($this->cache === null) {
            return;
        }

        $max   = $this->config['rate_limit'] ?? 10;
        $key   = "upload_rate_limit_{$userId}";
        $count = (int) $this->cache->get($key, 0);

        if ($count >= $max) {
            $remaining = 60 - (time() % 60);
            throw new \RuntimeException("Upload rate limit exceeded. Try again in {$remaining} seconds.");
        }
    }

    private function detectMimeType(string $filePath): string
    {
        $finfo = new \finfo(\FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($filePath);
        return $mime !== false ? $mime : 'application/octet-stream';
    }

    private function hasDoubleExtension(string $name): bool
    {
        // Only flag if the stem (filename without last extension) ends with a
        // known executable/dangerous extension — not version numbers like 0.10.1
        $stem    = pathinfo($name, PATHINFO_FILENAME);
        $stemExt = strtolower(pathinfo($stem, PATHINFO_EXTENSION));
        if ($stemExt === '') {
            return false;
        }
        $dangerous = [
            'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar',
            'exe', 'sh', 'bash', 'bat', 'cmd', 'com', 'vbs', 'vbe',
            'py', 'rb', 'pl', 'cgi', 'asp', 'aspx', 'jsp', 'jspx',
            'htaccess', 'htpasswd',
        ];
        return in_array($stemExt, $dangerous, true);
    }

    private static function phpUploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE   => 'File is too large (exceeds server upload limit).',
            UPLOAD_ERR_FORM_SIZE  => 'File is too large (exceeds form size limit).',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded. Please try again.',
            UPLOAD_ERR_NO_FILE    => 'No file was received by the server.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server misconfiguration: missing temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Server could not write the file to disk.',
            UPLOAD_ERR_EXTENSION  => 'Upload was blocked by a server extension.',
            default               => 'Upload failed (error code ' . $code . ').',
        };
    }

    /**
     * Parse a PHP ini size string (e.g. "256M", "1G") into bytes.
     */
    public static function parsePhpLimit(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '0') {
            return 0;
        }

        $num    = (int) $value;
        $suffix = strtoupper(substr($value, -1));

        return match ($suffix) {
            'G'     => $num * 1024 * 1024 * 1024,
            'M'     => $num * 1024 * 1024,
            'K'     => $num * 1024,
            default => $num,
        };
    }

    /**
     * Get effective upload limits as an associative array.
     *
     * @return array{max_file_size: int, max_file_count: int, max_file_size_mb: string, max_total_size: int, max_total_size_mb: string}
     */
    public function getEffectiveLimits(): array
    {
        $maxFileSize = $this->config['max_file_size'] ?? 0;
        if ($maxFileSize <= 0) {
            $maxFileSize = self::parsePhpLimit(ini_get('upload_max_filesize'));
        }

        $maxFileCount = $this->config['max_file_count'] ?? 0;
        if ($maxFileCount <= 0) {
            $maxFileCount = (int) ini_get('max_file_uploads');
        }

        $maxTotalSize = $this->maxBatchSizeBytes();
        $postMax      = self::parsePhpLimit(ini_get('post_max_size'));

        // Effective ceiling: the app batch cap is exact, but PHP silently
        // discards the whole multipart body past post_max_size, so the real
        // limit a client can send is the lower of the two.
        $effectiveTotal = ($postMax > 0) ? min($maxTotalSize, $postMax) : $maxTotalSize;

        return [
            'max_file_size'      => $maxFileSize,
            'max_file_count'     => $maxFileCount,
            'max_file_size_mb'   => $maxFileSize > 0 ? round($maxFileSize / 1024 / 1024) : '',
            'max_total_size'     => $maxTotalSize,
            'max_total_size_mb'  => $maxTotalSize > 0 ? (string) round($effectiveTotal / 1048576) : '',
        ];
    }

    /**
     * Effective total-batch cap in bytes.
     *
     * 0 (config or admin setting) means "follow the server": use PHP's
     * post_max_size, or no cap at all when that is disabled. The admin batch
     * setting is additionally clamped to post_max_size, because a batch above
     * it is discarded by PHP before the app ever sees it.
     */
    private function maxBatchSizeBytes(): int
    {
        $appCap  = (int) ($this->config['max_total_size'] ?? 0);
        $postMax = self::parsePhpLimit(ini_get('post_max_size'));

        if ($appCap <= 0) {
            return $postMax;
        }

        return ($postMax > 0) ? min($appCap, $postMax) : $appCap;
    }
}
