<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Domain\Newsletter\Entity;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Newsletter\Entity\Campaign;

final class CampaignTest extends TestCase
{
    public function testConstructorDefaults(): void
    {
        $c = new Campaign('Subject', '<p>Body</p>');

        self::assertSame('Subject', $c->getSubject());
        self::assertSame('<p>Body</p>', $c->getBodyHtml());
        self::assertNull($c->getSubjectB());
        self::assertNull($c->getBodyHtmlB());
        self::assertNull($c->getFromName());
        self::assertNull($c->getFromEmail());
        self::assertSame('draft', $c->getStatus());
        self::assertTrue($c->isDraft());
        self::assertFalse($c->isSent());
        self::assertFalse($c->isScheduled());
        self::assertFalse($c->isAbTestEnabled());
        self::assertSame(50, $c->getVariationBWeight());
        self::assertSame(0, $c->getOpensCount());
        self::assertSame(0, $c->getClicksCount());
        self::assertSame(Campaign::TRACKING_ALL, $c->getTrackingEnabled());
        self::assertFalse($c->isRecurring());
    }

    public function testSetters(): void
    {
        $c = new Campaign('S', '<p>B</p>');

        $c->setSubject('New');
        $c->setBodyHtml('<p>New</p>');
        $c->setSubjectB('Alt');
        $c->setBodyHtmlB('<p>Alt</p>');
        $c->setFromName('Vale');
        $c->setFromEmail('hi@vale.dev');
        $c->setStatus('sending');
        $c->setAbTestEnabled(true);
        $c->setVariationBWeight(70);
        $c->setTagFilter('vip');
        $c->setSourceFormFilter(5);
        $c->setMinOrdersFilter(1);
        $c->setMaxOrdersFilter(100);
        $c->setMinOpenRateFilter(10);
        $c->setMaxOpenRateFilter(90);
        $c->setMinClickRateFilter(5);
        $c->setMaxClickRateFilter(50);
        $c->setOpensCount(200);
        $c->setOpensCountB(150);
        $c->setClicksCount(50);
        $c->setClicksCountB(30);
        $c->setTrackingEnabled(Campaign::TRACKING_OPEN);
        $c->setRecurring(true);
        $c->setRecurringInterval('weekly');
        $c->setRecurringDay('monday');
        $c->setRecurringTime('09:00');
        $c->setRecurringPostCount(5);
        $c->setRecurringPostTypes('post');
        $c->setRecurringCategoryId(3);
        $c->setRecurringTagId(7);
        $c->setRecurringLanguage('tr');

        self::assertSame('New', $c->getSubject());
        self::assertSame('<p>New</p>', $c->getBodyHtml());
        self::assertSame('Alt', $c->getSubjectB());
        self::assertSame('Vale', $c->getFromName());
        self::assertSame('hi@vale.dev', $c->getFromEmail());
        self::assertSame('sending', $c->getStatus());
        self::assertTrue($c->isAbTestEnabled());
        self::assertSame(70, $c->getVariationBWeight());
        self::assertSame('vip', $c->getTagFilter());
        self::assertSame(5, $c->getSourceFormFilter());
        self::assertSame(1, $c->getMinOrdersFilter());
        self::assertSame(100, $c->getMaxOrdersFilter());
        self::assertSame(200, $c->getOpensCount());
        self::assertSame(50, $c->getClicksCount());
        self::assertTrue($c->isRecurring());
        self::assertSame('weekly', $c->getRecurringInterval());
        self::assertSame('monday', $c->getRecurringDay());
        self::assertSame('09:00', $c->getRecurringTime());
        self::assertSame(5, $c->getRecurringPostCount());
        self::assertSame('post', $c->getRecurringPostTypes());
        self::assertSame(3, $c->getRecurringCategoryId());
        self::assertSame(7, $c->getRecurringTagId());
        self::assertSame('tr', $c->getRecurringLanguage());
    }

