---
name: vale-integrate
description: "Connects external applications, custom code, scripts, Telegram bots, or automation platforms (Zapier, Make, n8n) to Vale CMS without modifying core PHP code. Guides the user through choosing between Webhooks (Vale calling external systems) or REST API (external systems calling Vale), generates ready-to-use client/receiver code (Node.js, Python, PHP, cURL, Telegram bot, Zapier/Make webhook/API), and provides step-by-step instructions for configuring API Tokens or Webhooks in Vale Admin Panel with optional live testing."
license: AGPL-3.0
metadata:
  project: vale/vale
  reference: vale-docs/en/latest/developer/api.md
---

# External Integration Guide (REST API & Outbound Webhooks)

This skill is for connecting **external software, custom code, scripts, Telegram bots, or no-code platforms (Zapier, Make, n8n)** to Vale CMS **without modifying Vale's source code**.

> 💡 **Exact Payload Reference:** For code-verified REST API envelopes (including `POST /api/v1/products`) and full Webhook payloads (`product.created`, `order.paid`, `user.registered`, `form.submitted`), refer to [REFERENCE.md](REFERENCE.md).

---

## Phase 1 — Ask Questions (Required before writing code)

Always ask the user to clarify their integration direction, domain/URL, platform, and goal first:

### Step 1: Ask Direction
> **"What is the direction of your integration?"**
> - **A) Webhooks (Vale → External):** Vale reaches out to your system when something happens (e.g. order placed, user registered, form submitted, product created).
> - **B) REST API (External → Vale):** Your external system/script calls Vale to perform actions (e.g. create products, fetch orders, update users, generate coupons).

### Step 2: Ask Domain / Target URL
> **"What is your domain or target URL?"**
> - If **Webhooks (A)**: Ask for your **Target Receiver URL** (e.g., `https://your-app.com/webhook/vale` or Zapier webhook URL).
> - If **REST API (B)**: Ask for your **Vale Site Domain** (e.g., `https://myvalesite.com`).

### Step 3: Ask Platform & Language
> **"Which language, platform, or tool will you use?"**
> - Code: Node.js / TypeScript, Python, PHP, Go, cURL / Shell
> - Bot / Chat: Telegram Bot, Discord Webhook
> - No-code / Automation: Zapier, Make (Integromat), n8n, Custom Webhook Receiver

### Step 4: Ask Specific Trigger / Action
> **"What specific event or action do you want to handle?"**
> - Examples:
>   - *"When a product is purchased, send a notification to Telegram / my email / Zapier."*
>   - *"Run a Python script to import new products into Vale."*
>   - *"Sync new registered users to an external CRM."*

---

## Phase 2 — Generate Integration Code

Based on user selection in Phase 1, generate the complete, self-contained implementation code using the provided domain/URL. All code comments and user-facing messages in generated scripts should be in English.

---

### Branch A: Webhooks (Vale → External System)

Vale sends a `POST` request with JSON body when events occur.

#### Event Catalog
- `order.paid`, `order.status_changed`
- `product.created`, `product.updated`, `product.deleted`
- `post.created`, `post.published`, `post.updated`
- `user.registered`
- `form.submitted`
- `subscriber.created`

#### Vale Webhook Envelope
```json
{
  "event": "order.paid",
  "timestamp": "2026-08-26T12:00:00+00:00",
  "data": {
    "id": 101,
    "order_number": "ORD-2026-001",
    "total": 299.99,
    "currency": "USD",
    "customer_email": "customer@example.com"
  }
}
```

#### Signature Verification Header
If a secret is set in Vale Admin, requests include `X-VL-Signature: sha256=<HMAC_HEX>`.
External receivers **must** verify this header using HMAC-SHA256 over the raw request body.

---

#### Code Examples for Webhook Receivers

<details>
<summary><b>1. Node.js (Express) Webhook Receiver</b></summary>

