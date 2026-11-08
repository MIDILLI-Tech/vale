<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Media;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

final class MediaScanService
{
    /** Text-based subtitles are tiny by nature; anything bigger is pathological. */
    private const SUBTITLE_CONVERT_MAX_BYTES = 33554432; // 32 MiB

    public function __construct(
        private readonly Connection $db,
        private readonly ImageProcessor $imageProcessor,
        private readonly array $config,
    ) {
    }

    public function scan(int $fallbackUserId, OutputInterface $output, bool $organize = true, bool $dryRun = false): array
    {
        $uploadDir = rtrim($this->config['upload_dir'], '/');
        if (!is_dir($uploadDir)) {
            throw new \RuntimeException("Upload directory not found: {$uploadDir}");
        }

        $dbPaths = $this->fetchDbPaths();
        $output->writeln(sprintf('  <info>Existing DB records:</info> %d', count($dbPaths)));

        $found = $this->findFiles($uploadDir);
        $output->writeln(sprintf('  <info>Files on disk:</info>       %d', count($found)));

        $scanned = 0;
        $added   = 0;
        $skipped = 0;
        $errors  = [];

        foreach ($found as $relativePath => $filePath) {
            $scanned++;

            if (isset($dbPaths[$relativePath])) {
                // Records created before content hashing existed have no hash.
                // Backfill it from the on-disk file (which is the served
                // content) so upload-time dedup / replace-by-hash covers them
                // too. Only runs once per record because the next scan finds a
                // non-null hash.
                if (!$dryRun && $dbPaths[$relativePath]['content_hash'] === null) {
                    $hash = @hash_file('sha256', $filePath);
                    if ($hash !== false && $hash !== '') {
                        $this->db->update(VL_DB_PREFIX . 'media', [
                            'content_hash' => $hash,
                        ], ['id' => $dbPaths[$relativePath]['id']]);
                        $output->writeln(sprintf('  <comment>Hashed:</comment> %s', $relativePath));
                    }
                }
                $skipped++;
                continue;
            }

            // A bare .webp whose original once existed next to it is an orphaned
            // conversion — never re-register it, regardless of the WebP setting.
            // Legitimate standalone WebP uploads (no siblings, no DB sibling
            // record) are still registered normally.
            if (
                str_ends_with(strtolower($relativePath), '.webp')
                && $this->isOrphanedWebpConversion($relativePath, $uploadDir, $dbPaths)
            ) {
                $skipped++;
                $output->writeln(sprintf('  <comment>Skipped orphaned WebP:</comment> %s', $relativePath));
                continue;
            }

            if ($dryRun) {
                $output->writeln(sprintf('  <comment>Would add:</comment> %s', $relativePath));
                $added++;
                continue;
            }

            try {
                $this->processFile($filePath, $relativePath, $fallbackUserId, $output, $organize);
                $added++;
            } catch (\Throwable $e) {
                $errors[] = [
                    'file'  => $relativePath,
                    'error' => $e->getMessage(),
                ];
                $output->writeln("  <error>  ERROR: {$relativePath} — {$e->getMessage()}</error>");
            }
        }

        return [
            'scanned' => $scanned,
            'added'   => $added,
            'skipped' => $skipped,
            'updated' => $this->backfillRegisteredMedia($output, $dryRun),
            'errors'  => $errors,
        ];
    }

    /**
     * Public wrapper so importers can generate WebP/size variants for
     * freshly imported media rows after their ingestion completes.
     */
    public function backfillVariants(OutputInterface $output, bool $dryRun = false): int
    {
        return $this->backfillRegisteredMedia($output, $dryRun);
    }

