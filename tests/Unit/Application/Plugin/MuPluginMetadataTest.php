<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Plugin;

use PHPUnit\Framework\TestCase;
use ValeApp\Application\Plugin\MuPluginMetadata;

final class MuPluginMetadataTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/vl-mu-meta-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/demo.php');
        @rmdir($this->dir);
    }

    public function testReadsHeaderMetadata(): void
    {
        $file = $this->writeFile(<<<'PHP'
            <?php

            /**
             * Plugin Name: Demo Must-Use
             * Version: 1.2.3
             * Description: Adds a response header.
             * License: MIT
             * Website: https://example.com
             */

            return static fn (): bool => true;
            PHP);

        $metadata = MuPluginMetadata::fromFile($file, 'demo-must-use');

        self::assertSame('Demo Must-Use', $metadata->name);
        self::assertSame('1.2.3', $metadata->version);
        self::assertSame('Adds a response header.', $metadata->description);
        self::assertSame('MIT', $metadata->license);
        self::assertSame('https://example.com', $metadata->homepage);
    }

    public function testFallsBackToFileNameWhenHeaderIsMissing(): void
    {
        $file = $this->writeFile(<<<'PHP'
            <?php

            return static fn (): bool => true;
            PHP);

        $metadata = MuPluginMetadata::fromFile($file, 'demo-must-use');

        self::assertSame('demo-must-use', $metadata->name);
        self::assertNull($metadata->version);
        self::assertNull($metadata->description);
        self::assertNull($metadata->license);
        self::assertNull($metadata->homepage);
    }

    public function testRejectsNonHttpWebsite(): void
    {
        foreach (['javascript:alert(1)', 'ftp://example.test/demo', '/relative/path'] as $url) {
            $file = $this->writeFile("<?php\n\n/**\n * Website: {$url}\n */\n");

            self::assertNull(
                MuPluginMetadata::fromFile($file, 'demo-must-use')->homepage,
                $url,
            );
        }
    }

    private function writeFile(string $contents): string
    {
        $file = $this->dir . '/demo.php';
        file_put_contents($file, $contents);

        return $file;
    }
}
