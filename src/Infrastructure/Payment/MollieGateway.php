<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Payment;

use ValeApp\Domain\Shop\Entity\Order;
use ValeApp\Domain\Shop\Port\PaymentGatewayInterface;

/**
 * Mollie gateway — uses Payments API v2 (redirect flow).
 *
 * Settings from settings (payment group):
 *   mollie_api_key, mollie_enabled
 */
final class MollieGateway implements PaymentGatewayInterface
{
    private string $baseUrl;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $redirectUrl = '',
        private readonly string $webhookUrl = '',
        private readonly bool $sandbox = false,
    ) {
        $this->baseUrl = PaymentApiUrls::fromFile()->mollieBase();
    }

    public function getName(): string
    {
        return 'mollie';
    }

    public function createPayment(Order $order): array
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException('Mollie API key is not configured.');
        }

        $payload = [
            'amount' => [
                'currency' => strtoupper($order->getCurrency()),
                'value'    => Money::formatForApi((float) $order->getTotal(), strtoupper($order->getCurrency())),
            ],
            'description'   => 'Order #' . $order->getOrderNumber(),
            'redirectUrl'   => $this->redirectUrl,
            'metadata'      => [
                'order_number' => $order->getOrderNumber(),
            ],
        ];

        if ($this->webhookUrl !== '' && !$this->sandbox) {
            $payload['webhookUrl'] = $this->webhookUrl;
        }

        $http = HttpTransport::request(
            'POST',
            $this->baseUrl . '/payments',
            [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            (string) json_encode($payload),
            15,
        );
        if (!$http['ok']) {
            throw new \RuntimeException('Mollie HTTP error: ' . $http['error']);
        }
        $result   = json_decode($http['body'], true);
        $httpCode = $http['status'];

        if ($httpCode < 200 || $httpCode >= 300 || empty($result['id'])) {
            $detail = $result['detail'] ?? $result['title'] ?? 'Unknown Mollie error';
            if (!empty($result['field'])) {
                $detail .= ' (field: ' . $result['field'] . ')';
            }
            throw new \RuntimeException('Mollie create payment failed: ' . $detail);
        }

        return [
            'redirect_url' => $result['_links']['checkout']['href'] ?? '',
            'payment_id'   => $result['id'],
        ];
    }

    public function verifyPayment(string $paymentId): array
    {
        $http = HttpTransport::request(
            'GET',
            $this->baseUrl . '/payments/' . $paymentId,
            [
                'Authorization: Bearer ' . $this->apiKey,
                'Accept: application/json',
            ],
            null,
            15,
        );
        if (!$http['ok']) {
            throw new \RuntimeException('Mollie verify HTTP error: ' . $http['error']);
        }
        $raw      = $http['body'];
        $httpCode = $http['status'];

        if ($raw === '' || $raw === false) {
            throw new \RuntimeException('Mollie verify returned empty response (HTTP ' . $httpCode . ')');
        }

        $result = json_decode((string) $raw, true);
        if (!is_array($result)) {
            throw new \RuntimeException('Mollie verify invalid JSON (HTTP ' . $httpCode . '): ' . substr((string) $raw, 0, 200));
        }

        $status = match ($result['status'] ?? '') {
            'paid'     => 'paid',
            'canceled' => 'cancelled',
            'expired'  => 'failed',
            'failed'   => 'failed',
            default    => 'pending',
        };

        return [
            'status'         => $status,
            'transaction_id' => $result['id'] ?? $paymentId,
        ];
    }

    /**
     * Refund a Mollie payment.
     *
     * @return array{status:string,refund_id:string}
     */
    public function refundPayment(string $paymentId, float $amount, string $currency, string $note = ''): array
    {
        if ($paymentId === '') {
            throw new \RuntimeException('Mollie payment ID is missing.');
        }

        $payload = [
            'amount' => [
                'currency' => strtoupper($currency),
                'value'    => Money::formatForApi($amount, $currency),
            ],
        ];
        if ($note !== '') {
            $payload['description'] = mb_substr($note, 0, 255);
        }

        $http = HttpTransport::request(
            'POST',
            $this->baseUrl . '/payments/' . rawurlencode($paymentId) . '/refunds',
            [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            (string) json_encode($payload),
            15,
        );
        if (!$http['ok']) {
            throw new \RuntimeException('Mollie refund HTTP error: ' . $http['error']);
        }

        $result = json_decode($http['body'], true);
        if (!is_array($result) || empty($result['id'])) {
            $detail = $result['detail'] ?? $result['title'] ?? 'Unknown Mollie refund error';
            throw new \RuntimeException('Mollie refund failed: ' . $detail);
        }

        return [
            'status'    => (string) ($result['status'] ?? 'pending'),
            'refund_id' => (string) $result['id'],
        ];
    }

    public function handleWebhook(string $payload, string $signature): ?array
    {
        // Mollie sends form-encoded body: id=tr_xxx  (NOT JSON)
        $data = [];
        parse_str($payload, $data);
        if (empty($data['id'])) {
            // Fallback for any future JSON format
            $json = json_decode($payload, true);
            if (is_array($json) && !empty($json['id'])) {
                $data = $json;
            }
        }

        if (empty($data['id'])) {
            return null;
        }

        $paymentId = (string) $data['id'];

        // Fetch full payment from Mollie API to get status AND metadata
        $http = HttpTransport::request(
            'GET',
            $this->baseUrl . '/payments/' . urlencode($paymentId),
            [
                'Authorization: Bearer ' . $this->apiKey,
                'Accept: application/json',
            ],
            null,
            15,
        );
        if (!$http['ok']) {
            return null;
        }
        $raw = $http['body'];

        $payment = json_decode((string) $raw, true);
        if (!is_array($payment) || ($payment['status'] ?? '') !== 'paid') {
            return null;
        }

        $orderNumber = $payment['metadata']['order_number'] ?? null;
        if ($orderNumber === null) {
            return null;
        }

        return [
            'event'      => 'payment_succeeded',
            'order_id'   => (string) $orderNumber,
            'status'     => 'paid',
            'payment_id' => $paymentId,
        ];
    }
}
