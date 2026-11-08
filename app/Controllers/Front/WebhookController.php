<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Front;

use Doctrine\DBAL\Connection;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use ValeApp\Application\Mail\MailService;
use ValeApp\Application\Membership\SubscriptionService;
use ValeApp\Application\Newsletter\AutomationService;
use ValeApp\Application\Webhook\OutboundWebhookService;
use ValeApp\Domain\Membership\Repository\PlanRepositoryInterface;
use ValeApp\Domain\Newsletter\Repository\SubscriberRepositoryInterface;
use ValeApp\Domain\Shared\Event\EventDispatcherInterface;
use ValeApp\Domain\Shop\Port\PaymentGatewayInterface;
use ValeApp\Domain\Shop\Repository\CouponRepositoryInterface;
use ValeApp\Domain\Shop\Repository\OrderLogRepositoryInterface;
use ValeApp\Domain\Shop\Repository\OrderRepositoryInterface;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;
use ValeApp\Infrastructure\Payment\PayPalGateway;

/**
 * Stripe, PayPal & Mollie webhook endpoints.
 *
 * POST /webhooks/stripe
 * POST /webhooks/paypal
 * POST /webhooks/mollie
 * POST /webhooks/twocheckout
 * POST /webhooks/stripe/subscriptions
 */
final class WebhookController
{
    public function __construct(
        private readonly OrderRepositoryInterface $orders,
        private readonly ProductRepositoryInterface $products,
        private readonly MailService $mail,
        private readonly EventDispatcherInterface $events,
        private readonly OutboundWebhookService $webhookService,
        /** @var PaymentGatewayInterface[] */
        private readonly array $gateways = [],
        private readonly ?OrderLogRepositoryInterface $orderLogs = null,
        private readonly ?SubscriberRepositoryInterface $subscribers = null,
        private readonly ?AutomationService $automationService = null,
        private readonly ?SubscriptionService $subscriptionService = null,
        private readonly ?PlanRepositoryInterface $plans = null,
        private readonly ?CouponRepositoryInterface $coupons = null,
        private readonly ?Connection $db = null,
    ) {
    }

    public function stripe(Request $request, Response $response): Response
    {
        return $this->handle($request, $response, 'stripe', 'Stripe-Signature');
    }

    /**
     * POST /webhooks/stripe/subscriptions
     * Handles subscription-related Stripe events (subscription updated/deleted, invoice paid).
     */
    public function stripeSubscription(Request $request, Response $response): Response
    {
        $payload   = (string) $request->getBody();
        $signature = $request->getHeaderLine('Stripe-Signature');

        $gw = $this->getGateway('stripe');
        if ($gw === null) {
            return $this->json($response, ['error' => 'Gateway not configured'], 503);
        }

        $event = $gw->handleWebhook($payload, $signature);
        if ($event === null) {
            return $this->json($response, ['ok' => true]);
        }

        try {
            $this->processSubscriptionEvent($event);
        } catch (\Throwable $e) {
            error_log('[Stripe Subscription Webhook] ' . $e->getMessage());
            return $this->json($response, ['error' => 'Processing failed'], 500);
        }

        return $this->json($response, ['ok' => true]);
    }

    public function paypal(Request $request, Response $response): Response
    {
        $gw = $this->getGateway('paypal');
        if ($gw === null) {
            return $this->json($response, ['error' => 'Gateway not configured'], 503);
        }

        $payload = (string) $request->getBody();

        // Fail closed: every PayPal webhook must pass PayPal's signature
        // verification API before any event is processed.
        if ($gw instanceof PayPalGateway) {
            $headers = [
                'auth_algo'         => $request->getHeaderLine('Paypal-Auth-Algo'),
                'cert_url'          => $request->getHeaderLine('Paypal-Cert-Url'),
                'transmission_id'   => $request->getHeaderLine('Paypal-Transmission-Id'),
                'transmission_sig'  => $request->getHeaderLine('Paypal-Transmission-Sig'),
                'transmission_time' => $request->getHeaderLine('Paypal-Transmission-Time'),
            ];

            if (!$gw->verifyWebhook($headers, $payload)) {
                error_log('[PayPal Webhook] Signature verification failed — webhook rejected.');
                return $this->json($response, ['ok' => true]);
            }
        }

        $event = $gw->handleWebhook($payload, $request->getHeaderLine('Paypal-Transmission-Sig'));
        if ($event === null) {
            return $this->json($response, ['ok' => true]);
        }

        try {
            $eventType = $event['event'] ?? '';
            if (in_array($eventType, ['subscription_updated', 'subscription_deleted', 'checkout_session_completed', 'invoice_paid'], true)) {
                if ($this->subscriptionService !== null) {
                    $this->processSubscriptionEvent($event);
                }
            } else {
                $this->processEvent($event, 'paypal');
            }
        } catch (\Throwable $e) {
            error_log('[PayPal Webhook] ' . $e->getMessage());
            return $this->json($response, ['error' => 'Processing failed'], 500);
        }

        return $this->json($response, ['ok' => true]);
    }

