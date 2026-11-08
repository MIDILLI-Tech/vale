<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Infrastructure\Media;

use PHPUnit\Framework\TestCase;

/**
 * Guards the web-server MIME mappings for subtitle/video uploads:
 * nginx:alpine's mime.types lacks vtt/srt/mkv, so docker/nginx.conf must
 * serve them with explicit default_type (never a `types {}` replacement,
 * which would drop the inherited png/jpg/mp4 mappings).
 */
final class SubtitleServingConfigTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 4);
    }

    public function testNginxServesVttAsTextVtt(): void
    {
        $conf = (string) file_get_contents($this->root . '/docker/nginx.conf');

        self::assertMatchesRegularExpression(
            '/location[^{]*uploads[^}]*vtt[^}]*default_type\s+text\/vtt;/s',
            $conf,
            '.vtt under /uploads/ must be served as text/vtt',
        );
    }

    public function testNginxServesMkvAsMatroska(): void
    {
        $conf = (string) file_get_contents($this->root . '/docker/nginx.conf');

        self::assertMatchesRegularExpression(
            '/location[^}]*mkv[^}]*default_type\s+video\/x-matroska;/s',
            $conf,
            '.mkv under /uploads/ must be served as video/x-matroska',
        );
    }

    public function testNginxDoesNotReplaceInheritedMimeMap(): void
    {
        $conf = (string) file_get_contents($this->root . '/docker/nginx.conf');

        self::assertDoesNotMatchRegularExpression(
            '/^\s*types\s*\{/m',
            $conf,
            'a types {} directive would replace the inherited mime.types map',
        );
    }

    public function testNginxSubtitleLocationsKeepNosniffWithoutTryFiles(): void
    {
        // try_files must NOT appear in these regex-location + alias blocks:
        // it resolves $uri against the document root instead of the alias,
        // so every request mismatches and falls back to the 404 error page
        // (verified against a live nginx container).
        $conf = (string) file_get_contents($this->root . '/docker/nginx.conf');

        foreach (['vtt', 'srt', 'mkv'] as $ext) {
            self::assertMatchesRegularExpression(
                '/location[^{]*' . $ext . '[^}]*X-Content-Type-Options nosniff/s',
                $conf,
                ".{$ext} location must keep the nosniff header",
            );

            self::assertDoesNotMatchRegularExpression(
                '/location[^{]*' . $ext . '[^}]*try_files[^}]*default_type/s',
                $conf,
                ".{$ext} alias location must not use try_files (breaks alias resolution)",
            );
        }
    }

    public function testApacheAddsSubtitleTypesAdditively(): void
    {
        $vhost = (string) file_get_contents($this->root . '/docker/vhost.conf');

        self::assertStringContainsString('AddType text/vtt .vtt', $vhost);
        self::assertStringContainsString('AddType video/x-matroska .mkv', $vhost);
    }

    public function testServeCatalogMatchesServerConfigs(): void
    {
        /** @var array<string, mixed> $ref */
        $ref   = require $this->root . '/config/reference-data.php';
        $serve = $ref['media_serve_types'] ?? [];

        self::assertSame('text/vtt', $serve['vtt'] ?? null);
        self::assertSame('video/x-matroska', $serve['mkv'] ?? null);
    }
}
