<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Domain\Newsletter\Entity;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Newsletter\Entity\AutomationStep;

final class AutomationStepTest extends TestCase
{
    public function testConstructorDefaults(): void
    {
        $step = new AutomationStep(1, 0, 120, 'Welcome', '<p>Hi</p>');

        self::assertSame(1, $step->getSequenceId());
        self::assertSame(0, $step->getSortOrder());
        self::assertSame(120, $step->getDelayMinutes());
        self::assertSame('Welcome', $step->getSubject());
        self::assertSame('<p>Hi</p>', $step->getBodyHtml());
        self::assertNull($step->getId());
        self::assertFalse($step->isAbTestEnabled());
        self::assertSame(50, $step->getVariationBWeight());
        self::assertNull($step->getSubjectB());
        self::assertNull($step->getBodyHtmlB());
        self::assertSame(0, $step->getOpensCount());
        self::assertSame(0, $step->getClicksCount());
        self::assertSame(0, $step->getOpensCountB());
        self::assertSame(0, $step->getClicksCountB());
        self::assertNull($step->getCreatedAt());
    }

    public function testSetters(): void
    {
        $step = new AutomationStep(1, 0, 0, 'S', '<p>B</p>');

        $step->setId(5);
        $step->setSortOrder(3);
        $step->setDelayMinutes(240);
        $step->setAbTestEnabled(true);
        $step->setVariationBWeight(30);
        $step->setSubject('New Subject');
        $step->setSubjectB('Variant B');
        $step->setBodyHtml('<p>New</p>');
        $step->setBodyHtmlB('<p>B</p>');
        $step->setOpensCount(10);
        $step->setClicksCount(5);
        $step->setOpensCountB(8);
        $step->setClicksCountB(3);

        self::assertSame(5, $step->getId());
        self::assertSame(3, $step->getSortOrder());
        self::assertSame(240, $step->getDelayMinutes());
        self::assertTrue($step->isAbTestEnabled());
        self::assertSame(30, $step->getVariationBWeight());
        self::assertSame('New Subject', $step->getSubject());
        self::assertSame('Variant B', $step->getSubjectB());
        self::assertSame('<p>New</p>', $step->getBodyHtml());
        self::assertSame('<p>B</p>', $step->getBodyHtmlB());
        self::assertSame(10, $step->getOpensCount());
        self::assertSame(5, $step->getClicksCount());
        self::assertSame(8, $step->getOpensCountB());
        self::assertSame(3, $step->getClicksCountB());
    }

    public function testFilterSetters(): void
    {
        $step = new AutomationStep(1, 0, 0, 'S', '<p>B</p>');

        $step->setMinOrdersFilter(1);
        $step->setMaxOrdersFilter(10);
        $step->setMinOpenRateFilter(20);
        $step->setMaxOpenRateFilter(80);
        $step->setMinClickRateFilter(5);
        $step->setMaxClickRateFilter(50);
        $step->setMinTotalSpendFilter(100.0);
        $step->setMaxTotalSpendFilter(500.0);
        $step->setMinDaysSinceLastPurchaseFilter(30);
        $step->setMaxDaysSinceLastPurchaseFilter(365);

        self::assertSame(1, $step->getMinOrdersFilter());
        self::assertSame(10, $step->getMaxOrdersFilter());
        self::assertSame(20, $step->getMinOpenRateFilter());
        self::assertSame(80, $step->getMaxOpenRateFilter());
        self::assertSame(5, $step->getMinClickRateFilter());
        self::assertSame(50, $step->getMaxClickRateFilter());
        self::assertSame(100.0, $step->getMinTotalSpendFilter());
        self::assertSame(500.0, $step->getMaxTotalSpendFilter());
        self::assertSame(30, $step->getMinDaysSinceLastPurchaseFilter());
        self::assertSame(365, $step->getMaxDaysSinceLastPurchaseFilter());
    }

    public function testJsonSerialize(): void
    {
        $step = new AutomationStep(1, 0, 60, 'Subject', '<p>Body</p>');
        $step->setId(10);
        $step->setAbTestEnabled(true);

        $json = $step->jsonSerialize();

        self::assertSame(10, $json['id']);
        self::assertSame(1, $json['sequence_id']);
        self::assertSame(0, $json['sort_order']);
        self::assertSame(60, $json['delay_minutes']);
        self::assertTrue($json['ab_test_enabled']);
        self::assertSame('Subject', $json['subject']);
        self::assertSame('<p>Body</p>', $json['body_html']);
    }

    public function testFromRow(): void
    {
        $step = AutomationStep::fromRow([
            'id'                    => '7',
            'sequence_id'           => '2',
            'sort_order'            => '3',
            'delay_minutes'         => '180',
            'subject'               => 'Follow-up',
            'body_html'             => '<p>Hey</p>',
            'ab_test_enabled'       => '1',
            'variation_b_weight'    => '60',
            'subject_b'             => 'Alt Subject',
            'body_html_b'           => '<p>Alt</p>',
            'opens_count'           => '25',
            'clicks_count'          => '10',
            'opens_count_b'         => '20',
            'clicks_count_b'        => '8',
            'created_at'            => '2024-04-01 12:00:00',
        ]);

        self::assertSame(7, $step->getId());
        self::assertSame(2, $step->getSequenceId());
        self::assertSame(3, $step->getSortOrder());
        self::assertSame(180, $step->getDelayMinutes());
        self::assertSame('Follow-up', $step->getSubject());
        self::assertTrue($step->isAbTestEnabled());
        self::assertSame(60, $step->getVariationBWeight());
        self::assertSame('Alt Subject', $step->getSubjectB());
        self::assertSame('<p>Alt</p>', $step->getBodyHtmlB());
        self::assertSame(25, $step->getOpensCount());
        self::assertSame(10, $step->getClicksCount());
        self::assertInstanceOf(\DateTimeImmutable::class, $step->getCreatedAt());
    }

    public function testFromRowWithFilters(): void
    {
        $step = AutomationStep::fromRow([
            'id'                                  => '1',
            'sequence_id'                         => '1',
            'subject'                             => 'S',
            'body_html'                           => '<p>B</p>',
            'min_orders_filter'                   => '2',
            'max_orders_filter'                   => '50',
            'min_open_rate_filter'                => '10',
            'max_open_rate_filter'                => '90',
            'min_click_rate_filter'               => '5',
            'max_click_rate_filter'               => '40',
            'min_total_spend_filter'              => '25.50',
            'max_total_spend_filter'              => '999.99',
            'min_days_since_last_purchase_filter' => '7',
            'max_days_since_last_purchase_filter' => '180',
        ]);

        self::assertSame(2, $step->getMinOrdersFilter());
        self::assertSame(50, $step->getMaxOrdersFilter());
        self::assertSame(25.50, $step->getMinTotalSpendFilter());
        self::assertSame(999.99, $step->getMaxTotalSpendFilter());
        self::assertSame(7, $step->getMinDaysSinceLastPurchaseFilter());
        self::assertSame(180, $step->getMaxDaysSinceLastPurchaseFilter());
    }
}
