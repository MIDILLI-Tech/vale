<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Shop;

use PHPUnit\Framework\TestCase;
use ValeApp\Application\Shop\BankTransferDetailsService;
use ValeApp\Domain\Shop\Entity\Order;

final class BankTransferDetailsServiceTest extends TestCase
{
    private function makeSettings(array $values): \ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface
    {
        $settings = $this->createStub(\ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface::class);
        $settings->method('get')->willReturnCallback(
            static fn (string $key, ?string $default = null): ?string => $values[$key] ?? $default
        );
        return $settings;
    }

    public function testIsBankTransferMethodAcceptsBothSlugs(): void
    {
        self::assertTrue(BankTransferDetailsService::isBankTransferMethod('banktransfer'));
        self::assertTrue(BankTransferDetailsService::isBankTransferMethod('bank_transfer'));
        self::assertFalse(BankTransferDetailsService::isBankTransferMethod('stripe'));
        self::assertFalse(BankTransferDetailsService::isBankTransferMethod('cash'));
        self::assertFalse(BankTransferDetailsService::isBankTransferMethod(null));
        self::assertFalse(BankTransferDetailsService::isBankTransferMethod(''));
    }

    private function makeOrder(): Order
    {
        $order = new Order('ORD-BT-1', ['email' => 'customer@example.com']);
        $order->setId(42);
        $order->setTotal(123.45);
        return $order;
    }

    public function testForOrderReplacesPlaceholdersAndReturnsTemplateKeys(): void
    {
        $service  = new BankTransferDetailsService($this->makeSettings([
            'banktransfer_label'       => 'Havale / EFT',
            'banktransfer_bank_name'   => 'Test Bank',
            'banktransfer_iban'        => 'TR00 0000 0000 0000',
            'banktransfer_holder_name' => 'Test Holder',
            'banktransfer_swift'       => 'TESTTRIS',
            'banktransfer_routing'     => '123456789',
            'banktransfer_branch'      => 'Kadikoy',
            'banktransfer_description' => 'Order {order_number} ({order_id}) for {order_total}',
            'banktransfer_max_days'    => '7',
        ]));

        $details = $service->forOrder($this->makeOrder());

        self::assertSame('Havale / EFT', $details['banktransfer_label']);
        self::assertSame('Test Bank', $details['banktransfer_bank']);
        self::assertSame('TR00 0000 0000 0000', $details['banktransfer_iban']);
        self::assertSame('Test Holder', $details['banktransfer_holder']);
        self::assertSame('TESTTRIS', $details['banktransfer_swift']);
        self::assertSame('123456789', $details['banktransfer_routing']);
        self::assertSame('Kadikoy', $details['banktransfer_branch']);
        self::assertSame('Order ORD-BT-1 (42) for 123.45', $details['banktransfer_desc']);
        self::assertSame(7, $details['banktransfer_max_days']);

        self::assertTrue(BankTransferDetailsService::isConfigured($details));
    }

    public function testForOrderWithMissingSettingsFallsBackToDefaults(): void
    {
        $service = new BankTransferDetailsService($this->makeSettings([]));

        $details = $service->forOrder($this->makeOrder());

        self::assertSame('', $details['banktransfer_bank']);
        self::assertSame('', $details['banktransfer_iban']);
        self::assertSame(14, $details['banktransfer_max_days']);
        self::assertFalse(BankTransferDetailsService::isConfigured($details));
    }
}
