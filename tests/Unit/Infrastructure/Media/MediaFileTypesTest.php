<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Infrastructure\Media;

use PHPUnit\Framework\TestCase;
use ValeApp\Infrastructure\Media\ImageProcessor;
use ValeApp\Infrastructure\Media\MediaFileTypes;
use ValeApp\Infrastructure\Media\MediaUploadService;

/**
 * Guards for the media_file_types catalog (config/reference-data.php):
 *  - the extension ↔ detected-MIME match accepts every documented detection
 *    (rar versions, tar, OOXML zip containers, subtitle formats)
 *  - the hard extension blocklist rejects executable families even when a
 *    configuration explicitly lists them
 */
final class MediaFileTypesTest extends TestCase
{
    private string $uploadDir = '';

    /** @return array<string, mixed> */
    private array $catalog = [];

    protected function setUp(): void
    {
        if (!class_exists(\finfo::class)) {
            $this->markTestSkipped('fileinfo extension required for MIME detection.');
        }
        $this->uploadDir = sys_get_temp_dir() . '/vl_mediatypes_' . bin2hex(random_bytes(6));
        mkdir($this->uploadDir, 0o777, true);

        $reference                   = require dirname(__DIR__, 4) . '/config/reference-data.php';
        $this->catalog               = $reference['media_file_types'] ?? [];
        $this->assertNotEmpty($this->catalog, 'reference-data.php must define media_file_types');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->uploadDir);
    }

    // ── pure guard unit tests ──────────────────────────────────────────────

    public function testBlockedExtensionsCoverExecutableFamilies(): void
    {
        $blocked = [
            'php', 'phtml', 'phar', 'htaccess', 'htpasswd', 'ini', 'exe', 'sh',
            'bat', 'cgi', 'jsp', 'shtml', 'html', 'htm',
        ];
        foreach ($blocked as $ext) {
            self::assertTrue(MediaFileTypes::isBlockedExtension($ext), $ext . ' must be blocked');
            self::assertTrue(MediaFileTypes::isBlockedExtension(strtoupper($ext)), 'blocklist is case-insensitive');
        }

        foreach (['jpg', 'pdf', 'zip', 'rar', 'srt', 'docx'] as $ext) {
            self::assertFalse(MediaFileTypes::isBlockedExtension($ext), $ext . ' must stay allowed');
        }
    }

    public function testMatchesDetectedMimeUsesPerExtensionValueLists(): void
    {
        self::assertTrue(MediaFileTypes::matchesDetectedMime('rar', 'application/x-rar', $this->catalog));
        self::assertTrue(MediaFileTypes::matchesDetectedMime('rar', 'application/x-rar-compressed', $this->catalog));
        self::assertTrue(MediaFileTypes::matchesDetectedMime('rar', 'application/vnd.rar', $this->catalog));
        self::assertFalse(MediaFileTypes::matchesDetectedMime('rar', 'image/png', $this->catalog));

        self::assertTrue(MediaFileTypes::matchesDetectedMime('jpg', 'image/jpeg', $this->catalog));
        self::assertFalse(MediaFileTypes::matchesDetectedMime('jpg', 'image/png', $this->catalog));

        self::assertTrue(MediaFileTypes::matchesDetectedMime('webm', 'video/webm', $this->catalog));
        self::assertTrue(MediaFileTypes::matchesDetectedMime('webm', 'video/x-matroska', $this->catalog));
        self::assertFalse(MediaFileTypes::matchesDetectedMime('webm', 'image/png', $this->catalog));
    }

    public function testMatchesDetectedMimeSkipsUnknownExtensions(): void
    {
        // Custom configurations keep the "no opinion" behavior: the extension
        // gate is allowed_extensions, this check must not fail them.
        self::assertTrue(MediaFileTypes::matchesDetectedMime('custom', 'whatever/type', ['png' => 'image/png']));
    }

    // ── upload integration ─────────────────────────────────────────────────

    public function testCatalogTypesUploadSuccessfully(): void
    {
        $service = $this->makeServiceFromCatalog();

        $rarBytes  = "Rar!\x1A\x07\x00" . str_repeat("\x00", 64);
        $srtBytes  = "1\n00:00:01,000 --> 00:00:02,000\nMerhaba dunya\n";
        $docxBytes = $this->docxBytes();

        $rar  = $this->upload($service, 'movies.rar', $rarBytes);
        $srt  = $this->upload($service, 'movie.en.srt', $srtBytes);
        $docx = $this->upload($service, 'contract.docx', $docxBytes);

        self::assertSame([], $rar['errors'], 'rar must be accepted by its documented detections');
        self::assertSame([], $srt['errors'], 'srt must be accepted (libmagic detects x-subrip or text/plain)');
        self::assertSame([], $docx['errors'], 'docx must be accepted (zip container detection)');

        self::assertCount(1, $rar['uploaded']);
        self::assertCount(1, $srt['uploaded']);
        self::assertCount(1, $docx['uploaded']);

        // SRT is normalized to WebVTT (<track> only plays VTT): stored file,
        // record MIME and on-disk bytes are all .vtt.
        $sub = $srt['uploaded'][0];
        self::assertStringEndsWith('.vtt', $sub->getFilename());
        self::assertStringEndsWith('.vtt', $sub->getPath());
        self::assertSame('text/vtt', $sub->getMimeType());
        $storedBytes = (string) file_get_contents($this->uploadDir . '/' . $sub->getPath());
        self::assertStringStartsWith("WEBVTT\n\n", $storedBytes);
        self::assertStringContainsString('00:00:01.000 --> 00:00:02.000', $storedBytes);
    }

    public function testExecutableExtensionIsBlockedEvenWhenConfiguredAsAllowed(): void
    {
        $config = [
            'upload_dir'             => $this->uploadDir,
            'use_year_month_folders' => false,
            'use_original_filename'  => false,
            // Misconfigured on purpose: php is listed in every allowlist.
            'file_type_map'          => ['php' => 'text/x-php'],
            'allowed_extensions'     => ['php', 'png'],
            'allowed_mime_types'     => ['text/x-php', 'image/png'],
            'enable_image_sanitize'  => false,
            'enable_webp'            => false,
            'thumbnails'             => ['sizes' => []],
            'max_file_size'          => 10485760,
            'max_file_count'         => 100,
            'max_total_size'         => 0,
            'rate_limit'             => 1000,
        ];
        $service = new MediaUploadService(new ArrayMediaRepository(), new ImageProcessor(), $config);

        $result = $this->upload($service, 'shell.php', "<?php echo 'x'; ?>");

        self::assertSame([], $result['uploaded']);
        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('not allowed', $result['errors'][0]['error']);
    }

    public function testHtmlContentIsRejectedUnderSubtitleExtension(): void
    {
        $service = $this->makeServiceFromCatalog();

        $result = $this->upload($service, 'attack.srt', "<html><script>alert(1)</script></html>");

        self::assertSame([], $result['uploaded']);
        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('text/html', $result['errors'][0]['error']);
    }

    private function makeServiceFromCatalog(): MediaUploadService
    {
        $config = [
            'upload_dir'             => $this->uploadDir,
            'use_year_month_folders' => false,
            'use_original_filename'  => false,
            'file_type_map'          => $this->catalog,
            'allowed_extensions'     => array_keys($this->catalog),
            'allowed_mime_types'     => $this->flattenMimes($this->catalog),
            'enable_image_sanitize'  => false,
            'enable_webp'            => false,
            'thumbnails'             => ['sizes' => []],
            'max_file_size'          => 10485760,
            'max_file_count'         => 100,
            'max_total_size'         => 0,
            'rate_limit'             => 1000,
        ];

        return new MediaUploadService(new ArrayMediaRepository(), new ImageProcessor(), $config);
    }

    /** @param array<string, string|array<int, string>> $catalog @return array<int, string> */
    private function flattenMimes(array $catalog): array
    {
        $mimes = [];
        foreach ($catalog as $accepted) {
            foreach ((array) $accepted as $mime) {
                $mimes[$mime] = true;
            }
        }

        return array_keys($mimes);
    }

    private function docxBytes(): string
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('zip extension required to fabricate a docx fixture.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'vl_docx_');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types/>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><document/>');
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    /** @return array{uploaded: array<int, mixed>, skipped: array<int, array{name: string, reason: string}>, overwritten: array<int, array{name: string, id: int}>, errors: array<int, array{file: string, error: string}>} */
    private function upload(MediaUploadService $service, string $name, string $bytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'vl_mtup_');
        file_put_contents($tmp, $bytes);

        $result = $service->uploadBatch([
            [
                'tmp_name' => $tmp,
                'name'     => $name,
                'type'     => '',
                'size'     => strlen($bytes),
                'error'    => UPLOAD_ERR_OK,
            ],
        ], 1, 'suffix');

        @unlink($tmp);

        return $result;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
