<?php

declare(strict_types=1);

namespace ValeApp\Tests\Support;

use ValeApp\Application\Shop\ChangeOrderStatusUseCase;
use ValeApp\Application\Shop\DeliveryEstimateService;
use ValeApp\Application\Shop\OrderShippingEstimator;
use ValeApp\Application\Shop\OrderStatusEffects;
use ValeApp\Application\Shop\OrderTrackingNotifier;
use ValeApp\Application\Shop\UpdateOrderTrackingUseCase;
use ValeApp\Domain\Shared\Event\EventDispatcherInterface;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Domain\Shop\Repository\CouponRepositoryInterface;
use ValeApp\Domain\Shop\Repository\OrderLogRepositoryInterface;
use ValeApp\Domain\Shop\Repository\OrderRepositoryInterface;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;
use ValeApp\Infrastructure\Shop\ShippingCarrierCatalog;

/**
 * Builds the real order collaborators the admin OrderController now requires
 * (status transitions, tracking updates, estimates, carrier catalog) from
 * interface stubs, so controller unit tests do not need to mock final classes.
 */
trait OrderControllerCollaborators
{
    /**
     * @return array{
     *     effects: OrderStatusEffects,
     *     changeOrderStatus: ChangeOrderStatusUseCase,
     *     updateOrderTracking: UpdateOrderTrackingUseCase,
     *     estimator: OrderShippingEstimator,
     *     carriers: ShippingCarrierCatalog,
     * }
     */
    protected function makeOrderCollaborators(
        OrderRepositoryInterface $orders,
        OrderLogRepositoryInterface $orderLogs,
        EventDispatcherInterface $events,
    ): array {
        $settings = $this->createStub(SettingsRepositoryInterface::class);
        $products = $this->createStub(ProductRepositoryInterface::class);

        $effects = new OrderStatusEffects(
            $orders,
            $this->createStub(CouponRepositoryInterface::class),
            null,
            null,
            null,
        );

        $estimator = new OrderShippingEstimator(
            $products,
            new DeliveryEstimateService($settings),
        );

        $notifier = new OrderTrackingNotifier(
            $orderLogs,
            new ShippingCarrierCatalog($settings),
            $estimator,
            null,
            null,
        );

        return [
            'effects'            => $effects,
            'changeOrderStatus'  => new ChangeOrderStatusUseCase(
                $orders,
                $orderLogs,
                $events,
                $effects,
            ),
            'updateOrderTracking' => new UpdateOrderTrackingUseCase(
                $orders,
                $orderLogs,
                $events,
                $notifier,
            ),
            'estimator'          => $estimator,
            'carriers'           => new ShippingCarrierCatalog($settings),
        ];
    }
}
