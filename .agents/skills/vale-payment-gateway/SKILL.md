---
name: vale-payment-gateway
description: "Adds a new payment gateway to Vale end-to-end by asking a few questions (provider name/slug, redirect vs client-secret flow, webhook support, refund/subscription needs, sandbox mode) and then generating the full 9-point skeleton: gateway class implementing PaymentGatewayInterface, reference-data entry, DI factory + Checkout/Order/WebhookController wiring, admin settings block, payment wizard step, storefront checkout radio, webhook route, and translation keys. USE FOR: 'add payment provider', new gateway integration, integrate PSP/checkout provider. DO NOT USE FOR: configuring existing gateways via admin settings, outbound webhooks to external systems (use vale-webhook), subscription plans/membership UI."
license: AGPL-3.0
metadata:
  project: vale/vale
  reference: vale-docs/en/latest/developer/payment-gateways.md
---

# Add a Payment Gateway

Vale's checkout flow is gateway-agnostic. A new gateway only needs: one class implementing `PaymentGatewayInterface` plus convention-based registration points. Reference implementations: `src/Infrastructure/Payment/{Stripe,PayPal,Mollie,TwoCheckoutGateway,CheckoutComGateway}.php`.

## Phase 1 — Ask (required before writing any code)

1. **Provider name + slug** — slug is used everywhere (`payment_method` column, route segments, settings keys). e.g. name "FooPay", slug `foopay`.
2. **Payment flow**: `redirect` (hosted checkout, customer approves on provider site) or `client_secret` (embedded SDK collects card in-place)?
3. **Webhooks?** If yes: signature scheme (HMAC-SHA256 header? which header name?), and event names for paid/failed.
4. **Refund support?** Subscription support? (both optional)
5. **Sandbox/test mode toggle?** API base URLs for test/live?

## Phase 2 — Generate

### Step 1 — Gateway class: `src/Infrastructure/Payment/FooPayGateway.php`

> API base URLs MUST come from the `payment_api` catalog in
> `config/reference-data.php` via `PaymentApiUrls::fromFile()` — never hardcode
> `https://api.foopay.com` in the class. Add the provider's sandbox/live bases
> to the catalog in the same change (see Step 2).

```php
<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Payment;

use ValeApp\Domain\Shop\Entity\Order;
use ValeApp\Domain\Shop\Port\PaymentGatewayInterface;

final class FooPayGateway implements PaymentGatewayInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $webhookSecret = '',
    ) {
    }

    public function getName(): string
    {
        return 'foopay';
    }

    public function createPayment(Order $order): array
    {
        // redirect flow → return ['redirect_url' => ..., 'payment_id' => ...]
        // client-secret flow → return ['client_secret' => ..., 'payment_id' => ...]
        $response = $this->apiRequest('POST', '/v1/charges', [
            'amount'       => (int) round($order->getTotal() * 100),
            'currency'     => strtolower($order->getCurrency()),
            'order_number' => $order->getOrderNumber(),
            'return_url'   => site_url('/checkout/success'),
            'cancel_url'   => site_url('/checkout/cancel'),
        ]);

        return [
            'redirect_url' => (string) ($response['approval_url'] ?? ''),
            'payment_id'   => (string) ($response['id'] ?? ''),
        ];
    }

    public function verifyPayment(string $paymentId): array
    {
        $charge = $this->apiRequest('GET', '/v1/charges/' . rawurlencode($paymentId));

        $status = match ((string) ($charge['status'] ?? '')) {
            'succeeded' => 'paid',
            'failed'    => 'failed',
            'canceled'  => 'cancelled',
            default     => 'pending',
        };

        return ['status' => $status, 'transaction_id' => (string) ($charge['id'] ?? $paymentId)];
    }

    public function handleWebhook(string $payload, string $signature): ?array
    {
        if ($this->webhookSecret === '' || !$this->isValidSignature($payload, $signature)) {
            return null;
        }
        $event = json_decode($payload, true);
        if (!is_array($event)) {
            return null;
        }

        return match ((string) ($event['event_type'] ?? '')) {
            'charge.succeeded' => [
                'event'      => 'payment_succeeded',
                'order_id'   => $event['data']['charge']['metadata']['order_number'] ?? null,
                'status'     => 'paid',
                'payment_id' => (string) ($event['data']['charge']['id'] ?? ''),
            ],
            'charge.failed' => [
                'event'      => 'payment_failed',
                'order_id'   => $event['data']['charge']['metadata']['order_number'] ?? null,
                'status'     => 'failed',
                'payment_id' => (string) ($event['data']['charge']['id'] ?? ''),
            ],
            default => null,
        };
    }

    /** @return array<string,mixed> */
    private function apiRequest(string $method, string $path, array $body = []): array
    {
        $http = HttpTransport::request(
            $method,
            PaymentApiUrls::fromFile()->foopayBase() . $path, // base URL from payment_api catalog
            ['Authorization: Bearer ' . $this->apiKey, 'Content-Type: application/json'],
            $body === [] ? null : json_encode($body),
            15,
        );
        if (!$http['ok']) {
            throw new \RuntimeException('FooPay HTTP error: ' . $http['error']);
        }
        $result = json_decode($http['body'], true);
        if (!is_array($result) || !empty($result['error'])) {
            throw new \RuntimeException('FooPay API error');
        }
        return $result;
    }

    private function isValidSignature(string $payload, string $signature): bool
    {
        $expected = hash_hmac('sha256', $payload, $this->webhookSecret);
        return hash_equals($expected, $signature);
    }
}
```

