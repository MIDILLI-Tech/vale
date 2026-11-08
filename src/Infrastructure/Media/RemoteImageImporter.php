<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Media;

use ValeApp\Domain\Media\Repository\MediaRepositoryInterface;
use ValeApp\Infrastructure\Http\RemoteFetchException;
use ValeApp\Infrastructure\Http\SafeRemoteFetcher;

/**
 * Imports an image/file into the media library from a numeric media ID,
 * an absolute URL, a base64 data URI, or a bare base64 payload.
 * Used by API write endpoints. https:// URLs are downloaded and stored
 * locally, so upload settings (mime whitelist, size limits) always apply.
 */
final class RemoteImageImporter
{
    private const DEFAULT_MAX_BYTES = 104857600; // 100MB fallback if uncapped

    private readonly SafeRemoteFetcher $fetcher;

    public function __construct(
        private readonly MediaRepositoryInterface $mediaRepo,
        private readonly MediaUploadService $mediaService,
        ?SafeRemoteFetcher $fetcher = null,
    ) {
        $this->fetcher = $fetcher ?? new SafeRemoteFetcher();
    }

    public function importFromInput(string $input, int $userId): ?int
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        if (ctype_digit($input)) {
            $media = $this->mediaRepo->findById((int) $input);
            return $media?->getId();
        }

        if (str_starts_with($input, 'http://') || str_starts_with($input, 'https://')) {
            $limits   = $this->mediaService->getEffectiveLimits();
            $maxLimit = (int) ($limits['max_file_size'] ?? 0);
            $maxBytes = $maxLimit > 0 ? $maxLimit : self::DEFAULT_MAX_BYTES;

            $downloaded = $this->downloadRemoteUrlSafely($input, $maxBytes);
            if ($downloaded === null) {
                return null;
            }

            [$tmpFile, $finalUrl] = $downloaded;

            $pathName     = (string) (parse_url($finalUrl, PHP_URL_PATH) ?: '');
            $originalName = basename($pathName);
            $ext          = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

            if ($ext === '' || $originalName === '') {
                $finfo       = new \finfo(FILEINFO_MIME_TYPE);
                $mime        = $finfo->file($tmpFile) ?: '';
                $detectedExt = $this->extensionForMime($mime);
                if ($detectedExt !== null) {
                    $originalName = ($originalName !== '' ? $originalName : 'image')
                        . '.' . $detectedExt;
                } else {
                    $originalName = ($originalName !== '' ? $originalName : 'image.jpg');
                }
            }

            return $this->store($tmpFile, $originalName, $userId);
        }

        // Accepts optional media-type parameters, e.g. data:image/svg+xml;charset=utf-8;base64,...
        if (preg_match('/^data:([\w.+\/-]+)(?:;[^,]*)?;base64,(.*)$/is', $input, $m)) {
            $data = base64_decode((string) preg_replace('/\s+/', '', $m[2]), true);
            if ($data === false || $data === '') {
                return null;
            }
            $ext = $this->extensionForMime(strtolower(trim($m[1]))) ?? $this->extensionForBinary($data);
            if ($ext === null) {
                return null;
            }
            $tmpFile      = tempnam(sys_get_temp_dir(), 'api_upload_');
            $originalName = 'image.' . $ext;
            file_put_contents($tmpFile, $data);
            return $this->store($tmpFile, $originalName, $userId);
        }

        // Bare base64 payload (no data-URI prefix — same shape as POST /api/v1/media {base64}).
        // Let MediaUploadService validate mime/size; anything invalid returns null.
        $compact = preg_replace('/\s+/', '', $input) ?? '';
        if (strlen($compact) >= 64 && preg_match('/^[A-Za-z0-9+\/=]+$/', $compact)) {
            $data = base64_decode($compact, true);
            if ($data !== false && $data !== '') {
                $ext = $this->extensionForBinary($data);
                if ($ext !== null) {
                    $tmpFile = tempnam(sys_get_temp_dir(), 'api_upload_');
                    file_put_contents($tmpFile, $data);
                    return $this->store($tmpFile, 'image.' . $ext, $userId);
                }
            }
        }

