<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Newsletter;

use PHPUnit\Framework\TestCase;
use ValeApp\Application\Newsletter\NewsletterService;
use ValeApp\Domain\Content\Entity\Post;
use ValeApp\Domain\Content\Repository\CategoryRepositoryInterface;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Content\Repository\TagRepositoryInterface;
use ValeApp\Domain\Mail\Port\MailInterface;
use ValeApp\Domain\Media\Entity\Media;
use ValeApp\Domain\Media\Repository\MediaRepositoryInterface;
use ValeApp\Domain\Newsletter\Entity\Campaign;
use ValeApp\Domain\Newsletter\Entity\Subscriber;
use ValeApp\Domain\Newsletter\Repository\CampaignRepositoryInterface;
use ValeApp\Domain\Newsletter\Repository\SubscriberRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\Shop\Repository\CouponRepositoryInterface;

class RecurringNewsletterTest extends TestCase
{
    private $mail;
    private $campaigns;
    private $subscribers;
    private $coupons;
    private $posts;
    private $categories;
    private $tags;
    private $media;
    private $service;

    protected function setUp(): void
    {
        $this->mail        = $this->createStub(MailInterface::class);
        $this->campaigns   = $this->createStub(CampaignRepositoryInterface::class);
        $this->subscribers = $this->createStub(SubscriberRepositoryInterface::class);
        $this->coupons     = $this->createStub(CouponRepositoryInterface::class);
        $this->posts       = $this->createStub(PostRepositoryInterface::class);
        $this->categories  = $this->createStub(CategoryRepositoryInterface::class);
        $this->tags        = $this->createStub(TagRepositoryInterface::class);
        $this->media       = $this->createStub(MediaRepositoryInterface::class);
        $this->service     = new NewsletterService(
            $this->mail,
            $this->campaigns,
            $this->subscribers,
            $this->coupons,
            $this->posts,
            $this->categories,
            $this->tags,
            $this->media,
            'http://localhost'
        );
    }

    public function testIsRecurringDueMultiDay(): void
    {
        $campaign = new Campaign('Recurring', 'Body');
        $campaign->setId(1);
        $campaign->setRecurring(true);
        $campaign->setRecurringInterval('weekly');
        $campaign->setRecurringDay((string)date('N'));
        $campaign->setRecurringTime('00:00');
        $campaign->setRecurringPostCount(1);
        $campaign->setCreatedAt(new \DateTimeImmutable('-1 month'));

        $this->campaigns->method('findActiveRecurring')->willReturn([$campaign]);
        $this->subscribers->method('findActive')->willReturn([]);

        $posts = $this->createMock(PostRepositoryInterface::class);
        $posts->expects($this->exactly(2))
            ->method('findAll')
            ->willReturnOnConsecutiveCalls(
                [],
                [
                    Post::fromRow([
                        'id'     => 1, 'title' => 'Post 1', 'slug' => 'post-1', 'content_type' => 'post', 'author_id' => 1,
                        'status' => 'published', 'published_at' => (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s')
                    ], [], [], [])
                ]
            );

        $service = new NewsletterService(
            $this->mail,
            $this->campaigns,
            $this->subscribers,
            $this->coupons,
            $posts,
            $this->categories,
            $this->tags,
            $this->media,
            'http://localhost'
        );

        $triggeredFirst = $service->processRecurring();
        $this->assertEquals(0, $triggeredFirst);

        $campaign->setLastRecurringRunAt(null);

        $triggeredSecond = $service->processRecurring();
        $this->assertEquals(1, $triggeredSecond);
    }

    public function testIsRecurringDueZeroPosts(): void
    {
        $campaign = new Campaign('Static', 'Hello');
        $campaign->setId(1);
        $campaign->setRecurring(true);
        $campaign->setRecurringInterval('daily');
        $campaign->setRecurringTime('00:00');
        $campaign->setRecurringPostCount(0);
        $campaign->setCreatedAt(new \DateTimeImmutable('-1 month'));

        $this->campaigns->method('findActiveRecurring')->willReturn([$campaign]);
        $this->subscribers->method('findActive')->willReturn([]);

        $this->posts->method('findAll')->willReturn([]);

        $triggered = $this->service->processRecurring();
        $this->assertEquals(1, $triggered);
    }

    public function testLatestPostsShortcode(): void
    {
        $campaign = new Campaign('Test', '[latest_posts count="1"]');
        $campaign->setId(1);
        $subscriber = new Subscriber('test@example.com', 'token');
        $subscriber->setId(1);

        $post = Post::fromRow([
            'id'     => 1, 'title' => 'Post 1', 'slug' => 'post-1', 'content_type' => 'post', 'author_id' => 1,
            'status' => 'published', 'published_at' => '2023-01-01 09:30:00', 'excerpt' => 'Hello'
        ], [], [], []);

        $this->posts->method('findAll')->willReturn([$post]);
        $this->subscribers->method('findActive')->willReturn([$subscriber]);

        $mail = $this->createMock(MailInterface::class);
        $mail->expects($this->once())
            ->method('sendBatch')
            ->with($this->callback(function ($messages) {
                return isset($messages[0]) && str_contains($messages[0]->htmlBody, 'Post 1') && str_contains($messages[0]->htmlBody, 'Hello');
            }));

        $service = new NewsletterService(
            $mail,
            $this->campaigns,
            $this->subscribers,
            $this->coupons,
            $this->posts,
            $this->categories,
            $this->tags,
            $this->media,
            'http://localhost'
        );

        $service->sendCampaign($campaign);
    }
}
