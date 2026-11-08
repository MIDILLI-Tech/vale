<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Domain\Seo\Entity;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Seo\Entity\SeoMeta;

final class SeoMetaTest extends TestCase
{
    public function testConstructorDefaults(): void
    {
        $seo = new SeoMeta('post', 5);

        self::assertSame('post', $seo->getEntityType());
        self::assertSame(5, $seo->getEntityId());
        self::assertNull($seo->getMetaTitle());
        self::assertNull($seo->getMetaDescription());
        self::assertNull($seo->getOgTitle());
        self::assertNull($seo->getOgDescription());
        self::assertNull($seo->getOgImageId());
        self::assertSame('summary', $seo->getTwitterCardType());
        self::assertNull($seo->getCanonicalUrl());
        self::assertSame('index,follow', $seo->getRobots());
        self::assertFalse($seo->isSitemapExcluded());
        self::assertNull($seo->getFocusKeyword());
    }

    public function testSetters(): void
    {
        $seo = new SeoMeta('post', 1);

        $seo->setMetaTitle('My Title');
        $seo->setMetaDescription('My Desc');
        $seo->setOgTitle('OG Title');
        $seo->setOgDescription('OG Desc');
        $seo->setOgImageId(10);
        $seo->setTwitterCardType('summary_large_image');
        $seo->setCanonicalUrl('https://example.com/canonical');
        $seo->setRobots('noindex,nofollow');
        $seo->setSitemapExcluded(true);
        $seo->setFocusKeyword('php testing');

        self::assertSame('My Title', $seo->getMetaTitle());
        self::assertSame('My Desc', $seo->getMetaDescription());
        self::assertSame('OG Title', $seo->getOgTitle());
        self::assertSame('OG Desc', $seo->getOgDescription());
        self::assertSame(10, $seo->getOgImageId());
        self::assertSame('summary_large_image', $seo->getTwitterCardType());
        self::assertSame('https://example.com/canonical', $seo->getCanonicalUrl());
        self::assertSame('noindex,nofollow', $seo->getRobots());
        self::assertTrue($seo->isSitemapExcluded());
        self::assertSame('php testing', $seo->getFocusKeyword());
    }

    public function testEffectiveOgTitleFallsBackToMetaTitle(): void
    {
        $seo = new SeoMeta('post', 1);
        self::assertNull($seo->getEffectiveOgTitle());

        $seo->setMetaTitle('Fallback Title');
        self::assertSame('Fallback Title', $seo->getEffectiveOgTitle());

        $seo->setOgTitle('OG Title');
        self::assertSame('OG Title', $seo->getEffectiveOgTitle());
    }

    public function testEffectiveOgDescriptionFallsBackToMetaDescription(): void
    {
        $seo = new SeoMeta('post', 1);
        self::assertNull($seo->getEffectiveOgDescription());

        $seo->setMetaDescription('Fallback Desc');
        self::assertSame('Fallback Desc', $seo->getEffectiveOgDescription());

        $seo->setOgDescription('OG Desc');
        self::assertSame('OG Desc', $seo->getEffectiveOgDescription());
    }

    public function testLlmDescriptionSharesMetaDescription(): void
    {
        $seo = new SeoMeta('post', 1);
        $seo->setMetaDescription('LLM content');
        self::assertSame('LLM content', $seo->getLlmDescription());
    }

    public function testFromRow(): void
    {
        $seo = SeoMeta::fromRow([
            'id'                => '10',
            'entity_type'       => 'page',
            'entity_id'         => '3',
            'meta_title'        => 'SEO Title',
            'meta_description'  => 'SEO Desc',
            'og_title'          => 'OG Title',
            'og_description'    => 'OG Desc',
            'og_image_id'       => '7',
            'twitter_card_type' => 'summary_large_image',
            'canonical_url'     => 'https://example.com',
            'robots'            => 'noindex',
            'sitemap_exclude'   => 1,
            'focus_keyword'     => 'test',
            'created_at'        => '2024-01-01 00:00:00',
            'updated_at'        => '2024-06-01 00:00:00',
        ]);

        self::assertSame(10, $seo->getId());
        self::assertSame('page', $seo->getEntityType());
        self::assertSame(3, $seo->getEntityId());
        self::assertSame('SEO Title', $seo->getMetaTitle());
        self::assertSame('SEO Desc', $seo->getMetaDescription());
        self::assertSame('OG Title', $seo->getOgTitle());
        self::assertSame('OG Desc', $seo->getOgDescription());
        self::assertSame(7, $seo->getOgImageId());
        self::assertSame('summary_large_image', $seo->getTwitterCardType());
        self::assertSame('https://example.com', $seo->getCanonicalUrl());
        self::assertSame('noindex', $seo->getRobots());
        self::assertTrue($seo->isSitemapExcluded());
        self::assertSame('test', $seo->getFocusKeyword());
        self::assertInstanceOf(\DateTimeImmutable::class, $seo->getCreatedAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $seo->getUpdatedAt());
    }

    public function testFromRowDefaults(): void
    {
        $seo = SeoMeta::fromRow([
            'id'           => '1',
            'entity_type'  => 'post',
            'entity_id'    => '1',
        ]);

        self::assertNull($seo->getMetaTitle());
        self::assertNull($seo->getCanonicalUrl());
        self::assertSame('summary', $seo->getTwitterCardType());
        self::assertSame('index,follow', $seo->getRobots());
        self::assertFalse($seo->isSitemapExcluded());
    }

    public function testFromRowWithoutSitemapColumnDerivesExclusionFromRobots(): void
    {
        $noindexed = SeoMeta::fromRow([
            'id'          => '1',
            'entity_type' => 'post',
            'entity_id'   => '1',
            'robots'      => 'noindex,follow',
        ]);
        self::assertTrue($noindexed->isSitemapExcluded());

        $indexed = SeoMeta::fromRow([
            'id'          => '2',
            'entity_type' => 'post',
            'entity_id'   => '2',
            'robots'      => 'index,follow',
        ]);
        self::assertFalse($indexed->isSitemapExcluded());
    }
}
