# Vale CMS API & Webhook Payload Reference

This document provides exact, code-verified JSON envelopes for Vale CMS REST API endpoints and Outbound Webhooks.

> 💡 **Image Import Options:** Image fields (`featured_image`, `gallery` entries, variation `image`) support:
> - **Existing Media ID:** e.g. `"15"`
> - **Remote URL:** e.g. `"https://example.com/images/product.jpg"` (downloaded & sanitized automatically)
> - **Base64 Data URI:** e.g. `"data:image/png;base64,iVBORw0KG..."`
> - **Bare Base64 String:** Raw base64 string payload

---

## 1. REST API Envelopes (`/api/v1`)

### A. Create Product (`POST /api/v1/products`)

#### Request Body
```json
{
  "name": "Awesome Product",
  "sku": "AP-100",
  "type": "simple",
  "price": 199.90,
  "sale_price": 149.90,
  "sale_price_start": "2026-06-01T00:00:00+00:00",
  "sale_price_end": "2026-06-30T23:59:59+00:00",
  "stock_quantity": 50,
  "stock_status": "in_stock",
  "status": "published",
  "short_description": "Short summary of product.",
  "description": "Detailed description in HTML or plain text.",
  "categories": ["electronics", 12],
  "featured_image": "https://example.com/images/product.jpg",
  "gallery": [
    "https://example.com/images/gallery1.jpg",
    "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==",
    "15"
  ],
  "weight": 1.5,
  "dimensions": {
    "length": "20",
    "width": "15",
    "height": "10"
  },
  "shipping_class": "standard",
  "shipping_cost": 5.00,
  "gtin": "1234567890123",
  "mpn": "MPN-100",
  "brand": "MyBrand",
  "google_product_category": "Electronics > Audio",
  "condition": "new",
  "gender": "unisex",
  "age_group": "adult",
  "color": "Black",
  "size": "M",
  "material": "Plastic/Metal",
  "pattern": "Solid",
  "custom_label_0": "Summer Promo",
  "gmc_exclude": false,
  "download_source": "file",
  "download_url": "https://example.com/files/download.zip",
  "download_limit": 5,
  "download_expiry_days": 30,
  "download_files": [
    "https://example.com/files/manual.pdf"
  ],
  "vat_mode": "standard",
  "vat_rate": 20.0,
  "refund_mode": "allow",
  "meta_title": "SEO Title",
  "meta_description": "SEO Description",
  "comment_status": "open",
  "vote_status": "open",
  "upsell_ids": [14, 15],
  "cross_sell_ids": [20, 21],
  "variations": [
    {
      "sku": "AP-100-BLK",
      "price": 199.90,
      "sale_price": 149.90,
      "stock_quantity": 25,
      "stock_status": "in_stock",
      "attributes": {
        "color": "Black"
      },
      "image": "https://example.com/images/black.jpg"
    }
  ]
}
```

#### Response Envelope (`201 Created`)
```json
{
  "message": "Product created",
  "data": {
    "id": 101,
    "slug": "awesome-product",
    "name": "Awesome Product",
    "sku": "AP-100",
    "type": "simple",
    "price": 199.9,
    "sale_price": 149.9,
    "sale_price_start": "2026-06-01T00:00:00+00:00",
    "sale_price_end": "2026-06-30T23:59:59+00:00",
    "on_sale": true,
    "effective_price": 149.9,
    "short_description": "Short summary of product.",
    "description": "Detailed description in HTML or plain text.",
    "status": "published",
    "stock_status": "in_stock",
    "stock_quantity": 50,
    "category_ids": [12],
    "upsell_ids": [14, 15],
    "cross_sell_ids": [20, 21],
    "featured_image_url": "https://your-vale-site.com/uploads/2026/05/product.jpg",
    "gallery": [
      "https://your-vale-site.com/uploads/2026/05/gallery1.jpg",
      "https://your-vale-site.com/uploads/2026/05/gallery2.jpg"
    ],
    "variations": [
      {
        "id": 1,
        "sku": "AP-100-BLK",
        "price": 199.9,
        "sale_price": 149.9,
        "sale_price_start": null,
        "sale_price_end": null,
        "stock_quantity": 25,
        "stock_status": "in_stock",
        "image_url": "https://your-vale-site.com/uploads/2026/05/black.jpg",
        "attributes": {
          "color": "Black"
        },
        "download_mode": "use_parent",
        "download_url": null,
        "download_limit": 0,
        "download_expiry_days": 0,
        "download_files": [],
        "shipping_class": "use_parent",
        "shipping_cost": null,
        "shipping_country_overrides": [],
        "vat_mode": "use_parent",
        "vat_rate": null,
        "refund_mode": "use_parent"
      }
    ],
    "gtin": "1234567890123",
    "mpn": "MPN-100",
    "brand": "MyBrand",
    "google_product_category": "Electronics > Audio",
    "condition": "new",
    "gender": "unisex",
    "age_group": "adult",
    "color": "Black",
    "size": "M",
    "material": "Plastic/Metal",
    "pattern": "Solid",
    "custom_label_0": "Summer Promo",
    "custom_label_1": null,
    "custom_label_2": null,
    "custom_label_3": null,
    "custom_label_4": null,
    "gmc_exclude": false,
    "download_source": "file",
    "download_url": "https://example.com/files/download.zip",
    "download_limit": 5,
    "download_expiry_days": 30,
    "download_files": [
      "https://your-vale-site.com/uploads/2026/05/manual.pdf"
    ],
    "meta_title": "SEO Title",
    "meta_description": "SEO Description",
    "comment_status": "open",
    "vote_status": "open",
    "total_sold": 0,
    "vat_mode": "standard",
    "vat_rate": 20.0,
    "refund_mode": "allow",
    "shipping_class": "standard",
    "shipping_cost": 5.0,
    "weight": 1.5,
    "dimensions": "20 x 15 x 10",
    "shipping_country_overrides": [],
    "vat_country_overrides": [],
    "created_at": "2026-05-18T12:00:00+00:00",
    "updated_at": "2026-05-18T12:00:00+00:00"
  }
}
```

