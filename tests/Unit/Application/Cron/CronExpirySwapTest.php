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
use ValeApp\Domain\Shop\Repository\CouponRepositoryInterface;
use ValeApp\Domain\Shop\Repository\OrderRepositoryInterface;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;
use ValeApp\Infrastructure\ExchangeRate\ExchangeRateService;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Security\Encrypter;

final class CronExpirySwapTest extends TestCase
{
    private $posts;

    private $settings;

    protected function setUp(): void
    {
        $this->posts    = $this->createStub(PostRepositoryInterface::class);
        $this->settings = $this->createStub(SettingsRepositoryInterface::class);
        $this->settings->method('bool')->willReturnCallback(
            static fn (string $key, bool $default = false): bool => $key === 'blog_enabled' ? true : $default,
        );
        $this->settings->method('get')->willReturnCallback(
            static fn (string $key, ?string $default = null): ?string => $default,
        );
    }

    private function buildService(): CronService
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
            $this->posts,
            $this->createStub(CategoryRepositoryInterface::class),
            $this->createStub(TagRepositoryInterface::class),
            $this->createStub(MediaRepositoryInterface::class),
            'https://example.com',
        );

        return new CronService(
            $newsletter,
            $automations,
            $campaigns,
            $this->posts,
            $this->createStub(ProductRepositoryInterface::class),
            new ExchangeRateService(
                new DbalSettingsRepository(
                    $this->createStub(Connection::class),
                    $this->createStub(Encrypter::class),
                ),
            ),
            $this->settings,
            new UpdateService(
                $this->createStub(SettingsRepositoryInterface::class),
                sys_get_temp_dir(),
                'https://example.com/manifest.json',
            ),
        );
    }

    private function publishedPage(int $id, string $slug, ?string $expiresAt = null, ?int $swapId = null): Post
    {
        $post = new Post('Page ' . $id, new Slug($slug), 1);
        $post->setId($id);
        $post->setContentType('page');
        $post->setStatus('published');
        if ($expiresAt !== null) {
            $post->setExpiresAt(new \DateTimeImmutable($expiresAt));
        }
        if ($swapId !== null) {
            $post->setSwapPostId($swapId);
        }
        return $post;
    }

    public function testPlainExpiryMovesToExpiredStatus(): void
    {
        $expired = $this->publishedPage(5, 'duyuru', '-1 hour');

        $posts = $this->createMock(PostRepositoryInterface::class);
        $posts->method('findDueForPublishing')->willReturn([]);
        $posts->method('findDueForExpiry')->willReturn([$expired]);
        $posts->expects(self::once())->method('save')->willReturnCallback(
            static function (Post $post): Post {
                self::assertSame('expired', $post->getStatus());
                self::assertTrue($post->isExpiredStatus());
                return $post;
            }
        );
        $posts->expects(self::never())->method('performSwap');
        $this->posts = $posts;

        $result = $this->buildService()->runDue();

        self::assertSame(1, $result['posts_expired']);
        self::assertSame(0, $result['swaps_processed']);
    }

    public function testExpiryWithDraftPartnerSwapsBack(): void
    {
        $campaign = $this->publishedPage(6, 'kampanya', '-1 hour', 5);
        $original = $this->publishedPage(5, 'kampanya-expired-20260101');
        $original->setStatus('draft');

        $posts = $this->createMock(PostRepositoryInterface::class);
        $posts->method('findDueForPublishing')->willReturn([]);
        $posts->method('findDueForExpiry')->willReturn([$campaign]);
        $posts->method('findById')->willReturnMap([[5, $original]]);
        $posts->expects(self::once())->method('performSwap')->willReturnCallback(
            static function (Post $expiring, Post $partner) use ($campaign, $original): void {
                self::assertSame($campaign->getId(), $expiring->getId());
                self::assertSame($original->getId(), $partner->getId());
            }
        );
        $this->posts = $posts;

        $result = $this->buildService()->runDue();

        self::assertSame(1, $result['posts_expired']);
        self::assertSame(1, $result['swaps_processed']);
    }

    public function testScheduledGoLiveSwapsWithPublishedPartner(): void
    {
        $campaign = new Post('BF', new Slug('kampanya-copy'), 1);
        $campaign->setId(6);
        $campaign->setContentType('page');
        $campaign->setStatus('scheduled');
        $campaign->setPublishedAt(new \DateTimeImmutable('-1 hour'));
        $campaign->setSwapPostId(5);

        $live = $this->publishedPage(5, 'kampanya');

        $posts = $this->createMock(PostRepositoryInterface::class);
        $posts->method('findDueForPublishing')->willReturn([$campaign]);
        $posts->method('findDueForExpiry')->willReturn([]);
        $posts->method('findById')->willReturnMap([[5, $live]]);
        $posts->expects(self::once())->method('performSwap')->willReturnCallback(
            static function (Post $expiring, Post $partner) use ($live, $campaign): void {
                self::assertSame($live->getId(), $expiring->getId());
                self::assertSame($campaign->getId(), $partner->getId());
            }
        );
        $this->posts = $posts;

        $result = $this->buildService()->runDue();

        self::assertSame(1, $result['posts_published']);
        self::assertSame(1, $result['swaps_processed']);
    }

    public function testMissingPartnerFallsBackToPlainFlow(): void
    {
        $campaign = new Post('BF', new Slug('kampanya-copy'), 1);
        $campaign->setId(6);
        $campaign->setContentType('page');
        $campaign->setStatus('scheduled');
        $campaign->setPublishedAt(new \DateTimeImmutable('-1 hour'));
        $campaign->setSwapPostId(999);

        $posts = $this->createMock(PostRepositoryInterface::class);
        $posts->method('findDueForPublishing')->willReturn([$campaign]);
        $posts->method('findDueForExpiry')->willReturn([]);
        $posts->method('findById')->willReturn(null);
        $posts->expects(self::never())->method('performSwap');
        $posts->expects(self::once())->method('save')->willReturnArgument(0);
        $this->posts = $posts;

        $result = $this->buildService()->runDue();

        self::assertSame(1, $result['posts_published']);
        self::assertSame(0, $result['swaps_processed']);
        self::assertTrue($campaign->isPublished());
    }

    public function testHomepageSettingFollowsSwap(): void
    {
        $campaign = $this->publishedPage(6, 'kampanya', '-1 hour', 5);
        $original = $this->publishedPage(5, 'kampanya-expired-x');
        $original->setStatus('draft');

        $posts = $this->createStub(PostRepositoryInterface::class);
        $posts->method('findDueForPublishing')->willReturn([]);
        $posts->method('findDueForExpiry')->willReturn([$campaign]);
        $posts->method('findById')->willReturnMap([[5, $original]]);
        $this->posts = $posts;

        $settings = $this->createMock(SettingsRepositoryInterface::class);
        $settings->method('bool')->willReturnCallback(
            static fn (string $key, bool $default = false): bool => $key === 'blog_enabled' ? true : $default,
        );
        $settings->method('get')->willReturnCallback(
            static fn (string $key, ?string $default = null): ?string => $key === 'homepage_page_id' ? '6' : $default,
        );
        $homepageFollows = 0;
        $settings->expects(self::atLeastOnce())->method('set')->willReturnCallback(
            static function (string $key, string $value, string $group = 'general') use (&$homepageFollows): void {
                if ($key === 'homepage_page_id') {
                    self::assertSame('5', $value);
                    self::assertSame('general', $group);
                    $homepageFollows++;
                }
            }
        );
        $this->settings = $settings;

        $this->buildService()->runDue();

        self::assertSame(1, $homepageFollows);
    }

    public function testHomepageSettingUntouchedWhenPointingElsewhere(): void
    {
        $campaign = $this->publishedPage(6, 'kampanya', '-1 hour', 5);
        $original = $this->publishedPage(5, 'kampanya-expired-x');
        $original->setStatus('draft');

        $posts = $this->createStub(PostRepositoryInterface::class);
        $posts->method('findDueForPublishing')->willReturn([]);
        $posts->method('findDueForExpiry')->willReturn([$campaign]);
        $posts->method('findById')->willReturnMap([[5, $original]]);
        $this->posts = $posts;

        $settings = $this->createMock(SettingsRepositoryInterface::class);
        $settings->method('bool')->willReturnCallback(
            static fn (string $key, bool $default = false): bool => $key === 'blog_enabled' ? true : $default,
        );
        $settings->method('get')->willReturnCallback(
            static fn (string $key, ?string $default = null): ?string => $key === 'homepage_page_id' ? '42' : $default,
        );
        $settings->expects(self::atLeastOnce())->method('set')->willReturnCallback(
            static function (string $key): void {
                self::assertNotSame('homepage_page_id', $key);
            }
        );
        $this->settings = $settings;

        $this->buildService()->runDue();
    }
}
