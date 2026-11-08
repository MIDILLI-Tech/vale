<?php

declare(strict_types=1);

namespace ValeApp\Application\Shop;

use ValeApp\Domain\Shared\Event\EventDispatcherInterface;
use ValeApp\Domain\Shared\Exception\ValidationException;
use ValeApp\Domain\Shop\Entity\Order;
use ValeApp\Domain\Shop\Repository\OrderLogRepositoryInterface;
use ValeApp\Domain\Shop\Repository\OrderRepositoryInterface;

/**
 * Stores carrier + tracking number on an order, logs the change and fires the
 * order.shipped event. Used by both the admin tracking form and the REST API.
 */
final class UpdateOrderTrackingUseCase
{
    public function __construct(
        private readonly OrderRepositoryInterface $orders,
        private readonly OrderLogRepositoryInterface $orderLogs,
        private readonly EventDispatcherInterface $events,
        private readonly OrderTrackingNotifier $notifier,
    ) {
    }

    /**
     * @throws ValidationException when the tracking number is empty
     */
    public function execute(
        Order $order,
        string $carrier,
        string $trackingNumber,
        bool $notifyCustomer = false,
        string $actorName = 'Admin',
        ?int $actorId = null
    ): void {
        if (trim($trackingNumber) === '') {
            throw ValidationException::withMessages(['tracking_number' => 'A tracking number is required.']);
        }
        $trackingNumber = trim($trackingNumber);
        $carrier        = trim($carrier);

        $order->setCarrier($carrier !== '' ? $carrier : null);
        $order->setTrackingNumber($trackingNumber);
        $this->orders->save($order);

        $this->orderLogs->add(
            $order->getId(),
            'tracking_updated',
            'Tracking updated — Carrier: ' . ($carrier !== '' ? $carrier : 'none')
                . ', Tracking #: ' . $trackingNumber,
            'system',
            $actorId,
            $actorName,
        );

        $this->events->doAction('order.shipped', $order);

        if ($notifyCustomer) {
            $this->notifier->notify($order, $actorName, $actorId);
        }
    }
}
