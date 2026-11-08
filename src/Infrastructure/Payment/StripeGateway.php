<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Payment;

use ValeApp\Domain\Shop\Entity\Order;
use ValeApp\Domain\Shop\Port\PaymentGatewayInterface;

/**
 * Stripe gateway — uses Payment Intents API.
 *
 * Settings are read from the settings table (payment group):
 *   stripe_secret_key, stripe_publishable_key, stripe_webhook_secret,
 *   stripe_payment_method_types (comma-separated, e.g. "card"; empty = automatic)
 */
final class StripeGateway implements PaymentGatewayInterface
{
    /**
     * Entries Stripe returns in a payment method configuration that are card
     * wallets or card brands rather than valid `payment_method_types` values.
     * Passing them in a PaymentIntent request would fail.
     */
    private const NON_PAYMENT_METHOD_KEYS = ['apple_pay', 'google_pay', 'cartes_bancaires'];

    public function __construct(
        private readonly string $secretKey,
        private readonly string $webhookSecret = '',
        /** @var list<string> Explicit Stripe payment method types. Empty = automatic. */
        private readonly array $paymentMethodTypes = [],
    ) {
    }

    #[\Override]
    public function getName(): string
    {
        return 'stripe';
    }

    /**
     * Fetch the payment method types this Stripe account can accept.
     *
     * Reads the account's default payment method configuration. A method with
     * `available: true` has both an active capability and its Dashboard display
     * preference switched on, so the result is exactly what Stripe would offer
     * at checkout today (already filtered to the account's country).
     *
     * @return list<string>
     */
    public function fetchAvailablePaymentMethods(): array
    {
        $list = $this->apiRequest('GET', '/v1/payment_method_configurations?limit=10');

        $configurations = $list['data'] ?? null;
        if (!is_array($configurations)) {
            return [];
        }

        $default = null;
        foreach ($configurations as $configuration) {
            if (is_array($configuration) && !empty($configuration['is_default'])) {
                $default = $configuration;
                break;
            }
        }
        $default ??= $configurations[0] ?? null;

        return is_array($default) ? self::extractAvailableMethods($default) : [];
    }

    /**
     * Pure parser for a Stripe payment method configuration object.
     *
     * @param  array<string, mixed> $configuration
     * @return list<string>
     */
    public static function extractAvailableMethods(array $configuration): array
    {
        $methods = [];
        foreach ($configuration as $key => $value) {
            if (!is_string($key) || !is_array($value) || !array_key_exists('available', $value)) {
                continue;
            }
            if (in_array($key, self::NON_PAYMENT_METHOD_KEYS, true)) {
                continue;
            }
            if (!empty($value['available'])) {
                $methods[] = $key;
            }
        }

        sort($methods);

        return array_values($methods);
    }

    #[\Override]
    public function createPayment(Order $order): array
    {
        $params = $this->buildPaymentIntentParams($order);

        $intent = $this->apiRequest(
            'POST',
            '/v1/payment_intents',
            http_build_query($this->flattenParams($params)),
            'application/x-www-form-urlencoded',
        );

        return [
            'client_secret' => (string) ($intent['client_secret'] ?? ''),
            'payment_id'    => (string) ($intent['id'] ?? ''),
        ];
    }

