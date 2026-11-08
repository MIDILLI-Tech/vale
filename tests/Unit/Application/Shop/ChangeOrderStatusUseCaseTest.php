<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Shop;

use PHPUnit\Framework\TestCase;
use ValeApp\Application\Shop\ChangeOrderStatusUseCase;
use ValeApp\Application\Shop\OrderStatusEffects;
use ValeApp\Domain\Shared\Event\EventDispatcherInterface;
use ValeApp\Domain\Shared\Exception\ValidationException;
use ValeApp\Domain\Shop\Entity\Order;
use ValeApp\Domain\Shop\OrderStatus;
use ValeApp\Domain\Shop\Repository\CouponRepositoryInterface;
use ValeApp\Domain\Shop\Repository\DownloadRepositoryInterface;
use ValeApp\Domain\Shop\Repository\OrderLogRepositoryInterface;
use ValeApp\Domain\Shop\Repository\OrderRepositoryInterface;

final class ChangeOrderStatusUseCaseTest extends TestCase
{
    /** @var list<array{0: string, 1: array<int, mixed>}> */
    private array $firedEvents = [];

    /** @var list<array{0: string, 1: string}> */
    private array $logged = [];

    private int $saved = 0;

    /** @var list<array{0: string, 1: array<int, mixed>}> */
    private array $sideEffects = [];

    private function makeOrder(string $status = 'pending', string $type = 'shop'): Order
    {
        return Order::fromRow([
            'id'           => 7,
            'order_number' => 'VL-7777',
            'status'       => $status,
            'currency'     => 'EUR',
            'total'        => '49.00',
            'order_type'   => $type,
            'billing'      => json_encode(['email' => 'ada@example.com']),
            'paid_at'      => in_array($status, OrderStatus::PAID, true)
                ? '2026-10-01 10:00:00'
                : null,
            'created_at'   => '2026-10-01 09:59:00',
        ]);
    }

    private function makeEffects(): OrderStatusEffects
    {
        $orders = $this->createStub(OrderRepositoryInterface::class);

        $downloads = $this->createStub(DownloadRepositoryInterface::class);
        $downloads->method('setRevokedByOrderId')->willReturnCallback(
            function (int $orderId, bool $revoked): void {
                $this->sideEffects[] = ['downloadRevoked', [$orderId, $revoked]];
            }
        );

        return new OrderStatusEffects(
            $orders,
            $this->createStub(CouponRepositoryInterface::class),
            null,
            $downloads,
            null,
        );
    }

    private function makeUseCase(?OrderStatusEffects $effects = null): ChangeOrderStatusUseCase
    {
        $orders = $this->createStub(OrderRepositoryInterface::class);
        $orders->method('save')->willReturnCallback(function (Order $saved): Order {
            $this->saved++;
            return $saved;
        });

        $logs = $this->createStub(OrderLogRepositoryInterface::class);
        $logs->method('add')->willReturnCallback(
            function (int $orderId, string $event, ?string $note): void {
                $this->logged[] = [$event, (string) $note];
            }
        );

        $events = $this->createStub(EventDispatcherInterface::class);
        $events->method('doAction')->willReturnCallback(function (string $event, ...$args): void {
            $this->firedEvents[] = [$event, $args];
        });

        return new ChangeOrderStatusUseCase(
            $orders,
            $logs,
            $events,
            $effects ?? $this->makeEffects(),
        );
    }

    private function fired(string $event): bool
    {
        foreach ($this->firedEvents as [$name, $args]) {
            if ($name === $event) {
                return true;
            }
        }
        return false;
    }

    public function testPendingToProcessingMarksPaidAndFiresEvents(): void
    {
        $order   = $this->makeOrder('pending');
        $useCase = $this->makeUseCase();

        self::assertTrue($useCase->execute($order, 'processing'));

        self::assertSame('processing', $order->getStatus());
        self::assertNotNull($order->getPaidAt());
        self::assertSame(1, $this->saved);

        self::assertSame('status_changed', $this->logged[0][0]);
        self::assertSame(
            'Status changed from "Pending Payment" to "Paid & Processing".',
            $this->logged[0][1]
        );

        self::assertTrue($this->fired('order.status_changed'));
        self::assertTrue($this->fired('order.paid'));
        self::assertFalse($this->fired('order.reversed'));
    }

    public function testInvalidStatusThrowsValidationException(): void
    {
        $order   = $this->makeOrder('pending');
        $useCase = $this->makeUseCase();

        $this->expectException(ValidationException::class);

        try {
            $useCase->execute($order, 'not-a-status');
        } finally {
            self::assertSame('pending', $order->getStatus());
            self::assertSame(0, $this->saved);
        }
    }

    public function testSameStatusIsANoOp(): void
    {
        $order   = $this->makeOrder('processing');
        $useCase = $this->makeUseCase();

        self::assertFalse($useCase->execute($order, 'processing'));
        self::assertSame(0, $this->saved);
        self::assertSame([], $this->logged);
        self::assertSame([], $this->firedEvents);
    }

    public function testPaidToCancelledRevokesDownloadsAndFiresReversed(): void
    {
        $order = Order::fromRow([
            'id'           => 7,
            'order_number' => 'VL-7777',
            'status'       => 'processing',
            'coupon_code'  => 'SAVE10',
            'currency'     => 'EUR',
            'total'        => '49.00',
            'paid_at'      => '2026-10-01 10:00:00',
            'created_at'   => '2026-10-01 09:59:00',
            'billing'      => json_encode(['email' => 'ada@example.com']),
        ]);

        $useCase = $this->makeUseCase();

        self::assertTrue($useCase->execute($order, 'cancelled'));

        self::assertSame('cancelled', $order->getStatus());
        self::assertTrue($this->fired('order.reversed'));
        self::assertFalse($this->fired('order.paid'));
        self::assertSame(
            ['downloadRevoked', [7, true]],
            $this->sideEffects[0]
        );
        self::assertSame(
            'Status changed from "Paid & Processing" to "Cancelled".',
            $this->logged[0][1]
        );
    }

    public function testSubscriptionOrderWithoutServicesDoesNotThrow(): void
    {
        $order = Order::fromRow([
            'id'              => 7,
            'order_number'    => 'VL-7778',
            'status'          => 'processing',
            'order_type'      => 'subscription',
            'subscription_id' => 'sub_123',
            'currency'        => 'EUR',
            'total'           => '9.99',
            'paid_at'         => '2026-10-01 10:00:00',
            'created_at'      => '2026-10-01 09:59:00',
            'billing'         => json_encode(['email' => 'ada@example.com']),
        ]);

        $useCase = $this->makeUseCase();

        self::assertTrue($useCase->execute($order, 'cancelled'));
        self::assertSame('cancelled', $order->getStatus());
    }

    public function testStatusEmailIsSkippedWithoutMailService(): void
    {
        $order   = $this->makeOrder('on-hold');
        $useCase = $this->makeUseCase();

        self::assertTrue($useCase->execute($order, 'shipped'));
        self::assertSame('shipped', $order->getStatus());
        self::assertTrue($this->fired('order.status_changed'));
    }
}
