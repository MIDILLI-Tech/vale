<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Infrastructure\Media;

use PHPUnit\Framework\TestCase;
use ValeApp\Infrastructure\Media\AvatarStorage;

final class AvatarStorageTest extends TestCase
{
    private string $uploadDir;

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir() . '/vl_avatar_test_' . bin2hex(random_bytes(6));
        mkdir($this->uploadDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->uploadDir);
    }

    public function testStoresSquareReEncodedImage(): void
    {
        $source = $this->createImage(400, 200);

        $path = (new AvatarStorage($this->uploadDir))->storeFromPath($source);

        self::assertMatchesRegularExpression('#^avatars/[a-f0-9]{32}\.png$#', $path);
        self::assertFileExists($this->uploadDir . '/' . $path);

        $stored = getimagesize($this->uploadDir . '/' . $path);
        self::assertNotFalse($stored);
        self::assertSame([AvatarStorage::DIMENSION, AvatarStorage::DIMENSION], [$stored[0], $stored[1]]);
    }

    public function testRejectsNonImagePayload(): void
    {
        $source = $this->uploadDir . '/payload.png';
        file_put_contents($source, '<?php echo "not an image";');

        $this->expectException(\RuntimeException::class);
        (new AvatarStorage($this->uploadDir))->storeFromPath($source);
    }

    public function testDeleteRemovesOnlyLocalAvatars(): void
    {
        $storage = new AvatarStorage($this->uploadDir);
        $path    = $storage->storeFromPath($this->createImage(64, 64));
        $full    = $this->uploadDir . '/' . $path;

        $storage->delete('../config/app.php');
        $storage->delete('https://example.com/a.png');
        self::assertFileExists($full);

        $storage->delete($path);
        self::assertFileDoesNotExist($full);
    }

    public function testStoresWebpWhenEnabled(): void
    {
        if (!function_exists('imagewebp')) {
            self::markTestSkipped('GD WebP support is not available.');
        }

        $path = (new AvatarStorage($this->uploadDir, true))->storeFromPath($this->createImage(320, 180));

        self::assertMatchesRegularExpression('#^avatars/[a-f0-9]{32}\.webp$#', $path);
        $stored = getimagesize($this->uploadDir . '/' . $path);
        self::assertNotFalse($stored);
        self::assertSame([AvatarStorage::DIMENSION, AvatarStorage::DIMENSION], [$stored[0], $stored[1]]);
    }

    public function testKeepsSourceFormatWhenWebpIsDisabled(): void
    {
        $path = (new AvatarStorage($this->uploadDir))->storeFromPath($this->createImage(120, 120));

        self::assertMatchesRegularExpression('#^avatars/[a-f0-9]{32}\.png$#', $path);
    }

    public function testVersionUsesFileMtimeForLocalAvatarsOnly(): void
    {
        $storage = new AvatarStorage($this->uploadDir);
        $path    = $storage->storeFromPath($this->createImage(90, 90));
        $full    = $this->uploadDir . '/' . $path;

        self::assertSame((string) filemtime($full), $storage->version($path));
        self::assertNull($storage->version('https://example.com/a.png'));
        self::assertNull($storage->version('avatars/missing.jpg'));
        self::assertNull($storage->version(null));
        self::assertSame('https://example.com/a.png', $storage->versionedUrl('https://example.com/a.png'));
        self::assertNull($storage->versionedUrl(null));
    }

    public function testMaxKilobytesIsClampedToServerCeiling(): void
    {
        $server = AvatarStorage::serverMaxKilobytes();

        self::assertSame($server > 0 ? min(64, $server) : 64, AvatarStorage::maxKilobytes(1));
        self::assertSame($server > 0 ? min(1024, $server) : 1024, AvatarStorage::maxKilobytes(1024));
        self::assertSame($server > 0 ? min(10240, $server) : 10240, AvatarStorage::maxKilobytes(999999));
    }

    public function testDeleteIgnoresMissingValue(): void
    {
        $storage = new AvatarStorage($this->uploadDir);
        $storage->delete(null);
        $storage->delete('avatars/does-not-exist.png');

        self::assertSame([], $this->listUploadDir());
    }

    private function createImage(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 20, 120, 200));

        $path = $this->uploadDir . '/source_' . bin2hex(random_bytes(4)) . '.png';
        self::assertTrue(imagepng($image, $path));

        return $path;
    }

    /**
     * @return list<string>
     */
    private function listUploadDir(): array
    {
        return array_values(array_diff(scandir($this->uploadDir) ?: [], ['.', '..']));
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }

        @rmdir($directory);
    }
}