    /**
     * Build PaymentIntent creation params (pure, no HTTP).
     *
     * When explicit payment method types are configured they are sent as
     * payment_method_types — Stripe forbids combining them with
     * automatic_payment_methods. Otherwise Stripe decides dynamically,
     * which may surface local methods like iDEAL regardless of the
     * billing/shipping address passed.
     *
     * @return array<string, mixed>
     */
    public function buildPaymentIntentParams(Order $order): array
    {
        // Minor units with zero-decimal currency support — charging JPY with
        // a x100 multiplier would overcharge customers 100x.
        $amountCents = Money::toMinorUnits((float) $order->getTotal(), strtoupper($order->getCurrency()));

        $billing      = $order->getBilling();
        $shippingAddr = $order->getShippingAddress();

        $params = [
            'amount'   => $amountCents,
            'currency' => strtolower($order->getCurrency()),
            'metadata' => ['order_number' => $order->getOrderNumber()],
        ];

        if ($this->paymentMethodTypes !== []) {
            $params['payment_method_types'] = array_values($this->paymentMethodTypes);
        } else {
            $params['automatic_payment_methods'] = ['enabled' => 'true'];
        }

        $email = trim((string) ($billing['email'] ?? ''));
        if ($email !== '') {
            $params['receipt_email'] = $email;
        }

        $shippingName       = trim(($shippingAddr['first_name'] ?? $billing['first_name'] ?? '') . ' ' . ($shippingAddr['last_name'] ?? $billing['last_name'] ?? ''));
        $shippingCountry    = strtoupper(trim((string) ($shippingAddr['country'] ?? $billing['country'] ?? '')));
        $shippingLine1      = trim((string) ($shippingAddr['address'] ?? $billing['address'] ?? ''));
        $shippingCity       = trim((string) ($shippingAddr['city'] ?? $billing['city'] ?? ''));
        $shippingState      = trim((string) ($shippingAddr['state'] ?? $billing['state'] ?? ''));
        $shippingPostalCode = trim((string) ($shippingAddr['postcode'] ?? $billing['postcode'] ?? ''));

        if ($shippingName !== '' || $shippingCountry !== '' || $shippingLine1 !== '') {
            $address = array_filter([
                'line1'       => $shippingLine1      !== '' ? $shippingLine1 : null,
                'city'        => $shippingCity       !== '' ? $shippingCity : null,
                'state'       => $shippingState      !== '' ? $shippingState : null,
                'postal_code' => $shippingPostalCode !== '' ? $shippingPostalCode : null,
                'country'     => $shippingCountry    !== '' ? $shippingCountry : null,
            ], static fn ($v) => $v !== null);

            $shippingData = array_filter([
                'name'    => $shippingName !== '' ? $shippingName : null,
                'address' => !empty($address) ? $address : null,
            ], static fn ($v) => $v !== null);

            if (!empty($shippingData)) {
                $params['shipping'] = $shippingData;
            }
        }

        return $params;
    }

    #[\Override]
    public function verifyPayment(string $paymentId): array
    {
        $intent = $this->apiRequest(
            'GET',
            '/v1/payment_intents/' . rawurlencode($paymentId),
        );

        $status = match ((string) ($intent['status'] ?? '')) {
            'succeeded' => 'paid',
            'canceled'  => 'cancelled',
            default     => 'pending',
        };

        return [
            'status'         => $status,
            'transaction_id' => (string) ($intent['id'] ?? $paymentId),
        ];
    }

    /**
     * Refund a Stripe payment intent.
     *
     * @return array{status:string,refund_id:string}
     */
    public function refundPaymentIntent(string $paymentIntentId, float $amount, string $currency, string $note = ''): array
    {
        if ($paymentIntentId === '') {
            throw new \RuntimeException('Stripe payment intent is missing.');
        }

        $minorAmount = $this->toMinorUnits($amount, $currency);
        if ($minorAmount <= 0) {
            throw new \RuntimeException('Stripe refund amount must be greater than zero.');
        }

        $params = [
            'payment_intent' => $paymentIntentId,
            'amount'         => $minorAmount,
            'reason'         => 'requested_by_customer',
        ];
        if ($note !== '') {
            $params['metadata'] = ['note' => mb_substr($note, 0, 500)];
        }

        $refund = $this->apiRequest(
            'POST',
            '/v1/refunds',
            http_build_query($params),
            'application/x-www-form-urlencoded',
        );

        return [
            'status'    => (string) ($refund['status'] ?? 'pending'),
            'refund_id' => (string) ($refund['id'] ?? ''),
        ];
    }

    /**
     * Refund the latest payment from a Stripe subscription.
     * Resolves subscription ID (sub_...) to its latest payment intent and refunds it.
     *
     * @return array{status:string,refund_id:string}
     */
    public function refundBySubscription(string $subscriptionId, float $amount, string $currency, string $note = ''): array
    {
        if ($subscriptionId === '') {
            throw new \RuntimeException('Stripe subscription ID is missing.');
        }

        // Retrieve the subscription to get the latest invoice
        $subscription = $this->apiRequest(
            'GET',
            '/v1/subscriptions/' . rawurlencode($subscriptionId),
        );

        $latestInvoiceId = $subscription['latest_invoice'] ?? '';
        if ($latestInvoiceId === '') {
            throw new \RuntimeException('No invoice found for this subscription.');
        }

        // Retrieve the invoice to get the payment intent
        $invoice = $this->apiRequest(
            'GET',
            '/v1/invoices/' . rawurlencode($latestInvoiceId),
        );

        $paymentIntentId = $invoice['payment_intent'] ?? '';
        if ($paymentIntentId === '') {
            throw new \RuntimeException('No payment intent found for this invoice.');
        }

        return $this->refundPaymentIntent($paymentIntentId, $amount, $currency, $note);
    }

