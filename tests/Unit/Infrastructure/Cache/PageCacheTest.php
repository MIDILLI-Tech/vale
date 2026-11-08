<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Infrastructure\Cache;

use PHPUnit\Framework\TestCase;
use ValeApp\Infrastructure\Cache\PageCache;

final class PageCacheTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = __DIR__ . '/cache_test';
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->cacheDir);
    }

    public function testInvalidateClearsAllVariants(): void
    {
        $cache   = new PageCache($this->cacheDir);
        $baseUrl = 'http://localhost/shop/product';

        // Put multiple variants
        $cache->put($baseUrl, 'guest variant', 'guest');
        $cache->put($baseUrl . '?q=1', 'query variant', 'guest');
        $cache->put($baseUrl, 'admin variant', 'admin');

        // Put trailing slash variant
        $cache->put($baseUrl . '/', 'trailing slash variant', 'guest');

        // Verify they exist
        $this->assertNotNull($cache->get($baseUrl, 'guest'));
        $this->assertNotNull($cache->get($baseUrl . '?q=1', 'guest'));
        $this->assertNotNull($cache->get($baseUrl, 'admin'));
        $this->assertNotNull($cache->get($baseUrl . '/', 'guest'));

        // Invalidate base URL
        $cache->invalidate($baseUrl);

        // All should be gone
        $this->assertNull($cache->get($baseUrl, 'guest'));
        $this->assertNull($cache->get($baseUrl . '?q=1', 'guest'));
        $this->assertNull($cache->get($baseUrl, 'admin'));
        $this->assertNull($cache->get($baseUrl . '/', 'guest'));
    }

    public function testInvalidateWithTrailingSlashClearsNonTrailing(): void
    {
        $cache   = new PageCache($this->cacheDir);
        $baseUrl = 'http://localhost/shop/product';

        $cache->put($baseUrl, 'non-trailing', 'guest');
        $cache->put($baseUrl . '/', 'trailing', 'guest');

        $cache->invalidate($baseUrl . '/');

        $this->assertNull($cache->get($baseUrl, 'guest'));
        $this->assertNull($cache->get($baseUrl . '/', 'guest'));
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->removeDirectory("$dir/$file") : unlink("$dir/$file");
        }
        rmdir($dir);
    }
}
