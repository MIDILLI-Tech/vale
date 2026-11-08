<?php

declare(strict_types=1);

namespace ValeApp\Application\Shop;

use ValeApp\Application\Mail\MailService;
use ValeApp\Application\Membership\SubscriptionService;
use ValeApp\Domain\Shared\Event\EventDispatcherInterface;
use ValeApp\Domain\Shared\Exception\ValidationException;
use ValeApp\Domain\Shop\Entity\Order;
use ValeApp\Domain\Shop\OrderStatus;
use ValeApp\Domain\Shop\Repository\OrderLogRepositoryInterface;
use ValeApp\Domain\Shop\Repository\OrderRepositoryInterface;
use ValeApp\Infrastructure\Payment\PayPalGateway;
use ValeApp\Infrastructure\Payment\StripeGateway;
use ValeApp\Infrastructure\Translation\Translator;

/**
 * Single source of truth for order status transitions.
 *
 * Both the admin panel and the REST API funnel through here so that paid-state
 * side effects (paid_at, coupon usage, download revocation, inventory events,
 * subscriber tagging, subscription cancellation, customer notification) behave
 * identically no matter where the change came from.
 */
final class ChangeOrderStatusUseCase
{
    public function __construct(
        private readonly OrderRepositoryInterface $orders,
        private readonly OrderLogRepositoryInterface $orderLogs,
        private readonly EventDispatcherInterface $events,
        private readonly OrderStatusEffects $effects,
        private readonly ?MailService $mail = null,
        private readonly ?Translator $translator = null,
        private readonly ?StripeGateway $stripeGateway = null,
        private readonly ?PayPalGateway $paypalGateway = null,
        private readonly ?SubscriptionService $subscriptionService = null,
    ) {
    }

    /**
     * @throws ValidationException when the target status is unknown
     * @return bool whether the status actually changed (false = no-op)
     */
    public function execute(Order $order, string $newStatus): bool
    {
        if (!OrderStatus::isValid($newStatus)) {
            throw ValidationException::withMessages(['status' => 'Invalid order status.']);
        }

        $oldStatus = $order->getStatus();
        if ($newStatus === $oldStatus) {
            return false;
        }

        $order->setStatus($newStatus);

        if (OrderStatus::isPaid($newStatus) && $order->getPaidAt() === null) {
            $order->setPaidAt(new \DateTimeImmutable());
        }

        $this->orders->save($order);

        $this->effects->syncDownloadRevocation($order, $newStatus);
        $this->effects->syncCouponUsage($order, $oldStatus, $newStatus);

        $this->orderLogs->add(
            $order->getId(),
            'status_changed',
            'Status changed from "' . OrderStatus::label($oldStatus) . '" to "' . OrderStatus::label($newStatus) . '".'
        );

        $wasPaid = OrderStatus::isPaid($oldStatus);
        $isPaid  = OrderStatus::isPaid($newStatus);

        $this->sendStatusUpdateEmail($order, $oldStatus);

        $this->events->doAction('order.status_changed', $order, $newStatus);

        if ($isPaid && !$wasPaid) {
            $this->events->doAction('order.paid', $order);
            $this->effects->tagSubscriberPaid($order);
        }

        if (!$isPaid && $wasPaid) {
            $this->events->doAction('order.reversed', $order);
        }

        if (OrderStatus::isReversal($newStatus)) {
            $this->effects->tagSubscriberReversed($order);
            $this->cancelGatewaySubscription($order);
        }

        return true;
    }

    private function cancelGatewaySubscription(Order $order): void
    {
        if ($order->getOrderType() !== 'subscription' || $this->subscriptionService === null) {
            return;
        }

        $subscriptionId = $order->getSubscriptionId();
        if ($subscriptionId === null || $subscriptionId === '') {
            return;
        }

        $gateway = $order->getPaymentMethod();
        try {
            if ($gateway === 'stripe' && $this->stripeGateway !== null) {
                $this->stripeGateway->cancelSubscription($subscriptionId);
            } elseif ($gateway === 'paypal' && $this->paypalGateway !== null) {
                $this->paypalGateway->cancelBillingAgreement($subscriptionId);
            }
        } catch (\Throwable $e) {
            error_log('Failed to cancel subscription at gateway: ' . $e->getMessage());
        }

        $this->subscriptionService->updateSubscriptionStatus($subscriptionId, 'cancelled');
        $this->subscriptionService->removePlanTagBySubscriptionId($subscriptionId);
    }

    private function sendStatusUpdateEmail(Order $order, string $oldStatus): void
    {
        if ($this->mail === null) {
            error_log('[Order] MailService not configured — skipping status email for order #' . $order->getOrderNumber());
            return;
        }

        $billing = $order->getBilling();
        $email   = $billing['email'] ?? null;
        if (empty($email)) {
            return;
        }

        try {
            $this->mail->sendTemplate(
                $email,
                $this->translator?->get(
                    'Order #:number — Status Updated',
                    [':number' => $order->getOrderNumber()],
                    $order->getLocale()
                ) ?? ('Order #' . $order->getOrderNumber() . ' — Status Updated'),
                'mail/order-status-update',
                ['order' => $order, 'old_status' => $oldStatus],
                locale: $order->getLocale(),
                recipientType: 'customer',
            );
        } catch (\Throwable $e) {
            error_log('[Order] Failed to send status email for order #' . $order->getOrderNumber() . ': ' . $e->getMessage());
        }
    }
}
