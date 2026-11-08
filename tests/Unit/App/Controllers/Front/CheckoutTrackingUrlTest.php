<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Controllers\Front;

use PHPUnit\Framework\TestCase;
use ValeApp\App\Controllers\Front\CheckoutController;
use ValeApp\Domain\Shop\Entity\Order;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;

final class CheckoutTrackingUrlTest extends TestCase
{
    public function testBuildTrackingUrlReturnsEmptyForNullOrderOrMissingData(): void
    {
        $reflection = new \ReflectionClass(CheckoutController::class);
        $method     = $reflection->getMethod('buildTrackingUrl');

        $controller = $reflection->newInstanceWithoutConstructor();

        $this->assertSame('', $method->invoke($controller, null));

        $order = $this->createStub(Order::class);
        $order->method('getCarrier')->willReturn(null);
        $order->method('getTrackingNumber')->willReturn(null);

        $this->assertSame('', $method->invoke($controller, $order));
    }

    public function testBuildTrackingUrlReturnsTrackingNumberForOtherCarrier(): void
    {
        $reflection = new \ReflectionClass(CheckoutController::class);
        $method     = $reflection->getMethod('buildTrackingUrl');

        $controller = $reflection->newInstanceWithoutConstructor();

        $order = $this->createStub(Order::class);
        $order->method('getCarrier')->willReturn('other');
        $order->method('getTrackingNumber')->willReturn('https://custom-tracking.example.com/123456');

        $this->assertSame('https://custom-tracking.example.com/123456', $method->invoke($controller, $order));
    }

    public function testBuildTrackingUrlUsesReferenceDataCarriers(): void
    {
        $settings = $this->createStub(DbalSettingsRepository::class);
        $settings->method('get')->willReturn('[]');

        $reflection = new \ReflectionClass(CheckoutController::class);
        $method     = $reflection->getMethod('buildTrackingUrl');

        $controller = $reflection->newInstanceWithoutConstructor();
        $prop       = $reflection->getProperty('settings');
        $prop->setValue($controller, $settings);

        $testCases = [
            'araskargo'    => ['123456', 'https://www.araskargo.com.tr/tr/takip?trackingNumber=123456'],
            'yurticikargo' => ['789012', 'https://www.yurticikargo.com/tr/online-servisler/gonderi-sorgula?code=789012'],
            'usps'         => ['94001000', 'https://tools.usps.com/go/TrackConfirmAction?tLabels=94001000'],
            'ups'          => ['1Z999999', 'https://www.ups.com/track?tracknum=1Z999999'],
            'dhl'          => ['555123', 'https://www.dhl.com/de-de/home/sendungsverfolgung.html?tracking-id=555123&submit=1'],
            'ptt'          => ['KP12345', 'https://gonderitakip.ptt.gov.tr/Track?key=KP12345'],
        ];

        foreach ($testCases as $carrier => [$trackNum, $expectedUrl]) {
            $order = $this->createStub(Order::class);
            $order->method('getCarrier')->willReturn($carrier);
            $order->method('getTrackingNumber')->willReturn($trackNum);

            $this->assertSame($expectedUrl, $method->invoke($controller, $order), "Failed for carrier: {$carrier}");
        }
    }

    public function testBuildTrackingUrlSupportsCustomCarriersFromSettings(): void
    {
        $settings = $this->createStub(DbalSettingsRepository::class);
        $settings->method('get')->willReturnCallback(
            static fn (string $key, ?string $default = null) => $key === 'custom_shipping_carriers'
                ? json_encode([['value' => 'mycustom', 'label' => 'My Custom Cargo', 'url_template' => 'https://mycustomcargo.com/track/{tracking_number}']])
                : $default
        );

        $reflection = new \ReflectionClass(CheckoutController::class);
        $method     = $reflection->getMethod('buildTrackingUrl');

        $controller = $reflection->newInstanceWithoutConstructor();
        $prop       = $reflection->getProperty('settings');
        $prop->setValue($controller, $settings);

        $order = $this->createStub(Order::class);
        $order->method('getCarrier')->willReturn('mycustom');
        $order->method('getTrackingNumber')->willReturn('CUST9876');

        $this->assertSame('https://mycustomcargo.com/track/CUST9876', $method->invoke($controller, $order));
    }
}