```javascript
const express = require('express');
const crypto = require('crypto');

const app = express();
const WEBHOOK_SECRET = process.env.VALE_WEBHOOK_SECRET || 'your_webhook_secret';

// Parse raw body for signature verification
app.use(express.json({
  verify: (req, res, buf) => { req.rawBody = buf; }
}));

app.post('/webhook/vale', (req, res) => {
  const signature = req.headers['x-vl-signature'];

  if (WEBHOOK_SECRET) {
    const expectedSig = 'sha256=' + crypto
      .createHmac('sha256', WEBHOOK_SECRET)
      .update(req.rawBody)
      .digest('hex');

    if (signature !== expectedSig) {
      return res.status(401).json({ error: 'Invalid signature' });
    }
  }

  const { event, data } = req.body;
  console.log(`Received event: ${event}`);

  if (event === 'order.paid') {
    console.log(`Order #${data.order_number} paid! Total: $${data.total}`);
    // Your custom logic here (e.g. send email, notify Slack)
  }

  // Always return 200 OK
  res.status(200).json({ ok: true });
});

app.listen(3000, () => console.log('Webhook receiver running on port 3000'));
```
</details>

<details>
<summary><b>2. Python (Flask) Webhook Receiver</b></summary>

```python
import hmac
import hashlib
import os
from flask import Flask, request, jsonify

app = Flask(__name__)
WEBHOOK_SECRET = os.getenv("VALE_WEBHOOK_SECRET", "your_webhook_secret").encode('utf-8')

@app.route('/webhook/vale', methods=['POST'])
def handle_vale_webhook():
    signature = request.headers.get('X-VL-Signature', '')
    raw_body = request.get_data()

    if WEBHOOK_SECRET:
        expected_sig = 'sha256=' + hmac.new(WEBHOOK_SECRET, raw_body, hashlib.sha256).hexdigest()
        if not hmac.compare_digest(signature, expected_sig):
            return jsonify({'error': 'Invalid signature'}), 401

    payload = request.get_json()
    event = payload.get('event')
    data = payload.get('data', {})

    if event == 'order.paid':
        print(f"Order #{data.get('order_number')} paid! Total: {data.get('total')}")
    elif event == 'product.created':
        print(f"New product added: {data.get('name')}")

    return jsonify({'ok': True}), 200

if __name__ == '__main__':
    app.run(port=5000)
```
</details>

<details>
<summary><b>3. Telegram Bot Notification (Python)</b></summary>

```python
import requests
import os
from flask import Flask, request, jsonify

app = Flask(__name__)

TELEGRAM_BOT_TOKEN = os.getenv("TELEGRAM_BOT_TOKEN", "YOUR_BOT_TOKEN")
TELEGRAM_CHAT_ID = os.getenv("TELEGRAM_CHAT_ID", "YOUR_CHAT_ID")

def send_telegram_message(text):
    url = f"https://api.telegram.org/bot{TELEGRAM_BOT_TOKEN}/sendMessage"
    requests.post(url, json={"chat_id": TELEGRAM_CHAT_ID, "text": text, "parse_mode": "Markdown"})

@app.route('/webhook/vale', methods=['POST'])
def vale_to_telegram():
    payload = request.get_json()
    event = payload.get('event')
    data = payload.get('data', {})

    if event == 'order.paid':
        msg = f"🛍️ *New Order Received!*\n\n*Order No:* #{data.get('order_number')}\n*Total:* {data.get('total')} {data.get('currency', 'USD')}\n*Customer:* {data.get('customer_email')}"
        send_telegram_message(msg)
    elif event == 'user.registered':
        msg = f"👤 *New User Registered!*\n\n*Email:* {data.get('email')}\n*Name:* {data.get('name')}"
        send_telegram_message(msg)

    return jsonify({'ok': True}), 200

if __name__ == '__main__':
    app.run(port=5000)
```
</details>

<details>
<summary><b>4. Zapier / Make / n8n Webhook Trigger</b></summary>

1. In Zapier/Make/n8n, create a new scenario/zap with trigger type **Webhooks / Catch Hook**.
2. Copy the generated Webhook URL (e.g. `https://hooks.zapier.com/hooks/catch/123456/abcde/`).
3. Paste this URL into Vale Admin Panel -> **Settings -> Advanced -> Webhooks**.
4. Test delivery in Vale Admin Panel; Zapier/Make will automatically detect the JSON payload structure (`event`, `timestamp`, `data`).
</details>