    public function testMarkSent(): void
    {
        $c = new Campaign('S', '<p>B</p>');
        $c->markSent();

        self::assertSame('sent', $c->getStatus());
        self::assertTrue($c->isSent());
        self::assertInstanceOf(\DateTimeImmutable::class, $c->getSentAt());
    }

    public function testTrackingBitmask(): void
    {
        $c = new Campaign('S', '<p>B</p>');

        $c->setTrackingEnabled(Campaign::TRACKING_NONE);
        self::assertFalse($c->hasOpenTracking());
        self::assertFalse($c->hasClickTracking());

        $c->setTrackingEnabled(Campaign::TRACKING_CLICK);
        self::assertFalse($c->hasOpenTracking());
        self::assertTrue($c->hasClickTracking());
    }

    public function testFromRow(): void
    {
        $c = Campaign::fromRow([
            'id'                    => '5',
            'subject'               => 'Summer Sale',
            'body_html'             => '<p>Sale!</p>',
            'subject_b'             => 'Alt Subject',
            'body_html_b'           => '<p>Alt</p>',
            'from_name'             => 'Vale',
            'from_email'            => 'sale@vale.dev',
            'status'                => 'sent',
            'ab_test_enabled'       => '1',
            'variation_b_weight'    => '60',
            'tag_filter'            => 'vip',
            'source_form_filter'    => '3',
            'min_orders_filter'     => '2',
            'max_orders_filter'     => '50',
            'min_open_rate_filter'  => '10',
            'max_open_rate_filter'  => '80',
            'min_click_rate_filter' => '5',
            'max_click_rate_filter' => '40',
            'opens_count'           => '300',
            'opens_count_b'         => '200',
            'clicks_count'          => '75',
            'clicks_count_b'        => '50',
            'recipients_count'      => '1000',
            'recipients_count_a'    => '500',
            'recipients_count_b'    => '500',
            'tracking_enabled'      => '2',
            'is_recurring'          => '1',
            'recurring_interval'    => 'monthly',
            'recurring_day'         => '1',
            'recurring_time'        => '10:00',
            'recurring_post_count'  => '3',
            'recurring_post_types'  => 'post',
            'recurring_category_id' => '4',
            'recurring_tag_id'      => '8',
            'recurring_language'    => 'en',
            'last_recurring_run_at' => '2024-07-01 10:00:00',
            'scheduled_at'          => '2024-08-01 09:00:00',
            'sent_at'               => '2024-07-15 12:00:00',
            'created_at'            => '2024-06-01 08:00:00',
            'updated_at'            => '2024-07-15 13:00:00',
        ]);

        self::assertSame(5, $c->getId());
        self::assertSame('Summer Sale', $c->getSubject());
        self::assertSame('sent', $c->getStatus());
        self::assertTrue($c->isAbTestEnabled());
        self::assertSame(60, $c->getVariationBWeight());
        self::assertSame('vip', $c->getTagFilter());
        self::assertSame(300, $c->getOpensCount());
        self::assertSame(75, $c->getClicksCount());
        self::assertSame(1000, $c->getRecipientsCount());
        self::assertSame(500, $c->getRecipientsCountA());
        self::assertSame(500, $c->getRecipientsCountB());
        self::assertTrue($c->isRecurring());
        self::assertSame('monthly', $c->getRecurringInterval());
        self::assertSame(3, $c->getRecurringPostCount());
        self::assertInstanceOf(\DateTimeImmutable::class, $c->getScheduledAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $c->getSentAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $c->getCreatedAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $c->getLastRecurringRunAt());
    }

    public function testConstants(): void
    {
        self::assertSame(0, Campaign::TRACKING_NONE);
        self::assertSame(1, Campaign::TRACKING_OPEN);
        self::assertSame(2, Campaign::TRACKING_CLICK);
        self::assertSame(3, Campaign::TRACKING_ALL);
    }
}
