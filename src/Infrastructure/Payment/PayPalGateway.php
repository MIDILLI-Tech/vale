<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Payment;

use ValeApp\Domain\Shop\Entity\Order;
use ValeApp\Domain\Shop\Port\PaymentGatewayInterface;

/**
 * PayPal gateway — uses Orders v2 REST API.
 *
 * Settings from settings (payment group):
 *   paypal_client_id, paypal_client_secret, paypal_sandbox (1|0)
 */
final class PayPalGateway implements PaymentGatewayInterface
{
    private string $baseUrl;

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        bool $sandbox = true,
        private readonly string $returnUrl = '',
        private readonly string $cancelUrl = '',
        private readonly string $webhookId = '',
    ) {
        $this->baseUrl = PaymentApiUrls::fromFile()->paypalBase($sandbox);
    }

    public function getName(): string
    {
        return 'paypal';
    }

    public function createPayment(Order $order, string $returnUrl = '', string $cancelUrl = ''): array
    {
        $token = $this->getAccessToken();

        $payload = [
            'intent'         => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $order->getOrderNumber(),
                'amount'       => [
                    'currency_code' => strtoupper($order->getCurrency()),
                    'value'         => Money::formatForApi((float) $order->getTotal(), strtoupper($order->getCurrency())),
                ],
            ]],
            'application_context' => [
                'return_url' => $returnUrl ?: $this->returnUrl,
                'cancel_url' => $cancelUrl ?: $this->cancelUrl,
            ],
        ];

        $http = HttpTransport::request(
            'POST',
            $this->baseUrl . '/v2/checkout/orders',
            [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $token,
            ],
            (string) json_encode($payload),
            15,
        );
        if (!$http['ok']) {
            throw new \RuntimeException('PayPal HTTP error: ' . $http['error']);
        }
        $result = json_decode($http['body'], true);

        if (!is_array($result) || empty($result['id'])) {
            $detail = $result['message'] ?? 'Unknown PayPal error';
            if (!empty($result['details'][0]['issue'])) {
                $detail .= ' (' . $result['details'][0]['issue'];
                if (!empty($result['details'][0]['description'])) {
                    $detail .= ': ' . $result['details'][0]['description'];
                }
                $detail .= ')';
            } elseif (!empty($result['error_description'])) {
                $detail .= ': ' . $result['error_description'];
            }
            throw new \RuntimeException('PayPal create order failed: ' . $detail);
        }

        $approveUrl = '';
        foreach ($result['links'] ?? [] as $link) {
            if ($link['rel'] === 'approve') {
                $approveUrl = $link['href'];
                break;
            }
        }

        return [
            'redirect_url' => $approveUrl,
            'payment_id'   => $result['id'] ?? '',
        ];
    }

    public function verifyPayment(string $paymentId): array
    {
        $token = $this->getAccessToken();

        // Capture the order
        $http = HttpTransport::request(
            'POST',
            $this->baseUrl . '/v2/checkout/orders/' . $paymentId . '/capture',
            [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $token,
            ],
            '{}',
            15,
        );
        if (!$http['ok']) {
            throw new \RuntimeException('PayPal capture HTTP error: ' . $http['error']);
        }
        $result = json_decode($http['body'], true);

        $rawStatus = $result['status'] ?? '';
        $status    = $rawStatus === 'COMPLETED' ? 'paid' : 'pending';
        $txId      = $result['purchase_units'][0]['payments']['captures'][0]['id'] ?? $paymentId;

        return [
            'status'         => $status,
            'transaction_id' => $txId,
        ];
    }

    /**
     * Fetch a PayPal order's status WITHOUT capturing. Used as an idempotent
     * fallback when a webhook already captured the order before the browser
     * returned (capture would throw ORDER_ALREADY_CAPTURED).
     *
     * @return array{status:string,transaction_id:string}|null null on any failure
     */
    public function getOrderStatus(string $paypalOrderId): ?array
    {
        if ($paypalOrderId === '') {
            return null;
        }

        try {
            $token = $this->getAccessToken();
        } catch (\Throwable) {
            return null;
        }

        $http = HttpTransport::request(
            'GET',
            $this->baseUrl . '/v2/checkout/orders/' . rawurlencode($paypalOrderId),
            [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $token,
            ],
            '',
            15,
        );
        if (!$http['ok']) {
            return null;
        }

        $result = json_decode($http['body'], true);
        if (!is_array($result)) {
            return null;
        }

        $completed = strtoupper((string) ($result['status'] ?? '')) === 'COMPLETED';
        $txId      = $result['purchase_units'][0]['payments']['captures'][0]['id'] ?? $paypalOrderId;

        return [
            'status'         => $completed ? 'paid' : 'pending',
            'transaction_id' => (string) $txId,
        ];
    }

    /**
     * Refund a captured PayPal payment.
     *
     * @return array{status:string,refund_id:string}
     */
    public function refundCapture(string $captureId, float $amount, string $currency, string $note = ''): array
    {
        if ($captureId === '') {
            throw new \RuntimeException('PayPal capture transaction ID is missing.');
        }

        $token = $this->getAccessToken();

        $payload = [
            'amount' => [
                'value'         => Money::formatForApi($amount, $currency),
                'currency_code' => strtoupper($currency),
            ],
        ];
        if ($note !== '') {
            $payload['note_to_payer'] = mb_substr($note, 0, 255);
        }

        $http = HttpTransport::request(
            'POST',
            $this->baseUrl . '/v2/payments/captures/' . rawurlencode($captureId) . '/refund',
            [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $token,
            ],
            (string) json_encode($payload),
            15,
        );
        if (!$http['ok']) {
            throw new \RuntimeException('PayPal refund HTTP error: ' . $http['error']);
        }

        $result = json_decode($http['body'], true);
        if (!is_array($result)) {
            throw new \RuntimeException('PayPal refund failed: invalid response.');
        }

        $status = strtoupper((string) ($result['status'] ?? ''));
        if (!in_array($status, ['COMPLETED', 'PENDING'], true)) {
            $detail = (string) ($result['message'] ?? 'Unknown PayPal refund error');
            if (!empty($result['details'][0]['description'])) {
                $detail .= ': ' . $result['details'][0]['description'];
            }
            throw new \RuntimeException('PayPal refund failed: ' . $detail);
        }

        return [
            'status'    => strtolower($status),
            'refund_id' => (string) ($result['id'] ?? ''),
        ];
    }

    /**
     * Create a billing plan (subscription plan).
     *
     * @return array{id:string}
     */
    public function createBillingPlan(string $name, string $description, float $price, string $currency, string $interval = 'monthly', string $returnUrl = '', string $cancelUrl = '', int $trialDays = 0, ?float $trialPrice = null, ?float $setupFee = null): array
    {
        $token = $this->getAccessToken();

        $intervalMap = [
            'daily'   => 'DAY',
            'day'     => 'DAY',
            'weekly'  => 'WEEK',
            'week'    => 'WEEK',
            'monthly' => 'MONTH',
            'month'   => 'MONTH',
            'yearly'  => 'YEAR',
            'year'    => 'YEAR',
        ];
        $paypalInterval = $intervalMap[$interval] ?? 'MONTH';

        $definitions = [];

        if ($trialDays > 0) {
            $definitions[] = [
                'name'                 => $name . ' Trial',
                'type'                 => 'TRIAL',
                'frequency_interval'   => (string) $trialDays,
                'frequency'            => 'DAY',
                'cycles'               => '1',
                'amount'               => [
                    'currency' => strtoupper($currency),
                    'value'    => Money::formatForApi($trialPrice ?? 0.0, $currency),
                ],
            ];
        }

        $definitions[] = [
            'name'                 => $name . ' Payment',
            'type'                 => 'REGULAR',
            'frequency_interval'   => '1',
            'frequency'            => $paypalInterval,
            'cycles'               => '0',
            'amount'               => [
                'currency' => strtoupper($currency),
                'value'    => Money::formatForApi($price, $currency),
            ],
        ];

        $effectiveSetupFee = $setupFee ?? ($trialDays > 0 ? 0.0 : $price);

        $payload = [
            'name'                 => $name,
            'description'          => $description,
            'type'                 => 'INFINITE',
            'payment_definitions'  => $definitions,
            'merchant_preferences' => [
                'setup_fee'             => [
                    'currency' => strtoupper($currency),
                    'value'    => Money::formatForApi($effectiveSetupFee, $currency),
                ],
                'return_url'                 => $returnUrl ?: $this->returnUrl,
                'cancel_url'                 => $cancelUrl ?: $this->cancelUrl,
                'auto_bill_amount'           => 'YES',
                'initial_fail_amount_action' => 'CANCEL',
                'max_fail_attempts'          => '3',
            ],
        ];

        $http = HttpTransport::request(
            'POST',
            $this->baseUrl . '/v1/payments/billing-plans',
            [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $token,
            ],
            (string) json_encode($payload),
            15,
        );
        if (!$http['ok']) {
            throw new \RuntimeException('PayPal billing plan error: ' . $http['error']);
        }
        $result = json_decode($http['body'], true);
        if (!is_array($result) || empty($result['id'])) {
            $detail = is_array($result) ? ($result['message'] ?? '') : '';
            if ($detail === '' && !empty($result['details'][0]['issue'])) {
                $detail = $result['details'][0]['issue'];
                if (!empty($result['details'][0]['description'])) {
                    $detail .= ': ' . $result['details'][0]['description'];
                }
            }
            if ($detail === '' && !empty($result['name'])) {
                $detail = $result['name'];
            }
            throw new \RuntimeException('PayPal billing plan creation failed: ' . ($detail ?: 'HTTP ' . $http['status']));
        }

        // Activate the plan
        $this->apiPatch($this->baseUrl . '/v1/payments/billing-plans/' . $result['id'], $token, [
            ['op' => 'replace', 'path' => '/state', 'value' => 'ACTIVE'],
        ]);

        return ['id' => $result['id']];
    }

    /**
     * Create a billing agreement (subscription) from a billing plan.
     *
     * @return array{redirect_url:string,agreement_id:string}
     */
    public function createBillingAgreement(string $planId, string $returnUrl = '', string $cancelUrl = '', ?string $startDate = null): array
    {
        $token = $this->getAccessToken();

        if ($startDate === null) {
            $startDate = (new \DateTimeImmutable('+1 day'))->format('c');
        }

        $payload = [
            'name'          => 'Membership Subscription',
            'description'   => 'Recurring membership payment',
            'start_date'    => $startDate,
            'plan'          => ['id' => $planId],
            'payer'         => ['payment_method' => 'paypal'],
        ];

        $http = HttpTransport::request(
            'POST',
            $this->baseUrl . '/v1/payments/billing-agreements',
            [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $token,
            ],
            (string) json_encode($payload),
            15,
        );
        if (!$http['ok']) {
            throw new \RuntimeException('PayPal billing agreement error: ' . $http['error']);
        }

        $result = json_decode($http['body'], true);
        if (!is_array($result)) {
            throw new \RuntimeException('PayPal billing agreement creation failed: invalid response (HTTP ' . $http['status'] . ')');
        }

        // Try top-level id first, then extract from links.self URL
        $agreementId = $result['id'] ?? '';
        if ($agreementId === '') {
            foreach ($result['links'] ?? [] as $link) {
                if ($link['rel'] === 'self' && !empty($link['href'])) {
                    $parts       = explode('/', rtrim($link['href'], '/'));
                    $agreementId = end($parts);
                    break;
                }
            }
        }
        // Also try execute link
        if ($agreementId === '') {
            foreach ($result['links'] ?? [] as $link) {
                if ($link['rel'] === 'execute' && !empty($link['href'])) {
                    $parts       = explode('/', rtrim($link['href'], '/'));
                    $agreementId = end($parts);
                    break;
                }
            }
        }

        if ($agreementId === '') {
            throw new \RuntimeException('PayPal billing agreement creation failed: no agreement ID in response (HTTP ' . $http['status'] . ')');
        }

        $approveUrl = '';
        foreach ($result['links'] ?? [] as $link) {
            if ($link['rel'] === 'approval_url') {
                $approveUrl = $link['href'];
                break;
            }
        }

        return [
            'redirect_url'  => $approveUrl,
            'agreement_id'  => $agreementId,
        ];
    }

    /**
     * Cancel a billing agreement (subscription).
     */
    public function cancelBillingAgreement(string $agreementId): void
    {
        $token   = $this->getAccessToken();
        $payload = ['note' => 'Subscription cancelled by user.'];

        $http = HttpTransport::request(
            'POST',
            $this->baseUrl . '/v1/payments/billing-agreements/' . rawurlencode($agreementId) . '/cancel',
            [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $token,
            ],
            (string) json_encode($payload),
            15,
        );
        if (!$http['ok']) {
            throw new \RuntimeException('PayPal cancel billing agreement HTTP error: ' . $http['error']);
        }
        // Cancel returns empty 204 on success
    }

    /**
     * @return array{id:string,state:string}|null null if not found or error
     */
    public function getBillingAgreementStatus(string $agreementId): ?array
    {
        $token = $this->getAccessToken();

        $http = HttpTransport::request(
            'GET',
            $this->baseUrl . '/v1/payments/billing-agreements/' . rawurlencode($agreementId),
            [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $token,
            ],
            '',
            15,
        );

        if (!$http['ok'] || $http['status'] === 404) {
            return null;
        }

        $result = json_decode($http['body'], true);
        if (!is_array($result) || empty($result['id'])) {
            return null;
        }

        return [
            'id'    => $result['id'],
            'state' => $result['state'] ?? 'Unknown',
        ];
    }

    /**
     * Execute a billing agreement after customer approval (called with the token).
     *
     * @return array{id:string,state:string,payer_email?:string}
     */
    public function executeBillingAgreement(string $agreementToken): array
    {
        $token = $this->getAccessToken();

        $http = HttpTransport::request(
            'POST',
            $this->baseUrl . '/v1/payments/billing-agreements/' . rawurlencode($agreementToken) . '/agreement-execute',
            [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $token,
            ],
            '{}',
            15,
        );
        if (!$http['ok']) {
            throw new \RuntimeException('PayPal agreement execute HTTP error: ' . $http['error']);
        }
        $result = json_decode($http['body'], true);
        if (!is_array($result) || empty($result['id'])) {
            error_log('PayPal executeBillingAgreement response: ' . $http['body']);
            $detail = is_array($result) ? ($result['message'] ?? '') : '';
            if ($detail === '' && !empty($result['details'][0]['issue'])) {
                $detail = $result['details'][0]['issue'];
                if (!empty($result['details'][0]['description'])) {
                    $detail .= ': ' . $result['details'][0]['description'];
                }
            }
            if ($detail === '' && !empty($result['name'])) {
                $detail = $result['name'];
            }
            $bodyPreview = mb_substr($http['body'], 0, 1500);
            throw new \RuntimeException(
                'PayPal agreement execute failed (HTTP ' . $http['status'] . '): '
                . ($detail ?: 'unknown error')
                . ' | Response: ' . $bodyPreview
            );
        }

        return [
            'id'           => $result['id'],
            'state'        => $result['state']                        ?? 'Active',
            'payer_email'  => $result['payer']['payer_info']['email'] ?? '',
        ];
    }

    /**
     * Verify a webhook against PayPal's verify-webhook-signature API.
     * Fails closed: missing webhook ID, missing headers, API errors or an
     * unsuccessful verification status all count as invalid.
     *
     * @param array<string, string> $headers transmission headers from the request
     */
    public function verifyWebhook(array $headers, string $payload): bool
    {
        if ($this->webhookId === '') {
            return false;
        }

        $required = ['auth_algo', 'cert_url', 'transmission_id', 'transmission_sig', 'transmission_time'];
        foreach ($required as $key) {
            if (($headers[$key] ?? '') === '') {
                return false;
            }
        }

        $event = json_decode($payload, true);
        if (!is_array($event)) {
            return false;
        }

        try {
            $token = $this->getAccessToken();
        } catch (\Throwable) {
            return false;
        }

        $http = HttpTransport::request(
            'POST',
            $this->baseUrl . '/v1/notifications/verify-webhook-signature',
            [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $token,
            ],
            (string) json_encode([
                'auth_algo'         => $headers['auth_algo'],
                'cert_url'          => $headers['cert_url'],
                'transmission_id'   => $headers['transmission_id'],
                'transmission_sig'  => $headers['transmission_sig'],
                'transmission_time' => $headers['transmission_time'],
                'webhook_id'        => $this->webhookId,
                'webhook_event'     => $event,
            ]),
            15,
        );

        if (!$http['ok']) {
            return false;
        }

        $result = json_decode($http['body'], true);

        return is_array($result)
            && strtoupper((string) ($result['verification_status'] ?? '')) === 'SUCCESS';
    }

    public function handleWebhook(string $payload, string $signature): ?array
    {
        $data = json_decode($payload, true);
        if (!$data) {
            return null;
        }

        $eventType = $data['event_type'] ?? '';

        if ($eventType === 'CHECKOUT.ORDER.APPROVED') {
            $paypalOrderId = $data['resource']['id']                                ?? '';
            $orderNumber   = $data['resource']['purchase_units'][0]['reference_id'] ?? null;

            if ($paypalOrderId === '' || $orderNumber === null) {
                return null;
            }

            try {
                $result = $this->verifyPayment($paypalOrderId);
            } catch (\Throwable) {
                return null;
            }

            if ($result['status'] !== 'paid') {
                return null;
            }

            return [
                'event'      => 'payment_succeeded',
                'order_id'   => $orderNumber,
                'status'     => 'paid',
                'payment_id' => $result['transaction_id'],
            ];
        }

        if ($eventType === 'BILLING.SUBSCRIPTION.PAYMENT.SUCCEEDED') {
            $subId  = $data['resource']['id'] ?? '';
            $amount = $data['resource']['amount']['total']
                ?? $data['resource']['amount']['value']
                ?? 0;

            if ($subId === '' || (float) $amount <= 0) {
                return null;
            }

            return [
                'event'           => 'invoice_paid',
                'gateway'         => 'paypal',
                'subscription_id' => $subId,
                'amount'          => (float) $amount * 100, // PayPal sends dollars, convert to cents
                'currency'        => strtoupper((string) ($data['resource']['amount']['currency'] ?? '')),
                'transaction_ref' => $data['resource']['id'] ?? '',
            ];
        }

        if (str_starts_with($eventType, 'BILLING.SUBSCRIPTION.')) {
            $subId = $data['resource']['id'] ?? '';

            if ($subId === '') {
                return null;
            }

            // Map PayPal events onto the local status vocabulary. Unknown
            // events return null (ignored) instead of being treated as active.
            $stripeStatus = match ($eventType) {
                'BILLING.SUBSCRIPTION.ACTIVATED'      => 'active',
                'BILLING.SUBSCRIPTION.CANCELLED'      => 'canceled',
                'BILLING.SUBSCRIPTION.SUSPENDED'      => 'past_due',
                'BILLING.SUBSCRIPTION.EXPIRED'        => 'canceled',
                'BILLING.SUBSCRIPTION.PAYMENT.FAILED' => 'past_due',
                default                               => null,
            };

            if ($stripeStatus === null) {
                return null;
            }

            return [
                'event'               => 'subscription_updated',
                'subscription_id'     => $subId,
                'subscription_status' => $stripeStatus,
            ];
        }

        return null;
    }

    /**
     * Execute a JSON PATCH request.
     */
    private function apiPatch(string $url, string $token, array $patches): void
    {
        $http = HttpTransport::request(
            'PATCH',
            $url,
            [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $token,
            ],
            (string) json_encode($patches),
            15,
        );

        if (!$http['ok']) {
            throw new \RuntimeException('PayPal PATCH error: ' . $http['error']);
        }

        if ($http['status'] >= 400) {
            $result = json_decode($http['body'], true);
            $detail = is_array($result) ? ($result['message'] ?? 'HTTP ' . $http['status']) : 'HTTP ' . $http['status'];
            throw new \RuntimeException('PayPal PATCH failed: ' . $detail);
        }
    }

    private function getAccessToken(): string
    {
        $http = HttpTransport::request(
            'POST',
            $this->baseUrl . '/v1/oauth2/token',
            [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
                'Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
            ],
            'grant_type=client_credentials',
            15,
        );
        if (!$http['ok']) {
            throw new \RuntimeException('PayPal auth HTTP error: ' . $http['error']);
        }
        $result = json_decode($http['body'], true);

        if (!is_array($result) || empty($result['access_token'])) {
            $detail = $result['error_description'] ?? $result['error'] ?? 'Unknown PayPal auth error';
            throw new \RuntimeException('PayPal auth failed: ' . $detail);
        }

        return $result['access_token'];
    }
}
