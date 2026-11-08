<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Infrastructure\Media;

use PHPUnit\Framework\TestCase;
use ValeApp\Infrastructure\Media\ImageProcessor;

final class ImageProcessorSvgTest extends TestCase
{
    private ImageProcessor $processor;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->processor = new ImageProcessor();
        $this->tmpDir    = sys_get_temp_dir() . '/vl_svg_test_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);
    }

    private function sanitize(string $svg): string
    {
        $src  = $this->tmpDir . '/in.svg';
        $dest = $this->tmpDir . '/out.svg';
        file_put_contents($src, $svg);
        $this->processor->sanitizeSvg($src, $dest);
        return (string) file_get_contents($dest);
    }

    public function testOnloadHandlerIsRemoved(): void
    {
        $clean = $this->sanitize('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(document.cookie)"><circle r="10"/></svg>');

        self::assertStringNotContainsString('onload', strtolower($clean));
    }

    public function testScriptTagsAreRemoved(): void
    {
        $clean = $this->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect width="5" height="5"/></svg>');

        self::assertStringNotContainsString('<script', strtolower($clean));
        self::assertStringNotContainsString('alert(1)', $clean);
    }

    public function testJavascriptHrefIsRemoved(): void
    {
        $clean = $this->sanitize('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><a xlink:href="javascript:alert(1)"><text>x</text></a></svg>');

        self::assertStringNotContainsString('javascript:', strtolower($clean));
    }

    public function testForeignObjectIsRemoved(): void
    {
        $clean = $this->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><body onload="x()"></body></foreignObject></svg>');

        self::assertStringNotContainsString('foreignobject', strtolower($clean));
    }

    public function testBenignArtworkSurvives(): void
    {
        $svg   = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="40" fill="#ff0000"/><path d="M10 10 L90 90"/></svg>';
        $clean = $this->sanitize($svg);

        self::assertStringContainsString('<circle', $clean);
        self::assertStringContainsString('<path', $clean);
    }

    public function testInvalidSvgThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        $src  = $this->tmpDir . '/bad.svg';
        $dest = $this->tmpDir . '/bad_out.svg';
        file_put_contents($src, 'this is not xml at all');
        $this->processor->sanitizeSvg($src, $dest);
    }
}