---

### Branch B: REST API (External System → Vale)

External systems call Vale's `/api/v1/*` endpoints using a **Bearer Token**.

#### Common API Endpoints & Abilities
| Action | Method & Path | Required Token Ability |
|---|---|---|
| List Products | `GET /api/v1/products` | `read:products` or `*` |
| Create Product | `POST /api/v1/products` | `write:products` or `*` |
| List Orders | `GET /api/v1/orders` | `read:orders` or `*` |
| Get Order | `GET /api/v1/orders/{id}` | `read:orders` or `*` |
| Create Manual Order | `POST /api/v1/orders` | `write:orders` or `*` |
| Update Order | `PUT /api/v1/orders/{id}` | `write:orders` or `*` |
| List Order Notes | `GET /api/v1/orders/{id}/notes` | `read:orders` or `*` |
| Add Order Note | `POST /api/v1/orders/{id}/notes` | `write:orders` or `*` |
| Delete Order Note | `DELETE /api/v1/orders/{id}/notes/{noteId}` | `write:orders` or `*` |
| List Coupons | `GET /api/v1/coupons` | `coupons:read` or `*` |
| Create Coupon | `POST /api/v1/coupons` | `coupons:write` or `*` |
| List Users | `GET /api/v1/users` | `users:read` or `*` |

Abilities follow the `verb:resource` convention (`read:orders`, not `orders:read`). The full endpoint→ability reference lives in `vale-docs/en/latest/developer/api.md`.

**Order write paths.** Orders are normally created by checkout and read with `read:orders`; notes are the standard write-back path for webhook receivers (`order.paid` → external fulfillment → note on the order). On top of that, two write operations mirror the admin panel:
- `POST /api/v1/orders` creates a **manual/offline order** (admin *Create Order* parity): prices for `product_id`/`sku`-referenced items are resolved server-side, the total is computed server-side (`subtotal + shipping − discount`), and `payment_method` accepts offline methods only (`cash`, `manual`, `banktransfer`).
- `PUT /api/v1/orders/{id}` runs the full admin status side-effect chain (paid_at, coupon usage sync, download revocation, `order.paid` / `order.reversed` / `order.shipped` events) plus `carrier`/`tracking_number`/`customer_note`/`note`. Amounts and addresses are protected and rejected with `422`.

`GET /api/v1/orders` supports `?status=`, `?search=`, `?from=`/`?to=`, `?updated_since=`, `?coupon_code=`, `?product_id=` and pagination — use `updated_since` for incremental sync.

#### Standard Response Envelope
```json
{
  "data": [
    {
      "id": 1,
      "name": "Sample Product",
      "slug": "sample-product",
      "price": 19.99,
      "sku": "SMP-001"
    }
  ],
  "meta": {
    "total": 1,
    "page": 1,
    "per_page": 30,
    "total_pages": 1
  }
}
```

---

#### Code Examples for Calling Vale REST API

<details>
<summary><b>1. Node.js (Fetch) — Create Product in Vale</b></summary>

```javascript
const VALE_URL = 'https://your-vale-site.com';
const API_TOKEN = 'your_api_bearer_token_here';

async function createProduct() {
  const response = await fetch(`${VALE_URL}/api/v1/products`, {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${API_TOKEN}`,
      'Content-Type': 'application/json',
      'Accept': 'application/json'
    },
    body: JSON.stringify({
      name: 'New Awesome Product',
      price: 149.90,
      sku: 'NAP-001',
      description: 'Added via external API script.',
      stock_quantity: 50
    })
  });

  const data = await response.json();
  if (!response.ok) {
    console.error('Error:', data);
    return;
  }

  console.log('Product Created Successfully:', data.data);
}

createProduct();
```
</details>

<details>
<summary><b>2. Python (Requests) — Get a Vale Order & Write Back a Fulfillment Note</b></summary>

```python
import requests

VALE_URL = "https://your-vale-site.com"
API_TOKEN = "your_api_bearer_token_here"