---

### B. Read Orders (`GET /api/v1/orders`, `/api/v1/orders/{id}`, `/api/v1/orders/{id}/notes`)

Requires a token with the `read:orders` ability. Useful for verifying an order (email, SKUs) before writing back a fulfillment result.

#### Response Envelope (`200 OK`)
```json
{
  "data": {
    "id": 501,
    "order_number": "VL-1001",
    "status": "processing",
    "currency": "EUR",
    "subtotal": 49.0,
    "total": 49.0,
    "payment_method": "stripe",
    "paid_at": "2026-05-18T12:00:00+00:00",
    "created_at": "2026-05-18T11:59:00+00:00",
    "customer_email": "john.doe@example.com",
    "items": [
      { "product_id": 101, "variation_id": null, "name": "LumiFind Pro", "sku": "LUMI-PRO", "quantity": 1 }
    ]
  }
}
```

Unknown IDs return `404 {"error": "Order not found"}`; a missing `read:orders` ability returns `403`.

`GET /api/v1/orders` lists orders (`data` + `meta` envelope) with `?status=`, `?search=`, `?from=` / `?to=`, `?updated_since=` (date or datetime — for incremental sync), `?coupon_code=`, `?product_id=`, `?page=`, `?per_page=` (max 100). Status values: `pending`, `processing`, `on-hold`, `shipped`, `completed`, `refund_requested`, `refund_rejected`, `refunded`, `cancelled`.

`GET /api/v1/orders/{id}/notes` returns the order's notes (`type=note` timeline entries) with `meta.total` — useful to confirm a previous write-back landed.

---

### C. Write Back to an Order (`POST /api/v1/orders/{id}/notes`)

Requires a token with the `write:orders` ability. The standard write-back path for webhook receivers: after `order.paid` triggers external fulfillment (e.g. license key issuance), the receiver posts the result as an order note. It appears in **Vale Admin → Orders → order detail → Order Notes** with `added_by: API`. Notes are validated (max 5000 chars, non-empty) and stored in the `order_logs` table — no migration needed.

#### Request Body
```json
{
  "note": "Sent License Key: LUMI-ABC-123"
}
```

#### Response Envelope (`201 Created`)
```json
{
  "data": {
    "order_id": 501,
    "note": "Sent License Key: LUMI-ABC-123",
    "added_by": "API"
  }
}
```

Errors: empty note → `422 {"error": "A note is required."}`; unknown order → `404`; missing ability → `403`.

`DELETE /api/v1/orders/{id}/notes/{noteId}` removes a single note (same ability). Only `type=note` rows can be deleted — system/event entries return `404`.

---

### D. Create a Manual / Offline Order (`POST /api/v1/orders`)

Requires `write:orders`. Admin **Create Order** parity for phone orders, ERP-created wholesale orders etc. Prices for catalog items are resolved **server-side**; the total is always computed by the server. `payment_method` accepts offline methods only (`cash`, `manual`, `banktransfer`).

#### Request Body
```json
{
  "billing": {
    "first_name": "Ada", "last_name": "Lovelace",
    "email": "ada@example.com", "phone": "+90 555 000 0000",
    "address_1": "Istiklal Cad. 1", "city": "Istanbul",
    "postcode": "34000", "country": "TR"
  },
  "items": [
    { "product_id": 101, "qty": 2 },
    { "sku": "LUMI-PRO-RED", "variation_id": 303, "variation": { "Color": "Red" }, "qty": 1 },
    { "name": "Custom engraving", "price": 25.00, "sku": "ENGRAVE", "qty": 1 }
  ],
  "shipping": 10.00,
  "discount": 5.00,
  "coupon_code": "SAVE10",
  "currency": "EUR",
  "status": "pending",
  "payment_method": "banktransfer",
  "customer_note": "Gift wrap please.",
  "transaction_id": "TXN-12345",
  "note": "Order taken by phone.",
  "shipping_address": {
    "first_name": "Ada", "last_name": "Lovelace",
    "address_1": "Istiklal Cad. 1", "city": "Istanbul",
    "postcode": "34000", "country": "TR"
  }
}
```

