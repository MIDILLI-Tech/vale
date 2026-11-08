<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../bootstrap/serve-upload.php';

final class UploadServingTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function servedExtensions(): array
    {
        return [
            'jpg'          => ['jpg', 'image/jpeg'],
            'vtt'          => ['vtt', 'text/vtt'],
            'srt'          => ['srt', 'application/x-subrip'],
            'webm'         => ['webm', 'video/webm'],
            'mkv'          => ['mkv', 'video/x-matroska'],
            'pdf'          => ['pdf', 'application/pdf'],
            'upper case'   => ['JPG', 'image/jpeg'],
            'unknown'      => ['bin', 'application/octet-stream'],
            'no extension' => ['', 'application/octet-stream'],
        ];
    }

    #[DataProvider('servedExtensions')]
    public function testKnownAndUnknownMediaGetAContentType(string $extension, string $expected): void
    {
        self::assertSame($expected, \vl_upload_content_type($extension));
    }

    /** @return array<string, array{string}> */
    public static function refusedExtensions(): array
    {
        return [
            'php'            => ['php'],
            'php8'           => ['php8'],
            'phtml'          => ['phtml'],
            'phar'           => ['phar'],
            'cgi'            => ['cgi'],
            'html'           => ['html'],
            'htm'            => ['htm'],
            'shtml'          => ['shtml'],
            'upper case php' => ['PHP'],
        ];
    }

    #[DataProvider('refusedExtensions')]
    public function testCodeAndMarkupAreNeverServed(string $extension): void
    {
        self::assertNull(\vl_upload_content_type($extension));
    }

    /** @return array<string, array{string}> */
    public static function hiddenPaths(): array
    {
        return [
            'dotfile at root'         => ['.env'],
            'dotfile in subdirectory' => ['sub/.env'],
            'hidden directory'        => ['.hidden/photo.jpg'],
            'hidden directory nested' => ['2026/10/.cache/x.jpg'],
            'bare dot segment'        => ['2026/.'],
        ];
    }

    #[DataProvider('hiddenPaths')]
    public function testHiddenPathsAreRefused(string $relativePath): void
    {
        self::assertTrue(\vl_upload_path_is_hidden($relativePath));
    }

    /** @return array<string, array{string}> */
    public static function visiblePaths(): array
    {
        return [
            'plain file'           => ['photo.jpg'],
            'dots inside the name' => ['photo.v2.jpg'],
            'nested file'          => ['2026/10/photo.jpg'],
        ];
    }

    #[DataProvider('visiblePaths')]
    public function testVisibleMediaPathsAreAllowed(string $relativePath): void
    {
        self::assertFalse(\vl_upload_path_is_hidden($relativePath));
    }
}
