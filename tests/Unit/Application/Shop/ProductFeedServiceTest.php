<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Shop;

use PHPUnit\Framework\TestCase;
use ValeApp\Application\Shop\ProductFeedService;
use ValeApp\Domain\Media\Repository\MediaRepositoryInterface;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\Shop\Entity\Product;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;

final class ProductFeedServiceTest extends TestCase
{
    public function testGenerateXmlIncludesPublishedProductsAndExcludesGmcExcluded(): void
    {
        $p1 = new Product('Simple Widget', Slug::fromString('simple-widget'));
        $p1->setSku('SKU-001');
        $p1->setPrice(29.99);
        $p1->setStatus('published');
        $p1->setGmcExclude(false);

        $p2 = new Product('Excluded Widget', Slug::fromString('excluded-widget'));
        $p2->setSku('SKU-002');
        $p2->setPrice(49.99);
        $p2->setStatus('published');
        $p2->setGmcExclude(true);

        $productRepo = $this->createStub(ProductRepositoryInterface::class);
        $productRepo->method('findAll')
            ->willReturn([$p1, $p2]);

        $mediaRepo    = $this->createStub(MediaRepositoryInterface::class);
        $settingsRepo = $this->createStub(SettingsRepositoryInterface::class);

        $service = new ProductFeedService(
            products: $productRepo,
            media: $mediaRepo,
            siteUrl: 'https://example.com',
            currency: 'EUR',
            settings: $settingsRepo,
        );

        $xml = $service->generateXml();

        $this->assertStringContainsString('xmlns:g="http://base.google.com/ns/1.0"', $xml);
        $this->assertStringContainsString('<g:id>SKU-001</g:id>', $xml);
        $this->assertStringContainsString('Simple Widget', $xml);
        $this->assertStringContainsString('<g:identifier_exists>no</g:identifier_exists>', $xml);
        $this->assertStringNotContainsString('SKU-002', $xml);
        $this->assertStringNotContainsString('Excluded Widget', $xml);
    }

    public function testGenerateXmlOmitsIdentifierExistsWhenBrandPresent(): void
    {
        $p = new Product('Branded Widget', Slug::fromString('branded-widget'));
        $p->setSku('SKU-100');
        $p->setPrice(9.99);
        $p->setStatus('published');
        $p->setGmcExclude(false);
        $p->setBrand('Acme');

        $productRepo = $this->createStub(ProductRepositoryInterface::class);
        $productRepo->method('findAll')
            ->willReturn([$p]);

        $mediaRepo    = $this->createStub(MediaRepositoryInterface::class);
        $settingsRepo = $this->createStub(SettingsRepositoryInterface::class);

        $service = new ProductFeedService(
            products: $productRepo,
            media: $mediaRepo,
            siteUrl: 'https://example.com',
            currency: 'EUR',
            settings: $settingsRepo,
        );

        $xml = $service->generateXml();

        $this->assertStringContainsString('<g:brand>Acme</g:brand>', $xml);
        $this->assertStringNotContainsString('identifier_exists', $xml);
    }

    public function testGenerateXmlExportsVariantsForVariableProduct(): void
    {
        $pVar = new Product('Variable Shirt', Slug::fromString('variable-shirt'));
        $pVar->setId(10);
        $pVar->setSku('SHIRT-PARENT');
        $pVar->setPrice(19.99);
        $pVar->setType('variable');
        $pVar->setStatus('published');
        $pVar->setGmcExclude(false);

        $productRepo = $this->createStub(ProductRepositoryInterface::class);
        $productRepo->method('findAll')
            ->willReturn([$pVar]);

        $lookedUp   = [];
        $productRepo->method('findVariationsByProductId')->willReturnCallback(
            static function (int $productId) use (&$lookedUp): array {
                $lookedUp[] = $productId;
                return [
                    [
                        'id'           => 101,
                        'sku'          => 'SHIRT-RED-M',
                        'price'        => 21.99,
                        'stock_status' => 'in_stock',
                        'attributes'   => json_encode(['color' => 'Red', 'size' => 'M']),
                    ],
                ];
            },
        );

        $mediaRepo    = $this->createStub(MediaRepositoryInterface::class);
        $settingsRepo = $this->createStub(SettingsRepositoryInterface::class);

        $service = new ProductFeedService(
            products: $productRepo,
            media: $mediaRepo,
            siteUrl: 'https://example.com',
            currency: 'EUR',
            settings: $settingsRepo,
        );

        $xml = $service->generateXml();

        self::assertSame([10], $lookedUp);
        $this->assertStringContainsString('<g:id>SHIRT-RED-M</g:id>', $xml);
        $this->assertStringContainsString('<g:item_group_id>SHIRT-PARENT</g:item_group_id>', $xml);
        $this->assertStringContainsString('<g:color>Red</g:color>', $xml);
        $this->assertStringContainsString('<g:size>M</g:size>', $xml);
    }
}
