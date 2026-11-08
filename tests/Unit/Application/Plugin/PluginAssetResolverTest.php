<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Plugin;

use PHPUnit\Framework\TestCase;
use ValeApp\Application\Plugin\PluginAssetResolver;

final class PluginAssetResolverTest extends TestCase
{
    private string $pluginDir;

    private PluginAssetResolver $resolver;

    protected function setUp(): void
    {
        $this->pluginDir = sys_get_temp_dir() . '/vl-plugin-assets-' . bin2hex(random_bytes(6));

        mkdir($this->pluginDir . '/assets/css', 0777, true);
        file_put_contents($this->pluginDir . '/assets/css/style.css', 'body{}');
        file_put_contents($this->pluginDir . '/assets/logo.svg', '<svg/>');
        file_put_contents($this->pluginDir . '/assets/run.php', '<?php echo 1;');
        file_put_contents($this->pluginDir . '/assets/page.html', '<p>x</p>');
        file_put_contents($this->pluginDir . '/plugin.php', '<?php return [];');

        mkdir($this->pluginDir . '/assets/.git', 0777, true);
        file_put_contents($this->pluginDir . '/assets/.git/config', 'secret');

        $this->resolver = new PluginAssetResolver();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->pluginDir);
    }

    public function testServesNestedCssWithCharset(): void
    {
        $asset = $this->resolver->resolve($this->pluginDir, 'css/style.css');

        self::assertNotNull($asset);
        self::assertSame('text/css; charset=UTF-8', $asset['content_type']);
        self::assertSame(6, $asset['size']);
        self::assertStringEndsWith('assets/css/style.css', str_replace('\\', '/', $asset['path']));
    }

    public function testServesSvg(): void
    {
        $asset = $this->resolver->resolve($this->pluginDir, 'logo.svg');

        self::assertNotNull($asset);
        self::assertSame('image/svg+xml; charset=UTF-8', $asset['content_type']);
    }

    public function testRefusesExecutableAndMarkupExtensions(): void
    {
        self::assertNull($this->resolver->resolve($this->pluginDir, 'run.php'));
        self::assertNull($this->resolver->resolve($this->pluginDir, 'page.html'));
    }

    public function testRefusesTraversal(): void
    {
        self::assertNull($this->resolver->resolve($this->pluginDir, '../plugin.php'));
        self::assertNull($this->resolver->resolve($this->pluginDir, 'css/../../plugin.php'));
        self::assertNull($this->resolver->resolve($this->pluginDir, 'css/../css/style.css'));
    }

    public function testRefusesDotfiles(): void
    {
        self::assertNull($this->resolver->resolve($this->pluginDir, '.git/config'));
    }

    public function testRefusesMissingFilesAndDirectories(): void
    {
        self::assertNull($this->resolver->resolve($this->pluginDir, 'missing.css'));
        self::assertNull($this->resolver->resolve($this->pluginDir, 'css'));
        self::assertNull($this->resolver->resolve($this->pluginDir, ''));
        self::assertNull($this->resolver->resolve($this->pluginDir, 'css//style.css'));
    }

    public function testRefusesWhenPluginHasNoAssetsDirectory(): void
    {
        $empty = sys_get_temp_dir() . '/vl-plugin-empty-' . bin2hex(random_bytes(6));
        mkdir($empty, 0777, true);

        try {
            self::assertNull($this->resolver->resolve($empty, 'style.css'));
        } finally {
            rmdir($empty);
        }
    }

    public function testRefusesUnknownPluginDirectory(): void
    {
        self::assertNull($this->resolver->resolve(null, 'css/style.css'));
        self::assertNull($this->resolver->resolve('', 'css/style.css'));
        self::assertNull($this->resolver->resolve($this->pluginDir . '/nope', 'css/style.css'));
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