    private function toMinorUnits(float $amount, string $currency): int
    {
        return Money::toMinorUnits($amount, $currency);
    }

    #[\Override]
    public function handleWebhook(string $payload, string $signature): ?array
    {
        if ($this->webhookSecret === '' || !$this->isValidWebhookSignature($payload, $signature, $this->webhookSecret)) {
            return null;
        }

        $event = json_decode($payload, true);
        if (!is_array($event)) {
            return null;
        }

        $type   = (string) ($event['type'] ?? '');
        $intent = $event['data']['object'] ?? null;
        if (!is_array($intent)) {
            return null;
        }

        if ($type === 'checkout.session.completed') {
            return [
                'event'          => 'checkout_session_completed',
                'session_id'     => (string) ($intent['id'] ?? ''),
                'subscription'   => $intent['subscription'] ?? null,
                'customer'       => $intent['customer']     ?? '',
                'payment_status' => (string) ($intent['payment_status'] ?? ''),
                'amount_total'   => (int) ($intent['amount_total'] ?? 0),
                'amount'         => (int) ($intent['amount_total'] ?? 0),
                'currency'       => strtoupper((string) ($intent['currency'] ?? '')),
                'metadata'       => $intent['metadata'] ?? [],
            ];
        }

        if ($type === 'payment_intent.succeeded') {
            return [
                'event'      => 'payment_succeeded',
                'order_id'   => $intent['metadata']['order_number'] ?? null,
                'status'     => 'paid',
                'payment_id' => (string) ($intent['id'] ?? ''),
            ];
        }

        if ($type === 'payment_intent.payment_failed') {
            return [
                'event'      => 'payment_failed',
                'order_id'   => $intent['metadata']['order_number'] ?? null,
                'status'     => 'failed',
                'payment_id' => (string) ($intent['id'] ?? ''),
            ];
        }

        if ($type === 'customer.subscription.updated' || $type === 'customer.subscription.deleted') {
            $subId             = $intent['id'] ?? '';
            $status            = (string) ($intent['status'] ?? '');
            $cancelAtPeriodEnd = !empty($intent['cancel_at_period_end']);
            return [
                'event'                => 'subscription_' . ($type === 'customer.subscription.deleted' ? 'deleted' : 'updated'),
                'subscription_id'      => $subId,
                'subscription_status'  => $status,
                'cancel_at_period_end' => $cancelAtPeriodEnd,
                'customer'             => $intent['customer'] ?? '',
            ];
        }

        if ($type === 'invoice.payment_succeeded') {
            $subId       = $intent['subscription'] ?? '';
            $customerId  = $intent['customer']     ?? '';
            $amountPaid  = $intent['amount_paid']  ?? 0;
            $invoiceId   = $intent['id']           ?? '';
            return [
                'event'             => 'invoice_paid',
                'subscription_id'   => $subId,
                'customer'          => $customerId,
                'amount'            => $amountPaid,
                'currency'          => strtoupper((string) ($intent['currency'] ?? '')),
                'invoice_id'        => $invoiceId,
            ];
        }

        return null; // Unhandled event type
    }

    /**
     * Create a Stripe customer with the given email/name.
     *
     * @return array{id:string,email:string}
     */
    public function createCustomer(string $email, string $name = ''): array
    {
        $params = ['email' => $email];
        if ($name !== '') {
            $params['name'] = $name;
        }

        $customer = $this->apiRequest(
            'POST',
            '/v1/customers',
            http_build_query($params),
            'application/x-www-form-urlencoded',
        );

        return [
            'id'    => (string) ($customer['id'] ?? ''),
            'email' => (string) ($customer['email'] ?? $email),
        ];
    }

