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
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\Shop\Entity\Product;
use ValeApp\Domain\Shop\Repository\CouponRepositoryInterface;
use ValeApp\Domain\Shop\Repository\OrderRepositoryInterface;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;
use ValeApp\Infrastructure\ExchangeRate\ExchangeRateService;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Security\Encrypter;

final class CronGoPublicTest extends TestCase
{
    private function buildService($posts, $products, $settings): CronService
    {
        $campaigns = $this->createStub(CampaignRepositoryInterface::class);

        $automations = new AutomationService(
            $this->createStub(AutomationRepositoryInterface::class),
            $this->createStub(SubscriberRepositoryInterface::class),
            $this->createStub(MailInterface::class),
            $this->createStub(OrderRepositoryInterface::class),
            'https://example.com',
        );

        $newsletter = new NewsletterService(
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
        );
    }

    private function settingsStub(): SettingsRepositoryInterface
    {
        $settings = $this->createStub(SettingsRepositoryInterface::class);
        $settings->method('bool')->willReturnCallback(
            static fn (string $key, bool $default = false): bool => in_array($key, ['blog_enabled', 'shop_enabled'], true) ? true : $default,
        );
        $settings->method('get')->willReturnCallback(
            static fn (string $key, ?string $default = null): ?string => $default,
        );
        return $settings;
    }

    public function testUnlistedPostWithReachedDateGoesPublic(): void
    {
        $post = new Post('Gizli', new Slug('gizli'), 1);
        $post->setContentType('post');
        $post->setStatus('unlisted');
        $post->setPublishedAt(new \DateTimeImmutable('-1 hour'));

        $posts = $this->createMock(PostRepositoryInterface::class);
        $posts->method('findDueForPublishing')->willReturn([]);
        $posts->method('findDueForExpiry')->willReturn([]);
        $posts->method('findDueForGoPublic')->willReturn([$post]);
        $posts->expects(self::once())->method('save')->willReturnCallback(
            static function (Post $saved): Post {
                self::assertSame('published', $saved->getStatus());
                return $saved;
            }
        );

        $products = $this->createStub(ProductRepositoryInterface::class);

        $result = $this->buildService($posts, $products, $this->settingsStub())->runDue();

        self::assertSame(1, $result['posts_go_public']);
        self::assertSame(0, $result['products_go_public']);
    }

    public function testGoPublicSkippedWhileContentTypeDisabled(): void
    {
        $post = new Post('Sayfa', new Slug('sayfa'), 1);
        $post->setContentType('page');
        $post->setStatus('unlisted');
        $post->setPublishedAt(new \DateTimeImmutable('-1 hour'));

        $posts = $this->createMock(PostRepositoryInterface::class);
        $posts->method('findDueForPublishing')->willReturn([]);
        $posts->method('findDueForExpiry')->willReturn([]);
        $posts->method('findDueForGoPublic')->willReturn([$post]);
        $posts->expects(self::never())->method('save');

        $products = $this->createStub(ProductRepositoryInterface::class);

        // pages_enabled off: the item stays unlisted until re-enabled.
        $settings = $this->createStub(SettingsRepositoryInterface::class);
        $settings->method('bool')->willReturnCallback(
            static fn (string $key, bool $default = false): bool => match ($key) {
                'blog_enabled', 'shop_enabled' => true,
                'pages_enabled'                => false,
                default                        => $default,
            },
        );
        $settings->method('get')->willReturnCallback(
            static fn (string $key, ?string $default = null): ?string => $default,
        );

        $result = $this->buildService($posts, $products, $settings)->runDue();

        self::assertSame(0, $result['posts_go_public']);
        self::assertSame('unlisted', $post->getStatus());
    }

    public function testUnlistedProductWithReachedDateGoesPublic(): void
    {
        $product = new Product('Gizli Ürün', new Slug('gizli-urun'));
        $product->setStatus('unlisted');
        $product->setPublishedAt(new \DateTimeImmutable('-1 hour'));

        $posts = $this->createStub(PostRepositoryInterface::class);

        $products = $this->createMock(ProductRepositoryInterface::class);
        $products->method('findDueForPublishing')->willReturn([]);
        $products->method('findDueForExpiry')->willReturn([]);
        $products->method('findDueForGoPublic')->willReturn([$product]);
        $products->expects(self::once())->method('save')->willReturnCallback(
            static function (Product $saved): Product {
                self::assertSame('published', $saved->getStatus());
                return $saved;
            }
        );

        $result = $this->buildService($posts, $products, $this->settingsStub())->runDue();

        self::assertSame(1, $result['products_go_public']);
    }
}
