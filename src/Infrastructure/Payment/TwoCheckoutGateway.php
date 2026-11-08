<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Payment;

use ValeApp\Domain\Shop\Entity\Order;
use ValeApp\Domain\Shop\Port\PaymentGatewayInterface;

/**
 * 2Checkout (Verifone) gateway — REST API v6 + INS webhook.
 *
 * Uses X-Avangate-Authentication header with HMAC-SHA256 for API auth
 * (Merchant Code + API Secret Key).
 * Uses INS Secret Word to verify webhook notification hashes.
 *
 * Settings from settings (payment group):
 *   twocheckout_merchant_code, twocheckout_api_secret,
 *   twocheckout_secret_key (INS Secret Word), twocheckout_enabled
 */
final class TwoCheckoutGateway implements PaymentGatewayInterface
{
    // API + checkout base URLs live in config/reference-data.php (payment_api.twocheckout).

    public function __construct(
        private readonly string $merchantCode = '',
        private readonly string $apiSecret = '',
        private readonly string $secretWord = '',
        private readonly string $returnUrl = '',
        private readonly string $cancelUrl = '',
        private readonly string $webhookUrl = '',
    ) {
    }

    public function getName(): string
    {
        return 'twocheckout';
    }

    private function authHeader(): string
    {
        $date   = gmdate('Y-m-d H:i:s');
        $string = strlen($this->merchantCode) . $this->merchantCode . strlen($date) . $date;
        $hash   = hash_hmac('sha3-256', $string, $this->apiSecret);
        return 'X-Avangate-Authentication: code="' . $this->merchantCode . '" date="' . $date . '" hash="' . $hash . '" algo="sha3-256"';
    }

    public function createPayment(Order $order): array
    {
        if ($this->merchantCode === '' || $this->apiSecret === '') {
            throw new \RuntimeException('2Checkout merchant code or API secret is not configured.');
        }

        $billing = $order->getBilling();

        $payload = [
            'Currency'    => strtoupper($order->getCurrency()),
            'Language'    => 'en',
            'Country'     => $billing['country'] ?? 'US',
            'ReturnUrl'   => $this->returnUrl,
            'CancelUrl'   => $this->cancelUrl,
            'Cart'        => [
                [
                    'Name'      => 'Order #' . $order->getOrderNumber(),
                    'Price'     => ['Amount' => $order->getTotal(), 'Type' => 'CUSTOM'],
                    'Quantity'  => 1,
                    'Type'      => 'PRODUCT',
                ],
            ],
            'PaymentDetails' => ['Type' => 'RETURN'],
            'BillingAddress' => [
                'FirstName' => $billing['first_name'] ?? '',
                'LastName'  => $billing['last_name']  ?? '',
                'Email'     => $billing['email']      ?? '',
                'Country'   => $billing['country']    ?? 'US',
            ],
            'MerchantOrderNo' => (string) $order->getOrderNumber(),
        ];

        if ($this->webhookUrl !== '' && !str_contains($this->webhookUrl, 'localhost') && !str_contains($this->webhookUrl, '127.0.0.1')) {
            $payload['PaymentDetails']['Href'] = $this->webhookUrl;
        }

        $http = HttpTransport::request(
            'POST',
            PaymentApiUrls::fromFile()->twoCheckoutApiBase() . '/orders/',
            [
                'Content-Type: application/json',
                'Accept: application/json',
                $this->authHeader(),
            ],
            (string) json_encode($payload),
            15,
        );
        if (!$http['ok']) {
            throw new \RuntimeException('2Checkout HTTP error: ' . $http['error']);
        }
        $result   = json_decode($http['body'], true);
        $httpCode = $http['status'];

        if ($httpCode < 200 || $httpCode >= 300 || empty($result['RefNo'])) {
            $detail = $result['Message'] ?? $result['message'] ?? 'Unknown 2Checkout error';
            if (!empty($result['Code'])) {
                $detail .= ' (code: ' . $result['Code'] . ')';
            }
            throw new \RuntimeException('2Checkout create order failed: ' . $detail);
        }

        $refNo = (string) $result['RefNo'];

        return [
            'redirect_url' => PaymentApiUrls::fromFile()->twoCheckoutUrl() . '/purchase?refno=' . $refNo,
            'payment_id'   => $refNo,
        ];
    }

