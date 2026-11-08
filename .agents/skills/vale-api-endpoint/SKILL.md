---
name: vale-api-endpoint
description: "Adds a new REST API v1 resource/endpoint to Vale by asking questions (resource name, operations read/write/delete, backing repository, pagination) and generating: app/Controllers/Api/<Resource>ApiController.php with the standard {data,meta} JSON envelope and 422 validation shape, routes/api.php registrations using the ApiAuthMiddleware + ApiAbilityMiddleware ability-closure pattern, DI wiring if a new use case/repository is needed, and a PHPUnit test skeleton. USE FOR: 'add API endpoint', expose resource via REST, extend /api/v1. DO NOT USE FOR: public unauthenticated feeds (FeedController pattern), webhook receivers (use vale-webhook), frontend AJAX form endpoints."
license: AGPL-3.0
metadata:
  project: vale/vale
  reference: vale-docs/en/latest/developer/api.md
---

# Add a REST API v1 Endpoint

All API routes live under `/api/v1` in `routes/api.php`, protected per-route by **Bearer token auth** (`ApiAuthMiddleware`) plus an **ability check** (`ApiAbilityMiddleware`, e.g. `coupons:read`). Tokens are managed in Admin → Settings → Advanced → API Tokens; each token carries comma-separated abilities or `*`. The whole group is wrapped by the `rate_limit.api` middleware — do not remove it.

## Phase 1 — Ask (required before writing any code)

1. Resource name (singular + plural)? → drives route path, controller name, ability prefix (e.g. coupons → `read|write|delete:coupons`. Caution: follow verb:resource)
2. Operations: index/show/store/update/destroy — which?
3. Backing data: existing repository interface (which?) or new one? If new → also plan Domain interface + Dbal implementation + DI factory (follow AGENTS.md §17 patterns)
4. Pagination needed for list endpoints? (default yes)
5. Which fields are writable, and what validation applies (required/format)?

## Phase 2 — Generate

### Step 1 — Controller: `app/Controllers/App/../Api/CouponApiController.php`

Path: `app/Controllers/Api/CouponApiController.php`. Model on `MediaApiController`:

```php
<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Api;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ValeApp\Domain\Shared\ValueObject\Pagination;

final class CouponApiController
{
    public function __construct(
        private readonly \ValeApp\Domain\Shop\Repository\CouponRepositoryInterface $coupons,
    ) {}

    /** GET /api/v1/coupons */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params  = $request->getQueryParams();
        $page    = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($params['per_page'] ?? 30)));

        $items = $this->coupons->findAll(new Pagination($page, $perPage));
        $total = $this->coupons->count();

        return $this->json($response, [
            'data' => array_map(fn ($c) => $this->serializeOne($c), $items),
            'meta' => [
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => (int) max(1, ceil($total / max(1, $perPage))),
            ],
        ]);
    }

    /** GET /api/v1/coupons/{slug} */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $coupon = $this->coupons->findByCode((string) ($args['slug'] ?? ''));
        if ($coupon === null) {
            return $this->error($response, 404, 'Resource not found');
        }
        return $this->json($response, ['data' => $this->serializeOne($coupon)]);
    }

    /** @return array<string,mixed> */
    private function serializeOne(object $c): array
    {
        return [
            'id'   => $c->getId(),
            // ... map entity getters to snake_case keys
        ];
    }

    /** @param array<string,mixed> $data */
    private function json(ResponseInterface $response, array $data): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function error(ResponseInterface $response, int $status, string $message): ResponseInterface
    {
        return $this->json($response->withStatus($status), ['error' => $message]);
    }
}
```

Conventions:
- Inject **repository interfaces** (or Application use cases) in the constructor — never DBAL directly.
- Write operations read `(array) $request->getParsedBody()`; current user id comes from `$request->getAttribute('api_user_id')`.
- Validation failure shape (match existing endpoints):

```php
return $this->json($response->withStatus(422), [
    'error'  => 'Validation failed',
    'errors' => ['code' => 'Code is required.'],
]);
```

