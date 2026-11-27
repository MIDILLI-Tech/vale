<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Shop;

use PHPUnit\Framework\TestCase;
use ValeApp\Application\Shop\WithdrawalService;
use ValeApp\Domain\Shop\Entity\Order;
use ValeApp\Domain\Shop\Entity\WithdrawalRequest;
use ValeApp\Domain\Shop\Repository\OrderRepositoryInterface;
use ValeApp\Domain\Shop\Repository\WithdrawalRequestRepositoryInterface;

final class WithdrawalServiceTest extends TestCase
{
    public function testValidateRequiresOrderEmailAndConfirmation(): void
    {
        $service = new WithdrawalService(
            $this->createStub(WithdrawalRequestRepositoryInterface::class),
            $this->createStub(OrderRepositoryInterface::class),
        );

        $errors = $service->validate([]);

        self::assertCount(3, $errors);
    }

    public function testValidateRejectsOverlongReason(): void
    {
        $service = new WithdrawalService(
            $this->createStub(WithdrawalRequestRepositoryInterface::class),
            $this->createStub(OrderRepositoryInterface::class),
        );

        $errors = $service->validate([
            'order_number' => 'VL-1',
            'email'        => 'buyer@example.com',
            'confirm'      => '1',
            'reason'       => str_repeat('a', 2001),
        ]);

        self::assertNotEmpty($errors);
    }

    public function testSubmitLinksMatchingOrderAndSnapshotsItems(): void
    {
        $order = new Order('VL-2026-0001', [
            'email'      => 'Buyer@Example.com',
            'first_name' => 'Ada',
            'last_name'  => 'Lovelace',
        ]);
        $order->setId(42);
        $order->setItems([
            ['product_id' => 7, 'product_name' => 'Candle', 'quantity' => 2, 'price' => 12.5],
        ]);

        $orders = $this->createStub(OrderRepositoryInterface::class);
        $orders->method('findByOrderNumber')->willReturn($order);

        $requests = $this->createStub(WithdrawalRequestRepositoryInterface::class);
        $requests->method('findByReference')->willReturn(null);
        $requests->method('save')->willReturnCallback(static fn (WithdrawalRequest $request): WithdrawalRequest => $request);

        $service = new WithdrawalService($requests, $orders);

        $withdrawal = $service->submit([
            'order_number' => 'vl-2026-0001',
            'email'        => 'buyer@example.com',
            'confirm'      => '1',
        ], '127.0.0.0', 'de');

        self::assertSame('VL-2026-0001', $withdrawal->getOrderNumber());
        self::assertSame(42, $withdrawal->getOrderId());
        self::assertSame('Ada Lovelace', $withdrawal->getCustomerName());
        self::assertSame('de', $withdrawal->getLocale());
        self::assertCount(1, $withdrawal->getItems());
        self::assertSame('Candle', $withdrawal->getItems()[0]['product_name']);
        self::assertMatchesRegularExpression('/^W-\d{8}-[A-F0-9]{6}$/', $withdrawal->getReference());
    }

    public function testFindDuplicateNormalizesOrderNumberAndEmail(): void
    {
        $existing  = new WithdrawalRequest('VL-2026-0003', 'buyer@example.com', 'W-20261127-ABCDEF');
        $requested = [];

        $requests = $this->createStub(WithdrawalRequestRepositoryInterface::class);
        $requests->method('findOpenByOrderAndEmail')->willReturnCallback(
            static function (string $orderNumber, string $email) use (&$requested, $existing): ?WithdrawalRequest {
                $requested = [$orderNumber, $email];

                return $existing;
            },
        );

        $service = new WithdrawalService($requests, $this->createStub(OrderRepositoryInterface::class));

        $duplicate = $service->findDuplicate([
            'order_number' => '  vl-2026-0003 ',
            'email'        => ' Buyer@Example.com ',
        ]);

        self::assertSame($existing, $duplicate);
        self::assertSame(['VL-2026-0003', 'buyer@example.com'], $requested);
    }

    public function testFindDuplicateReturnsNullWithoutOrderOrEmail(): void
    {
        $service = new WithdrawalService(
            $this->createStub(WithdrawalRequestRepositoryInterface::class),
            $this->createStub(OrderRepositoryInterface::class),
        );

        self::assertNull($service->findDuplicate(['order_number' => 'VL-1']));
        self::assertNull($service->findDuplicate(['email' => 'buyer@example.com']));
    }

    public function testSubmitDoesNotLinkOrderWhenEmailDiffers(): void
    {
        $order = new Order('VL-2026-0002', ['email' => 'owner@example.com']);
        $order->setId(9);

        $orders = $this->createStub(OrderRepositoryInterface::class);
        $orders->method('findByOrderNumber')->willReturn($order);

        $requests = $this->createStub(WithdrawalRequestRepositoryInterface::class);
        $requests->method('findByReference')->willReturn(null);
        $requests->method('save')->willReturnCallback(static fn (WithdrawalRequest $request): WithdrawalRequest => $request);

        $service = new WithdrawalService($requests, $orders);

        $withdrawal = $service->submit([
            'order_number' => 'VL-2026-0002',
            'email'        => 'someone-else@example.com',
            'confirm'      => '1',
        ], '127.0.0.0', 'en');

        self::assertNull($withdrawal->getOrderId());
        self::assertSame([], $withdrawal->getItems());
    }
}
