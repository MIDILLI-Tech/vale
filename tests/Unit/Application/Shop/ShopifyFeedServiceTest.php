<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Shop;

use PHPUnit\Framework\TestCase;
use ValeApp\Application\Shop\ShopifyFeedService;
use ValeApp\Domain\Media\Entity\Media;
use ValeApp\Domain\Media\Repository\MediaRepositoryInterface;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\Shop\Entity\Product;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;

final class ShopifyFeedServiceTest extends TestCase
{
    public function testGenerateRssIncludesPublishedAndExcludesGmcExcluded(): void
    {
        $p1 = new Product('Shopify Item 1', Slug::fromString('shopify-item-1'));
        $p1->setSku('SPF-001');
        $p1->setPrice(10.00);
        $p1->setStatus('published');
        $p1->setGmcExclude(false);
        $p1->setFeaturedImageId(5);

        $p2 = new Product('Shopify Item Excluded', Slug::fromString('shopify-item-2'));
        $p2->setSku('SPF-002');
        $p2->setPrice(20.00);
        $p2->setStatus('published');
        $p2->setGmcExclude(true);

        $productRepo = $this->createStub(ProductRepositoryInterface::class);
        $productRepo->method('findAll')
            ->willReturn([$p1, $p2]);

        $mediaRepo    = $this->createStub(MediaRepositoryInterface::class);
        $mediaRepo->method('findById')->willReturn(Media::fromRow([
            'id'            => 5,
            'user_id'       => 1,
            'filename'      => 'item1.jpg',
            'original_name' => 'item1.jpg',
            'mime_type'     => 'image/jpeg',
            'size'          => 12345,
            'path'          => '2026/10/item1.jpg',
        ]));
        $settingsRepo = $this->createStub(SettingsRepositoryInterface::class);

        $service = new ShopifyFeedService(
            products: $productRepo,
            media: $mediaRepo,
            siteUrl: 'https://example.com',
            shopName: 'Test Shop',
            settings: $settingsRepo,
        );

        $rss = $service->generateRss();

        $this->assertStringContainsString('<title>Shopify Item 1</title>', $rss);
        $this->assertStringContainsString(
            '<enclosure url="https://example.com/uploads/2026/10/item1.jpg" length="12345" type="image/jpeg"/>',
            $rss,
        );
        $this->assertStringNotContainsString('image_link', $rss);
        $this->assertStringNotContainsString('Shopify Item Excluded', $rss);
    }
}
