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

    public function testDeadlineStartsAtDelivery(): void
    {
        $order = new Order('VL-2026-0004', ['email' => 'buyer@example.com']);
        $order->setDeliveredAt(new \DateTimeImmutable('-10 days'));

        $deadline = (new WithdrawalService(
            $this->createStub(WithdrawalRequestRepositoryInterface::class),
            $this->createStub(OrderRepositoryInterface::class),
        ))->deadline($order, 14);

        self::assertNotNull($deadline['deadline']);
        self::assertSame($order->getDeliveredAt()->modify('+14 days')->format('Y-m-d'), $deadline['deadline']->format('Y-m-d'));
        self::assertFalse($deadline['late']);
        self::assertFalse($deadline['expired']);
        self::assertFalse($deadline['estimated']);
    }

    public function testDeadlineFallsBackToPaymentDateWithoutAHardAnswer(): void
    {
        $order = new Order('VL-2026-0005', ['email' => 'buyer@example.com']);
        $order->setPaidAt(new \DateTimeImmutable('-20 days'));

        $deadline = (new WithdrawalService(
            $this->createStub(WithdrawalRequestRepositoryInterface::class),
            $this->createStub(OrderRepositoryInterface::class),
        ))->deadline($order, 14);

        // No confirmed delivery: the estimate must not reject the customer.
        self::assertFalse($deadline['late']);
        self::assertFalse($deadline['expired']);
        self::assertTrue($deadline['estimated']);
        self::assertFalse($deadline['confirmed']);
    }

    public function testGuessedDeliveryDateNeverMarksTheDeclarationLate(): void
    {
        $order = new Order('VL-2026-0009', ['email' => 'buyer@example.com']);
        $order->setDeliveredAt(new \DateTimeImmutable('-20 days'));
        $order->setDeliveredAtSource(Order::DELIVERY_SOURCE_STATUS);

        $deadline = (new WithdrawalService(
            $this->createStub(WithdrawalRequestRepositoryInterface::class),
            $this->createStub(OrderRepositoryInterface::class),
        ))->deadline($order, 14);

        self::assertFalse($deadline['confirmed']);
        self::assertTrue($deadline['provisional']);
        self::assertFalse($deadline['estimated']);
        self::assertFalse($deadline['late']);
        self::assertSame($order->getDeliveredAt()?->modify('+14 days')->format('Y-m-d'), $deadline['deadline']?->format('Y-m-d'));
    }

    public function testConfirmedDeliveryDateMarksLate(): void
    {
        $order = new Order('VL-2026-0010', ['email' => 'buyer@example.com']);
        $order->setDeliveredAt(new \DateTimeImmutable('-20 days'));
        $order->setDeliveredAtSource(Order::DELIVERY_SOURCE_MANUAL);

        $deadline = (new WithdrawalService(
            $this->createStub(WithdrawalRequestRepositoryInterface::class),
            $this->createStub(OrderRepositoryInterface::class),
        ))->deadline($order, 14);

        self::assertTrue($deadline['confirmed']);
        self::assertFalse($deadline['provisional']);
        self::assertTrue($deadline['late']);
    }

    public function testDeadlineExpiresAfterLongStop(): void
    {
        $order = new Order('VL-2026-0006', ['email' => 'buyer@example.com']);
        $order->setDeliveredAt(new \DateTimeImmutable('-13 months'));

        $deadline = (new WithdrawalService(
            $this->createStub(WithdrawalRequestRepositoryInterface::class),
            $this->createStub(OrderRepositoryInterface::class),
        ))->deadline($order, 14);

        self::assertTrue($deadline['expired']);
    }

    public function testSubmitStoresDeadlineForLinkedOrder(): void
    {
        $order = new Order('VL-2026-0007', ['email' => 'buyer@example.com']);
        $order->setId(11);

        $orders = $this->createStub(OrderRepositoryInterface::class);
        $orders->method('findByOrderNumber')->willReturn($order);

        $requests = $this->createStub(WithdrawalRequestRepositoryInterface::class);
        $requests->method('findByReference')->willReturn(null);
        $requests->method('save')->willReturnCallback(static fn (WithdrawalRequest $request): WithdrawalRequest => $request);

        $deadline   = new \DateTimeImmutable('+4 days');
        $withdrawal = (new WithdrawalService($requests, $orders))->submit([
            'order_number' => 'VL-2026-0007',
            'email'        => 'buyer@example.com',
            'confirm'      => '1',
        ], '127.0.0.0', 'en', $deadline);

        self::assertSame($deadline, $withdrawal->getDeadlineAt());
        self::assertFalse($withdrawal->isDeadlineEstimated());
    }

    public function testSubmitKeepsAnEstimatedDeadlineCompliant(): void
    {
        $order = new Order('VL-2026-0011', ['email' => 'buyer@example.com']);
        $order->setId(12);

        $orders = $this->createStub(OrderRepositoryInterface::class);
        $orders->method('findByOrderNumber')->willReturn($order);

        $requests = $this->createStub(WithdrawalRequestRepositoryInterface::class);
        $requests->method('findByReference')->willReturn(null);
        $requests->method('save')->willReturnCallback(static fn (WithdrawalRequest $request): WithdrawalRequest => $request);

        $withdrawal = (new WithdrawalService($requests, $orders))->submit([
            'order_number' => 'VL-2026-0011',
            'email'        => 'buyer@example.com',
            'confirm'      => '1',
        ], '127.0.0.0', 'de', new \DateTimeImmutable('-1 day'), true);

        self::assertTrue($withdrawal->isDeadlineEstimated());
        self::assertFalse($withdrawal->isLate());
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