    /**
     * Ensure existing media records have all derived files: WebP conversion
     * (when enabled), original-format size variants, and WebP size variants.
     * Records whose original is missing on disk are skipped untouched.
     */
    private function backfillRegisteredMedia(OutputInterface $output, bool $dryRun = false): int
    {
        $rows       = $this->db->fetchAllAssociative(
            'SELECT id, filename, mime_type, path, thumbnails FROM ' . VL_DB_PREFIX . 'media',
        );
        $uploadDir  = rtrim($this->config['upload_dir'], '/');
        $thumbSizes = $this->config['thumbnails']['sizes'] ?? [];
        $enableWebp = (bool) ($this->config['enable_webp'] ?? false);
        $updated    = 0;

        foreach ($rows as $row) {
            $mime = (string) $row['mime_type'];
            if (!str_starts_with($mime, 'image/') || $mime === 'image/svg+xml') {
                continue;
            }
            if ($thumbSizes === [] && !$enableWebp) {
                continue;
            }

            $path     = str_replace('\\', '/', (string) $row['path']);
            $dirAbs   = dirname($uploadDir . '/' . $path);
            $original = $uploadDir . '/' . $path;
            if (!is_file($original)) {
                continue;
            }

            $thumbnails = !empty($row['thumbnails']) ? json_decode((string) $row['thumbnails'], true) : [];
            if (!is_array($thumbnails)) {
                $thumbnails = [];
            }

            $dirRelative = str_replace('\\', '/', dirname($path));
            $prefix      = ($dirRelative !== '.' && $dirRelative !== '') ? $dirRelative . '/' : '';
            $baseName    = basename($path);
            $changed     = false;

            // Dry-run: only report records whose derivable renditions are missing.
            if ($dryRun) {
                $info         = @getimagesize($original);
                $origW        = $info[0] ?? 0;
                $origH        = $info[1] ?? 0;
                $missingSize  = false;
                foreach (array_keys($thumbSizes) as $sizeName) {
                    $dim = $thumbSizes[$sizeName];
                    // Sizes larger than the source are legitimately never generated.
                    if (!isset($thumbnails[$sizeName]) && ($origW > $dim['width'] || $origH > $dim['height'])) {
                        $missingSize = true;
                        break;
                    }
                }
                $needsWebp        = $enableWebp && $mime !== 'image/webp' && empty($thumbnails['webp']);
                $needsWebpVariant = false;
                if (!$needsWebp && $thumbSizes !== [] && !empty($thumbnails['webp']) && !$missingSize) {
                    $webpInfo   = @getimagesize($uploadDir . '/' . $thumbnails['webp']);
                    $webpW      = $webpInfo[0] ?? 0;
                    $webpH      = $webpInfo[1] ?? 0;
                    foreach (array_keys($thumbSizes) as $sizeName) {
                        $dim = $thumbSizes[$sizeName];
                        if (!isset($thumbnails['webp_' . $sizeName]) && ($webpW > $dim['width'] || $webpH > $dim['height'])) {
                            $needsWebpVariant = true;
                            break;
                        }
                    }
                }

                if ($needsWebp || $missingSize || $needsWebpVariant) {
                    $updated++;
                    $output->writeln(sprintf('  <comment>Would update:</comment> %s', $path));
                }
                continue;
            }

            // Missing WebP conversion -> create it from the original.
            if ($enableWebp && $mime !== 'image/webp' && empty($thumbnails['webp'])) {
                $webpBase = pathinfo($baseName, PATHINFO_FILENAME) . '.webp';
                if ($this->imageProcessor->convertToWebp($original, $dirAbs . '/' . $webpBase)) {
                    $thumbnails['webp'] = $prefix . $webpBase;
                    $changed            = true;
                }
            }

            // Missing original-format size variants.
            if ($thumbSizes !== []) {
                try {
                    $thumbs = $this->imageProcessor->generateThumbnails($original, $dirAbs, $baseName, $thumbSizes);
                    foreach ($thumbs as $sizeName => $thumbFile) {
                        $rel = $prefix . $thumbFile;
                        if (($thumbnails[$sizeName] ?? null) !== $rel && is_file($dirAbs . '/' . $thumbFile)) {
                            $thumbnails[$sizeName] = $rel;
                            $changed               = true;
                        }
                    }
                } catch (\RuntimeException) {
                }

                // Missing WebP size variants.
                if (!empty($thumbnails['webp'])) {
                    $webpFull = $uploadDir . '/' . $thumbnails['webp'];
                    if (is_file($webpFull)) {
                        try {
                            $webpBase   = basename((string) $thumbnails['webp']);
                            $webpThumbs = $this->imageProcessor->generateThumbnails($webpFull, $dirAbs, $webpBase, $thumbSizes);
                            foreach ($webpThumbs as $sizeName => $thumbFile) {
                                $key = 'webp_' . $sizeName;
                                $rel = $prefix . $thumbFile;
                                if (($thumbnails[$key] ?? null) !== $rel && is_file($dirAbs . '/' . $thumbFile)) {
                                    $thumbnails[$key] = $rel;
                                    $changed          = true;
                                }
                            }
                        } catch (\RuntimeException) {
                        }
                    }
                }
            }

            if (!$changed) {
                continue;
            }

            $this->db->update(VL_DB_PREFIX . 'media', [
                'thumbnails' => json_encode($thumbnails),
            ], ['id' => (int) $row['id']]);
            $updated++;
            $output->writeln(sprintf('  <comment>Updated:</comment> %s', $path));
        }

        return $updated;
    }

