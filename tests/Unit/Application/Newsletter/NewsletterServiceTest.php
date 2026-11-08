<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Newsletter;

use PHPUnit\Framework\TestCase;
use ValeApp\Application\Newsletter\NewsletterService;
use ValeApp\Domain\Content\Repository\CategoryRepositoryInterface;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Content\Repository\TagRepositoryInterface;
use ValeApp\Domain\Mail\Port\MailInterface;
use ValeApp\Domain\Media\Repository\MediaRepositoryInterface;
use ValeApp\Domain\Newsletter\Entity\Campaign;
use ValeApp\Domain\Newsletter\Entity\Subscriber;
use ValeApp\Domain\Newsletter\Repository\CampaignRepositoryInterface;
use ValeApp\Domain\Newsletter\Repository\SubscriberRepositoryInterface;
use ValeApp\Domain\Shop\Repository\CouponRepositoryInterface;

class NewsletterServiceTest extends TestCase
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

        $this->service = new NewsletterService(
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

    public function testSendCampaignAppliesFilters(): void
    {
        $campaign = new Campaign('Subject', 'Body');
        $campaign->setTagFilter('test-tag');
        $campaign->setSourceFormFilter(123);
        $campaign->setMinOrdersFilter(5);
        $campaign->setMaxOrdersFilter(10);
        $campaign->setMinOpenRateFilter(20);
        $campaign->setMaxOpenRateFilter(80);
        $campaign->setMinClickRateFilter(2);
        $campaign->setMaxClickRateFilter(10);

        $subscribers = $this->createMock(SubscriberRepositoryInterface::class);
        $subscribers->expects($this->once())
            ->method('findActive')
            ->with('test-tag', 123, 5, 10, 20, 80, 2, 10)
            ->willReturn([]);

        $service = new NewsletterService(
            $this->mail,
            $this->campaigns,
            $subscribers,
            $this->coupons,
            $this->posts,
            $this->categories,
            $this->tags,
            $this->media,
            'http://localhost'
        );

        $service->sendCampaign($campaign);
    }

    public function testSendCampaignUsesVariationB(): void
    {
        $campaign = new Campaign('Subject A', 'Body A');
        $campaign->setId(1);
        $campaign->setSubjectB('Subject B');
        $campaign->setBodyHtmlB('Body B');
        $campaign->setAbTestEnabled(true);
        $campaign->setVariationBWeight(100); // Force B

        $subscriber = new Subscriber('test@example.com', 'token');
        $subscriber->setId(1);

        $this->subscribers->method('findActive')->willReturn([$subscriber]);

        $campaigns = $this->createMock(CampaignRepositoryInterface::class);
        $campaigns->expects($this->once())
            ->method('addRecipient')
            ->with($this->anything(), $this->anything(), 'B')
            ->willReturn('tracking-token');

        $mail = $this->createMock(MailInterface::class);
        $mail->expects($this->once())
            ->method('sendBatch')
            ->with($this->callback(fn ($messages) => isset($messages[0]) && $messages[0]->subject === 'Subject B'));

        $service = new NewsletterService(
            $mail,
            $campaigns,
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
