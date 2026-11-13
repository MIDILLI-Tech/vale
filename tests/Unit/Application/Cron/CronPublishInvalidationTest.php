<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Cron;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use ValeApp\Application\Cron\CronService;
use ValeApp\Application\Newsletter\AutomationService;
use ValeApp\Application\Newsletter\NewsletterService;
use ValeApp\Application\Update\UpdateService;
use ValeApp\Domain\Content\Entity\Post;
use ValeApp\Domain\Content\Repository\CategoryRepositoryInterface;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Content\Repository\TagRepositoryInterface;
use ValeApp\Domain\Mail\Port\MailInterface;
use ValeApp\Domain\Media\Repository\MediaRepositoryInterface;
use ValeApp\Domain\Newsletter\Repository\AutomationRepositoryInterface;
use ValeApp\Domain\Newsletter\Repository\CampaignRepositoryInterface;
use ValeApp\Domain\Newsletter\Repository\SubscriberRepositoryInterface;
use ValeApp\Domain\Shared\Event\EventDispatcherInterface;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\Shop\Entity\Product;
use ValeApp\Domain\Shop\Repository\CouponRepositoryInterface;
use ValeApp\Domain\Shop\Repository\OrderRepositoryInterface;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;
use ValeApp\Infrastructure\Cache\PageCache;
use ValeApp\Infrastructure\Cache\PageCacheInvalidator;
use ValeApp\Infrastructure\ExchangeRate\ExchangeRateService;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Security\Encrypter;

/**
 * Scheduled publishing runs through CronService, not the admin controllers, so
 * it must fire the same domain events and drop the same cached pages as an
 * admin save — otherwise /blog and the home page keep serving stale HTML until
 * the page-cache TTL expires.
 */
final class CronPublishInvalidationTest extends TestCase
{
    private string $cacheDir = '';

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/vale-cron-cache-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '/*.html') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->cacheDir);
    }

    public function testScheduledPostPublishDispatchesEventAndDropsCachedPages(): void
    {
        $post = new Post('Yeni', new Slug('yeni'), 1);
        $post->setContentType('post');
        $post->setStatus('scheduled');
        $post->setPublishedAt(new \DateTimeImmutable('-1 hour'));

        $posts = $this->createStub(PostRepositoryInterface::class);
        $posts->method('findDueForPublishing')->willReturn([$post]);
        $posts->method('findDueForExpiry')->willReturn([]);
        $posts->method('findDueForGoPublic')->willReturn([]);
        $posts->method('save')->willReturnCallback(static fn (Post $saved): Post => $saved);

        $events = $this->createMock(EventDispatcherInterface::class);
        $events->expects(self::once())
            ->method('doAction')
            ->with('post.published', self::isInstanceOf(Post::class));

        $cache       = new PageCache($this->cacheDir, 3600, [], true);
        $invalidator = new PageCacheInvalidator($cache, $this->settingsStub());

        foreach (['/', '/blog', '/feed', '/blog/yeni'] as $path) {
            $cache->put(site_url($path), '<html>cached</html>');
        }
        self::assertCount(4, glob($this->cacheDir . '/*.html') ?: []);

        $result = $this->buildService(
            $posts,
            $this->createStub(ProductRepositoryInterface::class),
            $this->settingsStub(),
            $events,
            $invalidator
        )->runDue();

        self::assertSame(1, $result['posts_published']);
        self::assertSame([], glob($this->cacheDir . '/*.html') ?: []);
    }

    public function testScheduledProductPublishDispatchesUpdateEvent(): void
    {
        $product = new Product('Yeni Ürün', new Slug('yeni-urun'));
        $product->setStatus('scheduled');
        $product->setPublishedAt(new \DateTimeImmutable('-1 hour'));

        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('findDueForPublishing')->willReturn([$product]);
        $products->method('findDueForExpiry')->willReturn([]);
        $products->method('findDueForGoPublic')->willReturn([]);

        $events = $this->createMock(EventDispatcherInterface::class);
        $events->expects(self::once())
            ->method('doAction')
            ->with('product.updated', self::isInstanceOf(Product::class));

        $result = $this->buildService(
            $this->createStub(PostRepositoryInterface::class),
            $products,
            $this->settingsStub(),
            $events,
            new PageCacheInvalidator(new PageCache($this->cacheDir, 3600, [], true), $this->settingsStub())
        )->runDue();

        self::assertSame(1, $result['products_published']);
    }

    private function settingsStub(): SettingsRepositoryInterface
    {
        $settings = $this->createStub(SettingsRepositoryInterface::class);
        $settings->method('bool')->willReturnCallback(
            static fn (string $key, bool $default = false): bool => in_array($key, ['blog_enabled', 'shop_enabled'], true),
        );
        $settings->method('get')->willReturnCallback(
            static fn (string $key, ?string $default = null): ?string => $default,
        );

        return $settings;
    }

    private function buildService(
        PostRepositoryInterface $posts,
        ProductRepositoryInterface $products,
        SettingsRepositoryInterface $settings,
        EventDispatcherInterface $events,
        PageCacheInvalidator $invalidator,
    ): CronService {
        $campaigns   = $this->createStub(CampaignRepositoryInterface::class);
        $automations = new AutomationService(
            $this->createStub(AutomationRepositoryInterface::class),
            $this->createStub(SubscriberRepositoryInterface::class),
            $this->createStub(MailInterface::class),
            $this->createStub(OrderRepositoryInterface::class),
            'https://example.com',
        );
        $newsletter  = new NewsletterService(
            $this->createStub(MailInterface::class),
            $campaigns,
            $this->createStub(SubscriberRepositoryInterface::class),
            $this->createStub(CouponRepositoryInterface::class),
            $posts,
            $this->createStub(CategoryRepositoryInterface::class),
            $this->createStub(TagRepositoryInterface::class),
            $this->createStub(MediaRepositoryInterface::class),
            'https://example.com',
        );

        return new CronService(
            $newsletter,
            $automations,
            $campaigns,
            $posts,
            $products,
            new ExchangeRateService(
                new DbalSettingsRepository(
                    $this->createStub(Connection::class),
                    $this->createStub(Encrypter::class),
                ),
            ),
            $settings,
            new UpdateService(
                $this->createStub(SettingsRepositoryInterface::class),
                sys_get_temp_dir(),
                'https://example.com/manifest.json',
            ),
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            '',
            '',
            null,
            null,
            null,
            $events,
            $invalidator,
        );
    }
}