    private function fetchDbPaths(): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, path, content_hash FROM ' . VL_DB_PREFIX . 'media',
        );
        $paths = [];
        foreach ($rows as $row) {
            $paths[str_replace('\\', '/', (string) $row['path'])] = [
                'id'           => (int) $row['id'],
                'content_hash' => $row['content_hash'] ?? null,
            ];
        }
        return $paths;
    }

    /**
     * Decide whether a standalone .webp file on disk is an orphaned conversion
     * rather than a legitimate upload. Evidence of being a conversion:
     * a registered same-base record with a different extension, or variant
     * sibling files (-small/-medium/-large) still sitting next to it.
     *
     * @param array<string, array{id: int, content_hash: ?string}> $dbPaths
     */
    private function isOrphanedWebpConversion(string $relativePath, string $uploadDir, array $dbPaths): bool
    {
        $path     = str_replace('\\', '/', $relativePath);
        $dir      = dirname($path);
        $filename = pathinfo($path, PATHINFO_FILENAME);
        $prefix   = ($dir !== '.' && $dir !== '') ? $dir . '/' : '';

        foreach (array_keys($dbPaths) as $registered) {
            $registered = str_replace('\\', '/', (string) $registered);
            if (
                dirname($registered)                        === $dir
                && pathinfo($registered, PATHINFO_FILENAME) === $filename
                && strtolower(pathinfo($registered, PATHINFO_EXTENSION)) !== 'webp'
            ) {
                return true;
            }
        }

        foreach (['small', 'medium', 'large'] as $size) {
            $siblings = glob($uploadDir . '/' . $prefix . $filename . '-' . $size . '.*') ?: [];
            foreach ($siblings as $sibling) {
                if (is_file($sibling)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function findFiles(string $uploadDir): array
    {
        $finder = new Finder();
        $finder->files()->in($uploadDir)->depth('< 3')->ignoreUnreadableDirs();

        $thumbPattern = '/-(?:small|medium|large)\.\w+$/i';

        // First pass: collect files grouped by base name (without extension).
        // When enable_webp is on, a .webp conversion sits next to the original
        // (e.g. photo.png + photo.webp).  We keep only the non-webp original.
        $byBase = [];
        foreach ($finder as $file) {
            // Normalise to forward slashes: DB paths always use '/', while
            // Finder returns '\' separators on Windows — a raw mismatch would
            // make every subdirectory file look unregistered.
            $relativePath = str_replace('\\', '/', $file->getRelativePathname());

            if (preg_match($thumbPattern, $relativePath)) {
                continue;
            }

            $base                = pathinfo($relativePath, PATHINFO_FILENAME);
            $ext                 = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
            $byBase[$base][$ext] = $relativePath;
        }

        $files = [];
        foreach ($byBase as $base => $exts) {
            // If both webp and another format exist, skip the webp conversion
            if (isset($exts['webp']) && count($exts) > 1) {
                unset($exts['webp']);
            }

            foreach ($exts as $ext => $relativePath) {
                $files[$relativePath] = $uploadDir . '/' . $relativePath;
            }
        }

        ksort($files);
        return $files;
    }

    /**
     * Validates a media file against the site's allowed-extension / allowed-MIME
     * whitelists and sanitizes SVG/raster images in place. No DB writes and no
     * file moves are performed, so importers can reuse the same policy for
     * freshly downloaded files.
     *
     * @throws \RuntimeException when the file is not a valid, allowed media file.
     */
    public function sanitizeFile(string $filePath): string
    {
        $filePath = str_replace('\\', '/', $filePath);
        if (!is_file($filePath)) {
            throw new \RuntimeException('File not found on disk.');
        }

        $size = @filesize($filePath);
        if ($size === false || $size <= 0) {
            throw new \RuntimeException('File is empty (0 bytes).');
        }

        $ext         = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $allowedExts = $this->config['allowed_extensions'] ?? [];
        if ($ext === ''
            || MediaFileTypes::isBlockedExtension($ext)
            || !in_array($ext, $allowedExts, true)
        ) {
            throw new \RuntimeException("Extension '{$ext}' not allowed.");
        }

        // Same SRT→VTT normalization as the upload path: <track> only plays
        // WebVTT, so a dropped .srt is converted in place and renamed to .vtt.
        // The conversion loads the whole file into memory — reject pathological
        // sizes with a clean error instead of exhausting memory.
        if (in_array($ext, ['srt', 'sub'], true)) {
            $bytes = @filesize($filePath);
            if ($bytes !== false && $bytes > self::SUBTITLE_CONVERT_MAX_BYTES) {
                throw new \RuntimeException('Subtitle file exceeds the 32 MB conversion limit.');
            }
            $content = @file_get_contents($filePath);
            $vtt     = is_string($content) ? SubtitleConverter::convertSrtToVtt($content) : null;
            if ($vtt !== null) {
                if (@file_put_contents($filePath, $vtt) === false) {
                    throw new \RuntimeException('Failed to convert subtitle.');
                }
                $vttPath = substr($filePath, 0, -strlen($ext)) . 'vtt';
                if (!@rename($filePath, $vttPath)) {
                    throw new \RuntimeException('Failed to convert subtitle.');
                }
                $filePath = $vttPath;
                $ext      = 'vtt';
            }
        }

        $detectedMime = $this->detectMimeType($filePath);
        $allowedMimes = $this->config['allowed_mime_types'] ?? [];
        if (!in_array($detectedMime, $allowedMimes, true)) {
            throw new \RuntimeException("MIME type '{$detectedMime}' not allowed.");
        }

        if (!MediaFileTypes::matchesDetectedMime(
            $ext,
            $detectedMime,
            $this->config['file_type_map'] ?? [],
        )) {
            throw new \RuntimeException("Extension '{$ext}' does not match MIME type '{$detectedMime}'.");
        }

        if ($this->config['enable_image_sanitize'] ?? true) {
            try {
                if ($detectedMime === 'image/svg+xml') {
                    $this->imageProcessor->sanitizeSvg($filePath, $filePath);
                } elseif (str_starts_with($detectedMime, 'image/') && $detectedMime !== 'image/svg+xml') {
                    $maxPixels = $this->config['max_image_pixels'] ?? 50000000;
                    $this->imageProcessor->sanitizeImage($filePath, $filePath, $maxPixels);
                }
            } catch (\RuntimeException $e) {
                throw new \RuntimeException('Image sanitization failed: ' . $e->getMessage());
            }
        }

        return $detectedMime;
    }

    private function processFile(string $filePath, string $relativePath, int $userId, OutputInterface $output, bool $organize = true): void
    {
        $filePath     = str_replace('\\', '/', $filePath);
        $detectedMime = $this->sanitizeFile($filePath);
        // sanitizeFile may rename the file (SRT→VTT); sync both paths from disk.
        $stemExt = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        if (in_array($stemExt, ['srt', 'sub'], true)) {
            $vttSibling = substr($filePath, 0, -strlen($stemExt)) . 'vtt';
            if (is_file($vttSibling)) {
                $filePath     = $vttSibling;
                $dir          = dirname($relativePath);
                $filename     = pathinfo($relativePath, PATHINFO_FILENAME) . '.vtt';
                $relativePath = ($dir === '.' || $dir === '') ? $filename : $dir . '/' . $filename;
            }
        }

        $uploadDir    = rtrim($this->config['upload_dir'], '/');
        $dir          = dirname($relativePath);
        $filename     = basename($relativePath);
        $originalName = $filename;

        if (str_starts_with($relativePath, 'public/')) {
            $originalName = basename($relativePath);
        }

        // When organizing (default), files found at the upload root are physically
        // moved into a YYYY/MM folder so the library stays tidy. With --no-organize
        // they are registered exactly where they are found (including the root), so
        // no file paths are changed.
        if ($organize && $dir === '.') {
            $relativePath = date('Y/m') . '/' . $filename;
            $destDir      = $uploadDir . '/' . date('Y/m');
            if (!is_dir($destDir) && !mkdir($destDir, 0o755, true)) {
                throw new \RuntimeException('Cannot create directory: ' . $destDir);
            }
            $newPath = $destDir . '/' . $filename;
            if (!rename($filePath, $newPath)) {
                throw new \RuntimeException('Cannot move file to ' . $relativePath);
            }
            $filePath = str_replace('\\', '/', $newPath);
            $output->writeln("  <comment>  Moved to:</comment> {$relativePath}");
        }

        $thumbnails = [];
        if (str_starts_with($detectedMime, 'image/') && $detectedMime !== 'image/svg+xml') {
            // WebP conversion
            if (
                ($this->config['enable_webp'] ?? false)
                && $detectedMime !== 'image/webp'
                && $detectedMime !== 'image/svg+xml'
            ) {
                $webpFilename = pathinfo($filename, PATHINFO_FILENAME) . '.webp';
                $webpPath     = dirname($filePath) . '/' . $webpFilename;
                if ($this->imageProcessor->convertToWebp($filePath, $webpPath)) {
                    $thumbPrefix = (dirname($relativePath) !== '.' && dirname($relativePath) !== '')
                        ? dirname($relativePath) . '/'
                        : '';
                    $thumbnails['webp'] = $thumbPrefix . $webpFilename;
                    $output->writeln("  <info>  WebP:</info> {$thumbPrefix}{$webpFilename}");
                }
            }

            $thumbSizes = $this->config['thumbnails']['sizes'] ?? [];
            if ($thumbSizes !== []) {
                $thumbDirRelative = dirname($relativePath);
                $thumbPrefix      = ($thumbDirRelative !== '.' && $thumbDirRelative !== '') ? $thumbDirRelative . '/' : '';
                try {
                    $thumbs = $this->imageProcessor->generateThumbnails(
                        $filePath,
                        dirname($filePath),
                        $filename,
                        $thumbSizes,
                    );
                    foreach ($thumbs as $sizeName => $thumbFile) {
                        $thumbnails[$sizeName] = $thumbPrefix . $thumbFile;
                    }

                    // Size variants derived from the WebP conversion so optimized
                    // renditions exist for every size (webp_small/webp_medium/webp_large).
                    if (isset($thumbnails['webp'])) {
                        $webpBase   = basename((string) $thumbnails['webp']);
                        $webpThumbs = $this->imageProcessor->generateThumbnails(
                            dirname($filePath) . '/' . $webpBase,
                            dirname($filePath),
                            $webpBase,
                            $thumbSizes,
                        );
                        foreach ($webpThumbs as $sizeName => $thumbFile) {
                            $thumbnails['webp_' . $sizeName] = $thumbPrefix . $thumbFile;
                        }
                    }
                } catch (\RuntimeException) {
                }
            }
        }

        $now         = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $contentHash = @hash_file('sha256', $filePath);
        $this->db->insert(VL_DB_PREFIX . 'media', [
            'user_id'       => $userId,
            'filename'      => $filename,
            'original_name' => $originalName,
            'mime_type'     => $detectedMime,
            'size'          => filesize($filePath),
            'path'          => $relativePath,
            'disk'          => 'local',
            'thumbnails'    => json_encode($thumbnails),
            'content_hash'  => $contentHash !== false ? $contentHash : null,
            'created_at'    => $now,
        ]);

        $output->writeln(sprintf(
            '  <info>  Added:</info> %s (%s, %d bytes)',
            $relativePath,
            $detectedMime,
            filesize($filePath)
        ));
    }

    private function detectMimeType(string $filePath): string
    {
        $finfo = new \finfo(\FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($filePath);
        return $mime !== false ? $mime : 'application/octet-stream';
    }
}
