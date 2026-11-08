<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Controllers\Front;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use ValeApp\App\Controllers\Front\CheckoutController;
use ValeApp\Domain\Form\Repository\FormRepositoryInterface;
use ValeApp\Domain\Shop\Entity\Product;
use ValeApp\Domain\Shop\Repository\CountryTaxRateRepositoryInterface;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Translation\Translator;

final class CheckoutShippingDisabledTest extends TestCase
{
    public function testResolveCheckoutCountriesReturnsEmptyForPhysicalCartWhenShippingDisabled(): void
    {
        $settings = $this->createStub(DbalSettingsRepository::class);
        $settings->method('get')->willReturnCallback(
            static function (string $key, ?string $default = null): ?string {
                if ($key === 'shipping_enabled') {
                    return '0';
                }
                if ($key === 'shop_country') {
                    return 'US';
                }
                return $default;
            }
        );

        $physicalProduct = $this->createStub(Product::class);
        $physicalProduct->method('isDigital')->willReturn(false);

        $items = [
            ['product' => $physicalProduct, 'qty' => 1, 'price' => 10.0],
        ];

        $reflection = new \ReflectionClass(CheckoutController::class);
        $method     = $reflection->getMethod('resolveCheckoutCountries');

        $controller = $reflection->newInstanceWithoutConstructor();
        $prop       = $reflection->getProperty('settings');
        $prop->setValue($controller, $settings);

        $countries = $method->invoke($controller, $items, true);

        $this->assertSame([], $countries, 'For shipping, physical items cart must return no shippable countries when shipping_enabled is 0.');
    }

    public function testDigitalCartCanCheckoutWhenShippingDisabled(): void
    {
        $settings = $this->createStub(DbalSettingsRepository::class);
        $settings->method('get')->willReturnCallback(
            static function (string $key, ?string $default = null): ?string {
                if ($key === 'shipping_enabled') {
                    return '0';
                }
                if ($key === 'shop_country') {
                    return 'US';
                }
                return $default;
            }
        );

        $digitalProduct = $this->createStub(Product::class);
        $digitalProduct->method('isDigital')->willReturn(true);

        $items = [
            ['product' => $digitalProduct, 'qty' => 1, 'price' => 5.0],
        ];

        $reflection = new \ReflectionClass(CheckoutController::class);
        $method     = $reflection->getMethod('resolveCheckoutCountries');

        $controller = $reflection->newInstanceWithoutConstructor();
        $prop       = $reflection->getProperty('settings');
        $prop->setValue($controller, $settings);

        $countries = $method->invoke($controller, $items, true);

        $this->assertNotEmpty($countries, 'Digital products cart must return countries for checkout even when shipping_enabled is 0.');
    }

    public function testQuoteReturnsShippingErrorWhenShippingDisabledAndCartHasPhysicalItems(): void
    {
        $settings = $this->createStub(DbalSettingsRepository::class);
        $settings->method('get')->willReturnCallback(
            static function (string $key, ?string $default = null): ?string {
                if ($key === 'shipping_enabled') {
                    return '0';
                }
                if ($key === 'shop_country') {
                    return 'US';
                }
                return $default;
            }
        );

        $session = $this->createStub(SessionManager::class);
        $session->method('get')->willReturnCallback(
            static fn (string $key, mixed $default = null) => $key === 'cart' ? ['1' => 1] : $default
        );

        $physicalProduct = $this->createStub(Product::class);
        $physicalProduct->method('getId')->willReturn(1);
        $physicalProduct->method('getName')->willReturn('Physical Item');
        $physicalProduct->method('isDigital')->willReturn(false);
        $physicalProduct->method('getEffectivePrice')->willReturn(20.0);
        $physicalProduct->method('getWeight')->willReturn(1.0);

        $productsRepo = $this->createStub(\ValeApp\Domain\Shop\Repository\ProductRepositoryInterface::class);
        $productsRepo->method('findById')->willReturn($physicalProduct);

        $translator = $this->createStub(Translator::class);
        $translator->method('get')->willReturnCallback(static fn (string $key, array $params = []) => $key);

        $reflection = new \ReflectionClass(CheckoutController::class);
        $controller = $reflection->newInstanceWithoutConstructor();

        foreach ([
            'settings'         => $settings,
            'session'          => $session,
            'products'         => $productsRepo,
            'translator'       => $translator,
            'countryTaxRates'  => $this->createStub(\ValeApp\Domain\Shop\Repository\CountryTaxRateRepositoryInterface::class),
            'exchangeRates'    => null,
            'cachedFxRate'     => null,
            'deliveryEstimate' => null,
            'couponValidator'  => null,
            'abandonedCart'    => null,
        ] as $property => $value) {
            $prop = $reflection->getProperty($property);
            $prop->setValue($controller, $value);
        }

        $responseStream = $this->createMock(StreamInterface::class);
        $responseStream->expects($this->once())
            ->method('write')
            ->with($this->callback(function (string $json): bool {
                $data = json_decode($json, true);
                return is_array($data)
                    && isset($data['shipping_error'])
                    && $data['shipping_error'] === 'Shipping is currently unavailable.'
                    && isset($data['has_do_not_ship_items'])
                    && $data['has_do_not_ship_items'] === true;
            }));

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getBody')->willReturn($responseStream);
        $response->method('withHeader')->willReturnSelf();
        $response->method('withStatus')->willReturnSelf();

        $reqBodyStream = $this->createStub(StreamInterface::class);
        $reqBodyStream->method('__toString')->willReturn(json_encode([
            'country'          => 'US',
            'shipping_country' => 'US',
        ]));

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getBody')->willReturn($reqBodyStream);

        $controller->quote($request, $response);
    }