        return null;
    }

    /**
     * Downloads a remote URL safely. SafeRemoteFetcher validates
     * PrivateNetworkGuard on every hop in the redirect chain; the body is then
     * streamed to disk in chunks up to a byte limit.
     *
     * @return array{0: string, 1: string}|null [tmpFilePath, finalUrl]
     */
    private function downloadRemoteUrlSafely(string $initialUrl, int $maxBytes): ?array
    {
        try {
            $fetch = $this->fetcher->open($initialUrl, 'Vale CMS Remote Importer', 15);
        } catch (RemoteFetchException) {
            return null;
        }

        $stream = $fetch['stream'];

        if ($fetch['status'] !== 200) {
            fclose($stream);

            return null;
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'api_upload_');
        if ($tmpFile === false) {
            fclose($stream);
            return null;
        }

        $outHandle = @fopen($tmpFile, 'wb');
        if ($outHandle === false) {
            fclose($stream);
            @unlink($tmpFile);
            return null;
        }

        $bytesDownloaded = 0;
        while (!feof($stream)) {
            $chunk = fread($stream, 8192);
            if ($chunk === false) {
                break;
            }
            $chunkLen = strlen($chunk);
            if ($chunkLen === 0) {
                continue;
            }
            $bytesDownloaded += $chunkLen;

            if ($bytesDownloaded > $maxBytes) {
                fclose($stream);
                fclose($outHandle);
                @unlink($tmpFile);
                return null;
            }

            fwrite($outHandle, $chunk);
        }

        fclose($stream);
        fclose($outHandle);

        if ($bytesDownloaded === 0) {
            @unlink($tmpFile);
            return null;
        }

        return [$tmpFile, $fetch['url']];
    }

    private function extensionForMime(string $mime): ?string
    {
        return match (strtolower($mime)) {
            'image/jpeg', 'image/jpg'                                                  => 'jpg',
            'image/png'                                                                => 'png',
            'image/gif'                                                                => 'gif',
            'image/webp'                                                               => 'webp',
            'image/svg+xml'                                                            => 'svg',
            'image/avif'                                                               => 'avif',
            'application/pdf'                                                          => 'pdf',
            'application/rtf', 'text/rtf'                                              => 'rtf',
            'application/zip'                                                          => 'zip',
            'application/x-rar-compressed', 'application/x-rar', 'application/vnd.rar' => 'rar',
            'application/x-7z-compressed'                                              => '7z',
            'application/gzip', 'application/x-gzip'                                   => 'gz',
            'application/x-tar'                                                        => 'tar',
            'application/x-bzip2'                                                      => 'bz2',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'  => 'docx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'        => 'xlsx',
            'application/x-subrip'                                                     => 'srt',
            'text/vtt'                                                                 => 'vtt',
            'text/plain'                                                               => 'txt',
            'audio/mpeg', 'audio/mp3'                                                  => 'mp3',
            'video/mp4'                                                                => 'mp4',
            'video/webm'                                                               => 'webm',
            'video/x-matroska'                                                         => 'mkv',
            default                                                                    => null,
        };
    }

    private function extensionForBinary(string $data): ?string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->buffer($data) ?: '';
        return $this->extensionForMime($mime);
    }

    private function store(string $tmpFile, string $originalName, int $userId): ?int
    {
        try {
            $media = $this->mediaService->upload([
                'tmp_name' => $tmpFile,
                'name'     => $originalName,
                'type'     => mime_content_type($tmpFile) ?: 'application/octet-stream',
                'size'     => (int) filesize($tmpFile),
                'error'    => UPLOAD_ERR_OK,
            ], $userId);
            return $media->getId();
        } catch (\Throwable) {
            return null;
        } finally {
            if (is_file($tmpFile)) {
                @unlink($tmpFile);
            }
        }
    }
}
