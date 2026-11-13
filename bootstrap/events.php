<?php

declare(strict_types=1);

use DI\Container;
use ValeApp\Application\Mail\MailService;
use ValeApp\Application\Webhook\OutboundWebhookService;
use ValeApp\Domain\Content\Entity\Post;
use ValeApp\Domain\Shared\Event\EventDispatcherInterface;
use ValeApp\Domain\Shop\Entity\Order;
use ValeApp\Domain\Shop\Entity\Product;
use ValeApp\Domain\Shop\Repository\DownloadRepositoryInterface;
use ValeApp\Domain\Shop\Repository\OrderRepositoryInterface;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;
use ValeApp\Domain\User\Entity\User;
use ValeApp\Infrastructure\Cache\PageCache;
use ValeApp\Infrastructure\Cache\PageCacheInvalidator;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;

/**
 * Domain event listeners: outbound webhook delivery + page-cache invalidation.
 *
 * Loaded by both entry points so behaviour never depends on how the code ran:
 *   - web / pseudo-cron:  bootstrap/app.php
 *   - CLI (`php vale …`):  the vale console script
 *
 * Page-cache invalidation lives in PageCacheInvalidator so a scheduled cron
 * publish drops exactly the same URLs as an admin save.
 */
return static function (Container $container): void {
    $events      = $container->get(EventDispatcherInterface::class);
    $webhookSvc  = $container->get(OutboundWebhookService::class);
    $productRepo = $container->get(ProductRepositoryInterface::class);
    $pageCache   = $container->get(PageCache::class);
    $invalidator = $container->get(PageCacheInvalidator::class);

    $buildPayload = static function (Order $order, array $extra = []): array {
        $billing = $order->getBilling();
        return array_merge([
            'order_id'             => $order->getId(),
            'order_number'         => $order->getOrderNumber(),
            'order_status'         => $order->getStatus(),
            'order_total'          => (string) $order->getTotal(),
            'currency'             => $order->getCurrency(),
            'order_customer_email' => $billing['email'] ?? '',
            'order_customer_name'  => trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? '')),
            'items'                => $order->getItems(),
        ], $extra);
    };

    $buildPostPayload = static function (Post $post): array {
        return [
            'post_id'           => $post->getId(),
            'post_title'        => $post->getTitle(),
            'post_slug'         => (string) $post->getSlug(),
            'post_status'       => $post->getStatus(),
            'post_content_type' => $post->getContentType(),
            'post_author_id'    => $post->getAuthorId(),
            'post_published_at' => $post->getPublishedAt()?->format(\DateTimeInterface::ATOM),
        ];
    };

    $buildUserPayload = static function (User $user): array {
        return [
            'user_id'      => $user->getId(),
            'user_name'    => $user->getName(),
            'user_email'   => (string) $user->getEmail(),
            'roles'        => $user->getRoles(),
        ];
    };

    $events->addAction('order.paid', static function (Order $order) use ($webhookSvc, $buildPayload): void {
        $webhookSvc->dispatch('order.paid', $buildPayload($order));
    });

    $events->addAction('order.paid', static function (Order $order) use ($container, $productRepo, $pageCache): void {
        // Atomically claim inventory processing — survives concurrent webhook
        // + browser-return races and repeated event fires (persisted flag).
        /** @var OrderRepositoryInterface $orders */
        $orders = $container->get(OrderRepositoryInterface::class);
        if (!$orders->claimInventoryProcessing((int) $order->getId())) {
            return;
        }
        $order->setInventoryProcessed(true);
        $order->setInventoryRestored(null);

        /** @var DbalSettingsRepository $settings */
        $settings       = $container->get(DbalSettingsRepository::class);
        $mailSvc        = $container->get(MailService::class);
        $warningEnabled = $settings->get('low_stock_warning_enabled', '0') === '1';
        $threshold      = (int) ($settings->get('low_stock_warning_threshold', '2') ?? '2');

        foreach ($order->getItems() as $item) {
            $productId   = (int) ($item['product_id'] ?? 0);
            $qty         = (int) ($item['qty'] ?? $item['quantity'] ?? 1);
            $variationId = isset($item['variation_id']) && $item['variation_id'] !== null
                ? (int) $item['variation_id']
                : null;

            if ($productId > 0 && $qty > 0) {
                $productRepo->incrementSold($productId, $qty);
                $productRepo->decrementStock($productId, $qty, $variationId);

                $product = $productRepo->findById($productId);
                if ($product !== null) {
                    $shopPrefix = $container->get(DbalSettingsRepository::class)->get('routing_shop_prefix', '/shop');
                    $pageCache->invalidate(site_url($shopPrefix . '/' . (string) $product->getSlug()));

                    // Stock warning logic
                    if ($warningEnabled) {
                        $currentStock = $product->getStockQuantity();
                        $status       = $product->getStockStatus();
                        $sku          = $product->getSku();
                        $varName      = null;

                        if ($variationId !== null) {
                            $variations = $productRepo->findVariationsByProductId($productId);
                            foreach ($variations as $v) {
                                if ((int) ($v['id'] ?? 0) === $variationId) {
                                    $currentStock = (int) ($v['stock_quantity'] ?? 0);
                                    $status       = (string) ($v['stock_status'] ?? 'in_stock');
                                    $sku          = !empty($v['sku']) ? $v['sku'] : $sku;
                                    $attrs        = is_string($v['attributes']) ? json_decode($v['attributes'], true) : $v['attributes'];
                                    if (is_array($attrs)) {
                                        $varName = implode(', ', array_map(fn ($k, $v) => "$k: $v", array_keys($attrs), $attrs));
                                    }
                                    break;
                                }
                            }
                        }

                        // Trigger only when the product is explicitly out of stock,
                        // or when it is tracked and has reached the low stock threshold.
                        $isOut = $status === 'out_of_stock';
                        $isLow = ($status === 'in_stock' && $currentStock > 0 && $currentStock <= $threshold);

                        if ($isOut || $isLow) {
                            $adminEmail = $settings->get('admin_email', $settings->get('mail_from_address', ''));
                            if (!empty($adminEmail)) {
                                try {
                                    $mailSvc->sendTemplate(
                                        $adminEmail,
                                        ($isOut ? '[OUT OF STOCK] ' : '[LOW STOCK] ') . $product->getName(),
                                        'mail/stock-warning-admin',
                                        [
                                            'product_name'      => $product->getName(),
                                            'variation_name'    => $varName,
                                            'sku'               => $sku,
                                            'current_stock'     => $currentStock,
                                            'stock_status'      => $status,
                                            'is_out_of_stock'   => $isOut,
                                            'admin_edit_url'    => admin_url('/products/edit/' . $product->getId()),
                                        ],
                                        recipientType: 'admin',
                                    );
                                } catch (\Throwable) {
                                    // Non-fatal
                                }
                            }
                        }
                    }
                }
            }
        }
        $shopPrefix = $container->get(DbalSettingsRepository::class)->get('routing_shop_prefix', '/shop');
        $pageCache->invalidate(site_url($shopPrefix));
        /** @var DownloadRepositoryInterface $dlRepo */
        $dlRepo = $container->get(DownloadRepositoryInterface::class);
        /** @var ProductRepositoryInterface $pRepo */
        $pRepo = $container->get(ProductRepositoryInterface::class);

        foreach ($order->getItems() as $index => $item) {
            $productId   = (int) ($item['product_id'] ?? 0);
            $variationId = isset($item['variation_id']) && $item['variation_id'] !== null
                ? (int) $item['variation_id']
                : null;

            if ($productId <= 0) {
                continue;
            }

            $product = $pRepo->findById($productId);
            if ($product === null || !$product->isDigital()) {
                continue;
            }

            $parentLimit  = (int) $product->getDownloadLimit();
            $parentExpiry = (int) $product->getDownloadExpiryDays();
            $parentProxy  = (bool) $product->isDownloadUrlProxy();

            /** @var array<int,array{source_type:string,source_file_id:int|null,source_url:string|null,download_url_proxy:bool,download_limit:int,download_expiry_days:int}> $parentSources */
            $parentSources    = [];
            $parentSourceType = $product->getDownloadSource();
            if ($parentSourceType === 'internal') {
                $downloadFileIds = $pRepo->findDownloadFileIds($productId);
                if ($downloadFileIds === []) {
                    $legacyFileId    = $product->getDownloadFileId();
                    $downloadFileIds = $legacyFileId ? [(int) $legacyFileId] : [];
                }
                foreach ($downloadFileIds as $downloadFileId) {
                    $parentSources[] = [
                        'source_type'          => 'internal',
                        'source_file_id'       => (int) $downloadFileId,
                        'source_url'           => null,
                        'download_url_proxy'   => false,
                        'download_limit'       => $parentLimit,
                        'download_expiry_days' => $parentExpiry,
                    ];
                }
            } elseif ($parentSourceType === 'external') {
                $parentUrl = trim((string) ($product->getDownloadUrl() ?? ''));
                if ($parentUrl !== '') {
                    $parentSources[] = [
                        'source_type'          => 'external',
                        'source_file_id'       => null,
                        'source_url'           => $parentUrl,
                        'download_url_proxy'   => $parentProxy,
                        'download_limit'       => $parentLimit,
                        'download_expiry_days' => $parentExpiry,
                    ];
                }
            }

            /** @var array<int,array{source_type:string,source_file_id:int|null,source_url:string|null,download_url_proxy:bool,download_limit:int,download_expiry_days:int}> $variationSources */
            $variationSources = [];
            $skipDownloads    = false;

            if ($variationId !== null) {
                $variations = $pRepo->findVariationsByProductId($productId);
                foreach ($variations as $v) {
                    if ((int) $v['id'] !== $variationId) {
                        continue;
                    }

                    $mode = $v['download_mode'] ?? 'use_parent';
                    if ($mode === 'non_digital') {
                        $mode = 'physical';
                    }

                    if ($mode === 'physical') {
                        $skipDownloads = true;
                        break;
                    }

                    $variationLimit = isset($v['download_limit']) && (int) $v['download_limit'] > 0
                        ? (int) $v['download_limit']
                        : $parentLimit;
                    $variationExpiry = isset($v['download_expiry_days']) && (int) $v['download_expiry_days'] > 0
                        ? (int) $v['download_expiry_days']
                        : $parentExpiry;
                    $variationProxy = isset($v['download_url_proxy'])
                        ? (bool) $v['download_url_proxy']
                        : $parentProxy;

                    if ($mode === 'internal') {
                        $varDownloadFileIds = $pRepo->findVariationDownloadFileIds((int) $v['id']);
                        if ($varDownloadFileIds === []) {
                            $legacyVarFileId    = !empty($v['download_file_id']) ? (int) $v['download_file_id'] : 0;
                            $varDownloadFileIds = $legacyVarFileId > 0 ? [$legacyVarFileId] : [];
                        }
                        foreach ($varDownloadFileIds as $downloadFileId) {
                            $variationSources[] = [
                                'source_type'          => 'internal',
                                'source_file_id'       => (int) $downloadFileId,
                                'source_url'           => null,
                                'download_url_proxy'   => false,
                                'download_limit'       => $variationLimit,
                                'download_expiry_days' => $variationExpiry,
                            ];
                        }
                    } elseif ($mode === 'external') {
                        $variationUrl = trim((string) ($v['download_url'] ?? ''));
                        if ($variationUrl !== '') {
                            $variationSources[] = [
                                'source_type'          => 'external',
                                'source_file_id'       => null,
                                'source_url'           => $variationUrl,
                                'download_url_proxy'   => $variationProxy,
                                'download_limit'       => $variationLimit,
                                'download_expiry_days' => $variationExpiry,
                            ];
                        }
                    }

                    break;
                }
            }

            if ($skipDownloads) {
                continue;
            }

            $sources = $parentSources;
            if ($variationSources !== []) {
                // Variation-specific downloads augment parent downloads (inherit behavior).
                $sources = array_merge($parentSources, $variationSources);
            }

            if ($sources === []) {
                continue;
            }

            $seen = [];
            foreach ($sources as $source) {
                $key = $source['source_type'] === 'internal'
                    ? 'internal:' . (string) ($source['source_file_id'] ?? 0)
                    : 'external:' . (string) ($source['source_url'] ?? '');
                if ($key === 'internal:0' || $key === 'external:') {
                    continue;
                }
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $expiresAt = null;
                if ((int) $source['download_expiry_days'] > 0) {
                    $days      = (int) $source['download_expiry_days'];
                    $expiresAt = (new \DateTimeImmutable())->modify("+{$days} days")->format('Y-m-d H:i:s');
                }

                $dlRepo->createGrant([
                    'order_id'           => $order->getId(),
                    'order_item_index'   => $index,
                    'product_id'         => $productId,
                    'variation_id'       => $variationId,
                    'product_name'       => (string) ($item['name'] ?? $product->getTitle()),
                    'token'              => bin2hex(random_bytes(32)),
                    'source_type'        => $source['source_type'],
                    'source_file_id'     => $source['source_type'] === 'internal' ? $source['source_file_id'] : null,
                    'source_url'         => $source['source_type'] === 'external' ? $source['source_url'] : null,
                    'download_url_proxy' => (bool) ($source['download_url_proxy'] ?? false),
                    'download_limit'     => (int) $source['download_limit'],
                    'expires_at'         => $expiresAt,
                ]);
            }
        }
    });

    $events->addAction('order.reversed', static function (Order $order) use ($container, $productRepo, $pageCache): void {
        // Atomically claim the restoration (persisted, race-safe).
        /** @var OrderRepositoryInterface $orders */
        $orders = $container->get(OrderRepositoryInterface::class);
        if (!$orders->claimInventoryRestored((int) $order->getId())) {
            return;
        }
        $order->setInventoryRestored(true);
        $order->setInventoryProcessed(null);

        foreach ($order->getItems() as $item) {
            $productId   = (int) ($item['product_id'] ?? 0);
            $qty         = (int) ($item['qty'] ?? $item['quantity'] ?? 1);
            $variationId = isset($item['variation_id']) && $item['variation_id'] !== null
                ? (int) $item['variation_id']
                : null;

            if ($productId > 0 && $qty > 0) {
                $productRepo->decrementSold($productId, $qty);
                $productRepo->incrementStock($productId, $qty, $variationId);

                $product = $productRepo->findById($productId);
                if ($product !== null) {
                    $shopPrefix = $container->get(DbalSettingsRepository::class)->get('routing_shop_prefix', '/shop');
                    $pageCache->invalidate(site_url($shopPrefix . '/' . (string) $product->getSlug()));
                }
            }
        }
        $shopPrefix = $container->get(DbalSettingsRepository::class)->get('routing_shop_prefix', '/shop');
        $pageCache->invalidate(site_url($shopPrefix));
    });
    $events->addAction('order.status_changed', static function (Order $order, string $newStatus) use ($webhookSvc, $buildPayload): void {
        $webhookSvc->dispatch('order.status_changed', $buildPayload($order, ['new_status' => $newStatus]));
    });

    $events->addAction('post.created', static function (Post $post) use ($webhookSvc, $buildPostPayload): void {
        $webhookSvc->dispatch('post.created', $buildPostPayload($post));
    });

    $events->addAction('post.published', static function (Post $post) use ($webhookSvc, $buildPostPayload, $invalidator): void {
        $webhookSvc->dispatch('post.published', $buildPostPayload($post));
        $invalidator->forPost($post);
    });

    $events->addAction('post.updated', static function (Post $post) use ($webhookSvc, $buildPostPayload, $invalidator): void {
        $webhookSvc->dispatch('post.updated', $buildPostPayload($post));
        $invalidator->forPost($post);
    });

    $buildProductPayload = static function (Product $product) use ($productRepo): array {
        $variations = [];
        try {
            $rows = $productRepo->findVariationsByProductId((int) $product->getId());
        } catch (\Throwable) {
            $rows = [];
        }
        foreach ($rows as $row) {
            $attrs = $row['attributes'] ?? [];
            if (is_string($attrs)) {
                $decoded = json_decode($attrs, true);
                $attrs   = is_array($decoded) ? $decoded : [];
            }
            $variations[] = [
                'id'             => (int) ($row['id'] ?? 0),
                'sku'            => $row['sku'] ?? null,
                'attributes'     => $attrs,
                'price'          => (float) ($row['price'] ?? 0),
                'sale_price'     => isset($row['sale_price']) && $row['sale_price'] !== null
                    ? (float) $row['sale_price'] : null,
                'stock_quantity' => (int) ($row['stock_quantity'] ?? 0),
                'stock_status'   => $row['stock_status'] ?? 'in_stock',
            ];
        }

        return [
            'product_id'         => $product->getId(),
            'product_name'       => $product->getName(),
            'product_slug'       => (string) $product->getSlug(),
            'product_sku'        => $product->getSku(),
            'product_status'     => $product->getStatus(),
            'product_price'      => $product->getPrice(),
            'product_sale_price' => $product->getSalePrice(),
            'product_stock'      => $product->getStockQuantity(),
            'variations'         => $variations,
        ];
    };

    $events->addAction('product.created', static function (Product $product) use ($webhookSvc, $buildProductPayload, $invalidator): void {
        $webhookSvc->dispatch('product.created', $buildProductPayload($product));
        $invalidator->forProduct($product);
    });

    $events->addAction('product.updated', static function (Product $product) use ($webhookSvc, $buildProductPayload, $invalidator): void {
        $webhookSvc->dispatch('product.updated', $buildProductPayload($product));
        $invalidator->forProduct($product);
    });

    $events->addAction('product.deleted', static function (array $payload) use ($webhookSvc): void {
        $webhookSvc->dispatch('product.deleted', $payload);
    });

    $events->addAction('user.registered', static function (User $user) use ($webhookSvc, $buildUserPayload): void {
        $webhookSvc->dispatch('user.registered', $buildUserPayload($user));
    });
};
