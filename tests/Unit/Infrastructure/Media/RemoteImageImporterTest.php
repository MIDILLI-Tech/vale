<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Infrastructure\Media;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Media\Entity\Media;
use ValeApp\Infrastructure\Media\ImageProcessor;
use ValeApp\Infrastructure\Media\MediaUploadService;
use ValeApp\Infrastructure\Media\RemoteImageImporter;

final class RemoteImageImporterTest extends TestCase
{
    private string $uploadDir = '';

    protected function setUp(): void
    {
        if (!function_exists('imagepng')) {
            $this->markTestSkipped('GD extension required.');
        }
        if (!class_exists(\finfo::class)) {
            $this->markTestSkipped('fileinfo extension required.');
        }
        $this->uploadDir = sys_get_temp_dir() . '/vl_remote_test_' . bin2hex(random_bytes(6));
        mkdir($this->uploadDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->uploadDir);
    }

    public function testEmptyInputReturnsNull(): void
    {
        $repo     = new ArrayMediaRepository();
        $service  = $this->makeService($repo);
        $importer = new RemoteImageImporter($repo, $service);

        self::assertNull($importer->importFromInput('', 1));
        self::assertNull($importer->importFromInput('   ', 1));
    }

    public function testNumericMediaIdReturnsId(): void
    {
        $repo  = new ArrayMediaRepository();
        $media = new Media(1, 'test.png', 'test.png', 'image/png', 100, 'test.png');
        $repo->seed($media);

        $service  = $this->makeService($repo);
        $importer = new RemoteImageImporter($repo, $service);

        self::assertSame(1, $importer->importFromInput('1', 1));
        self::assertNull($importer->importFromInput('999', 1));
    }

    public function testBase64DataUriImport(): void
    {
        $repo    = new ArrayMediaRepository();
        $service = $this->makeService($repo);
        $png     = self::pngBytes([100, 150, 200]);
        $dataUri = 'data:image/png;base64,' . base64_encode($png);

        $importer = new RemoteImageImporter($repo, $service);
        $id       = $importer->importFromInput($dataUri, 1);

        self::assertNotNull($id);
        $saved = $repo->findById($id);
        self::assertNotNull($saved);
        self::assertSame('image/png', $saved->getMimeType());
        self::assertSame('image.png', $saved->getOriginalName());
    }

    public function testSvgDataUriImport(): void
    {
        $repo    = new ArrayMediaRepository();
        $service = $this->makeService($repo);
        $svg     = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10">'
            . '<rect width="10" height="10" fill="red"/></svg>';
        $dataUri = 'data:image/svg+xml;base64,' . base64_encode($svg);

        $importer = new RemoteImageImporter($repo, $service);
        $id       = $importer->importFromInput($dataUri, 1);

        self::assertNotNull($id);
        $saved = $repo->findById($id);
        self::assertNotNull($saved);
        self::assertSame('image/svg+xml', $saved->getMimeType());
        self::assertSame('image.svg', $saved->getOriginalName());
    }

    public function testDataUriWithMediaTypeParametersImport(): void
    {
        $repo    = new ArrayMediaRepository();
        $service = $this->makeService($repo);
        $svg     = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10">'
            . '<rect width="10" height="10" fill="blue"/></svg>';
        $dataUri = 'data:image/svg+xml;charset=utf-8;base64,' . base64_encode($svg);

        $importer = new RemoteImageImporter($repo, $service);
        $id       = $importer->importFromInput($dataUri, 1);

        self::assertNotNull($id);
        $saved = $repo->findById($id);
        self::assertNotNull($saved);
        self::assertSame('image/svg+xml', $saved->getMimeType());
        self::assertSame('image.svg', $saved->getOriginalName());
    }

    public function testDataUriWithUnsupportedTypeIsRejected(): void
    {
        $repo     = new ArrayMediaRepository();
        $service  = $this->makeService($repo);
        $importer = new RemoteImageImporter($repo, $service);

        self::assertNull($importer->importFromInput(
            'data:text/html;base64,' . base64_encode('<html><body>x</body></html>'),
            1,
        ));
        self::assertNull($importer->importFromInput(
            'data:application/x-msdownload;base64,' . base64_encode("MZ\x90\x00binary"),
            1,
        ));
    }

    public function testPdfDataUriImport(): void
    {
        $repo    = new ArrayMediaRepository();
        $service = $this->makeService($repo);
        $pdf     = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF";
        $dataUri = 'data:application/pdf;base64,' . base64_encode($pdf);

        $importer = new RemoteImageImporter($repo, $service);
        $id       = $importer->importFromInput($dataUri, 1);

        self::assertNotNull($id);
        $saved = $repo->findById($id);
        self::assertNotNull($saved);
        self::assertSame('application/pdf', $saved->getMimeType());
        self::assertSame('image.pdf', $saved->getOriginalName());
    }

    public function testBareBase64PayloadImport(): void
    {
        $repo    = new ArrayMediaRepository();
        $service = $this->makeService($repo);
        $png     = self::pngBytes([200, 100, 50]);
        $base64  = base64_encode($png);

        $importer = new RemoteImageImporter($repo, $service);
        $id       = $importer->importFromInput($base64, 1);

        self::assertNotNull($id);
        $saved = $repo->findById($id);
        self::assertNotNull($saved);
        self::assertSame('image/png', $saved->getMimeType());
        self::assertSame('image.png', $saved->getOriginalName());
    }

    public function testPrivateHostUrlIsBlockedByPrivateNetworkGuard(): void
    {
        $repo     = new ArrayMediaRepository();
        $service  = $this->makeService($repo);
        $importer = new RemoteImageImporter($repo, $service);

        self::assertNull($importer->importFromInput('http://127.0.0.1/secret.png', 1));
        self::assertNull($importer->importFromInput('http://localhost/image.png', 1));
        self::assertNull($importer->importFromInput('http://169.254.169.254/latest/meta-data/', 1));
    }

    /** @param array<int, int> $rgb */
    private static function pngBytes(array $rgb): string
    {
        $img = imagecreatetruecolor(2, 2);
        $col = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
        imagefill($img, 0, 0, $col);

        $tmp = tempnam(sys_get_temp_dir(), 'vl_png_');
        imagepng($img, $tmp);

        $bytes = (string) file_get_contents($tmp);
        unlink($tmp);

        return $bytes;
    }

    private function makeService(ArrayMediaRepository $repo): MediaUploadService
    {
        $config = [
            'upload_dir'             => $this->uploadDir,
            'use_year_month_folders' => false,
            'use_original_filename'  => false,
            'allowed_extensions'     => ['png', 'jpg', 'webp', 'svg', 'pdf'],
            'allowed_mime_types'     => [
                'image/png',
                'image/jpeg',
                'image/webp',
                'image/svg+xml',
                'application/pdf',
            ],
            'file_type_map'          => [
                'svg' => 'image/svg+xml',
                'pdf' => 'application/pdf',
            ],
            'enable_image_sanitize'  => true,
            'enable_webp'            => false,
            'thumbnails'             => ['sizes' => []],
            'max_file_size'          => 10485760,
            'max_file_count'         => 100,
            'max_total_size'         => 0,
            'rate_limit'             => 1000,
        ];

        return new MediaUploadService($repo, new ImageProcessor(), $config);
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
