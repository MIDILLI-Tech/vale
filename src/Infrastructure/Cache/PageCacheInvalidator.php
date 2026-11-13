<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Cache;

use ValeApp\Domain\Content\Entity\Post;
use ValeApp\Domain\Content\Repository\ContentTypeRepositoryInterface;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\Shop\Entity\Product;

/**
 * Drops the page cache for the public URLs a content change can affect.
 *
 * Shared by the domain event listeners (bootstrap/events.php) and the cron
 * worker so an admin save and a scheduled publish invalidate exactly the same
 * paths — never the whole cache, and never only the detail page.
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
                return;
            }
        }

        // 'post', or a custom type whose definition is missing (legacy data).
        $this->invalidate($blogPrefix . '/' . $slug);
    }

    /** A product changed (created, updated, published, expired or deleted). */
    public function forProduct(Product $product): void
    {
        $shop = rtrim($this->prefixed('routing_shop_prefix', '/shop'), '/');

        // The home page can list featured products; the shop listing carries
        // filters and pagination as query variants of the same path.
        $this->invalidate('/');
        $this->invalidate($shop);
        $this->invalidate($shop . '/' . (string) $product->getSlug());
        $this->invalidate('/feed/google-merchant');
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