    public function verifyPayment(string $paymentId): array
    {
        if ($this->merchantCode === '' || $this->apiSecret === '') {
            throw new \RuntimeException('2Checkout merchant code or API secret is not configured.');
        }

        $http = HttpTransport::request(
            'GET',
            PaymentApiUrls::fromFile()->twoCheckoutApiBase() . '/orders/' . urlencode($paymentId),
            [
                'Accept: application/json',
                $this->authHeader(),
            ],
            null,
            15,
        );
        if (!$http['ok']) {
            throw new \RuntimeException('2Checkout verify HTTP error: ' . $http['error']);
        }
        $raw      = $http['body'];
        $httpCode = $http['status'];

        if ($raw === '' || $raw === false) {
            throw new \RuntimeException('2Checkout verify returned empty response (HTTP ' . $httpCode . ')');
        }

        $result = json_decode((string) $raw, true);
        if (!is_array($result)) {
            throw new \RuntimeException('2Checkout verify invalid JSON (HTTP ' . $httpCode . '): ' . substr((string) $raw, 0, 200));
        }

        $status = match (strtoupper($result['Status'] ?? '')) {
            'COMPLETE', 'AUTHRECEIVED' => 'paid',
            'CANCELED', 'CANCELLED'    => 'cancelled',
            'REFUND'                   => 'failed',
            default                    => 'pending',
        };

        return [
            'status'         => $status,
            'transaction_id' => $result['RefNo'] ?? $paymentId,
        ];
    }

    /**
     * Refund a 2Checkout order by RefNo.
     *
     * @return array{status:string,refund_id:string}
     */
    public function refundOrder(string $refNo, float $amount, string $currency, string $note = ''): array
    {
        if ($this->merchantCode === '' || $this->apiSecret === '') {
            throw new \RuntimeException('2Checkout merchant code or API secret is not configured.');
        }
        if ($refNo === '') {
            throw new \RuntimeException('2Checkout RefNo is missing.');
        }

        $payload = [
            'Amount'   => round($amount, 2),
            'Currency' => strtoupper($currency),
            'Comment'  => $note !== '' ? mb_substr($note, 0, 255) : 'Refund approved by admin',
        ];

        $http = HttpTransport::request(
            'POST',
            PaymentApiUrls::fromFile()->twoCheckoutApiBase() . '/orders/' . rawurlencode($refNo) . '/refund/',
            [
                'Content-Type: application/json',
                'Accept: application/json',
                $this->authHeader(),
            ],
            (string) json_encode($payload),
            15,
        );
        if (!$http['ok']) {
            throw new \RuntimeException('2Checkout refund HTTP error: ' . $http['error']);
        }

        $result = json_decode($http['body'], true);
        if (!is_array($result)) {
            throw new \RuntimeException('2Checkout refund failed: invalid response.');
        }

        if (!empty($result['Code']) || !empty($result['Message'])) {
            $detail = (string) ($result['Message'] ?? 'Unknown 2Checkout refund error');
            if (!empty($result['Code'])) {
                $detail .= ' (code: ' . $result['Code'] . ')';
            }
            throw new \RuntimeException('2Checkout refund failed: ' . $detail);
        }

        $refundId = (string) ($result['RefundReference'] ?? ($result['Reference'] ?? $refNo));

        return [
            'status'    => 'pending',
            'refund_id' => $refundId,
        ];
    }

    public function handleWebhook(string $payload, string $signature): ?array
    {
        // Fail closed: without a configured INS Secret Word the webhook is
        // never trusted. Note: the INS hash carries no timestamp, so replayed
        // notifications remain possible; order status transitions stay
        // idempotent which limits the impact.
        if ($this->secretWord === '') {
            return null;
        }

        $data = [];
        parse_str($payload, $data);

        $messageType = $data['message_type'] ?? '';

        if ($messageType === '') {
            $json = json_decode($payload, true);
            if ($json && !empty($json['MessageType'])) {
                $messageType = $json['MessageType'];
                $data        = $json;
            }
        }

        if ($messageType !== 'ORDER_CREATED') {
            return null;
        }

        $refNo       = $data['refno']        ?? $data['RefNo'] ?? '';
        $orderNumber = $data['order_number'] ?? $data['MerchantOrderNo'] ?? '';

        if ($refNo === '' || $orderNumber === '') {
            return null;
        }

        $receivedHash = strtoupper($data['md5_hash'] ?? $data['Md5Hash'] ?? '');
        if ($receivedHash === '') {
            return null;
        }

        $total        = $data['total'] ?? $data['Total'] ?? '0';
        $expectedHash = strtoupper(md5($this->secretWord . $refNo . $orderNumber . $total));

        if (!hash_equals($expectedHash, $receivedHash)) {
            error_log('[2Checkout] INS hash mismatch — webhook rejected.');
            return null;
        }

        return [
            'event'      => 'payment_succeeded',
            'order_id'   => $orderNumber,
            'status'     => 'paid',
            'payment_id' => $refNo,
        ];
    }
}
