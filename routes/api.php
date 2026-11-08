<?php

/**
 * REST API v1 routes — JSON responses, no CSRF.
 */

declare(strict_types=1);

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use ValeApp\App\Controllers\Api\FeedController;
use ValeApp\App\Controllers\Api\HealthController;
use ValeApp\App\Controllers\Api\MediaApiController;
use ValeApp\App\Controllers\Api\OrderApiController;
use ValeApp\App\Controllers\Api\PostApiController;
use ValeApp\App\Controllers\Api\ProductApiController;
use ValeApp\App\Controllers\Api\SubscriberApiController;
use ValeApp\App\Middleware\ApiAbilityMiddleware;
use ValeApp\App\Middleware\ApiAuthMiddleware;

return function (App $app): void {
    $c = $app->getContainer();

    $app->group('/api/v1', function (RouteCollectorProxy $group) use ($c): void {
        // Health check — no auth required. Plain mode is a static liveness
        // proof (used by the Docker HEALTHCHECK); ?deep=1 adds DB + storage
        // verification with per-IP throttling for external monitors.
        $group->get('/health', [HealthController::class, 'index'])->setName('api.health');

        // ── Posts & Pages ─────────────────────────────────────────────
        $group->get('/posts', [PostApiController::class, 'index'])
            ->setName('api.posts.index')
            ->add(function ($req, $handler) use ($c) {
                return $c->get(ApiAbilityMiddleware::class)($req, $handler, 'read:posts');
            })
            ->add($c->get(ApiAuthMiddleware::class));
        $group->get('/posts/{slug}', [PostApiController::class, 'show'])
            ->setName('api.posts.show')
            ->add(function ($req, $handler) use ($c) {
                return $c->get(ApiAbilityMiddleware::class)($req, $handler, 'read:posts');
            })
            ->add($c->get(ApiAuthMiddleware::class));

        $contentTypeMiddleware = static function (string $type, string $ability) use ($c): callable {
            return function ($req, $handler) use ($c, $type, $ability) {
                $req = $req->withAttribute('_content_type', $type);
                return $c->get(ApiAbilityMiddleware::class)($req, $handler, "{$ability}:{$type}s");
            };
        };

        $group->post('/posts', [PostApiController::class, 'store'])
            ->setName('api.posts.store')
            ->add($contentTypeMiddleware('post', 'write'))
            ->add($c->get(ApiAuthMiddleware::class));
        $group->put('/posts/{id}', [PostApiController::class, 'update'])
            ->setName('api.posts.update')
            ->add($contentTypeMiddleware('post', 'write'))
            ->add($c->get(ApiAuthMiddleware::class));
        $group->delete('/posts/{id}', [PostApiController::class, 'destroy'])
            ->setName('api.posts.destroy')
            ->add(function ($req, $handler) use ($c) {
                return $c->get(ApiAbilityMiddleware::class)($req, $handler, 'delete:posts');
            })
            ->add($c->get(ApiAuthMiddleware::class));

        $group->post('/pages', [PostApiController::class, 'store'])
            ->setName('api.pages.store')
            ->add($contentTypeMiddleware('page', 'write'))
            ->add($c->get(ApiAuthMiddleware::class));
        $group->put('/pages/{id}', [PostApiController::class, 'update'])
            ->setName('api.pages.update')
            ->add($contentTypeMiddleware('page', 'write'))
            ->add($c->get(ApiAuthMiddleware::class));
        $group->delete('/pages/{id}', [PostApiController::class, 'destroy'])
            ->setName('api.pages.destroy')
            ->add(function ($req, $handler) use ($c) {
                return $c->get(ApiAbilityMiddleware::class)($req, $handler, 'delete:pages');
            })
            ->add($c->get(ApiAuthMiddleware::class));

        $group->get('/pages', [PostApiController::class, 'pageIndex'])
            ->setName('api.pages.index')
            ->add(function ($req, $handler) use ($c) {
                return $c->get(ApiAbilityMiddleware::class)($req, $handler, 'read:pages');
            })
            ->add($c->get(ApiAuthMiddleware::class));
        $group->get('/pages/{slug}', [PostApiController::class, 'pageShow'])
            ->setName('api.pages.show')
            ->add(function ($req, $handler) use ($c) {
                return $c->get(ApiAbilityMiddleware::class)($req, $handler, 'read:pages');
            })
            ->add($c->get(ApiAuthMiddleware::class));

        // ── Products ──────────────────────────────────────────────────
        $group->get('/products', [ProductApiController::class, 'index'])
            ->setName('api.products.index')
            ->add(function ($req, $handler) use ($c) {
                return $c->get(ApiAbilityMiddleware::class)($req, $handler, 'read:products');
            })
            ->add($c->get(ApiAuthMiddleware::class));
        $group->get('/products/id/{id}', [ProductApiController::class, 'showById'])
            ->setName('api.products.showById')
            ->add(function ($req, $handler) use ($c) {
                return $c->get(ApiAbilityMiddleware::class)($req, $handler, 'read:products');
            })
            ->add($c->get(ApiAuthMiddleware::class));
        $group->get('/products/{slug}', [ProductApiController::class, 'show'])
            ->setName('api.products.show')
            ->add(function ($req, $handler) use ($c) {
                return $c->get(ApiAbilityMiddleware::class)($req, $handler, 'read:products');
            })
            ->add($c->get(ApiAuthMiddleware::class));

        $group->post('/products/categories', [ProductApiController::class, 'storeCategory'])
            ->setName('api.products.categories.store')
            ->add(function ($req, $handler) use ($c) {
                return $c->get(ApiAbilityMiddleware::class)($req, $handler, 'write:products');
            })
            ->add($c->get(ApiAuthMiddleware::class));

        $group->post('/products', [ProductApiController::class, 'store'])
            ->setName('api.products.store')
            ->add(function ($req, $handler) use ($c) {
                return $c->get(ApiAbilityMiddleware::class)($req, $handler, 'write:products');
            })
            ->add($c->get(ApiAuthMiddleware::class));

        $group->put('/products/{id}', [ProductApiController::class, 'update'])
            ->setName('api.products.update')
            ->add(function ($req, $handler) use ($c) {
                return $c->get(ApiAbilityMiddleware::class)($req, $handler, 'write:products');
            })
            ->add($c->get(ApiAuthMiddleware::class));

        $group->delete('/products/{id}', [ProductApiController::class, 'destroy'])
            ->setName('api.products.destroy')
            ->add(function ($req, $handler) use ($c) {
                return $c->get(ApiAbilityMiddleware::class)($req, $handler, 'delete:products');
            })
            ->add($c->get(ApiAuthMiddleware::class));

        // ── Orders ───────────────────────────────────────────────
        $group->get('/orders', [OrderApiController::class, 'index'])
            ->setName('api.orders.index')
            ->add(function ($req, $handler) use ($c) {
                return $c->get(ApiAbilityMiddleware::class)($req, $handler, 'read:orders');
            })
            ->add($c->get(ApiAuthMiddleware::class));

        $group->get('/orders/{id}', [OrderApiController::class, 'show'])
            ->setName('api.orders.show')
            ->add(function ($req, $handler) use ($c) {
                return $c->get(ApiAbilityMiddleware::class)($req, $handler, 'read:orders');
            })
            ->add($c->get(ApiAuthMiddleware::class));

        $group->get('/orders/{id}/notes', [OrderApiController::class, 'notes'])
            ->setName('api.orders.notes.index')
            ->add(function ($req, $handler) use ($c) {
                return $c->get(ApiAbilityMiddleware::class)($req, $handler, 'read:orders');
            })
            ->add($c->get(ApiAuthMiddleware::class));

        $group->post('/orders/{id}/notes', [OrderApiController::class, 'storeNote'])
            ->setName('api.orders.notes.store')
            ->add(function ($req, $handler) use ($c) {
                return $c->get(ApiAbilityMiddleware::class)($req, $handler, 'write:orders');
            })
            ->add($c->get(ApiAuthMiddleware::class));

        $group->delete('/orders/{id}/notes/{noteId}', [OrderApiController::class, 'destroyNote'])
            ->setName('api.orders.notes.destroy')
            ->add(function ($req, $handler) use ($c) {
                return $c->get(ApiAbilityMiddleware::class)($req, $handler, 'write:orders');
            })
            ->add($c->get(ApiAuthMiddleware::class));

        $group->post('/orders', [OrderApiController::class, 'store'])
            ->setName('api.orders.store')
            ->add(function ($req, $handler) use ($c) {
                return $c->get(ApiAbilityMiddleware::class)($req, $handler, 'write:orders');
            })
            ->add($c->get(ApiAuthMiddleware::class));

        $group->put('/orders/{id}', [OrderApiController::class, 'update'])
            ->setName('api.orders.update')
            ->add(function ($req, $handler) use ($c) {
                return $c->get(ApiAbilityMiddleware::class)($req, $handler, 'write:orders');
            })
            ->add($c->get(ApiAuthMiddleware::class));

        // ── Media ─────────────────────────────────────────────────────
        $group->get('/media', [MediaApiController::class, 'index'])
            ->setName('api.media.index')
            ->add(function ($req, $handler) use ($c) {
                return $c->get(ApiAbilityMiddleware::class)($req, $handler, 'read:media');
            })
            ->add($c->get(ApiAuthMiddleware::class));
        $group->post('/media', [MediaApiController::class, 'store'])
            ->setName('api.media.store')
            ->add(function ($req, $handler) use ($c) {
                return $c->get(ApiAbilityMiddleware::class)($req, $handler, 'write:media');
            })
            ->add($c->get(ApiAuthMiddleware::class));
        $group->delete('/media/{id}', [MediaApiController::class, 'destroy'])
            ->setName('api.media.destroy')
            ->add(function ($req, $handler) use ($c) {
                return $c->get(ApiAbilityMiddleware::class)($req, $handler, 'delete:media');
            })
            ->add($c->get(ApiAuthMiddleware::class));

        // ── Newsletter Subscribers ────────────────────────────────────
        $group->get('/subscribers', [SubscriberApiController::class, 'index'])
            ->setName('api.subscribers.index')
            ->add(function ($req, $handler) use ($c) {
                return $c->get(ApiAbilityMiddleware::class)($req, $handler, 'read:subscribers');
            })
            ->add($c->get(ApiAuthMiddleware::class));
        $group->get('/subscribers/{id}', [SubscriberApiController::class, 'show'])
            ->setName('api.subscribers.show')
            ->add(function ($req, $handler) use ($c) {
                return $c->get(ApiAbilityMiddleware::class)($req, $handler, 'read:subscribers');
            })
            ->add($c->get(ApiAuthMiddleware::class));
        $group->post('/subscribers', [SubscriberApiController::class, 'store'])
            ->setName('api.subscribers.store')
            ->add(function ($req, $handler) use ($c) {
                return $c->get(ApiAbilityMiddleware::class)($req, $handler, 'write:subscribers');
            })
            ->add($c->get(ApiAuthMiddleware::class));
        $group->put('/subscribers/{id}', [SubscriberApiController::class, 'update'])
            ->setName('api.subscribers.update')
            ->add(function ($req, $handler) use ($c) {
                return $c->get(ApiAbilityMiddleware::class)($req, $handler, 'write:subscribers');
            })
            ->add($c->get(ApiAuthMiddleware::class));
        $group->delete('/subscribers/{id}', [SubscriberApiController::class, 'destroy'])
            ->setName('api.subscribers.destroy')
            ->add(function ($req, $handler) use ($c) {
                return $c->get(ApiAbilityMiddleware::class)($req, $handler, 'delete:subscribers');
            })
            ->add($c->get(ApiAuthMiddleware::class));

        // ── Feeds ─────────────────────────────────────────────────────
        $group->get('/feed/google-merchant', [FeedController::class, 'googleMerchant'])
            ->setName('api.feed.google-merchant');
        $group->get('/feed/bing', [FeedController::class, 'bing'])
            ->setName('api.feed.bing');
        $group->get('/feed/meta', [FeedController::class, 'meta'])
            ->setName('api.feed.meta');
        $group->get('/feed/pinterest', [FeedController::class, 'pinterest'])
            ->setName('api.feed.pinterest');
        $group->get('/feed/idealo', [FeedController::class, 'idealo'])
            ->setName('api.feed.idealo');
        $group->get('/feed/shopify', [FeedController::class, 'shopify'])
            ->setName('api.feed.shopify');
        $group->get('/feed/tiktok', [FeedController::class, 'tiktok'])
            ->setName('api.feed.tiktok');
        $group->get('/feed/yandex-market', [FeedController::class, 'yandexMarket'])
            ->setName('api.feed.yandex-market');
        $group->get('/feed/google-taxonomy', [FeedController::class, 'browseTaxonomy'])
            ->setName('api.feed.google-taxonomy')
            ->add($c->get(ApiAuthMiddleware::class));
    })->add($c->get('rate_limit.api'));
};
