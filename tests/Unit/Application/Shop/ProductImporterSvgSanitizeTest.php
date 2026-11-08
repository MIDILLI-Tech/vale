<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Shop;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use ValeApp\Application\Shop\ProductImporter;
use ValeApp\Infrastructure\Content\SlugService;
use ValeApp\Infrastructure\Media\ImageProcessor;

/**
 * Guards the stored-XSS fix in ProductImporter::downloadImage(): SVG fetched
 * from a remote product feed must pass through the same sanitizer as admin
 * uploads, and a sanitization failure must reject the file (fail-closed).
 *
 * downloadImage() fetches over HTTP (not unit-testable), so this test pins
 * the two halves of the fix: the real sanitizeDownloadedSvg() strips active
 * content via ImageProcessor, and the downloadImage() source wires the
 * image/svg+xml branch to that seam with unlink-on-failure.
 */
final class ProductImporterSvgSanitizeTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/vl_svgimp_' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);
    }

    public function testSanitizeDownloadedSvgStripsActiveContent(): void
    {
        $src = $this->tmpDir . '/evil.svg';
        file_put_contents(
            $src,
            '<svg xmlns="http://www.w3.org/2000/svg"'
            . ' onload="alert(document.cookie)">'
            . '<script>alert(1)</script><circle r="10"/></svg>',
        );

        $this->makeImporter()->sanitizeDownloadedSvg($src);

        $clean = strtolower((string) file_get_contents($src));
        self::assertStringNotContainsString('<script', $clean);
        self::assertStringNotContainsString('onload', $clean);
        self::assertStringContainsString('<circle', $clean);
    }

    public function testSanitizeDownloadedSvgThrowsOnUnsafe(): void
    {
        $src = $this->tmpDir . '/bad.svg';
        file_put_contents($src, 'not svg at all {{{');

        $this->expectException(\RuntimeException::class);
        $this->makeImporter()->sanitizeDownloadedSvg($src);
    }

    public function testDownloadImageWiresSvgBranchToSeamFailClosed(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 4) . '/src/Application/Shop/ProductImporter.php',
        );

        self::assertMatchesRegularExpression(
            '/\$mimeType === .image\/svg\+xml.\) \{\s*'
            . 'try \{\s*\$this->sanitizeDownloadedSvg\(\$destPath\);\s*'
            . '\} catch \(\\\\RuntimeException\) \{\s*'
            . '@unlink\(\$destPath\);\s*return null;/s',
            $src,
            'image/svg+xml branch must sanitize via the seam and unlink on failure',
        );
    }

    private function makeImporter(): ProductImporter
    {
        $db = $this->createStub(Connection::class);

        return new ProductImporter(
            $db,
            new SlugService($db),
            new ImageProcessor(),
            ['thumbnails' => ['sizes' => []]],
        );
    }
}