Item rules: `qty` defaults to `1`. Reference a product with `product_id` or `sku` → `name`/`price`/`sku` come from the catalog (sale prices honored); an explicit `price` overrides it. Items without a product reference are custom lines and require both `name` and `price`. An unknown `variation_id` fails with `422`.

Rules: `billing.first_name` + valid `billing.email` and at least one item are required. `status` may be any order status; a paid status sets `paid_at` and fires `order.paid` (stock + tagging side effects included). Totals are VAT-inclusive: `total = subtotal + shipping − discount`.

#### Response (`201 Created`)
`{ "message": "Order created", "data": { …serialized order…, "note": "Order taken by phone." } }` — the `data` shape is identical to `GET /orders/{id}`.

---

### E. Update an Order (`PUT /api/v1/orders/{id}`)

Requires `write:orders`. Partial update of operator-controlled fields. Full admin side-effect chain — amounts and addresses are **protected** (`422`).

#### Request Body (all fields optional)
```json
{
  "status": "shipped",
  "carrier": "dhl",
  "tracking_number": "JD014600003812345678",
  "notify_customer": true,
  "customer_note": "Leave at the door.",
  "note": "Shipped via DHL Express."
}
```

- `status` — any order status. Paid-state bookkeeping (`paid_at`), coupon usage sync, download revocation, `order.status_changed` / `order.paid` / `order.reversed` events and subscription cancellation all match the admin panel exactly.
- `carrier` + `tracking_number` — shipment data + `order.shipped`; `notify_customer: true` emails the customer (same template as the admin form).
- `customer_note` — replaces the order's customer-facing note.
- `note` — adds a timeline note attributed to `API` (max 5000 chars).

#### Response (`200 OK`)
`{ "message": "Order updated", "data": { …serialized order… } }`

Protected fields rejected with `422`: `subtotal`, `shipping`, `discount`, `total`, `tax`, `items`, `billing`, `shipping_address`, `currency`, `payment_method`, `user_id`.

---

## 2. Outbound Webhook Payloads (`Vale -> External`)

### A. Product Created / Updated (`product.created` / `product.updated`)

```json
{
  "event": "product.created",
  "timestamp": "2026-05-18T12:00:00+00:00",
  "data": {
    "product_id": 101,
    "product_name": "Awesome Product",
    "product_slug": "awesome-product",
    "product_sku": "AP-100",
    "product_status": "published",
    "product_price": 199.90,
    "product_sale_price": 149.90,
    "product_stock": 50,
    "variations": [
      {
        "id": 1,
        "sku": "AP-100-BLK",
        "attributes": { "color": "Black" },
        "price": 199.90,
        "sale_price": 149.90,
        "stock_quantity": 25,
        "stock_status": "in_stock"
      }
    ]
  }
}
```

### B. Order Paid (`order.paid`)

```json
{
  "event": "order.paid",
  "timestamp": "2026-05-18T12:00:00+00:00",
  "data": {
    "order_id": 501,
    "order_number": "ORD-2026-00501",
    "order_status": "processing",
    "order_total": "299.90",
    "currency": "USD",
    "order_customer_email": "john.doe@example.com",
    "order_customer_name": "John Doe",
    "items": [
      {
        "product_id": 101,
        "name": "Awesome Product",
        "sku": "AP-100",
        "price": 199.90,
        "qty": 2,
        "subtotal": 299.80,
        "variation_id": 1,
        "variation_data": "{\"color\":\"Black\"}"
      }
    ]
  }
}
```

`sku` is the variation SKU for variation items, otherwise the parent product SKU. `variation_id` / `variation_data` only appear for variation items. Receivers commonly write the fulfillment result back with `POST /api/v1/orders/{id}/notes` (see section 1C).

### C. User Registered (`user.registered`)

```json
{
  "event": "user.registered",
  "timestamp": "2026-05-18T12:00:00+00:00",
  "data": {
    "user_id": 42,
    "email": "jane@example.com",
    "name": "Jane Smith",
    "role": "customer",
    "created_at": "2026-05-18T12:00:00+00:00"
  }
}
```

### D. Form Submitted (`form.submitted`)

```json
{
  "event": "form.submitted",
  "timestamp": "2026-05-18T12:00:00+00:00",
  "data": {
    "form_id": 3,
    "form_name": "Contact Us",
    "form_slug": "contact-us",
    "submission_id": 89,
    "fields": {
      "name": "Alice Johnson",
      "email": "alice@example.com",
      "message": "Hello, I have a question about shipping."
    },
    "ip_address": "198.51.100.45",
    "user_agent": "Mozilla/5.0 ...",
    "submitted_at": "2026-05-18T12:00:00+00:00"
  }
}
```
