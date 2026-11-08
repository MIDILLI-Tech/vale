<?php

declare(strict_types=1);

namespace ValeApp\Application\Shop;

use ValeApp\Application\Mail\MailService;
use ValeApp\Domain\Shop\Entity\Order;
use ValeApp\Domain\Shop\Repository\OrderLogRepositoryInterface;
use ValeApp\Infrastructure\Shop\ShippingCarrierCatalog;
use ValeApp\Infrastructure\Translation\Translator;

/**
 * Sends the "your order shipped" email with the resolved tracking URL.
 * Used by both the admin tracking form and the REST API so the notification
 * content and logging stay identical.
 */
final class OrderTrackingNotifier
{
    public function __construct(
        private readonly OrderLogRepositoryInterface $orderLogs,
        private readonly ShippingCarrierCatalog $carriers,
        private readonly OrderShippingEstimator $estimator,
        private readonly ?MailService $mail = null,
        private readonly ?Translator $translator = null,
    ) {
    }

    public function notify(Order $order, string $actorName, ?int $actorId = null): void
    {
        if ($this->mail === null) {
            error_log('[Order] Tracking email skipped — MailService not configured');
            $this->orderLogs->add(
                $order->getId(),
                'mail_not_configured',
                'Tracking notification could not be sent because MailService is not configured.',
                'system',
                $actorId,
                $actorName,
            );
            return;
        }

        $billing = $order->getBilling();
        $email   = $billing['email'] ?? null;
        if (empty($email)) {
            error_log('[Order] Tracking email skipped — no customer email for order #' . $order->getOrderNumber());
            $this->orderLogs->add(
                $order->getId(),
                'tracking_email_skipped',
                'Tracking notification email skipped — no customer email on file for order #' . $order->getOrderNumber(),
                'system',
                $actorId,
                $actorName,
            );
            return;
        }

        $carrier  = $order->getCarrier();
        $trackNum = (string) $order->getTrackingNumber();
        $trackUrl = $carrier === 'other'
            ? $trackNum
            : $this->carriers->trackingUrl($carrier, $trackNum);

        try {
            $this->mail->sendTemplate(
                $email,
                $this->translator?->get(
                    'Order #:number — Shipping Update',
                    [':number' => $order->getOrderNumber()],
                    $order->getLocale()
                ) ?? ('Order #' . $order->getOrderNumber() . ' — Shipping Update'),
                'mail/tracking-update',
                [
                    'order'              => $order,
                    'track_url'          => $trackUrl,
                    'order_status_url'   => absolute_url('/checkout/success', [], ['lang' => $order->getLocale(), 'order' => $order->getOrderNumber()]),
                    'shipping_estimate'  => $this->estimator->estimate($order),
                ],
                locale: $order->getLocale(),
                recipientType: 'customer',
            );

            $this->orderLogs->add(
                $order->getId(),
                'tracking_email_sent',
                'Tracking notification email sent to ' . $email
                    . ' — Carrier: ' . ($carrier ?: 'none')
                    . ', Tracking #: ' . $trackNum,
                'system',
                $actorId,
                $actorName,
            );
        } catch (\Throwable $e) {
            error_log('[Order] Failed to send tracking email for order #' . $order->getOrderNumber() . ': ' . $e->getMessage());

            $this->orderLogs->add(
                $order->getId(),
                'tracking_email_failed',
                'Failed to send tracking notification email: ' . $e->getMessage(),
                'system',
                $actorId,
                $actorName,
            );
        }
    }
}