    public function testProcessValidatesShippingCountryWhenSameAsBillingIsFalse(): void
    {
        $settings = $this->createStub(DbalSettingsRepository::class);
        $settings->method('get')->willReturnCallback(
            static function (string $key, ?string $default = null): ?string {
                if ($key === 'shipping_enabled') {
                    return '1';
                }
                if ($key === 'shipping_countries') {
                    return 'US,CA';
                }
                if ($key === 'shop_country') {
                    return 'US';
                }
                return $default;
            }
        );

        $session = $this->createStub(SessionManager::class);
        $session->method('get')->willReturnCallback(
            static function (string $key, mixed $default = null): mixed {
                return $key === 'cart' ? ['1' => 1] : $default;
            }
        );

        $physicalProduct = $this->createStub(Product::class);
        $physicalProduct->method('getId')->willReturn(1);
        $physicalProduct->method('getName')->willReturn('Physical Item');
        $physicalProduct->method('isDigital')->willReturn(false);
        $physicalProduct->method('getEffectivePrice')->willReturn(20.0);
        $physicalProduct->method('getShippingClass')->willReturn('use_global');

        $productsRepo = $this->createStub(ProductRepositoryInterface::class);
        $productsRepo->method('findById')->willReturn($physicalProduct);

        $translator = $this->createStub(Translator::class);
        $translator->method('get')->willReturnCallback(
            static function (string $key, array $params = []): string {
                return $key;
            }
        );

        $twig = $this->createMock(\Slim\Views\Twig::class);
        $twig->expects($this->once())
            ->method('render')
            ->with(
                $this->anything(),
                'shop/checkout.twig',
                $this->callback(function (array $data): bool {
                    $message = $data['errors']['shipping_country'] ?? null;

                    return $message === 'We do not sell to your country you selected.';
                })
            )
            ->willReturnCallback(static fn ($res) => $res);

        $reflection = new \ReflectionClass(CheckoutController::class);
        $controller = $reflection->newInstanceWithoutConstructor();

        foreach (
            [
                'twig'             => $twig,
                'settings'         => $settings,
                'session'          => $session,
                'products'         => $productsRepo,
                'translator'       => $translator,
                'forms'            => $this->createStub(FormRepositoryInterface::class),
                'countryTaxRates'  => $this->createStub(CountryTaxRateRepositoryInterface::class),
                'exchangeRates'    => null,
                'cachedFxRate'     => null,
                'deliveryEstimate' => null,
                'abandonedCart'    => null,
            ] as $property => $value
        ) {
            $prop = $reflection->getProperty($property);
            $prop->setValue($controller, $value);
        }

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'first_name'       => 'John',
            'last_name'        => 'Doe',
            'email'            => 'john@example.com',
            'phone'            => '123456',
            'address'          => '123 Main St',
            'city'             => 'New York',
            'state'            => 'NY',
            'postcode'         => '10001',
            'country'          => 'US', // Valid billing country
            'same_as_billing'  => '0',
            'shipping_country' => 'XX', // Invalid/blocked shipping country
        ]);

        $response = $this->createStub(ResponseInterface::class);
        $response->method('withStatus')->willReturnSelf();

        $controller->process($request, $response);
    }
}