    public function mollie(Request $request, Response $response): Response
    {
        return $this->handle($request, $response, 'mollie', '');
    }

    public function twocheckout(Request $request, Response $response): Response
    {
        return $this->handle($request, $response, 'twocheckout', '');
    }

    public function checkoutcom(Request $request, Response $response): Response
    {
        return $this->handle($request, $response, 'checkoutcom', 'cko-signature');
    }



    private function handle(Request $request, Response $response, string $gatewayName, string $sigHeader): Response
    {
        $gw = $this->getGateway($gatewayName);
        if ($gw === null) {
            return $this->json($response, ['error' => 'Gateway not configured'], 503);
        }

        $payload   = (string) $request->getBody();
        $signature = $sigHeader !== '' ? $request->getHeaderLine($sigHeader) : '';

        $event = $gw->handleWebhook($payload, $signature);
        if ($event === null) {
            return $this->json($response, ['ok' => true]); // ignored
        }

        try {
            $this->processEvent($event, $gatewayName);
        } catch (\Throwable $e) {
            error_log('[Webhook] ' . $e->getMessage());
            return $this->json($response, ['error' => 'Processing failed'], 500);
        }

        return $this->json($response, ['ok' => true]);
    }

    /**
     * @param array{event:string,subscription_id?:string,subscription_status?:string,customer?:string,amount?:int,invoice_id?:string} $event
     */
    private function processSubscriptionEvent(array $event): void
    {
        if ($this->subscriptionService === null) {
            return;
        }

        $type  = (string) ($event['event'] ?? '');
        $subId = (string) ($event['subscription_id']
            ?? ($type === 'checkout_session_completed' ? ($event['subscription'] ?? '') : ''));

        if ($subId === '') {
            return;
        }

        // checkout.session.completed CREATES the local subscription — it must
        // not require an existing row (that is exactly what this event seeds).
        if ($type === 'checkout_session_completed') {
            if ($this->plans === null || $this->subscribers === null) {
                return;
            }

            $metadata    = $event['metadata'] ?? [];
            $planId      = (int) ($metadata['plan_id'] ?? 0);
            $userId      = (int) ($metadata['user_id'] ?? 0);
            $stripeSubId = (string) ($event['subscription'] ?? '');

            if ($planId === 0 || $userId === 0 || $stripeSubId === '') {
                return;
            }

            $existingSub = $this->subscriptionService->findByGatewayId($stripeSubId);
            if ($existingSub !== null) {
                return;
            }

            $plan       = $this->plans->findById($planId);
            $subscriber = $this->subscribers->findByUserId($userId);
            if ($plan === null || $subscriber === null) {
                return;
            }

            $trialEndsAt = $plan->getTrialDays() > 0
                ? new \DateTimeImmutable('+' . $plan->getTrialDays() . ' days')
                : null;

            $this->subscriptionService->createSubscription(
                $userId,
                $plan,
                'stripe',
                $stripeSubId,
                $plan->getTrialDays() > 0 ? 'trialing' : 'active',
                $trialEndsAt,
            );

            if ($plan->getTrialDays() > 0 && $plan->getTrialTag() !== null && $plan->getTrialTag() !== '') {
                $this->subscriptionService->assignTrialTag($subscriber, $plan);
            } else {
                $this->subscriptionService->assignPlanTag($subscriber, $plan);
            }

            $amount          = $event['amount'] ?? 0;
            $amountFormatted = number_format((float) $amount / 100, 2);
            $currency        = strtoupper((string) ($event['currency'] ?? '')) ?: 'USD';

            // Free trials charge nothing — recording a zero-completed order
            // would let the trial sweep flip the membership to active.
            if ((float) $amount > 0) {
                $orderId = $this->subscriptionService->recordPayment(
                    $userId,
                    $plan,
                    'stripe',
                    $stripeSubId,
                    $amountFormatted,
                    $currency,
                    [],
                    'signup:' . $stripeSubId,
                );

                $this->stampCouponFromMetadata($metadata, (int) $orderId);
            }

            return;
        }

        $subRow = $this->subscriptionService->findByGatewayId($subId);
        if ($subRow === null) {
            return;
        }

        if ($type === 'subscription_updated') {
            $status            = $event['subscription_status']  ?? '';
            $cancelAtPeriodEnd = $event['cancel_at_period_end'] ?? false;

            if ($status === 'canceled') {
                $this->subscriptionService->markCancelled($subId);
            } elseif ($cancelAtPeriodEnd && in_array($subRow['status'], ['active', 'trialing'], true)) {
                $this->subscriptionService->updateSubscriptionStatus($subId, 'cancelling');
            } else {
                $subscriptionStatus = match ($status) {
                    'active'             => 'active',
                    // Keep local trialing state so the trial sweep can act on it.
                    'trialing'           => 'trialing',
                    'past_due'           => 'past_due',
                    'canceled'           => 'cancelled',
                    'incomplete'         => 'incomplete',
                    'incomplete_expired' => 'incomplete_expired',
                    'unpaid'             => 'unpaid',
                    default              => $status,
                };

                $this->subscriptionService->updateSubscriptionStatus($subId, $subscriptionStatus);
            }

            $planId = (int) ($subRow['plan_id'] ?? 0);
            $userId = (int) ($subRow['user_id'] ?? 0);

            if ($planId > 0 && $userId > 0 && $this->subscribers !== null && $this->plans !== null) {
                $plan       = $this->plans->findById($planId);
                $subscriber = $this->subscribers->findByUserId($userId);

                if ($plan !== null && $subscriber !== null) {
                    if ($status === 'active') {
                        $this->subscriptionService->assignPlanTag($subscriber, $plan);
                    } elseif ($status === 'canceled' || $status === 'incomplete_expired') {
                        $this->subscriptionService->removePlanTag($subscriber, $plan);
                    }
                }
            }
        } elseif ($type === 'subscription_deleted') {
            // The subscription no longer exists at the gateway — terminate
            // locally instead of scheduling another period-end cancellation.
            $this->subscriptionService->markCancelled($subId);

            $planId = (int) ($subRow['plan_id'] ?? 0);
            $userId = (int) ($subRow['user_id'] ?? 0);

            if ($planId > 0 && $userId > 0 && $this->subscribers !== null && $this->plans !== null) {
                $plan       = $this->plans->findById($planId);
                $subscriber = $this->subscribers->findByUserId($userId);

                if ($plan !== null && $subscriber !== null) {
                    $this->subscriptionService->removePlanTag($subscriber, $plan);
                }
            }
        } elseif ($type === 'invoice_paid') {
            $userId = (int) ($subRow['user_id'] ?? 0);
            $planId = (int) ($subRow['plan_id'] ?? 0);

            if ($planId > 0 && $this->plans !== null) {
                $plan = $this->plans->findById($planId);
                if ($plan !== null) {
                    $amount          = $event['amount'] ?? 0;
                    $amountFormatted = number_format((float) $amount / 100, 2);
                    $gateway         = $event['gateway'] ?? 'stripe';
                    $currency        = strtoupper((string) ($event['currency'] ?? '')) ?: 'USD';
                    // Stripe supplies an invoice id, PayPal a per-charge id —
                    // either works as the dedup key for this renewal.
                    $reference       = (string) ($event['invoice_id'] ?? ($event['transaction_ref'] ?? ''));
                    $this->subscriptionService->recordPayment(
                        $userId,
                        $plan,
                        $gateway,
                        $subId,
                        $amountFormatted,
                        $currency,
                        [],
                        $reference !== '' ? 'charge:' . $reference : null,
                    );
                }
            }
        }
    }