Interface contract:
- `getName()` — unique slug; matches settings keys and route segments.
- `createPayment()` — `redirect_url` XOR `client_secret`; always `payment_id`.
- `verifyPayment()` — maps provider status to exactly one of `paid|failed|cancelled|pending`.
- `handleWebhook()` — return `null` to ignore; **fail-closed** (no secret configured ⇒ reject).

Optional extras when the user asked for them (see StripeGateway): `refundPayment()`, `createSubscription()`, `cancelSubscription()`, `retrieveCheckoutSession()`.

### Step 2 — `config/reference-data.php`

Two entries per gateway:

1. Append under `signup_urls.payment_gateways` (dashboard/signup links):

```php
'foopay' => [
    'name'       => 'FooPay',
    'signup_url' => 'https://foopay.com/signup',
    'api_urls'   => [
        'test' => 'https://sandbox.foopay.com/keys',
        'live' => 'https://dashboard.foopay.com/keys',
    ],
],
```

2. Append under `payment_api` (runtime API base URLs — the single source of
truth read by `PaymentApiUrls::fromFile()`):

```php
'foopay' => [
    'api_base_sandbox' => 'https://sandbox-api.foopay.com',
    'api_base_live'    => 'https://api.foopay.com',
],
```

Then add a matching accessor on `src/Infrastructure/Payment/PaymentApiUrls.php`
(e.g. `foopayBase(bool $sandbox)` with a hardcoded fallback so verification
keeps working if the catalog entry is missing).

### Step 3 — DI factory in `config/services.php`

```php
\ValeApp\Infrastructure\Payment\FooPayGateway::class => factory(function ($c) {
    $settings = $c->get(DbalSettingsRepository::class);
    return new \ValeApp\Infrastructure\Payment\FooPayGateway(
        $settings->get('foopay_api_key', ''),
        $settings->get('foopay_webhook_secret', ''),
    );
}),
```

Settings are read from DB at runtime — admin can change keys without code edits.

### Step 4 — Controller wiring in `config/services.php`

- `CheckoutController::class` → add `get(FooPayGateway::class)` to its `gateways` constructorParameter array.
- `Front\WebhookController::class` → same `gateways` array.
- `Admin\OrderController::class` → `->constructorParameter('foopayGateway', get(FooPayGateway::class))` + declare `private readonly ?FooPayGateway $foopayGateway = null,` in the constructor + `use` import at top.
- **Optional controller dependencies must be registered explicitly.** PHP-DI falls back to the `null` default for a nullable constructor param even when the class is bound, so `Admin\PaymentController` needs `autowire()->constructorParameter('stripeGateway', get(StripeGateway::class))->constructorParameter('cache', get(CacheInterface::class))`. `ContainerSmokeTest::test_optional_class_dependencies_are_injected` fails otherwise.
- If the provider needs an admin-side helper endpoint (Stripe does: the live payment-method list for the Custom chips), put the read-only `GET` route in the `settings.view` group next to `/maintenance/stats`, keep the Stripe API knowledge in the gateway class (`StripeGateway::fetchAvailablePaymentMethods()`) and cache the response in the PSR-16 cache — never store derived API data in the settings table.

### Step 5 — Admin settings block: `resources/views/admin/settings/_ecommerce_payment.twig`