    /**
     * Create a Stripe InvoiceItem (one-time charge) on a customer.
     * InvoiceItems are automatically included in the next invoice.
     */
    public function createInvoiceItem(string $customerId, int $amountCents, string $currency, string $description): array
    {
        $params = [
            'customer'    => $customerId,
            'amount'      => $amountCents,
            'currency'    => strtolower($currency),
            'description' => $description,
        ];

        $item = $this->apiRequest(
            'POST',
            '/v1/invoiceitems',
            http_build_query($params),
            'application/x-www-form-urlencoded',
        );

        return [
            'id'          => (string) ($item['id'] ?? ''),
            'amount'      => (int) ($item['amount'] ?? 0),
            'currency'    => (string) ($item['currency'] ?? $currency),
            'description' => (string) ($item['description'] ?? $description),
        ];
    }

    /**
     * Create a Stripe subscription for an existing customer.
     *
     * @param  string         $customerId  Stripe customer ID
     * @param  int            $amountCents Price in cents
     * @param  string         $currency    Currency code (e.g. "usd")
     * @param  string|null    $interval    "month", "year", or null for one_time
     * @param  int|null       $trialDays   Number of free trial days (0 = no trial)
     * @return array{id:string,status:string,latest_invoice?:array}
     */
    public function createSubscription(string $customerId, int $amountCents, string $currency = 'usd', ?string $interval = 'month', ?int $trialDays = null): array
    {
        $priceParams = [
            'currency'     => strtolower($currency),
            'unit_amount'  => $amountCents,
            'product_data' => ['name' => 'Membership'],
        ];

        if ($interval !== null) {
            $stripeInterval = match ($interval) {
                'daily'  => 'day',
                'weekly' => 'week',
                'yearly' => 'year',
                default  => 'month',
            };
            $priceParams['recurring'] = ['interval' => $stripeInterval, 'interval_count' => 1];
        }

        $price = $this->apiRequest(
            'POST',
            '/v1/prices',
            http_build_query($this->flattenParams($priceParams)),
            'application/x-www-form-urlencoded',
        );

        $subParams = [
            'customer' => $customerId,
            'items'    => [['price' => $price['id']]],
        ];

        if ($trialDays !== null && $trialDays > 0) {
            $subParams['trial_period_days'] = $trialDays;
        }

        $subscription = $this->apiRequest(
            'POST',
            '/v1/subscriptions',
            http_build_query($this->flattenParams($subParams)),
            'application/x-www-form-urlencoded',
        );

        return [
            'id'              => (string) ($subscription['id'] ?? ''),
            'status'          => (string) ($subscription['status'] ?? 'incomplete'),
            'latest_invoice'  => $subscription['latest_invoice'] ?? [],
            'customer'        => $customerId,
        ];
    }

    /**
     * Create a Stripe Checkout Session for subscription.
     *
     * @return array{id:string,url:string,customer:string,subscription?:string}
     */
    public function createCheckoutSession(
        string $customerId,
        int $amountCents,
        string $currency = 'usd',
        ?string $interval = 'month',
        ?int $trialDays = null,
        string $successUrl = '',
        string $cancelUrl = '',
        int $planId = 0,
        int $userId = 0,
        string $couponCode = '',
        int $discountCents = 0,
    ): array {
        $priceData = [
            'currency'     => strtolower($currency),
            'unit_amount'  => $amountCents,
            'product_data' => ['name' => 'Membership'],
        ];

        if ($interval !== null) {
            $stripeInterval = match ($interval) {
                'daily'  => 'day',
                'weekly' => 'week',
                'yearly' => 'year',
                default  => 'month',
            };
            $priceData['recurring'] = ['interval' => $stripeInterval, 'interval_count' => 1];
        }

        $mode = $interval !== null ? 'subscription' : 'payment';

        $params = [
            'mode'                        => $mode,
            'customer'                    => $customerId,
            'line_items'                  => [['price_data' => $priceData, 'quantity' => 1]],
            'success_url'                 => $successUrl,
            'cancel_url'                  => $cancelUrl,
            'metadata'                    => array_filter([
                'plan_id'        => (string) $planId,
                'user_id'        => (string) $userId,
                'coupon_code'    => $couponCode !== '' ? $couponCode : null,
                'discount_cents' => $discountCents > 0 ? (string) $discountCents : null,
            ], static fn ($v) => $v !== null),
        ];

        if ($mode === 'subscription' && $trialDays !== null && $trialDays > 0) {
            $params['subscription_data'] = ['trial_period_days' => $trialDays];
        }

        $session = $this->apiRequest(
            'POST',
            '/v1/checkout/sessions',
            http_build_query($this->flattenParams($params)),
            'application/x-www-form-urlencoded',
        );

        return [
            'id'           => (string) ($session['id'] ?? ''),
            'url'          => (string) ($session['url'] ?? ''),
            'customer'     => $customerId,
            'subscription' => $session['subscription'] ?? null,
        ];
    }