    /** @param array{event: string, order_id?: ?string, status?: string, payment_id?: string} $event */
    private function processEvent(array $event, string $gatewayName): void
    {
        $orderNumber = $event['order_id'] ?? null;
        if ($orderNumber === null) {
            return;
        }

        $order = $this->orders->findByOrderNumber($orderNumber);
        if ($order === null) {
            return;
        }

        $status = $event['status'] ?? null;
        if ($status === 'paid' && $order->getStatus() === 'pending') {
            $statusValue = $order->isAllItemsDigital($this->products) ? 'completed' : 'processing';
            $order->setStatus($statusValue);
            $order->setPaidAt(new \DateTimeImmutable());
            if (!empty($event['payment_id'])) {
                $order->setTransactionId($event['payment_id']);
            }
            $this->orders->save($order);
            $this->orderLogs?->add(
                $order->getId(),
                'payment_received',
                'Payment confirmed via ' . ucfirst($gatewayName) . '. Transaction: ' . ($event['payment_id'] ?? 'n/a')
            );

            // Fire outbound webhook event
            $this->events->doAction('order.paid', $order);

            // Tag subscriber if exists
            $this->tagSubscriberOrderPaid($order);
        } elseif ($status === 'failed') {
            $order->setStatus('failed');
            $this->orders->save($order);
            $this->orderLogs?->add(
                $order->getId(),
                'payment_failed',
                'Payment failed reported by ' . ucfirst($gatewayName) . '.'
            );

            // Return the coupon use — nothing was purchased. The atomic
            // claim makes this idempotent against a checkout cancel or an
            // admin-side status change.
            $couponCode = $order->getCouponCode();
            if ($couponCode !== null && $couponCode !== ''
                                     && $this->orders->claimCouponReturn((int) $order->getId())
            ) {
                $coupon = $this->coupons->findByCode($couponCode);
                if ($coupon !== null) {
                    $this->coupons->decrementUses((int) $coupon->getId());
                }
            }
        }
    }

