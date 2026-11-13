<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Cache;

use ValeApp\Domain\Content\Entity\Post;
use ValeApp\Domain\Content\Repository\CategoryRepositoryInterface;
use ValeApp\Domain\Content\Repository\ContentTypeRepositoryInterface;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Content\Repository\TagRepositoryInterface;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\Shop\Entity\Product;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;

/**
 * Drops the page cache for the public URLs a content change can affect.
 *
 * Shared by the domain event listeners (bootstrap/events.php), the cron worker
 * and the admin controllers so an admin save and a scheduled publish invalidate
 * exactly the same paths — never the whole cache, and never only the detail
 * page. Menus, themes and taxonomies render on every page, so those writers
 * flush the cache instead of invalidating paths.
 *
 * PageCache::invalidate() removes every query-string variant of a path, so a
 * filtered or paginated listing is covered by invalidating its base path.
 */
final class PageCacheInvalidator
{
    public function __construct(
        private readonly PageCache $cache,
        private readonly SettingsRepositoryInterface $settings,
        private readonly ?ContentTypeRepositoryInterface $contentTypes = null,
        private readonly ?CategoryRepositoryInterface $categories = null,
        private readonly ?TagRepositoryInterface $tags = null,
        private readonly ?PostRepositoryInterface $posts = null,
        private readonly ?ProductRepositoryInterface $products = null,
    ) {
    }

    /** A post, page or custom post type entry changed. */
    public function forPost(Post $post): void
    {
        $blogPrefix = $this->prefixed('routing_blog_prefix', '/blog');
        $slug       = (string) $post->getSlug();

        // Home, blog listing and the main feed always embed the newest items.
        $this->invalidate('/');
        $this->invalidate($blogPrefix);
        $this->invalidate('/feed');

        $type = $post->getContentType();

        if ($type === 'page') {
            $this->invalidate($this->pagePrefix() . '/' . $slug);
            return;
        }

        if ($type !== 'post' && $this->contentTypes !== null) {
            $custom = $this->contentTypes->findBySlug(new Slug($type));
            if ($custom !== null) {
                $base = $custom->getPublicBasePath();
                $this->invalidate($base);
                $this->invalidate($base . '/' . $slug);
                if (in_array('rss', $custom->getSupports(), true)) {
                    $this->invalidate($base . '/feed.xml');
                }
                $this->invalidateTaxonomies(
                    $post,
                    $custom->getCategoryPrefix() ?: '/category',
                    $custom->getTagPrefix() ?: '/tag',
                );
                return;
            }
        }

        // 'post', or a custom type whose definition is missing (legacy data).
        $this->invalidate($blogPrefix . '/' . $slug);
        $this->invalidateTaxonomies(
            $post,
            $this->prefixed('routing_category_prefix', '/category'),
            $this->prefixed('routing_tag_prefix', '/tag'),
        );
    }

    /** A product changed (created, updated, published or expired). */
    public function forProduct(Product $product): void
    {
        $this->invalidateProductPaths((string) $product->getSlug());
    }

    /** A deleted product only leaves its slug behind; its listing entry is stale. */
    public function forDeletedProduct(string $slug): void
    {
        if (trim($slug) === '') {
            return;
        }

        $this->invalidateProductPaths($slug);
    }

    /**
     * A comment or review was created, moderated or deleted. The entity page
     * renders them server-side, so its cached HTML no longer matches.
     */
    public function forComment(string $entityType, int $entityId): void
    {
        if ($entityId < 1) {
            return;
        }

        if ($entityType === 'product') {
            $product = $this->products?->findById($entityId);
            if ($product !== null) {
                $this->forProduct($product);
            }
            return;
        }

        $post = $this->posts?->findById($entityId);
        if ($post !== null) {
            $this->forPost($post);
        }
    }

    private function invalidateProductPaths(string $slug): void
    {
        $shop = rtrim($this->prefixed('routing_shop_prefix', '/shop'), '/');

        // The home page can list featured products; the shop listing carries
        // filters and pagination as query variants of the same path.
        $this->invalidate('/');
        $this->invalidate($shop);
        $this->invalidate($shop . '/' . $slug);
        $this->invalidate('/feed/google-merchant');
    }

    /**
     * A post listed under a category/tag also changes that archive listing.
     */
    private function invalidateTaxonomies(Post $post, string $categoryPrefix, string $tagPrefix): void
    {
        if ($this->categories !== null) {
            foreach ($post->getCategoryIds() as $id) {
                $category = $this->categories->findById((int) $id);
                if ($category !== null) {
                    $this->invalidate(rtrim($categoryPrefix, '/') . '/' . (string) $category->getSlug());
                }
            }
        }

        if ($this->tags !== null) {
            foreach ($post->getTagIds() as $id) {
                $tag = $this->tags->findById((int) $id);
                if ($tag !== null) {
                    $this->invalidate(rtrim($tagPrefix, '/') . '/' . (string) $tag->getSlug());
                }
            }
        }
    }

    private function invalidate(string $path): void
    {
        $this->cache->invalidate(site_url($path));
    }

    private function prefixed(string $key, string $default): string
    {
        return '/' . trim((string) ($this->settings->get($key, $default) ?? $default), '/');
    }

    private function pagePrefix(): string
    {
        $prefix = trim((string) ($this->settings->get('routing_page_prefix', '') ?? ''), '/');

        return $prefix === '' ? '' : '/' . $prefix;
    }
}
