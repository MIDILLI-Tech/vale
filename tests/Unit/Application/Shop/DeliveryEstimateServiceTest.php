<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Shop;

use PHPUnit\Framework\TestCase;
use ValeApp\Application\Shop\DeliveryEstimateService;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\Shop\Entity\Product;

final class DeliveryEstimateServiceTest extends TestCase
{
    private function makeSettings(array $values = []): SettingsRepositoryInterface
    {
        $settings = $this->createStub(SettingsRepositoryInterface::class);
        $settings->method('get')->willReturnCallback(
            static fn (string $key, ?string $default = null): ?string => $values[$key] ?? $default
        );
        return $settings;
    }

    private function makeProduct(?int $min = null, ?int $max = null, array $overrides = []): Product
    {
        $product = new Product('Test', new Slug('test'));
        $product->setShippingTimeMin($min);
        $product->setShippingTimeMax($max);
        $product->setShippingCountryOverrides($overrides);
        return $product;
    }

    public function testGlobalDefaultOnly(): void
    {
        $service = new DeliveryEstimateService($this->makeSettings([
            'shipping_time_min' => '4',
            'shipping_time_max' => '7',
        ]));

        $this->assertSame(['min' => 4, 'max' => 7], $service->estimateForProduct());
    }

    public function testCountryRateOverridesGlobal(): void
    {
        $service = new DeliveryEstimateService($this->makeSettings([
            'shipping_time_min'      => '4',
            'shipping_time_max'      => '7',
            'shipping_country_rates' => json_encode([
                'DE' => ['shipping_time_min' => 2, 'shipping_time_max' => 3],
            ]),
        ]));

        $this->assertSame(['min' => 2, 'max' => 3], $service->estimateForProduct(null, null, 'de'));
        $this->assertSame(['min' => 4, 'max' => 7], $service->estimateForProduct(null, null, 'FR'));
    }

    public function testProductBaseOverridesCountryRate(): void
    {
        $service = new DeliveryEstimateService($this->makeSettings([
            'shipping_country_rates' => json_encode(['DE' => ['shipping_time_min' => 2, 'shipping_time_max' => 3]]),
        ]));
        $product = $this->makeProduct(10, 12);

        $this->assertSame(['min' => 10, 'max' => 12], $service->estimateForProduct($product, null, 'DE'));
    }

    public function testProductCountryOverrideWinsForThatCountryOnly(): void
    {
        $service = new DeliveryEstimateService($this->makeSettings([
            'shipping_time_min' => '4',
            'shipping_time_max' => '7',
        ]));
        $product = $this->makeProduct(8, 9, [
            'US' => ['class' => 'custom', 'cost' => 3.5, 'shipping_time_min' => 5, 'shipping_time_max' => 6],
        ]);

        $this->assertSame(['min' => 5, 'max' => 6], $service->estimateForProduct($product, null, 'us'));
        $this->assertSame(['min' => 8, 'max' => 9], $service->estimateForProduct($product, null, 'DE'));
        $this->assertSame(['min' => 8, 'max' => 9], $service->estimateForProduct($product));
    }

    public function testVariationBaseWinsOverProduct(): void
    {
        $service = new DeliveryEstimateService($this->makeSettings([
            'shipping_time_min' => '4',
            'shipping_time_max' => '7',
        ]));
        $product    = $this->makeProduct(8, 9);
        $variation  = ['shipping_time_min' => 1, 'shipping_time_max' => 2, 'shipping_country_overrides' => '{}'];

        $this->assertSame(['min' => 1, 'max' => 2], $service->estimateForProduct($product, $variation, 'DE'));
    }

    public function testCustomTshirtVariationEstimate(): void
    {
        $service   = new DeliveryEstimateService($this->makeSettings());
        $product   = $this->makeProduct(2, 3);
        $variation = [
            'shipping_time_min' => 4,
            'shipping_time_max' => 10,
        ];

        $this->assertSame(['min' => 4, 'max' => 10], $service->estimateForProduct($product, $variation));
    }

    public function testVariationCountryOverrideWinsOverVariationBase(): void
    {
        $service   = new DeliveryEstimateService($this->makeSettings());
        $product   = $this->makeProduct(8, 9);
        $variation = [
            'shipping_time_min'          => 1,
            'shipping_time_max'          => 2,
            'shipping_country_overrides' => json_encode([
                'TR' => ['class' => 'custom', 'cost' => 5.0, 'shipping_time_min' => 20, 'shipping_time_max' => 25],
            ]),
        ];

        $this->assertSame(['min' => 20, 'max' => 25], $service->estimateForProduct($product, $variation, 'TR'));
        $this->assertSame(['min' => 1, 'max' => 2], $service->estimateForProduct($product, $variation, 'DE'));
    }

    public function testVariationCountryOverridesMayBePreDecodedArray(): void
    {
        $service   = new DeliveryEstimateService($this->makeSettings());
        $variation = [
            'shipping_country_overrides' => [
                'IT' => ['class' => 'custom', 'cost' => 4.0, 'shipping_time_min' => 3, 'shipping_time_max' => 4],
            ],
        ];

        $this->assertSame(['min' => 3, 'max' => 4], $service->estimateForProduct(null, $variation, 'it'));
    }

    public function testMinMaxNormalizedWhenOnlyOneSidePresent(): void
    {
        $service = new DeliveryEstimateService($this->makeSettings([
            'shipping_time_min' => '5',
        ]));

        $this->assertSame(['min' => 5, 'max' => 5], $service->estimateForProduct());
    }

    public function testOrderEstimateSkipsDigitalAndAggregatesRange(): void
    {
        $service = new DeliveryEstimateService($this->makeSettings([
            'shipping_time_min' => '4',
            'shipping_time_max' => '7',
        ]));
        $digital  = $this->makeProduct(1, 1);
        $digital->setShippingClass('digital');
        $fast  = new Product('Fast', new Slug('fast'));
        $fast->setShippingTimeMin(2);
        $fast->setShippingTimeMax(3);
        $slow  = new Product('Slow', new Slug('slow'));
        $slow->setShippingTimeMin(6);
        $slow->setShippingTimeMax(14);

        $items = [
            ['product' => $digital],
            ['product' => $fast],
            ['product' => $slow],
        ];

        $this->assertSame(['min' => 2, 'max' => 14], $service->estimateForOrder($items, 'DE'));
    }
}
