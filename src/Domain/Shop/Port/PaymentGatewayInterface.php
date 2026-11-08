<?php

declare(strict_types=1);

namespace ValeApp\Domain\Shop\Port;

use ValeApp\Domain\Shop\Entity\Order;

/**
 * Payment gateway interface — Stripe, PayPal, etc. implement this.
 */
interface PaymentGatewayInterface
{
    /** Returns the gateway slug, e.g. "stripe" or "paypal" */
    public function getName(): string;

    /**
     * Create a payment intent / session and return a redirect URL or client secret.
     *
     * @param Order $order  The order to charge
     * @return array{client_secret?: string, redirect_url?: string, payment_id: string}
     */
    public function createPayment(Order $order): array;

    /**
     * Verify a completed payment and return updated status.
     *
     * @param string $paymentId  The payment ID / intent ID
     * @return array{status: string, transaction_id: string}
     */
    public function verifyPayment(string $paymentId): array;

    /**
     * Handle a webhook payload.
     *
     * @param string $payload  Raw request body
     * @param string $signature  Signature header value
     * @return array{event: string, order_id?: string, status?: string}|null  null = ignored
     */
    public function handleWebhook(string $payload, string $signature): ?array;
}