- Creating/updating/deleting resources that have webhook events should fire them via `OutboundWebhookService::dispatch('coupon.created', [...])` — see vale-webhook skill.
- Sub-resource "note"-style endpoints (e.g. `POST /api/v1/orders/{id}/notes` in `OrderApiController`) write through an existing log repository (`OrderLogRepositoryInterface::add(...)`) with `added_by = 'API'` — no migration needed; the note appears in the admin detail timeline. This is also the standard write-back path for webhook receivers (see vale-webhook, vale-integrate). `GET`/`DELETE` note variants (`OrderApiController::notes` / `destroyNote`, the latter restricted to `type=note` rows) complete that sub-resource.
- When an API operation mirrors an admin workflow with heavy side effects (order status changes: stock, coupons, subscriptions, mail), **extract the admin logic into an Application use case first** and have both the admin controller and the API controller call it — `ChangeOrderStatusUseCase` / `UpdateOrderTrackingUseCase` in `src/Application/Shop/` are the reference pattern. Never reimplement the side-effect chain in the API layer.
- When an API write must not touch financial records, declare a protected-field list in the controller and reject those keys with `422` (see `OrderApiController::PROTECTED_FIELDS` for the `PUT /orders/{id}` pattern) instead of silently ignoring them.

### Step 2 — Routes: `routes/api.php`

Inside the `/api/v1` group, follow the exact middleware stacking order (ability closure FIRST via `->add()`, then auth — LIFO means auth runs first):

```php
$group->get('/coupons', [CouponApiController::class, 'index'])
    ->setName('api.coupons.index')
    ->add(function ($req, $handler) use ($c) {
        return $c->get(ApiAbilityMiddleware::class)($req, $handler, 'coupons:read');
    })
    ->add($c->get(ApiAuthMiddleware::class));

$group->post('/coupons', [CouponApiController::class, 'store'])
    ->setName('api.coupons.store')
    ->add(function ($req, $handler) use ($c) {
        return $c->get(ApiAbilityMiddleware::class)($req, $handler, 'coupons:write');
    })
    ->add($c->get(ApiAuthMiddleware::class));
```

Add the controller's `use` import at the top of the file.

### Step 3 — New repository? (only when Phase 1 answer says so)

Follow AGENTS.md §17: interface in `src/Domain/<Module>/Repository/`, implementation `src/Infrastructure/Persistence/Dbal<Camel>Repository.php` (constructor-injected `Connection`, table names via `VL_DB_PREFIX` constant, prepared statements only), factory binding in `config/services.php`.

### Step 4 — Test skeleton: `tests/Integration/Api/CouponApiControllerTest.php`

Place under the matching suite — existing API controller tests live in `tests/Integration/Api/` (e.g. `ProductApiControllerTest.php`, `SubscriberApiControllerTest.php`). Unit-test serialization/validation logic in `PHPUnit\Framework\TestCase` with stubbed repositories, `Slim\Psr7\Factory\RequestFactory`/`ResponseFactory` and the controller called directly; integration-test through the router if a fixture exists to copy. Never hit a live database from unit tests. Stub argument checks via `willReturnCallback` capture — never `with()` on a stub (AGENTS.md §15.1).

### Step 5 — Token abilities note

New abilities (`coupons:*`) work automatically — tokens stored as comma-separated strings; `*` grants everything. Mention to the user that they must create/update a token with the new abilities in Admin → Settings → Advanced → API Tokens before testing.

## Phase 3 — Verify

- `php -l` on touched PHP files
- `composer lint:changed`
- `vendor/bin/phpunit tests/Api` (or the created test)
- Manual smoke test with curl:

```bash
curl -H "Authorization: Bearer TOKEN" http://localhost/api/v1/coupons?page=1
```

Expect `{data: [...], meta: {...}}`; without token expect 401; wrong ability expect 403.

## Checklist

- [ ] Controller follows envelope/error helpers pattern
- [ ] Routes registered with correct ability strings + both middlewares
- [ ] Rate-limit group middleware untouched
- [ ] New repository wired in DI (if applicable)
- [ ] Tests added and green
- [ ] resources\views\admin\api-tokens user side text ability etc. updated
- [ ] resources\views\admin\webhooks user side text ability etc. updated
- [ ] Docs: update `vale-docs/en/latest/developer/api.md` endpoint list (only `en/latest`, not versioned snapshots)
- [ ] Docs: update `vale-docs/en/latest/admin/settings/advanced.md` ability list (and webhook event table if a new outbound event was added)
- [ ] Docs: update `vale-docs/en/latest/developer/api.md` webhook payload note if a new event fires
- [ ] Skills: update `.agents/skills/vale-integrate/SKILL.md` & `REFERENCE.md` if endpoint schema or payload shape changed
