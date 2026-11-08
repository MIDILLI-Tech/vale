<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Infrastructure\Media;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use ValeApp\Infrastructure\Media\ImageProcessor;
use ValeApp\Infrastructure\Media\MediaScanService;

final class MediaScanSubtitleLimitTest extends TestCase
{
    private string $tmpDir;

    private string $file = '';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/vl_subtitle_' . bin2hex(random_bytes(4));
        if (!is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        if ($this->file !== '') {
            @unlink($this->file);
            @unlink(substr($this->file, 0, -3) . 'vtt');
        }
        @rmdir($this->tmpDir);
    }

    public function testOversizedSrtRejectedWithCleanError(): void
    {
        $scanner = new MediaScanService(
            $this->createStub(Connection::class),
            new ImageProcessor(),
            ['allowed_extensions' => ['srt', 'sub', 'vtt']],
        );

        // Cap is 32 MiB — one byte over the limit must fail cleanly instead of
        // attempting the full in-memory conversion.
        $this->file = $this->tmpDir . '/huge.srt';
        $handle     = fopen($this->file, 'wb');
        self::assertNotFalse($handle);
        $chunk = str_repeat('2
00:00:01,000 --> 00:00:02,000
Hello

', 4096);
        while (filesize($this->file) <= 32 * 1024 * 1024) {
            fwrite($handle, $chunk);
            clearstatcache(true, $this->file);
        }
        fclose($handle);

        $method = new \ReflectionMethod($scanner, 'sanitizeFile');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Subtitle file exceeds the 32 MB conversion limit.');
        $method->invoke($scanner, $this->file);
    }
}
