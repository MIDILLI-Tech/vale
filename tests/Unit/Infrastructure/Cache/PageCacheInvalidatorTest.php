<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Infrastructure\Cache;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Content\Entity\Category;
use ValeApp\Domain\Content\Entity\Post;
use ValeApp\Domain\Content\Entity\Tag;
use ValeApp\Domain\Content\Repository\CategoryRepositoryInterface;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Content\Repository\TagRepositoryInterface;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\Shop\Entity\Product;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;
use ValeApp\Infrastructure\Cache\PageCache;
use ValeApp\Infrastructure\Cache\PageCacheInvalidator;

/**
 * The page cache is served before routing, so every public URL a write can
 * change must be dropped explicitly — otherwise guests keep the old HTML until
 * the TTL expires.
 */
final class PageCacheInvalidatorTest extends TestCase
{
    private string $cacheDir = '';

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/vale-invalidator-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '/*.html') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->cacheDir);
    }

    public function testPostSaveDropsItsCategoryAndTagArchives(): void
    {
        $post = new Post('Haber', new Slug('haber'), 1);
        $post->setContentType('post');
        $post->setCategoryIds([3]);
        $post->setTagIds([7]);

        [$invalidator, $cache] = $this->buildInvalidator(
            categories: $this->categoryRepository([3 => new Category('Duyurular', new Slug('duyurular'))]),
            tags: $this->tagRepository([7 => new Tag('PHP', new Slug('php'))]),
        );

        $this->seed($cache, ['/', '/blog', '/blog/haber', '/category/duyurular', '/tag/php', '/unrelated']);

        $invalidator->forPost($post);

        self::assertNull($cache->get(site_url('/category/duyurular')), 'the category archive must drop');
        self::assertNull($cache->get(site_url('/tag/php')), 'the tag archive must drop');
        self::assertNotNull($cache->get(site_url('/unrelated')), 'unrelated pages must survive');
    }

    public function testDeletedProductDropsShopListingAndDetail(): void
    {
        [$invalidator, $cache] = $this->buildInvalidator();

        $this->seed($cache, ['/', '/shop', '/shop/widget', '/feed/google-merchant', '/shop/other']);

        $invalidator->forDeletedProduct('widget');

        self::assertNull($cache->get(site_url('/')));
        self::assertNull($cache->get(site_url('/shop')));
        self::assertNull($cache->get(site_url('/shop/widget')));
        self::assertNull($cache->get(site_url('/feed/google-merchant')));
        self::assertNotNull($cache->get(site_url('/shop/other')), 'other products stay cached');
    }

    public function testDeletedProductWithoutSlugLeavesCacheUntouched(): void
    {
        [$invalidator, $cache] = $this->buildInvalidator();

        $this->seed($cache, ['/', '/shop']);

        $invalidator->forDeletedProduct('');

        self::assertNotNull($cache->get(site_url('/')));
        self::assertNotNull($cache->get(site_url('/shop')));
    }

    public function testCommentDropsTheCommentedPostPage(): void
    {
        $post = new Post('Haber', new Slug('haber'), 1);
        $post->setContentType('post');

        [$invalidator, $cache] = $this->buildInvalidator(posts: $this->postRepository(11, $post));

        $this->seed($cache, ['/blog/haber', '/shop/widget']);

        $invalidator->forComment('post', 11);

        self::assertNull($cache->get(site_url('/blog/haber')));
        self::assertNotNull($cache->get(site_url('/shop/widget')));
    }

    public function testReviewDropsTheReviewedProductPage(): void
    {
        [$invalidator, $cache] = $this->buildInvalidator(
            products: $this->productRepository(22, new Product('Widget', new Slug('widget'))),
        );

        $this->seed($cache, ['/shop/widget', '/blog/haber']);

        $invalidator->forComment('product', 22);

        self::assertNull($cache->get(site_url('/shop/widget')));
        self::assertNotNull($cache->get(site_url('/blog/haber')));
    }

    /**
     * @return array{0: PageCacheInvalidator, 1: PageCache}
     */
    private function buildInvalidator(
        ?PostRepositoryInterface $posts = null,
        ?ProductRepositoryInterface $products = null,
        ?CategoryRepositoryInterface $categories = null,
        ?TagRepositoryInterface $tags = null,
    ): array {
        $settings = $this->createStub(SettingsRepositoryInterface::class);
        $settings->method('get')->willReturnCallback(
            static fn (string $key, ?string $default = null): ?string => $default,
        );

        $cache       = new PageCache($this->cacheDir, 3600, [], true);
        $invalidator = new PageCacheInvalidator($cache, $settings, null, $categories, $tags, $posts, $products);

        return [$invalidator, $cache];
    }

    /**
     * @param array<int, Category> $byId
     */
    private function categoryRepository(array $byId): CategoryRepositoryInterface
    {
        $repo = $this->createStub(CategoryRepositoryInterface::class);
        $repo->method('findById')->willReturnCallback(
            static fn (int $id): ?Category => $byId[$id] ?? null,
        );

        return $repo;
    }

    /**
     * @param array<int, Tag> $byId
     */
    private function tagRepository(array $byId): TagRepositoryInterface
    {
        $repo = $this->createStub(TagRepositoryInterface::class);
        $repo->method('findById')->willReturnCallback(
            static fn (int $id): ?Tag => $byId[$id] ?? null,
        );

        return $repo;
    }

    private function postRepository(int $id, Post $post): PostRepositoryInterface
    {
        $repo = $this->createStub(PostRepositoryInterface::class);
        $repo->method('findById')->willReturnCallback(
            static fn (int $requested): ?Post => $requested === $id ? $post : null,
        );

        return $repo;
    }

    private function productRepository(int $id, Product $product): ProductRepositoryInterface
    {
        $repo = $this->createStub(ProductRepositoryInterface::class);
        $repo->method('findById')->willReturnCallback(
            static fn (int $requested): ?Product => $requested === $id ? $product : null,
        );

        return $repo;
    }

    /**
     * @param string[] $paths
     */
    private function seed(PageCache $cache, array $paths): void
    {
        foreach ($paths as $path) {
            $cache->put(site_url($path), '<html>cached</html>');
        }

        self::assertCount(count($paths), glob($this->cacheDir . '/*.html') ?: []);
    }
}
