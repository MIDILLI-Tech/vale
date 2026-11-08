<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Shop;

use PHPUnit\Framework\TestCase;
use ValeApp\Application\Shop\IdealoFeedService;
use ValeApp\Domain\Media\Repository\MediaRepositoryInterface;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\Shop\Entity\Product;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;

final class IdealoFeedServiceTest extends TestCase
{
    private function settingsStub(): SettingsRepositoryInterface
    {
        $settings = $this->createStub(SettingsRepositoryInterface::class);
        $settings->method('get')->willReturnCallback(
            static function (string $key, ?string $default = null): ?string {
                return match ($key) {
                    'payment_active_gateway' => 'stripe',
                    'shipping_time_min'      => '2',
                    'shipping_time_max'      => '4',
                    default                  => $default,
                };
            },
        );
        return $settings;
    }

    public function testGenerateCsvMatchesIdealoSpecColumns(): void
    {
        $p1 = new Product('Idealo Item 1', Slug::fromString('idealo-item-1'));
        $p1->setSku('IDL-001');
        $p1->setPrice(15.00);
        $p1->setStatus('published');
        $p1->setGmcExclude(false);
        $p1->setBrand('Acme');
        $p1->setGtin('4029011568541');
        $p1->setDescription('A short description');

        $p2 = new Product('Idealo Item Excluded', Slug::fromString('idealo-item-2'));
        $p2->setSku('IDL-002');
        $p2->setPrice(25.00);
        $p2->setStatus('published');
        $p2->setGmcExclude(true);

        $productRepo = $this->createStub(ProductRepositoryInterface::class);
        $productRepo->method('findAll')
            ->willReturn([$p1, $p2]);

        $mediaRepo = $this->createStub(MediaRepositoryInterface::class);

        $service = new IdealoFeedService(
            products: $productRepo,
            media: $mediaRepo,
            siteUrl: 'https://example.com',
            currency: 'EUR',
            settings: $this->settingsStub(),
        );

        $csv = $service->generateCsv();

        $this->assertStringContainsString(
            'sku,brand,title,categoryPath,url,eans,description,price,delivery,deliveryCosts_dhl,paymentCosts_credit_card,imageUrls,download',
            $csv,
        );
        $this->assertStringContainsString(
            'IDL-001,Acme,"Idealo Item 1",,https://example.com/shop/idealo-item-1,4029011568541,"A short description",15.00,"Delivered in 2-4 working days",,0.00,,',
            $csv,
        );
        $this->assertStringNotContainsString('IDL-002', $csv);
        $this->assertStringNotContainsString('Idealo Item Excluded', $csv);
    }

    public function testGenerateCsvExportsVariantsWithMatchingColumnCount(): void
    {
        $pVar = new Product('Idealo Shirt', Slug::fromString('idealo-shirt'));
        $pVar->setId(10);
        $pVar->setSku('SHIRT-PARENT');
        $pVar->setPrice(19.99);
        $pVar->setType('variable');
        $pVar->setStatus('published');
        $pVar->setGmcExclude(false);

        $productRepo = $this->createStub(ProductRepositoryInterface::class);
        $productRepo->method('findAll')
            ->willReturn([$pVar]);

        $lookedUp = [];
        $productRepo->method('findVariationsByProductId')->willReturnCallback(
            static function (int $productId) use (&$lookedUp): array {
                $lookedUp[] = $productId;
                return [
                    [
                        'id'           => 201,
                        'sku'          => 'SHIRT-RED-M',
                        'price'        => 21.99,
                        'stock_status' => 'in_stock',
                        'attributes'   => json_encode(['color' => 'Red', 'size' => 'M']),
                    ],
                ];
            },
        );

        $mediaRepo = $this->createStub(MediaRepositoryInterface::class);

        $service = new IdealoFeedService(
            products: $productRepo,
            media: $mediaRepo,
            siteUrl: 'https://example.com',
            currency: 'EUR',
            settings: $this->settingsStub(),
        );

        $csv = $service->generateCsv();

        self::assertSame([10], $lookedUp);
        $this->assertStringContainsString('SHIRT-RED-M', $csv);
        $this->assertStringContainsString('Idealo Shirt - Red / M', $csv);
        $this->assertStringNotContainsString('SHIRT-PARENT', $csv);

        $lines      = explode("\n", trim($csv));
        $headerCols = str_getcsv($lines[0], ',', '"', '\\');
        foreach (array_slice($lines, 1) as $line) {
            if ($line === '') {
                continue;
            }
            self::assertCount(count($headerCols), str_getcsv($line, ',', '"', '\\'));
        }
    }
}
