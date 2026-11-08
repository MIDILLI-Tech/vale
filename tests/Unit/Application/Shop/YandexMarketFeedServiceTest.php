<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Shop;

use PHPUnit\Framework\TestCase;
use ValeApp\Application\Shop\YandexMarketFeedService;
use ValeApp\Domain\Media\Repository\MediaRepositoryInterface;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\Shop\Entity\Product;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;

final class YandexMarketFeedServiceTest extends TestCase
{
    public function testGenerateYmlIncludesPublishedAndExcludesGmcExcluded(): void
    {
        $p1 = new Product('Yandex Item 1', Slug::fromString('yandex-item-1'));
        $p1->setSku('YND-001');
        $p1->setPrice(100.00);
        $p1->setStatus('published');
        $p1->setGmcExclude(false);

        $p2 = new Product('Yandex Item Excluded', Slug::fromString('yandex-item-2'));
        $p2->setSku('YND-002');
        $p2->setPrice(200.00);
        $p2->setStatus('published');
        $p2->setGmcExclude(true);

        $productRepo = $this->createStub(ProductRepositoryInterface::class);
        $productRepo->method('findAll')
            ->willReturn([$p1, $p2]);

        $mediaRepo    = $this->createStub(MediaRepositoryInterface::class);
        $settingsRepo = $this->createStub(SettingsRepositoryInterface::class);

        $service = new YandexMarketFeedService(
            products: $productRepo,
            media: $mediaRepo,
            siteUrl: 'https://example.com',
            shopName: 'Test Shop',
            currency: 'EUR',
            settings: $settingsRepo,
        );

        $yml = $service->generateYml();

        $this->assertStringContainsString('<yml_catalog', $yml);
        $this->assertStringContainsString('YND-001', $yml);
        $this->assertStringContainsString('Yandex Item 1', $yml);
        $this->assertStringContainsString('<delivery>true</delivery>', $yml);
        $this->assertStringNotContainsString('YND-002', $yml);
        $this->assertStringNotContainsString('Yandex Item Excluded', $yml);
    }
}
