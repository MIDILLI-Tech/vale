<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Payment;

use ValeApp\Domain\Shop\Entity\Order;
use ValeApp\Domain\Shop\Port\PaymentGatewayInterface;

/**
 * Checkout.com gateway — Payment Links API (redirect flow).
 *
 * Uses Bearer token (Secret Key) for authentication.
 * Uses HMAC-SHA256 (base64-encoded) to verify webhook signatures
 * via the `cko-signature` header.
 *
 * Settings from settings (payment group):
 *   checkoutcom_secret_key, checkoutcom_webhook_secret,
 *   checkoutcom_sandbox, checkoutcom_enabled
 */
final class CheckoutComGateway implements PaymentGatewayInterface
{
    public function __construct(
        private readonly string $secretKey = '',
        private readonly string $webhookSecret = '',
        private readonly bool $sandbox = true,
        private readonly string $successUrl = '',
        private readonly string $cancelUrl = '',
        private readonly string $webhookUrl = '',
        private readonly string $baseUrlOverride = '',
        private readonly string $processingChannelId = '',
    ) {
    }

    public function getName(): string
    {
        return 'checkoutcom';
    }

    private function baseUrl(): string
    {
        if ($this->baseUrlOverride !== '') {
            return rtrim($this->baseUrlOverride, '/');
        }
        return PaymentApiUrls::fromFile()->checkoutComBase($this->sandbox);
    }

