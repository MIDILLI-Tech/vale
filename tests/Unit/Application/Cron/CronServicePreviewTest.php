<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Cron;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use ValeApp\Application\Cron\CronService;
use ValeApp\Application\Newsletter\AutomationService;
use ValeApp\Application\Newsletter\NewsletterService;
use ValeApp\Application\Update\UpdateService;
use ValeApp\Domain\Content\Repository\CategoryRepositoryInterface;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Content\Repository\TagRepositoryInterface;
use ValeApp\Domain\Mail\Port\MailInterface;
use ValeApp\Domain\Media\Repository\MediaRepositoryInterface;
use ValeApp\Domain\Newsletter\Repository\AutomationRepositoryInterface;
use ValeApp\Domain\Newsletter\Repository\CampaignRepositoryInterface;
use ValeApp\Domain\Newsletter\Repository\SubscriberRepositoryInterface;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Domain\Shop\Repository\CouponRepositoryInterface;
use ValeApp\Domain\Shop\Repository\OrderRepositoryInterface;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;
use ValeApp\Infrastructure\ExchangeRate\ExchangeRateService;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Security\Encrypter;

final class CronServicePreviewTest extends TestCase
{
    public function testPreviewDueCountsJobsWithoutExecutingThem(): void
    {
        $settings = $this->createMock(SettingsRepositoryInterface::class);
        $settings->method('get')->willReturnCallback(
            static fn (string $key, ?string $default = null): ?string => match ($key) {
                'newsletter_enabled' => '1',
                'blog_enabled'       => '1',
                'shop_enabled'       => '1',
                default              => $default,
            },
        );
        $settings->method('bool')->willReturnCallback(
            static fn (string $key, bool $default = false): bool => match ($key) {
                'newsletter_enabled', 'blog_enabled', 'shop_enabled' => true,
                default                                              => $default,
            },
        );
        $settings->expects($this->never())->method('set');

        $campaigns = $this->createStub(CampaignRepositoryInterface::class);
        $campaigns->method('findDueForSending')->willReturn([new \stdClass(), new \stdClass()]);

        $automationsRepo = $this->createStub(AutomationRepositoryInterface::class);
        $automationsRepo->method('countDueEnrollments')->willReturn(3);

        $automations = new AutomationService(
            $automationsRepo,
            $this->createStub(SubscriberRepositoryInterface::class),
            $this->createStub(MailInterface::class),
            $this->createStub(OrderRepositoryInterface::class),
            'https://example.com',
        );

        $duePost = $this->createStub(\ValeApp\Domain\Content\Entity\Post::class);
        $duePost->method('getContentType')->willReturn('post');

        $posts = $this->createStub(PostRepositoryInterface::class);
        $posts->method('findDueForPublishing')->willReturn([$duePost]);
        $posts->method('findDueForExpiry')->willReturn([$duePost, $duePost]);
        $posts->method('findDueForGoPublic')->willReturn([$duePost]);

        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('findDueForPublishing')->willReturn([]);
        $products->method('findDueForGoPublic')->willReturn([]);

        $mailStub = $this->createStub(MailInterface::class);

        $newsletter = new NewsletterService(
            $mailStub,
            $campaigns,
            $this->createStub(SubscriberRepositoryInterface::class),
            $this->createStub(CouponRepositoryInterface::class),
            $posts,
            $this->createStub(CategoryRepositoryInterface::class),
            $this->createStub(TagRepositoryInterface::class),
            $this->createStub(MediaRepositoryInterface::class),
            'https://example.com',
        );

        $updater = new UpdateService(
            $this->createStub(SettingsRepositoryInterface::class),
            sys_get_temp_dir(),
            'https://example.com/manifest.json',
        );

        $exchangeRate = new ExchangeRateService(
            new DbalSettingsRepository(
                $this->createStub(Connection::class),
                $this->createStub(Encrypter::class),
            ),
        );

        $service = new CronService(
            $newsletter,
            $automations,
            $campaigns,
            $posts,
            $products,
            $exchangeRate,
            $settings,
            $updater,
        );

        $preview = $service->previewDue();

        self::assertTrue($preview['newsletter_enabled']);
        self::assertSame(2, $preview['campaigns_due']);
        self::assertSame(3, $preview['automations_due']);
        self::assertSame(1, $preview['posts_due']);
        self::assertSame(2, $preview['posts_expiring']);
        self::assertSame(1, $preview['posts_go_public']);
        self::assertSame(0, $preview['products_due']);
        self::assertSame(0, $preview['products_go_public']);
        self::assertFalse($preview['rates_configured']);
        self::assertFalse($preview['rates_due']);
        self::assertTrue($preview['update_check_due']);
    }
}
