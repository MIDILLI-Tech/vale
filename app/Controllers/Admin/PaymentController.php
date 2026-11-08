<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\SimpleCache\CacheInterface;
use Slim\Views\Twig;
use ValeApp\Infrastructure\Payment\HttpTransport;
use ValeApp\Infrastructure\Payment\PaymentApiUrls;
use ValeApp\Infrastructure\Payment\StripeGateway;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;

/**
 * Admin payment settings (single panel under Settings → E-Commerce → Payment).
 *
 * GET  /admin/payment           — legacy redirect to the settings panel
 * POST /admin/payment/save      — Save credentials
 * POST /admin/payment/test      — Test connection & auto-enable
 * POST /admin/payment/toggle    — Enable / disable a provider
 */
final class PaymentController
{
    public function __construct(
        private readonly Twig $twig,
        private readonly DbalSettingsRepository $settings,
        private readonly SessionManager $session,
        private readonly ?StripeGateway $stripeGateway = null,
        private readonly ?CacheInterface $cache = null,
    ) {
    }

    /** GET /admin/payment — legacy standalone page, now redirects to the single
     * Settings → E-Commerce → Payment panel (single-template UX). */
    public function wizard(Request $request, Response $response): Response
    {
        return $response->withHeader('Location', admin_url('/settings?tab=ecommerce&subtab=payment'))->withStatus(302);
    }

    /**
     * Shared payment config for the settings panel.
     *
     * @return array<string, array<string,string>>
     */
    public static function buildConfig(DbalSettingsRepository $settings): array
    {
        return [
            'stripe' => [
                'publishable_key'      => $settings->get('stripe_publishable_key', ''),
                'secret_key'           => $settings->get('stripe_secret_key', '') ? '••••••••' : '',
                'webhook_secret'       => $settings->get('stripe_webhook_secret', '') ? '••••••••' : '',
                'enabled'              => $settings->get('stripe_enabled', '0'),
                'tested'               => $settings->get('stripe_tested_ok', '0'),
                'label'                => $settings->get('stripe_label', 'Credit / Debit Card'),
                'payment_method_types' => $settings->get('stripe_payment_method_types', ''),
            ],
            'paypal' => [
                'client_id'     => $settings->get('paypal_client_id', ''),
                'client_secret' => $settings->get('paypal_client_secret', '') ? '••••••••' : '',
                'sandbox'       => $settings->get('paypal_sandbox', '1'),
                'enabled'       => $settings->get('paypal_enabled', '0'),
                'tested'        => $settings->get('paypal_tested_ok', '0'),
                'webhook_id'    => $settings->get('paypal_webhook_id', ''),
                'label'         => $settings->get('paypal_label', 'PayPal'),
            ],
            'mollie' => [
                'api_key' => $settings->get('mollie_api_key', '') ? '••••••••' : '',
                'sandbox' => $settings->get('mollie_sandbox', '1'),
                'enabled' => $settings->get('mollie_enabled', '0'),
                'tested'  => $settings->get('mollie_tested_ok', '0'),
                'label'   => $settings->get('mollie_label', 'Credit / Debit Card'),
            ],
            'twocheckout' => [
                'merchant_code' => $settings->get('twocheckout_merchant_code', ''),
                'api_secret'    => $settings->get('twocheckout_api_secret', '') ? '••••••••' : '',
                'secret_key'    => $settings->get('twocheckout_secret_key', '') ? '••••••••' : '',
                'enabled'       => $settings->get('twocheckout_enabled', '0'),
                'tested'        => $settings->get('twocheckout_tested_ok', '0'),
                'label'         => $settings->get('twocheckout_label', 'Credit / Debit Card'),
            ],
            'checkoutcom' => [
                'secret_key'            => $settings->get('checkoutcom_secret_key', '') ? '••••••••' : '',
                'webhook_secret'        => $settings->get('checkoutcom_webhook_secret', '') ? '••••••••' : '',
                'sandbox'               => $settings->get('checkoutcom_sandbox', '1'),
                'base_url'              => $settings->get('checkoutcom_base_url', ''),
                'processing_channel_id' => $settings->get('checkoutcom_processing_channel_id', ''),
                'enabled'               => $settings->get('checkoutcom_enabled', '0'),
                'tested'                => $settings->get('checkoutcom_tested_ok', '0'),
                'label'                 => $settings->get('checkoutcom_label', 'Credit / Debit Card'),
            ],
            'banktransfer' => [
                'label'       => $settings->get('banktransfer_label', 'Bank Transfer / EFT'),
                'bank_name'   => $settings->get('banktransfer_bank_name', ''),
                'iban'        => $settings->get('banktransfer_iban', ''),
                'holder_name' => $settings->get('banktransfer_holder_name', ''),
                'swift'       => $settings->get('banktransfer_swift', ''),
                'routing'     => $settings->get('banktransfer_routing', ''),
                'branch'      => $settings->get('banktransfer_branch', ''),
                'description' => $settings->get('banktransfer_description', ''),
                'max_days'    => $settings->get('banktransfer_max_days', '14'),
                'enabled'     => $settings->get('banktransfer_enabled', '0'),
                'tested'      => '1',
            ],
        ];
    }