    public function createPayment(Order $order): array
    {
        if ($this->secretKey === '') {
            throw new \RuntimeException('Checkout.com secret key is not configured.');
        }

        $billing  = $order->getBilling();
        $currency = strtoupper($order->getCurrency());

        // Checkout.com uses minor currency units (e.g. cents).
        // Zero-decimal currencies (JPY, BIF, etc.) must NOT be multiplied.
        $amount = Money::toMinorUnits((float) $order->getTotal(), $currency);

        $payload = [
            'amount'      => $amount,
            'currency'    => $currency,
            'reference'   => $order->getOrderNumber(),
            'description' => 'Order #' . $order->getOrderNumber(),
            'customer'    => [
                'email' => $billing['email'] ?? '',
                'name'  => trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? '')),
            ],
            'billing'     => [
                'address' => [
                    'address_line1' => $billing['address'] ?? '',
                    'city'          => $billing['city']    ?? '',
                    'zip'           => $billing['zip']     ?? '',
                    'country'       => strtoupper($billing['country'] ?? 'US'),
                ],
            ],
            'success_url' => $this->successUrl . '?order=' . urlencode((string) $order->getOrderNumber()),
            'failure_url' => $this->cancelUrl,
        ];

        if ($this->processingChannelId !== '') {
            $payload['processing_channel_id'] = $this->processingChannelId;
        }

        if ($this->webhookUrl !== '' && !str_contains($this->webhookUrl, 'localhost') && !str_contains($this->webhookUrl, '127.0.0.1')) {
            $payload['3ds'] = ['enabled' => false];
        }

        $http = HttpTransport::request(
            'POST',
            $this->baseUrl() . '/payment-links',
            [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $this->secretKey,
            ],
            (string) json_encode($payload),
            15,
        );
        if (!$http['ok']) {
            throw new \RuntimeException('Checkout.com HTTP error: ' . $http['error']);
        }
        $httpCode = $http['status'];
        $result   = json_decode($http['body'], true);

        if ($httpCode < 200 || $httpCode >= 300 || empty($result['id'])) {
            $detail = '';
            if (!empty($result['error_codes']) && is_array($result['error_codes'])) {
                $detail = implode(', ', $result['error_codes']);
            } elseif (!empty($result['message'])) {
                $detail = $result['message'];
            }
            throw new \RuntimeException('Checkout.com create payment link failed (HTTP ' . $httpCode . '): ' . ($detail ?: 'Unknown error'));
        }

        $linkId      = (string) $result['id'];
        $redirectUrl = $result['_links']['redirect']['href'] ?? '';

        if ($redirectUrl === '') {
            throw new \RuntimeException('Checkout.com did not return a redirect URL for link ' . $linkId);
        }

        return [
            'redirect_url' => $redirectUrl,
            'payment_id'   => $linkId,
        ];
    }

    public function verifyPayment(string $linkId): array
    {
        if ($this->secretKey === '') {
            throw new \RuntimeException('Checkout.com secret key is not configured.');
        }

        $http = HttpTransport::request(
            'GET',
            $this->baseUrl() . '/payment-links/' . urlencode($linkId),
            [
                'Accept: application/json',
                'Authorization: Bearer ' . $this->secretKey,
            ],
            null,
            15,
        );
        if (!$http['ok']) {
            throw new \RuntimeException('Checkout.com verify HTTP error: ' . $http['error']);
        }
        $raw      = $http['body'];
        $httpCode = $http['status'];

        if ($raw === false || $raw === '') {
            throw new \RuntimeException('Checkout.com verify returned empty response (HTTP ' . $httpCode . ')');
        }

        $result = json_decode((string) $raw, true);
        if (!is_array($result)) {
            throw new \RuntimeException('Checkout.com verify invalid JSON (HTTP ' . $httpCode . '): ' . substr((string) $raw, 0, 200));
        }

        // Checkout.com returns "Active", "Payment Received", "Paid", etc.
        $rawStatus     = strtolower($result['status'] ?? '');
        $paid          = str_contains($rawStatus, 'paid') || str_contains($rawStatus, 'payment received');
        $status        = $paid ? 'paid' : 'pending';
        $transactionId = $result['payment_id'] ?? $linkId;

        return [
            'status'         => $status,
            'transaction_id' => (string) $transactionId,
        ];
    }

    /**
     * Refund a Checkout.com payment.
     *
     * @return array{status:string,refund_id:string}
     */
    public function refundPayment(string $paymentId, float $amount, string $currency, string $note = ''): array
    {
        if ($this->secretKey === '') {
            throw new \RuntimeException('Checkout.com secret key is not configured.');
        }
        if ($paymentId === '') {
            throw new \RuntimeException('Checkout.com payment ID is missing.');
        }

        $minorAmount = $this->toMinorUnits($amount, $currency);
        if ($minorAmount <= 0) {
            throw new \RuntimeException('Checkout.com refund amount must be greater than zero.');
        }

        $payload = ['amount' => $minorAmount];
        if ($note !== '') {
            $payload['reference'] = mb_substr($note, 0, 100);
        }

        $http = HttpTransport::request(
            'POST',
            $this->baseUrl() . '/payments/' . rawurlencode($paymentId) . '/refunds',
            [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $this->secretKey,
            ],
            (string) json_encode($payload),
            15,
        );
        if (!$http['ok']) {
            throw new \RuntimeException('Checkout.com refund HTTP error: ' . $http['error']);
        }

        $result = json_decode($http['body'], true);
        if (!is_array($result) || empty($result['action_id'])) {
            $detail = '';
            if (!empty($result['error_codes']) && is_array($result['error_codes'])) {
                $detail = implode(', ', $result['error_codes']);
            } elseif (!empty($result['message'])) {
                $detail = $result['message'];
            }
            throw new \RuntimeException('Checkout.com refund failed: ' . ($detail ?: 'Unknown error'));
        }

        return [
            'status'    => 'pending',
            'refund_id' => (string) $result['action_id'],
        ];
    }

    private function toMinorUnits(float $amount, string $currency): int
    {
        return Money::toMinorUnits($amount, $currency);
    }

    public function handleWebhook(string $payload, string $signature): ?array
    {
        // Fail closed: without a configured secret or a missing/invalid
        // signature header, the webhook is never trusted.
        if ($this->webhookSecret === '' || $signature === '') {
            return null;
        }

        $expected = base64_encode(hash_hmac('sha256', $payload, $this->webhookSecret, true));
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $data = json_decode($payload, true);
        if (!is_array($data)) {
            return null;
        }

        $type = $data['type'] ?? '';

        if ($type !== 'payment_captured') {
            return null;
        }

        $orderNumber = $data['data']['reference'] ?? '';
        $paymentId   = $data['data']['id']        ?? '';

        if ($orderNumber === '') {
            return null;
        }

        return [
            'event'      => 'payment_succeeded',
            'order_id'   => (string) $orderNumber,
            'status'     => 'paid',
            'payment_id' => (string) $paymentId,
        ];
    }
}