This is the ONLY admin UI (standalone `resources/views/admin/payment/wizard.twig` was removed; `GET /admin/payment` redirects to Settings → E-Commerce → Payment). Copy the Mollie card as template:

- Card wrapper MUST have `data-provider-card="<slug>"`.
- ONE form per card (`id="pay-save-<slug>"`, posts to `/admin/payment/save`) with a single `Save & Test Gateway` submit button using `formaction` pointing at `/admin/payment/test`, so it always submits the typed values — never build a second test-only form and never copy values with `document.querySelector()` JS (that pattern sent the wrong card's inputs and silently tested stale values). Bank Transfer (no API test) keeps a plain `Save` button instead.
- Header badges: `Enabled`/`Disabled` + `Tested`/`Not tested` (from `paymentConfig.<slug>.tested`, i.e. `{slug}_tested_ok`) + `★ Default` when `default_gw == '<slug>'`. Below the header a one-line status hint (`Accepting payments.` / `Test passed — switch on…` / `Save credentials, then run Save & Test.`).
- Enable switch: for API providers the switch is rendered DISABLED with hint `Test the connection first to enable.` until `{slug}_tested_ok == '1'` (a successful `POST /admin/payment/test` now auto-enables the provider). Bank Transfer instead gates on required fields (`Test the connection…`→`Complete the required fields to enable.`, badge `No test needed`).
- The `Save & Test Gateway` button is ALWAYS rendered — never wrap it in `{% if secret %}` (that was the "empty card" bug).
- Default row: when active show a non-clickable `✓ Default Gateway — Preselected at checkout.` badge; when enabled-but-not-default show `Set Default` (`POST /admin/payment/default`); when disabled show the same label as a disabled button with `Enable this provider first.` tooltip.
- Minimum fields: API key input (`name="api_key"`, posted to `/admin/payment/save`), webhook secret input, optional sandbox toggle, sign-up link from `signup_urls`. Use Tailwind classes **with `dark:` variants** on every color (AGENTS.md §16).
- Backend: add the provider to `PaymentController::buildConfig()` (`enabled` + `tested` keys) and to the `in_array(...)` allow-lists in `save`/`toggle`/`setDefault`. `SettingsController` reuses `buildConfig()` — no duplication.

### Step 6 — Wizard step: REMOVED

Do not create `resources/views/admin/payment/wizard.twig`. There is no wizard anymore by design.

### Step 7 — Storefront radio: `resources/views/shop/checkout.twig`

Add a `{% if foopay_enabled %}` radio block following the Mollie block, and extend the "no gateway enabled" fallback condition with `and not foopay_enabled`.

### Step 8 — Webhook route (only if webhooks): `routes/web.php`

```php
$g->post('/webhooks/foopay', [WebhookController::class, 'handle'])->setName('webhook.foopay');
```

If the signature scheme is non-standard, add a dedicated method on `WebhookController` that reads the right header and delegates (see `checkoutcom()` using `cko-signature`). WebhookController loops all injected gateways calling `handleWebhook()` until one returns non-null.

### Step 9 — Translations

Add the gateway's admin-facing strings to `resources/lang/en.json` (+ `tr.json`, `fr.json`), or run `composer translations` after edits.

Settings key convention (nothing to create — documented behavior):

```
{slug}_enabled | {slug}_label | {slug}_api_key | {slug}_webhook_secret | {slug}_sandbox
```

## Phase 3 — Verify

- `php -l` each touched PHP file
- `composer lint:changed`
- Boot check: instantiate through container (or hit `/admin/settings` page render)
- Manual test path: enable gateway in admin → add product to cart → checkout → confirm `createPayment` call fires
- Remind: docs update in vale-docs (`developer/payment-gateways.md` mentions built-ins) if this becomes core

## Checklist (report completion against it)

- [ ] Gateway class implements all 4 interface methods
- [ ] `reference-data.php` entry (slug === getName())
- [ ] `services.php`: factory + 3 controller wirings
- [ ] `OrderController.php`: nullable property + use import
- [ ] `_ecommerce_payment.twig` admin card (dark: variants included, `data-provider-card` + `pay-save-<slug>` conventions followed; no wizard file)
- [ ] `PaymentController::buildConfig()` + allow-lists extended with the new slug
- [ ] `checkout.twig` radio + no-gateway fallback extended
- [ ] webhook route + fail-closed verification
- [ ] lang files updated
- [ ] **All 4 themes** (default, dark, nova, vivid) updated if the gateway adds storefront UI elements — see AGENTS.md §22
