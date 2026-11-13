---
name: vale-webhook
description: "Works with Vale webhooks in both directions. CONSUMER: builds an external receiver endpoint that safely consumes Vale's outbound webhooks — verifies the X-VL-Signature sha256 HMAC header, parses the {event, timestamp, data} envelope, handles the event catalog (order.paid, order.status_changed, post.*, product.*, user.registered, form.submitted, subscriber.created). PRODUCER: adds a new internal event to Vale's outbound webhook system (register in OutboundWebhookService::AVAILABLE_EVENTS so it appears in the admin UI, fire via EventDispatcher addAction wiring in bootstrap, dispatch payloads). USE FOR: 'receive Vale webhooks', verify webhook signature, 'add new webhook event', integrate Vale with external systems. DO NOT USE FOR: payment provider inbound webhooks (use vale-payment-gateway), REST API polling (use vale-api-endpoint), admin-configured test deliveries."
license: AGPL-3.0
metadata:
  project: vale/vale
  reference: vale-docs/en/latest/developer/webhooks.md
---

# Webhooks (consume or produce)

Two distinct flows. **Always ask which direction first.**

## Phase 1 — Ask

1. **Direction:** consuming Vale's outbound webhooks externally, or adding a new internal event that fires outbound webhooks?
2. Consumer → target language/runtime for the receiver skeleton? Which events to handle?
3. Producer → new event name (`module.action`, e.g. `comment.created`)? Payload fields? Where in the code is it triggered?

## Direction A — Consumer: external receiver endpoint

### Request format (what Vale sends)

- `POST` (or configured method) with JSON body; default envelope when no custom body template:

```json
{
  "event": "order.paid",
  "timestamp": "2026-08-26T12:00:00+00:00",
  "data": { }
}
```

- Headers: `Content-Type: application/json`, `User-Agent: Vale-Webhook/1.0`
- If a secret is set on the webhook: `X-VL-Signature: sha256=<hex HMAC-SHA256 of the RAW body>` (GitHub/Stripe scheme)
- Delivery is **synchronous, fire-and-forget**; **no automatic retry**; private/local IP targets are blocked (SSRF guard)

### Event catalog

| Event | data payload |
|---|---|
| `order.paid` | `order_id, order_number, order_status, order_total, currency, order_customer_email, order_customer_name, items[] (product_id, name, sku, price, qty, subtotal, variation_id, variation_data)` |
| `order.status_changed` | same fields plus `new_status` |
| `post.created` / `post.published` / `post.updated` | post fields |
| `product.created` / `product.updated` / `product.deleted` | product fields (id, name, sku, price, stock, ...) + `variations[]` (`id, sku, attributes, price, sale_price, stock_quantity, stock_status`) on created/updated; deleted carries only `{id, sku, slug}`. One delivery per product (3 products = 3 requests). No image URLs in the payload. |
| `user.registered` | user fields |
| `form.submitted` | `{form_id, form_name, form_slug, submission_id, fields, ip_address, user_agent, submitted_at}` |
| `subscriber.created` | `{id, email, name, status, tier, tags}` |

### Receiver skeleton (PHP, standalone)

```php
<?php

declare(strict_types=1);

$secret = getenv('VALE_WEBHOOK_SECRET') ?: '';
$raw    = file_get_contents('php://input') ?: '';

if ($secret !== '') {
    $expected = 'sha256=' . hash_hmac('sha256', $raw, $secret);
    $provided = $_SERVER['HTTP_X_VL_SIGNATURE'] ?? '';
    if (!hash_equals($expected, $provided)) {
        http_response_code(401);
        exit(json_encode(['error' => 'Invalid signature']));
    }
}

$payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
$event   = (string) ($payload['event'] ?? '');
$data    = (array) ($payload['data'] ?? []);

match ($event) {
    'order.paid'          => handleOrderPaid($data),
    'order.status_changed' => handleStatusChanged($data),
    'form.submitted'      => handleFormSubmitted($data),
    default               => null, // ignore unknown events, still 200
};

http_response_code(200);
echo json_encode(['ok' => true]);

function handleOrderPaid(array $order): void
{
    // ...
}
```

Rules to state explicitly:
- Always answer `200` for recognized-but-unhandled events; only 4xx/5xx on real failures (there are no retries, but status is logged per-webhook in admin).
- Verify signature over the **raw** body before json_decode.
- **Write-back:** receivers can push results back into Vale via the REST API (e.g. after fulfilling an `order.paid` order, `POST /api/v1/orders/{id}/notes` with `{"note": "Sent License Key: LUMI-ABC-123"}` — needs a token with the `write:orders` ability; `GET /api/v1/orders/{id}` with `read:orders` for verification, `GET /api/v1/orders/{id}/notes` to read existing notes back). Fulfillment systems can also drive the order forward with `PUT /api/v1/orders/{id}` (`status`, `carrier`, `tracking_number`, `customer_note`, `note` — amounts/addresses stay protected). The note shows up in the admin order detail timeline with `added_by: API`. Make the write-back best-effort: keys/fulfillment are already delivered, so log + alert on failure but never flip the shipment state. See vale-api-endpoint skill and `vale-docs/en/latest/developer/api.md#orders`.
- Configure the endpoint URL + secret under Admin → Settings → Advanced → Webhooks; use the built-in **Test** button to validate end-to-end.
- Masked-secret rule: the edit form never receives the stored secret — it shows the `SECRET_PLACEHOLDER` bullets (`WebhookAdminController::SECRET_PLACEHOLDER`) instead. Every code path that processes the edit form (`validate()`/`update()`, `testPreview()`, and any future one) must treat that exact value as "unchanged" and never persist it, otherwise one untouched save overwrites the real secret with bullets.