    public function retrieveCheckoutSession(string $sessionId): array
    {
        return $this->apiRequest(
            'GET',
            '/v1/checkout/sessions/' . rawurlencode($sessionId),
        );
    }

    public function getSubscriptionStatus(string $subscriptionId): ?array
    {
        try {
            $result = $this->apiRequest(
                'GET',
                '/v1/subscriptions/' . rawurlencode($subscriptionId),
            );

            return [
                'id'     => (string) ($result['id'] ?? $subscriptionId),
                'status' => (string) ($result['status'] ?? ''),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    public function cancelSubscription(string $subscriptionId): array
    {
        $result = $this->apiRequest(
            'POST',
            '/v1/subscriptions/' . rawurlencode($subscriptionId),
            http_build_query(['cancel_at_period_end' => 'true']),
            'application/x-www-form-urlencoded',
        );

        return [
            'id'     => (string) ($result['id'] ?? $subscriptionId),
            'status' => (string) ($result['status'] ?? 'active'),
        ];
    }

    public function cancelImmediately(string $subscriptionId): array
    {
        $result = $this->apiRequest(
            'DELETE',
            '/v1/subscriptions/' . rawurlencode($subscriptionId),
        );

        return [
            'id'     => (string) ($result['id'] ?? $subscriptionId),
            'status' => (string) ($result['status'] ?? 'canceled'),
        ];
    }

    /**
     * @param array<string,mixed> $params
     * @param string              $prefix
     * @return array<string,string>
     */
    private function flattenParams(array $params, string $prefix = ''): array
    {
        $result = [];
        foreach ($params as $key => $value) {
            $fullKey = $prefix !== '' ? $prefix . '[' . $key . ']' : $key;
            if (is_array($value)) {
                $result = array_merge($result, $this->flattenParams($value, $fullKey));
            } else {
                $result[$fullKey] = (string) $value;
            }
        }
        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    private function apiRequest(string $method, string $path, ?string $body = null, string $contentType = 'application/json'): array
    {
        if ($this->secretKey === '') {
            throw new \RuntimeException('Stripe secret key is not configured.');
        }

        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->secretKey,
        ];
        if ($method === 'POST') {
            $headers[] = 'Content-Type: ' . $contentType;
        }

        $http = HttpTransport::request(
            $method,
            PaymentApiUrls::fromFile()->stripeBase() . $path,
            $headers,
            $body,
            15,
        );

        if (!$http['ok']) {
            throw new \RuntimeException('Stripe HTTP error: ' . $http['error']);
        }

        $result = json_decode($http['body'], true);
        if (!is_array($result)) {
            throw new \RuntimeException('Stripe API returned invalid JSON.');
        }

        if (!empty($result['error'])) {
            $err     = $result['error'];
            $message = is_array($err)
                ? (string) ($err['message'] ?? 'Unknown Stripe API error')
                : (string) $err;
            throw new \RuntimeException('Stripe API error: ' . $message);
        }

        if ($http['status'] < 200 || $http['status'] >= 300) {
            throw new \RuntimeException('Stripe API failed with HTTP ' . $http['status'] . '.');
        }

        return $result;
    }

    private function isValidWebhookSignature(string $payload, string $signatureHeader, string $secret, int $toleranceSeconds = 300): bool
    {
        if ($signatureHeader === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $signatureHeader) as $piece) {
            $kv = explode('=', trim($piece), 2);
            if (count($kv) === 2) {
                $parts[$kv[0]][] = $kv[1];
            }
        }

        $timestamp = isset($parts['t'][0]) ? (int) $parts['t'][0] : 0;
        $v1s       = $parts['v1'] ?? [];
        if ($timestamp <= 0 || empty($v1s)) {
            return false;
        }

        if (abs(time() - $timestamp) > $toleranceSeconds) {
            return false;
        }

        $signedPayload = $timestamp . '.' . $payload;
        $expected      = hash_hmac('sha256', $signedPayload, $secret);
        foreach ($v1s as $sig) {
            if (hash_equals($expected, $sig)) {
                return true;
            }
        }

        return false;
    }
}