headers = {
    "Authorization": f"Bearer {API_TOKEN}",
    "Accept": "application/json"
}

# 1. Look up the order (verify email / items before writing back)
order_id = 501
order = requests.get(f"{VALE_URL}/api/v1/orders/{order_id}", headers=headers).json()["data"]
print(f"Order #{order['order_number']}: {order['total']} {order['currency']} ({order['status']})")
print(f"Customer: {order['customer_email']}, items: {[i['sku'] for i in order['items']]}")

# 2. Fulfill externally, then write the result back as an order note
#    (shows in Vale admin → Orders → order detail → Order Notes)
response = requests.post(
    f"{VALE_URL}/api/v1/orders/{order_id}/notes",
    headers=headers,
    json={"note": "Sent License Key: LUMI-ABC-123"},
)

if response.status_code == 201:
    print("Order note written.")
else:
    print(f"Error {response.status_code}: {response.text}")
```
</details>

<details>
<summary><b>3. cURL Command — Create Coupon in Vale</b></summary>

```bash
curl -X POST https://your-vale-site.com/api/v1/coupons \
  -H "Authorization: Bearer YOUR_API_TOKEN_HERE" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "code": "SUMMER2026",
    "type": "percentage",
    "value": 20,
    "max_uses": 100
  }'
```
</details>

---

## Phase 3 — Step-by-Step Vale Admin Panel Instructions

Always guide the user step-by-step on how to configure Vale's Admin Panel based on their choice.

### If using Webhooks (Vale → External System):

1. **Log in to Vale Admin Panel** (e.g. `https://your-site.com/admin`).
2. Go to **Settings → Advanced** (`/admin/settings/advanced`).
3. Scroll to the **Webhooks** section and click **Add Webhook**.
4. Configure the settings:
   - **Target URL:** Enter your external receiver URL (e.g. `https://your-app.com/webhook/vale` or Zapier catch hook URL).
   - **Secret:** Enter a random secret string (used for HMAC signature verification).
   - **Events:** Select the events you want Vale to trigger (e.g. `order.paid`, `user.registered`, `product.created`).
5. Click **Save Settings**.
6. **Test the integration:** Click the **Test** button next to your webhook to send a sample payload and verify that your receiver handles it correctly.

---

### If using REST API (External System → Vale):

1. **Log in to Vale Admin Panel** (e.g. `https://your-site.com/admin`).
2. Go to **Settings → Advanced** (`/admin/settings/advanced`).
3. Scroll to the **API Tokens** section and click **Create Token**.
4. Configure the token:
   - **Token Name:** Give it a descriptive name (e.g., `Python Inventory Sync`, `Zapier Integration`).
   - **Abilities:** Select required permissions (e.g. `read:products`, `write:products`, `read:orders`, `write:orders`) or `*` for full access.
5. Click **Generate** and **copy the API Token**. *(Important: Keep this token secret!)*
6. Use this token as an HTTP Header in your external requests:
   ```http
   Authorization: Bearer YOUR_COPIED_TOKEN
   ```

---

## Phase 4 — Optional Live Testing

Ask the user if they would like to execute an instant live test:

> **"Would you like to test this integration right now?"**
> - If **Yes (REST API)**: Ask the user for their **API Bearer Token**. Execute a sample `cURL` request against their Vale site domain (e.g., `GET /api/v1/products`) and display the real response.
> - If **Yes (Webhooks)**: Advise them to use the built-in **Test** button in Admin Panel (**Settings → Advanced → Webhooks**), or send a simulated webhook payload to their receiver URL to verify signature verification.

---

## Verification Checklist

- [ ] Interactive questions asked to clarify direction (Webhooks vs API), domain/target URL, platform, and action.
- [ ] Complete, runnable integration code provided (Node.js / Python / PHP / cURL / Telegram / Zapier).
- [ ] Signature verification included for Webhook receivers (`X-VL-Signature`).
- [ ] Bearer Token authentication headers included for API requests (`Authorization: Bearer <token>`).
- [ ] Clear Admin Panel configuration steps provided for Settings → Advanced.
- [ ] Live testing option offered at the end.