## Direction B — Producer: add a new outbound event

Three touch points:

### 1. Register the event: `src/Application/Webhook/OutboundWebhookService.php`

Append to `AVAILABLE_EVENTS` (this const feeds the admin subscription UI):

```php
public const AVAILABLE_EVENTS = [
    // ... existing entries ...
    'comment.created' => 'New comment created',
];
```

### 2. Fire the action where it happens

The webhook pipeline is wired to the event dispatcher. Find the domain/application point that should trigger it and ensure an action exists there:

```php
$this->events->doAction('comment.created', $comment);
```

If the emitting class doesn't yet have `EventDispatcherInterface`, constructor-inject it and bind it in `config/services.php`.

### 3. Wire action → webhook dispatch: `bootstrap/events.php`

After the container is built, bridge the action to the outbound service (follow how existing events like `order.paid` are bridged). All domain listeners live in `bootstrap/events.php`; it is required by `bootstrap/app.php` (web/pseudo-cron) **and** by the `vale` CLI (so `php vale cron:run` fires webhooks too) — never register listeners only in `bootstrap/app.php`.

```php
$events = $container->get(\ValeApp\Domain\Shared\Event\EventDispatcherInterface::class);
$webhooks = $container->get(\ValeApp\Application\Webhook\OutboundWebhookService::class);

$events->addAction('comment.created', function ($comment) use ($webhooks): void {
    $webhooks->dispatch('comment.created', [
        'id'      => $comment->getId(),
        'author'  => $comment->getAuthorName(),
        'content' => $comment->getContent(),
    ]);
});
```

Payload guidance: keep it JSON-safe scalars/arrays; include a stable identifier (id/slug); don't embed secrets.

### 4. Expose Body Template placeholders + keep sample in sync with the real body

New events must make their payload keys available as `{{key}}` placeholders in the admin webhook "Body Template" reference. There are three places that must stay in sync — the **sample payload keys, the placeholder chips, and the actual dispatched payload** must all match:

1. **`app/Controllers/Admin/WebhookAdminController.php::samplePayload()`** — add a `match` arm for the new event prefix (this also powers the Test-preview payload). Follow the existing prefixed patterns, e.g. `str_starts_with($event, 'subscriber.') => ['id' => 0, 'email' => ..., ...]`.
2. **`resources/views/admin/settings/_advanced_webhooks.twig`** — add a grouped `<p class="font-semibold">{{ __('<Event> events') }}</p>` block of click-to-insert `<code data-tag="{{ '{{key}}' }}">` chips inside the "Available placeholders" collapsible. Every chip key must exist in the `samplePayload()` arm AND in the real dispatched body.
3. **The real dispatch** — when wiring step 3, use the exact same key names in the `$webhooks->dispatch('<event>', [...])` array as you put in `samplePayload()` and the chips. If the dispatch payload gains/renames/drops a key after the event ships, update `samplePayload()` and the chips to match, or that key resolves to the literal `{{key}}` string in delivered bodies.

The placeholder regex in `OutboundWebhookService` is `/\{\{(\w+)\}\}/` and resolves against the dispatched payload keys. Only top-level keys work — there is no dot-notation, so `{{variation.sku}}` stays literal. Use `{{variations}}` for the whole JSON-encoded array instead.

**Value rendering rule** (see `OutboundWebhookService::applyBodyTemplate()`): strings/numbers stringify, null → empty, bool → `true`/`false`, **arrays → JSON-encoded** (so `{{tags}}`, `{{items}}`, `{{fields}}` stay valid JSON inside Body Templates). This is why payload arrays like `tags` and `items` were previously misrendered as the literal string `Array` — the new helper encodes them properly. Any new event with array payload keys therefore needs the sample + chips + real-body keys aligned (above), and the placeholder reference in step 4.2 stays source-of-truth for users building Body Templates.

## Phase 3 — Verify

- Consumer: send a signed test request locally:

```bash
BODY='{"event":"order.paid","timestamp":"2026-01-01T00:00:00Z","data":{"id":1}}'
SIG=$(php -r 'echo hash_hmac("sha256", $argv[1], $argv[2]);' "$BODY" "$SECRET")
curl -s -X POST http://localhost/receiver.php -H "Content-Type: application/json" -H "X-VL-Signature: sha256=$SIG" -d "$BODY"
```

Then wrong-signature case must return 401.
- Producer: create a webhook pointing at a request-bin, trigger the event, confirm delivery logged OK in admin (per-webhook delivery stats).
- **Admin Test button**: when the webhook has multiple subscribed events, the Test button now fires one request per event (own payload + correct `X-VL-Event` header). The result badge shows per-event status (e.g. `order.paid ✓ HTTP 200`, `subscriber.created ✓ HTTP 200`). This also means array-valued placeholders like `{{tags}}` render as inline JSON (`["newsletter","vip"]`) instead of the literal `Array`.
- `composer lint:changed` on touched PHP files.

## Checklist

- [ ] Signature verified over raw body (consumer) / fail-closed
- [ ] Unknown events answered 200, not 500
- [ ] AVAILABLE_EVENTS entry present (producer) — visible in admin dropdown
- [ ] Action bridged in bootstrap/events.php, payload JSON-safe
- [ ] `samplePayload()` match arm + admin placeholder chips added for the new event
- [ ] sample payload keys === placeholder chips === real dispatched body keys (all three in sync)
- [ ] Docs reminder: update `vale-docs/en/latest/developer/webhooks.md` event table (producer)
- [ ] Skills: update `.agents/skills/vale-integrate/SKILL.md` & `REFERENCE.md` if webhook event list or payload shape changed