    /**
     * Normalize a raw (comma/space separated) Stripe payment method type list
     * into the canonical comma-separated storage form.
     *
     * Empty input means "automatic" — the gateway then lets Stripe decide.
     * Invalid tokens (anything but [a-z0-9_]) are dropped, duplicates removed.
     */
    public static function normalizePaymentMethodTypes(string $raw): string
    {
        $types = array_values(array_unique(array_filter(
            array_map(
                static fn (string $type): string => strtolower(trim($type)),
                preg_split('/[\s,]+/', $raw) ?: []
            ),
            static fn (string $type): bool => preg_match('/^[a-z0-9_]+$/', $type) === 1
        )));

        return implode(',', $types);
    }

    /**
     * GET /admin/payment/stripe/methods — payment methods the configured
     * Stripe account can accept, used by the Custom chips of the settings panel.
     *
     * Read-only and best-effort: when Stripe cannot be reached (no key saved
     * yet, network/API failure) the curated reference-data list is returned as
     * a fallback so the panel always has something to offer. `?refresh=1`
     * bypasses the cache.
     */
    public function stripeMethods(Request $request, Response $response): Response
    {
        $catalog   = self::stripePaymentMethodCatalog();
        $refresh   = ((string) ($request->getQueryParams()['refresh'] ?? '')) === '1';
        $secretKey = (string) $this->settings->get('stripe_secret_key', '');
        $cacheKey  = 'stripe_available_methods_' . sha1($secretKey);

        $methods = $refresh ? null : $this->cache?->get($cacheKey);
        if (!is_array($methods)) {
            $methods = null;
        }

        $source = 'stripe';
        if ($methods === null) {
            $fetched = [];
            if ($secretKey !== '' && $this->stripeGateway !== null) {
                try {
                    $fetched = $this->stripeGateway->fetchAvailablePaymentMethods();
                } catch (\Throwable $e) {
                    error_log('[StripeMethods] ' . $e->getMessage());
                }
            }

            if ($fetched !== []) {
                $methods = $fetched;
                $this->cache?->set($cacheKey, $methods, 21600);
            } else {
                $source  = 'catalog';
                $methods = array_keys($catalog);
            }
        }

        $payload = [];
        foreach ($methods as $key) {
            $key       = (string) $key;
            $payload[] = [
                'key'   => $key,
                'label' => self::stripeMethodLabel($key, $catalog),
            ];
        }

        $response->getBody()->write((string) json_encode([
            'ok'      => $source === 'stripe',
            'source'  => $source,
            'methods' => $payload,
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Human-readable label for a Stripe payment method type.
     *
     * @param array<string, string> $catalog
     */
    public static function stripeMethodLabel(string $key, array $catalog = []): string
    {
        if (isset($catalog[$key])) {
            return $catalog[$key];
        }

        return ucwords(str_replace('_', ' ', $key));
    }

    /**
     * Curated Stripe method labels (config/reference-data.php).
     *
     * @return array<string, string>
     */
    private static function stripePaymentMethodCatalog(): array
    {
        $referenceData = require config_path('reference-data.php');

        return is_array($referenceData['stripe_payment_methods'] ?? null)
            ? $referenceData['stripe_payment_methods']
            : [];
    }

    /** POST /admin/payment/save */
    public function save(Request $request, Response $response): Response
    {
        $body     = (array) $request->getParsedBody();
        $provider = $body['provider'] ?? 'stripe';

        // Snapshot before persisting so we can tell real changes apart from
        // no-op saves (which must not invalidate the tested state).
        $before = [];
        foreach (self::PROVIDER_SETTING_KEYS[$provider] ?? [] as $settingKey) {
            $before[$settingKey] = $this->settings->get($settingKey);
        }

        $this->persistProvider($provider, $body);

        $changed = false;
        foreach (self::PROVIDER_SETTING_KEYS[$provider] ?? [] as $settingKey) {
            if ($this->settings->get($settingKey) !== ($before[$settingKey] ?? null)) {
                $changed = true;
                break;
            }
        }

        if (!$changed) {
            $this->session->set('flash_info', 'No changes detected.');
            $this->session->set('payment_open_card', $provider);
            return $response->withHeader('Location', admin_url('/settings?tab=ecommerce&subtab=payment'))->withStatus(302);
        }

        // Any credential/config change invalidates the previous successful test state.
        if (in_array($provider, ['stripe', 'paypal', 'mollie', 'twocheckout', 'checkoutcom', 'banktransfer'], true)) {
            $this->settings->set($provider . '_tested_ok', '0', 'payment');
        }

        $this->session->set('flash_success', 'Payment credentials saved.');
        $this->session->set('payment_open_card', $provider);
        return $response->withHeader('Location', admin_url('/settings?tab=ecommerce&subtab=payment'))->withStatus(302);
    }

    /**
     * Every settings key each provider's forms may write. Used to detect
     * whether a Save actually changed anything.
     *
     * @var array<string, list<string>>
     */
    private const PROVIDER_SETTING_KEYS = [
        'stripe' => [
            'stripe_publishable_key', 'stripe_secret_key', 'stripe_webhook_secret', 'stripe_label',
            'stripe_payment_method_types',
        ],
        'paypal' => [
            'paypal_client_id', 'paypal_client_secret', 'paypal_sandbox', 'paypal_webhook_id', 'paypal_label',
        ],
        'mollie' => [
            'mollie_api_key', 'mollie_sandbox', 'mollie_label',
        ],
        'twocheckout' => [
            'twocheckout_merchant_code', 'twocheckout_api_secret', 'twocheckout_secret_key', 'twocheckout_label',
        ],
        'checkoutcom' => [
            'checkoutcom_secret_key', 'checkoutcom_webhook_secret', 'checkoutcom_sandbox',
            'checkoutcom_base_url', 'checkoutcom_processing_channel_id', 'checkoutcom_label',
        ],
        'banktransfer' => [
            'banktransfer_label', 'banktransfer_bank_name', 'banktransfer_iban', 'banktransfer_holder_name',
            'banktransfer_swift', 'banktransfer_routing', 'banktransfer_branch', 'banktransfer_description',
            'banktransfer_max_days',
        ],
    ];

    /**
     * Persist the submitted credentials/config for a provider.
     *
     * When $partial is true (Save & Test flow with minimal payloads),
     * fields absent from the request are left untouched instead of reset.
     *
     * @param array<string, mixed> $body
     */
    private function persistProvider(string $provider, array $body, bool $partial = false): void
    {
        if ($provider === 'stripe') {
            if (array_key_exists('publishable_key', $body)) {
                $this->settings->set('stripe_publishable_key', trim((string) $body['publishable_key']), 'payment');
            }
            if (!empty($body['secret_key']) && $body['secret_key'] !== '••••••••') {
                $this->settings->set('stripe_secret_key', $body['secret_key'], 'payment');
            }
            if (!empty($body['webhook_secret']) && $body['webhook_secret'] !== '••••••••') {
                $this->settings->set('stripe_webhook_secret', $body['webhook_secret'], 'payment');
            }
            if (array_key_exists('label', $body)) {
                $this->settings->set('stripe_label', trim((string) $body['label']), 'payment');
            }
            if (array_key_exists('payment_method_types', $body)) {
                $this->settings->set(
                    'stripe_payment_method_types',
                    self::normalizePaymentMethodTypes((string) $body['payment_method_types']),
                    'payment'
                );
            }
        } elseif ($provider === 'paypal') {
            if (array_key_exists('client_id', $body)) {
                $this->settings->set('paypal_client_id', trim((string) $body['client_id']), 'payment');
            }
            if (!empty($body['client_secret']) && $body['client_secret'] !== '••••••••') {
                $this->settings->set('paypal_client_secret', $body['client_secret'], 'payment');
            }
            if (array_key_exists('sandbox', $body)) {
                $this->settings->set('paypal_sandbox', !empty($body['sandbox']) ? '1' : '0', 'payment');
            } elseif (!$partial) {
                $this->settings->set('paypal_sandbox', '0', 'payment');
            }
            if (isset($body['webhook_id'])) {
                $this->settings->set('paypal_webhook_id', trim((string) $body['webhook_id']), 'payment');
            }
            if (array_key_exists('label', $body)) {
                $this->settings->set('paypal_label', trim((string) $body['label']), 'payment');
            }
        } elseif ($provider === 'mollie') {
            if (!empty($body['api_key']) && $body['api_key'] !== '••••••••') {
                $this->settings->set('mollie_api_key', $body['api_key'], 'payment');
            }
            if (array_key_exists('sandbox', $body)) {
                $this->settings->set('mollie_sandbox', !empty($body['sandbox']) ? '1' : '0', 'payment');
            } elseif (!$partial) {
                $this->settings->set('mollie_sandbox', '0', 'payment');
            }
            if (array_key_exists('label', $body)) {
                $this->settings->set('mollie_label', trim((string) $body['label']), 'payment');
            }
        } elseif ($provider === 'twocheckout') {
            if (array_key_exists('merchant_code', $body)) {
                $this->settings->set('twocheckout_merchant_code', trim((string) $body['merchant_code']), 'payment');
            }
            if (!empty($body['api_secret']) && $body['api_secret'] !== '••••••••') {
                $this->settings->set('twocheckout_api_secret', $body['api_secret'], 'payment');
            }
            if (!empty($body['secret_key']) && $body['secret_key'] !== '••••••••') {
                $this->settings->set('twocheckout_secret_key', $body['secret_key'], 'payment');
            }
            if (array_key_exists('label', $body)) {
                $this->settings->set('twocheckout_label', trim((string) $body['label']), 'payment');
            }
        } elseif ($provider === 'checkoutcom') {
            if (!empty($body['secret_key']) && $body['secret_key'] !== '••••••••') {
                $this->settings->set('checkoutcom_secret_key', $body['secret_key'], 'payment');
            }
            if (!empty($body['webhook_secret']) && $body['webhook_secret'] !== '••••••••') {
                $this->settings->set('checkoutcom_webhook_secret', $body['webhook_secret'], 'payment');
            }
            if (array_key_exists('sandbox', $body)) {
                $this->settings->set('checkoutcom_sandbox', !empty($body['sandbox']) ? '1' : '0', 'payment');
            } elseif (!$partial) {
                $this->settings->set('checkoutcom_sandbox', '0', 'payment');
            }
            if (array_key_exists('base_url', $body)) {
                $this->settings->set('checkoutcom_base_url', trim((string) ($body['base_url'] ?? '')), 'payment');
            }
            if (array_key_exists('processing_channel_id', $body)) {
                $this->settings->set('checkoutcom_processing_channel_id', trim((string) ($body['processing_channel_id'] ?? '')), 'payment');
            }
            if (array_key_exists('label', $body)) {
                $this->settings->set('checkoutcom_label', trim((string) $body['label']), 'payment');
            }
        } elseif ($provider === 'banktransfer') {
            $this->settings->set('banktransfer_label', trim((string) ($body['label'] ?? 'Bank Transfer / EFT')), 'payment');
            $this->settings->set('banktransfer_bank_name', trim((string) ($body['bank_name'] ?? '')), 'payment');
            $this->settings->set('banktransfer_iban', trim((string) ($body['iban'] ?? '')), 'payment');
            $this->settings->set('banktransfer_holder_name', trim((string) ($body['holder_name'] ?? '')), 'payment');
            $this->settings->set('banktransfer_swift', trim((string) ($body['swift'] ?? '')), 'payment');
            $this->settings->set('banktransfer_routing', trim((string) ($body['routing'] ?? '')), 'payment');
            $this->settings->set('banktransfer_branch', trim((string) ($body['branch'] ?? '')), 'payment');
            $this->settings->set('banktransfer_description', trim((string) ($body['description'] ?? '')), 'payment');
            $rawDays   = trim((string) ($body['max_days'] ?? ''));
            $maxDays   = $rawDays === '' ? 14 : (int) $rawDays;
            $this->settings->set('banktransfer_max_days', (string) max(1, min(90, $maxDays)), 'payment');
        }
    }

    /** POST /admin/payment/test — save submitted credentials, then quick API connectivity check */
    public function test(Request $request, Response $response): Response
    {
        try {
            return $this->runConnectionTest($request, $response);
        } catch (\Throwable $e) {
            // An admin diagnostics endpoint must never 500 — surface the
            // failure as a normal flash result instead.
            error_log('[PaymentTest] ' . $e->getMessage());
            $this->settings->set(($body['provider'] ?? 'stripe') . '_tested_ok', '0', 'payment');
            $this->session->set('flash_error', 'Connection test failed unexpectedly. Please check your credentials and try again.');
            return $response->withHeader('Location', admin_url('/settings?tab=ecommerce&subtab=payment'))->withStatus(302);
        }
    }

    private function runConnectionTest(Request $request, Response $response): Response
    {
        $body     = (array) $request->getParsedBody();
        $provider = $body['provider'] ?? 'stripe';

        // "Save & Test": persist what was submitted so the tested state and
        // the stored credentials can never drift apart. Partial mode leaves
        // fields absent from the minimal test payloads untouched.
        $this->persistProvider($provider, $body, true);

        $ok          = false;
        $errorDetail = '';

        if ($provider === 'stripe') {
            $secretKey = $this->settings->get('stripe_secret_key', '');
            if ($secretKey) {
                $http = HttpTransport::request(
                    'GET',
                    PaymentApiUrls::fromFile()->stripeBase() . '/v1/balance',
                    [
                        'Accept: application/json',
                        'Authorization: Bearer ' . $secretKey,
                    ],
                );

                if (!$http['ok']) {
                    $errorDetail = $http['error'] ?: 'HTTP request failed.';
                } else {
                    $result = json_decode($http['body'], true);
                    $ok     = $http['status'] >= 200 && $http['status'] < 300 && empty($result['error']);
                    if (!$ok) {
                        $msg = is_array($result['error'] ?? null)
                            ? ((string) ($result['error']['message'] ?? 'Unknown error'))
                            : ((string) ($result['error'] ?? 'Unknown error'));
                        $errorDetail = 'Stripe says: ' . $msg . '. Stripe has no separate sandbox switch; use test keys (sk_test_...).';
                    }
                }
            } else {
                $errorDetail = 'Secret key is missing.';
            }
        } elseif ($provider === 'paypal') {
            $clientId     = $body['client_id'] ?? $this->settings->get('paypal_client_id', '');
            $bodySecret   = trim((string) ($body['client_secret'] ?? ''));
            $clientSecret = ($bodySecret !== '' && $bodySecret !== '••••••••') ? $bodySecret : $this->settings->get('paypal_client_secret', '');
            $sandbox      = $body['sandbox'] ?? $this->settings->get('paypal_sandbox', '1');
            $sandboxBool  = $sandbox === '1' || $sandbox === true;
            $baseUrl      = PaymentApiUrls::fromFile()->paypalBase($sandboxBool);

            if ($clientId && $clientSecret) {
                $http = HttpTransport::request(
                    'POST',
                    $baseUrl . '/v1/oauth2/token',
                    [
                        'Content-Type: application/x-www-form-urlencoded',
                        'Accept: application/json',
                        'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret),
                    ],
                    'grant_type=client_credentials',
                    15
                );

                if (!$http['ok']) {
                    $errorDetail = $http['error'] ?: 'HTTP request failed.';
                } else {
                    $result = json_decode($http['body'], true);
                    $ok     = !empty($result['access_token']);
                    if (!$ok) {
                        $envLabel    = $sandboxBool ? 'SANDBOX' : 'LIVE';
                        $errorDetail = 'PayPal HTTP ' . $http['status'] . ' (' . $envLabel . '): '
                            . ($result['error_description'] ?? $result['error'] ?? 'Unknown error')
                            . '. Verify your credentials match the ' . $envLabel . ' environment at developer.paypal.com.'
                            . ' Client ID starts with: ' . substr($clientId, 0, 8) . '…';
                    }
                }
            } else {
                $errorDetail = 'Client ID or Client Secret is missing.';
            }
        } elseif ($provider === 'mollie') {
            $bodyKey = trim((string) ($body['api_key'] ?? ''));
            $apiKey  = ($bodyKey !== '' && $bodyKey !== '••••••••') ? $bodyKey : $this->settings->get('mollie_api_key', '');
            if ($apiKey) {
                $http = HttpTransport::request(
                    'GET',
                    PaymentApiUrls::fromFile()->mollieBase() . '/payments?limit=1',
                    [
                        'Authorization: Bearer ' . $apiKey,
                        'Accept: application/json',
                    ],
                );

                if (!$http['ok']) {
                    $errorDetail = $http['error'] ?: 'HTTP request failed.';
                } else {
                    $result = json_decode($http['body'], true);
                    $ok     = $http['status'] >= 200 && $http['status'] < 300;
                    if (!$ok) {
                        $prefix      = substr($apiKey, 0, 8);
                        $errorDetail = 'Mollie HTTP ' . $http['status'] . ': ' . ($result['detail'] ?? $result['title'] ?? 'Unknown error')
                            . ' (key starts with: ' . $prefix . '…)';
                    }
                }
            } else {
                $errorDetail = 'API key is missing.';
            }
        } elseif ($provider === 'twocheckout') {
            $merchantCode = $body['merchant_code'] ?? $this->settings->get('twocheckout_merchant_code', '');
            $apiSecret    = $body['api_secret']    ?? $this->settings->get('twocheckout_api_secret', '');
            if ($merchantCode && $apiSecret) {
                $date   = gmdate('Y-m-d H:i:s');
                $string = strlen($merchantCode) . $merchantCode . strlen($date) . $date;
                $hash   = hash_hmac('sha3-256', $string, $apiSecret);
                $auth   = 'X-Avangate-Authentication: code="' . $merchantCode . '" date="' . $date . '" hash="' . $hash . '" algo="sha3-256"';
                $http   = HttpTransport::request(
                    'GET',
                    PaymentApiUrls::fromFile()->twoCheckoutApiBase() . '/orders/?limit=1',
                    [
                        'Accept: application/json',
                        $auth,
                    ],
                );

                if (!$http['ok']) {
                    $errorDetail = $http['error'] ?: 'HTTP request failed.';
                } else {
                    $result = json_decode($http['body'], true);
                    $ok     = $http['status'] >= 200 && $http['status'] < 300;
                    if (!$ok) {
                        $errorDetail = '2Checkout HTTP ' . $http['status'] . ': '
                            . ($result['Message'] ?? $result['message'] ?? 'Unknown error')
                            . ' (code starts with: ' . substr($merchantCode, 0, 6) . '…)';
                    }
                }
            } else {
                $errorDetail = 'Merchant code or API secret key is missing.';
            }
        } elseif ($provider === 'checkoutcom') {
            $bodyKey      = trim((string) ($body['secret_key'] ?? ''));
            $secretKey    = ($bodyKey !== '' && $bodyKey !== '••••••••') ? $bodyKey : $this->settings->get('checkoutcom_secret_key', '');
            $sandbox      = $body['sandbox'] ?? $this->settings->get('checkoutcom_sandbox', '1');
            $sandboxBool  = $sandbox === '1' || $sandbox === true;
            $bodyBaseUrl  = trim((string) ($body['base_url'] ?? ''));
            $savedBaseUrl = $this->settings->get('checkoutcom_base_url', '');
            $baseUrl      = $bodyBaseUrl !== '' ? $bodyBaseUrl
                     : ($savedBaseUrl !== '' ? $savedBaseUrl
                     : PaymentApiUrls::fromFile()->checkoutComBase($sandboxBool));
            if ($secretKey) {
                $http = HttpTransport::request(
                    'GET',
                    rtrim($baseUrl, '/') . '/workflows',
                    [
                        'Accept: application/json',
                        'Authorization: Bearer ' . $secretKey,
                    ],
                );

                if (!$http['ok']) {
                    $errorDetail = $http['error'] ?: 'HTTP request failed.';
                } else {
                    $ok = $http['status'] >= 200 && $http['status'] < 300;
                    if (!$ok) {
                        $result      = json_decode($http['body'], true);
                        $envLabel    = $sandboxBool ? 'SANDBOX' : 'LIVE';
                        $errorDetail = 'Checkout.com HTTP ' . $http['status'] . ' (' . $envLabel . '): '
                            . (is_array($result) && !empty($result['message']) ? $result['message'] : 'Check your secret key.')
                            . ' Key starts with: ' . substr($secretKey, 0, 8) . '…';
                    }
                }
            } else {
                $errorDetail = 'Secret key is missing.';
            }
        }

        if ($ok) {
            $this->settings->set($provider . '_tested_ok', '1', 'payment');
            // Successful test auto-enables the provider so Save & Test is a
            // single step instead of test-then-toggle.
            $this->settings->set($provider . '_enabled', '1', 'payment');
            $currentDefault = (string) $this->settings->get('payment_active_gateway', '');
            if ($currentDefault === '' || $this->settings->get($currentDefault . '_enabled', '0') !== '1') {
                $this->settings->set('payment_active_gateway', $provider, 'payment');
            }
            $this->session->set('flash_success', ucfirst($provider) . ' connection successful and enabled.');
            $this->session->set('payment_open_card', $provider);
        } else {
            $this->settings->set($provider . '_tested_ok', '0', 'payment');
            $msg = ucfirst($provider) . ' connection failed.';
            if (!empty($errorDetail)) {
                $msg .= ' ' . $errorDetail;
            } else {
                $msg .= ' Please check your credentials.';
            }
            $this->session->set('flash_error', $msg);
            $this->session->set('payment_open_card', $provider);
        }

        return $response->withHeader('Location', admin_url('/settings?tab=ecommerce&subtab=payment'))->withStatus(302);
    }

    /** POST /admin/payment/toggle — enable or disable a provider */
    public function toggle(Request $request, Response $response): Response
    {
        $body     = (array) $request->getParsedBody();
        $provider = $body['provider'] ?? '';
        $enabled  = ($body['enabled'] ?? '0') === '1' ? '1' : '0';

        $allowedProviders = ['stripe', 'paypal', 'mollie', 'twocheckout', 'checkoutcom', 'banktransfer'];
        if (!in_array($provider, $allowedProviders, true)) {
            $this->session->set('flash_error', 'Unknown provider.');
            return $response->withHeader('Location', admin_url('/settings?tab=ecommerce&subtab=payment'))->withStatus(302);
        }

        // Bank transfer is a manual/offline method — no API test required,
        // but required fields (bank name, holder, IBAN) must be configured.
        if ($enabled === '1') {
            if ($provider !== 'banktransfer') {
                $testedOk = $this->settings->get($provider . '_tested_ok', '0') === '1';
                if (!$testedOk) {
                    $this->session->set('flash_error', ucfirst($provider) . ' is not enabled: please run a successful connection test first.');
                    return $response->withHeader('Location', admin_url('/settings?tab=ecommerce&subtab=payment'))->withStatus(302);
                }
            } else {
                $bankName   = trim((string) $this->settings->get('banktransfer_bank_name', ''));
                $holderName = trim((string) $this->settings->get('banktransfer_holder_name', ''));
                $iban       = trim((string) $this->settings->get('banktransfer_iban', ''));
                if ($bankName === '' || $holderName === '' || $iban === '') {
                    $this->session->set('flash_error', 'Bank Transfer cannot be enabled: Bank Name, Account Holder, and IBAN are required.');
                    return $response->withHeader('Location', admin_url('/settings?tab=ecommerce&subtab=payment'))->withStatus(302);
                }
            }
        }

        $this->settings->set($provider . '_enabled', $enabled, 'payment');

        // Disabling the default gateway falls back to the first still-enabled
        // provider so checkout always has a valid pre-selection.
        if ($enabled === '0' && $this->settings->get('payment_active_gateway', '') === $provider) {
            $fallback = null;
            foreach ($allowedProviders as $candidate) {
                if ($candidate !== $provider && $this->settings->get($candidate . '_enabled', '0') === '1') {
                    $fallback = $candidate;
                    break;
                }
            }
            if ($fallback !== null) {
                $this->settings->set('payment_active_gateway', $fallback, 'payment');
            }
        }

        // First-ever enable also becomes the default when none is set yet.
        if ($enabled === '1' && $this->settings->get('payment_active_gateway', '') === '') {
            $this->settings->set('payment_active_gateway', $provider, 'payment');
        }

        $label = ucfirst($provider);
        $this->session->set('flash_success', $label . ($enabled === '1' ? ' enabled.' : ' disabled.'));
        $this->session->set('payment_open_card', $provider);
        return $response->withHeader('Location', admin_url('/settings?tab=ecommerce&subtab=payment'))->withStatus(302);
    }

    /** POST /admin/payment/default — set default active gateway */
    public function setDefault(Request $request, Response $response): Response
    {
        $body     = (array) $request->getParsedBody();
        $provider = (string) ($body['provider'] ?? '');

        if (!in_array($provider, ['stripe', 'paypal', 'mollie', 'twocheckout', 'checkoutcom', 'banktransfer'], true)) {
            $this->session->set('flash_error', 'Unknown provider.');
            return $response->withHeader('Location', admin_url('/settings?tab=ecommerce&subtab=payment'))->withStatus(302);
        }

        $isEnabled = $this->settings->get($provider . '_enabled', '0') === '1';
        if (!$isEnabled) {
            $this->session->set('flash_error', ucfirst($provider) . ' must be enabled before setting it as default.');
            return $response->withHeader('Location', admin_url('/settings?tab=ecommerce&subtab=payment'))->withStatus(302);
        }

        $this->settings->set('payment_active_gateway', $provider, 'payment');
        $this->session->set('flash_success', ucfirst($provider) . ' is now the default gateway.');
        $this->session->set('payment_open_card', $provider);

        return $response->withHeader('Location', admin_url('/settings?tab=ecommerce&subtab=payment'))->withStatus(302);
    }
}
