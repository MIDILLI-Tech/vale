<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Infrastructure\Cache;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\Shop\Entity\Product;
use ValeApp\Infrastructure\Cache\AdminSearchCache;
use ValeApp\Infrastructure\Cache\FileCache;

final class AdminSearchCacheTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = __DIR__ . '/search_cache_test';
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->cacheDir);
    }

    public function testSetThenGetRoundtrip(): void
    {
        $cache = new AdminSearchCache(new FileCache($this->cacheDir));
        $rows  = [
            ['id' => 3, 'original_name' => 'matrix-poster.jpg', 'size' => 1024],
            ['id' => 7, 'original_name' => 'matrix-still.png', 'size' => 2048],
        ];

        $cache->set('media_search', $this->keyParts('matrix'), 2, $rows);

        $hit = $cache->get('media_search', $this->keyParts('matrix'));
        self::assertSame(['total' => 2, 'rows' => $rows], $hit);
    }

    public function testDifferentKeyPartsMiss(): void
    {
        $cache = new AdminSearchCache(new FileCache($this->cacheDir));
        $cache->set('media_search', $this->keyParts('matrix'), 2, []);

        self::assertNull($cache->get('media_search', $this->keyParts('inception')));
    }

    public function testGetOnEmptyCacheMisses(): void
    {
        $cache = new AdminSearchCache(new FileCache($this->cacheDir));

        self::assertNull($cache->get('media_search', $this->keyParts('matrix')));
    }

    public function testBumpInvalidatesPreviousEntries(): void
    {
        $cache = new AdminSearchCache(new FileCache($this->cacheDir));
        $cache->set('media_search', $this->keyParts('matrix'), 2, [['id' => 3]]);
        self::assertNotNull($cache->get('media_search', $this->keyParts('matrix')));

        $cache->bump('media_search');

        self::assertNull($cache->get('media_search', $this->keyParts('matrix')));
    }

    public function testBumpOnlyAffectsItsOwnNamespace(): void
    {
        $cache = new AdminSearchCache(new FileCache($this->cacheDir));
        $cache->set('media_search', $this->keyParts('matrix'), 1, [['id' => 3]]);
        $cache->set('post_search', $this->keyParts('matrix'), 1, [['id' => 9]]);

        $cache->bump('media_search');

        self::assertNull($cache->get('media_search', $this->keyParts('matrix')));
        self::assertNotNull($cache->get('post_search', $this->keyParts('matrix')));
    }

    public function testSetObjectsThenGetObjectsRoundtrip(): void
    {
        $cache   = new AdminSearchCache(new FileCache($this->cacheDir));
        $product = new Product('Matrix Figure', new Slug('matrix-figure'));
        $product->setId(42);

        $cache->setObjects('product_search', $this->keyParts('matrix'), 1, [$product]);

        $hit = $cache->getObjects(
            'product_search',
            $this->keyParts('matrix'),
            [Product::class, Slug::class, \DateTimeImmutable::class],
        );

        self::assertNotNull($hit);
        self::assertSame(1, $hit['total']);
        self::assertCount(1, $hit['items']);
        self::assertInstanceOf(Product::class, $hit['items'][0]);
        self::assertSame('Matrix Figure', $hit['items'][0]->getName());
        self::assertSame('matrix-figure', (string) $hit['items'][0]->getSlug());
        self::assertSame(42, $hit['items'][0]->getId());
    }

    public function testGetObjectsWithEmptyAllowlistDoesNotReviveEntities(): void
    {
        $cache = new AdminSearchCache(new FileCache($this->cacheDir));
        $cache->setObjects('product_search', $this->keyParts('matrix'), 1, [
            new Product('Matrix Figure', new Slug('matrix-figure')),
        ]);

        $hit = $cache->getObjects('product_search', $this->keyParts('matrix'), []);

        // Callers must validate with instanceof and fall through to a
        // fresh query — the payload itself never throws here.
        self::assertNotNull($hit);
        self::assertNotInstanceOf(Product::class, $hit['items'][0]);
    }

    public function testGetObjectsOnEmptyCacheMisses(): void
    {
        $cache = new AdminSearchCache(new FileCache($this->cacheDir));

        self::assertNull($cache->getObjects(
            'product_search',
            $this->keyParts('matrix'),
            [Product::class, Slug::class, \DateTimeImmutable::class],
        ));
    }

    /** @return array<string, mixed> */
    private function keyParts(string $search): array
    {
        return [
            'search' => $search,
            'type'   => '',
            'page'   => 1,
            'per'    => 24,
            'sort'   => '',
            'dir'    => 'desc',
        ];
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($dir);
    }
}