    private function getGateway(string $name): ?PaymentGatewayInterface
    {
        foreach ($this->gateways as $gw) {
            if ($gw->getName() === $name) {
                return $gw;
            }
        }
        return null;
    }

    /**
     * Stamp membership coupon metadata onto a recorded subscription order exactly once.
     *
     * @param array<string, mixed> $metadata
     */
    private function stampCouponFromMetadata(array $metadata, int $orderId): void
    {
        $couponCode = strtoupper(trim((string) ($metadata['coupon_code'] ?? '')));
        if ($couponCode === '' || $this->coupons === null || $this->db === null) {
            return;
        }

        $discountCents = (int) ($metadata['discount_cents'] ?? 0);
        $discount      = $discountCents > 0 ? round($discountCents / 100, 2) : 0.0;

        $stamped = $this->db->executeStatement(
            'UPDATE ' . VL_DB_PREFIX . 'orders SET coupon_code = ?, discount = ?'
            . " WHERE id = ? AND (coupon_code IS NULL OR coupon_code = '')",
            [$couponCode, $discount, $orderId],
        );
        if ($stamped === 0) {
            return;
        }

        $cEntity = $this->coupons->findByCode($couponCode);
        if ($cEntity !== null && !$this->coupons->incrementUsesIfAvailable((int) $cEntity->getId())) {
            error_log('[Webhook] Coupon usage limit reached during stamping: ' . $couponCode);
        }
    }

    /** @param array<string, mixed> $data */
    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($data));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }

    private function tagSubscriberOrderPaid(Order $order): void
    {
        if ($this->subscribers === null) {
            return;
        }
        $email = strtolower(trim($order->getBilling()['email'] ?? ''));
        if ($email === '') {
            return;
        }
        $subscriber = $this->subscribers->findByEmail($email);
        if ($subscriber === null) {
            return;
        }
        $tags    = $subscriber->getTags();
        $changed = false;
        if (!in_array('customer', $tags, true)) {
            $tags[]  = 'customer';
            $changed = true;
        }
        $idx = array_search('pending_order', $tags, true);
        if ($idx !== false) {
            array_splice($tags, $idx, 1);
            $changed = true;
        }
        if ($changed) {
            $subscriber->setTags($tags);
            $this->subscribers->save($subscriber);
            $this->automationService?->enrollSubscriberByTag($subscriber, 'customer');
        }
    }
}
