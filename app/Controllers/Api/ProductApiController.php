<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Api;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ValeApp\Domain\Media\Repository\MediaRepositoryInterface;
use ValeApp\Domain\Shared\Event\EventDispatcherInterface;
use ValeApp\Domain\Shared\Exception\DomainException;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\Shop\Entity\Product;
use ValeApp\Domain\Shop\Entity\ProductCategory;
use ValeApp\Domain\Shop\Repository\ProductCategoryRepositoryInterface;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;
use ValeApp\Infrastructure\Content\SlugService;
use ValeApp\Infrastructure\Media\RemoteImageImporter;

final class ProductApiController
{
    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly ProductCategoryRepositoryInterface $categories,
        private readonly MediaRepositoryInterface $mediaRepo,
        private readonly RemoteImageImporter $imageImporter,
        private readonly SlugService $slugService,
        private readonly EventDispatcherInterface $events,
    ) {
    }

    /** GET /api/v1/products */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params  = $request->getQueryParams();
        $page    = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($params['per_page'] ?? 15)));
        $filters = ['status' => $params['status'] ?? 'published'];

        foreach (['sku', 'search', 'type'] as $key) {
            if (isset($params[$key]) && trim((string) $params[$key]) !== '') {
                $filters[$key] = trim((string) $params[$key]);
            }
        }
        if (isset($params['on_sale']) && in_array(strtolower((string) $params['on_sale']), ['1', 'true', 'yes'], true)) {
            $filters['on_sale'] = true;
        }

        // If not 'published', check if user is admin/authenticated for more?
        // For now, let's keep it simple or restricted to published for public.
        // But the user wants a powerful API, so if they have a token, maybe they can see all.

        $products = $this->products->findAll(new Pagination($page, $perPage), $filters);
        $total    = $this->products->count($filters);

        return $this->json($response, [
        'data' => array_map(fn (Product $p) => $this->serialize($p), $products),
        'meta' => [
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ],
        ]);
    }

    /** GET /api/v1/products/{slug} */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $slug = new Slug($args['slug']);
        } catch (DomainException) {
            return $this->error($response, 404, 'Not Found');
        }

        $product = $this->products->findBySlug($slug);
        if ($product === null) {
            return $this->error($response, 404, 'Not Found');
        }

        return $this->json($response, ['data' => $this->serialize($product)]);
    }

    /** GET /api/v1/products/id/{id} */
    public function showById(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $product = $this->products->findById((int) ($args['id'] ?? 0));
        if ($product === null) {
            return $this->error($response, 404, 'Not Found');
        }

        return $this->json($response, ['data' => $this->serialize($product)]);
    }

    /** POST /api/v1/products */
    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data   = (array) $request->getParsedBody();
        $userId = (int) $request->getAttribute('api_user_id');

        if (empty($data['name'])) {
            return $this->error($response, 422, 'Product name is required.');
        }

        if (!empty($data['sku']) && $this->products->findBySku((string) $data['sku']) !== null) {
            return $this->error($response, 422, "SKU '{$data['sku']}' is already in use.");
        }

        $slugStr = $data['slug'] ?? $data['name'];
        $slug    = $this->slugService->unique($slugStr, VL_DB_PREFIX . 'products');

        $product = new Product($data['name'], $slug);
        try {
            $this->applyData($product, $data, $userId);

            $this->products->save($product);

            if (isset($data['gallery']) && is_array($data['gallery'])) {
                $this->saveGallery($product, $data['gallery'], $userId);
            }

            if (!empty($data['variations']) && is_array($data['variations'])) {
                $this->saveVariations($product, $data['variations'], $userId);
            }
        } catch (DomainException $e) {
            return $this->error($response, 422, $e->getMessage());
        }

        // Re-fetch so persisted timestamps and relations are reflected
        $fresh = $this->products->findById((int) $product->getId());
        $final = $fresh ?? $product;

        $this->events->doAction('product.created', $final);

        return $this->json($response->withStatus(201), [
        'message' => 'Product created',
        'data'    => $this->serialize($final),
        ]);
    }

    /** PUT /api/v1/products/{id} */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id      = (int) $args['id'];
        $product = $this->products->findById($id);
        if (!$product) {
            return $this->error($response, 404, 'Product not found.');
        }

        $data   = (array) $request->getParsedBody();
        $userId = (int) $request->getAttribute('api_user_id');

        if (isset($data['name'])) {
            $product->setName($data['name']);
        }

        if (isset($data['slug'])) {
            $newSlug = $this->slugService->unique(
                $data['slug'],
                VL_DB_PREFIX . 'products',
                'slug',
                $id
            );
            $product->setSlug($newSlug);
        }

        if (isset($data['sku'])) {
            $trimmedSku = trim((string) $data['sku']);
            if ($trimmedSku !== '') {
                $conflict = $this->products->findBySku($trimmedSku);
                if ($conflict !== null && $conflict->getId() !== $id) {
                    return $this->error($response, 422, "SKU '{$trimmedSku}' is already in use.");
                }
            }
        }

        try {
            $this->applyData($product, $data, $userId);
            $this->products->save($product);

            if (isset($data['gallery']) && is_array($data['gallery'])) {
                $this->saveGallery($product, $data['gallery'], $userId);
            }

            if (isset($data['variations']) && is_array($data['variations'])) {
                $this->saveVariations($product, $data['variations'], $userId);
            }
        } catch (DomainException $e) {
            return $this->error($response, 422, $e->getMessage());
        }

        // Re-fetch so persisted timestamps and relations are reflected
        $fresh = $this->products->findById($id);
        $final = $fresh ?? $product;

        $this->events->doAction('product.updated', $final);

        return $this->json($response, [
        'message' => 'Product updated',
        'data'    => $this->serialize($final),
        ]);
    }

    /** DELETE /api/v1/products/{id} */
    public function destroy(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $id      = (int) $args['id'];
        $product = $this->products->findById($id);
        if (!$product) {
            return $this->error($response, 404, 'Product not found.');
        }

        $payload = [
            'id'   => $id,
            'sku'  => $product->getSku(),
            'slug' => (string) $product->getSlug(),
        ];
        $this->products->delete($id);
        $this->events->doAction('product.deleted', $payload);

        return $this->json($response, ['message' => 'Product deleted']);
    }

    /** POST /api/v1/products/categories */
    public function storeCategory(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = (array) $request->getParsedBody();

        if (empty($data['name'])) {
            return $this->error($response, 422, 'Category name is required.');
        }

        $explicitSlug = isset($data['slug']) && trim((string) $data['slug']) !== '' ? (string) $data['slug'] : null;

        if ($explicitSlug !== null) {
            try {
                $slug = new Slug($explicitSlug);
            } catch (DomainException) {
                return $this->error($response, 422, 'Invalid category slug.');
            }
        } else {
            $slug = $this->slugService->unique((string) $data['name'], VL_DB_PREFIX . 'product_categories');
        }

        if ($this->categories->findBySlug($slug) !== null) {
            return $this->json($response, [
                'message' => 'Category already exists',
                'data'    => $this->serializeCategory($this->categories->findBySlug($slug)),
            ]);
        }

        $category = new ProductCategory((string) $data['name'], $slug);
        if (!empty($data['description'])) {
            $category->setDescription((string) $data['description']);
        }
        if (!empty($data['parent_id'])) {
            $category->setParentId((int) $data['parent_id']);
        }

        $saved = $this->categories->save($category);

        return $this->json($response->withStatus(201), [
            'message' => 'Category created',
            'data'    => $this->serializeCategory($saved),
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function serializeCategory(ProductCategory $c): array
    {
        return [
            'id'          => $c->getId(),
            'name'        => $c->getName(),
            'slug'        => (string) $c->getSlug(),
            'parent_id'   => $c->getParentId(),
            'description' => $c->getDescription(),
        ];
    }

    private function applyData(Product $p, array $data, int $userId): void
    {
        if (isset($data['sku'])) {
            $p->setSku($data['sku']);
        }
        if (isset($data['description'])) {
            $p->setDescription($data['description']);
        }
        if (isset($data['short_description'])) {
            $p->setShortDescription($data['short_description']);
        }
        if (isset($data['price'])) {
            $p->setPrice((float) str_replace(',', '.', (string) $data['price']));
        }
        if (isset($data['sale_price'])) {
            $salePrice = $data['sale_price'] !== null && trim((string) $data['sale_price']) !== ''
                ? (float) str_replace(',', '.', (string) $data['sale_price']) : null;
            $p->setSalePrice($salePrice);
        }
        if (array_key_exists('sale_price_start', $data)) {
            $p->setSalePriceStart(self::parseSaleDate($data['sale_price_start'], 'sale_price_start'));
        }
        if (array_key_exists('sale_price_end', $data)) {
            $p->setSalePriceEnd(self::parseSaleDate($data['sale_price_end'], 'sale_price_end'));
        }
        if (array_key_exists('upsell_ids', $data)) {
            $p->setUpsellIds(self::normalizeIdList($data['upsell_ids'], 'upsell_ids'));
        }
        if (array_key_exists('cross_sell_ids', $data)) {
            $p->setCrossSellIds(self::normalizeIdList($data['cross_sell_ids'], 'cross_sell_ids'));
        }
        if (isset($data['stock_quantity'])) {
            $p->setStockQuantity((int) $data['stock_quantity']);
        }
        if (isset($data['stock_status'])) {
            $p->setStockStatus(self::normalizeStockStatus((string) $data['stock_status']));
        }
        if (isset($data['status'])) {
            $p->setStatus($data['status']);
        }
        if (isset($data['type'])) {
            $p->setType($data['type']);
        }
        if (isset($data['weight'])) {
            $p->setWeight($data['weight'] !== null ? (float) str_replace(',', '.', (string) $data['weight']) : null);
        }
        if (isset($data['dimensions'])) {
            $p->setDimensions(self::normalizeDimensions($data['dimensions']));
        }
        if (isset($data['shipping_class'])) {
            $p->setShippingClass($data['shipping_class']);
        }
        if (isset($data['shipping_cost'])) {
            $cost = $data['shipping_cost'] !== null ? (float) str_replace(',', '.', (string) $data['shipping_cost']) : null;
            $p->setShippingCost($cost);
        }

        // GMC
        if (isset($data['gtin'])) {
            $p->setGtin($data['gtin']);
        }
        if (isset($data['mpn'])) {
            $p->setMpn($data['mpn']);
        }
        if (isset($data['brand'])) {
            $p->setBrand($data['brand']);
        }
        if (isset($data['google_product_category'])) {
            $p->setGoogleProductCategory($data['google_product_category']);
        }
        if (isset($data['condition'])) {
            $p->setCondition($data['condition']);
        }
        if (isset($data['gender'])) {
            $p->setGender($data['gender']);
        }
        if (isset($data['age_group'])) {
            $p->setAgeGroup($data['age_group']);
        }
        if (isset($data['color'])) {
            $p->setColor($data['color']);
        }
        if (isset($data['size'])) {
            $p->setSize($data['size']);
        }
        if (isset($data['material'])) {
            $p->setMaterial($data['material']);
        }
        if (isset($data['pattern'])) {
            $p->setPattern($data['pattern']);
        }
        if (isset($data['custom_label_0'])) {
            $p->setCustomLabel0($data['custom_label_0']);
        }
        if (isset($data['custom_label_1'])) {
            $p->setCustomLabel1($data['custom_label_1']);
        }
        if (isset($data['custom_label_2'])) {
            $p->setCustomLabel2($data['custom_label_2']);
        }
        if (isset($data['custom_label_3'])) {
            $p->setCustomLabel3($data['custom_label_3']);
        }
        if (isset($data['custom_label_4'])) {
            $p->setCustomLabel4($data['custom_label_4']);
        }
        if (isset($data['gmc_exclude'])) {
            $p->setGmcExclude((bool) $data['gmc_exclude']);
        }

        // Other
        if (isset($data['comment_status'])) {
            $p->setCommentStatus($data['comment_status']);
        }
        if (isset($data['vote_status'])) {
            $p->setVoteStatus($data['vote_status']);
        }
        if (isset($data['vat_mode'])) {
            $p->setVatMode($data['vat_mode']);
        }
        if (isset($data['refund_mode'])) {
            $allowed = ['use_global', 'allow', 'non_refundable'];
            $p->setRefundMode(in_array($data['refund_mode'], $allowed, true) ? $data['refund_mode'] : 'use_global');
        }
        if (isset($data['vat_rate'])) {
            $p->setVatRate($data['vat_rate'] !== null ? (float) str_replace(',', '.', (string) $data['vat_rate']) : null);
        }
        if (isset($data['meta_title'])) {
            $p->setMetaTitle($data['meta_title']);
        }
        if (isset($data['meta_description'])) {
            $p->setMetaDescription($data['meta_description']);
        }
        if (isset($data['total_sold'])) {
            $p->setTotalSold((int) $data['total_sold']);
        }
        if (isset($data['shipping_country_overrides'])) {
            $p->setShippingCountryOverrides((array) $data['shipping_country_overrides']);
        }
        if (isset($data['vat_country_overrides'])) {
            $p->setVatCountryOverrides((array) $data['vat_country_overrides']);
        }

        // Categories
        if (isset($data['categories']) && is_array($data['categories'])) {
            $p->setCategoryIds($this->resolveCategoryIds($data['categories']));
        }

        // Images — a provided but unimportable value is a 422, never a silent drop.
        if (isset($data['featured_image'])) {
            $raw = trim((string) $data['featured_image']);
            if ($raw !== '') {
                $id = $this->importImage($raw, $userId);
                if ($id === null) {
                    throw new DomainException('Could not import "featured_image". Use a media ID, an absolute https:// URL, or base64 image data.');
                }
                $p->setFeaturedImageId($id);
            } else {
                $p->setFeaturedImageId(null);
            }
        }

        // Digital
        if (isset($data['download_source'])) {
            $p->setDownloadSource($data['download_source']);
        }
        if (isset($data['download_url'])) {
            $p->setDownloadUrl($data['download_url']);
        }
        if (isset($data['download_limit'])) {
            $p->setDownloadLimit((int) $data['download_limit']);
        }
        if (isset($data['download_expiry_days'])) {
            $p->setDownloadExpiryDays((int) $data['download_expiry_days']);
        }

        if (isset($data['download_files']) && is_array($data['download_files'])) {
            $ids = [];
            foreach ($data['download_files'] as $file) {
                $raw = trim((string) $file);
                if ($raw === '') {
                    continue;
                }
                $id = $this->importImage($raw, $userId);
                if ($id === null) {
                    throw new DomainException('Could not import a "download_files" entry. Use a media ID, an absolute https:// URL, or base64 data.');
                }
                $ids[] = $id;
            }
            $this->products->saveDownloadFileIds((int) $p->getId(), $ids);
        }
    }

    private function resolveCategoryIds(array $identifiers): array
    {
        $ids = [];
        foreach ($identifiers as $idnt) {
            if (is_numeric($idnt)) {
                $ids[] = (int) $idnt;
            } else {
                $cat = $this->categories->findBySlug(new Slug((string) $idnt));
                if ($cat) {
                    $ids[] = $cat->getId();
                }
            }
        }
        return array_unique($ids);
    }

    private function saveGallery(Product $p, array $galleryData, int $userId): void
    {
        $ids = [];
        foreach ($galleryData as $img) {
            $raw = trim((string) $img);
            if ($raw === '') {
                continue;
            }
            $id = $this->importImage($raw, $userId);
            if ($id === null) {
                throw new DomainException('Could not import a "gallery" image. Use media IDs, absolute https:// URLs, or base64 image data.');
            }
            $ids[] = $id;
        }
        $this->products->saveGalleryImages((int) $p->getId(), $ids);
    }

    private function saveVariations(Product $p, array $variationsData, int $userId): void
    {
        $variations = [];
        foreach ($variationsData as $vData) {
            $v = [
                'id'                         => $vData['id']                         ?? null,
                'attributes'                 => $vData['attributes']                 ?? [],
                'sku'                        => $vData['sku']                        ?? null,
                'price'                      => (float) ($vData['price']             ?? 0),
                'sale_price'                 => isset($vData['sale_price']) ? (float) $vData['sale_price'] : null,
                'sale_price_start'           => self::formatSaleDate($vData['sale_price_start'] ?? null),
                'sale_price_end'             => self::formatSaleDate($vData['sale_price_end'] ?? null),
                'stock_quantity'             => (int) ($vData['stock_quantity']      ?? 0),
                'stock_status'               => isset($vData['stock_status'])
                    ? self::normalizeStockStatus((string) $vData['stock_status']) : 'in_stock',
                'download_mode'              => $vData['download_mode']              ?? 'use_parent',
                'download_url'               => $vData['download_url']               ?? null,
                'download_limit'             => (int) ($vData['download_limit']      ?? 0),
                'download_expiry_days'       => (int) ($vData['download_expiry_days'] ?? 0),
                'shipping_class'             => $vData['shipping_class']             ?? 'use_parent',
                'shipping_cost'              => isset($vData['shipping_cost']) ? (float) $vData['shipping_cost'] : null,
                'shipping_country_overrides' => $vData['shipping_country_overrides'] ?? [],
                'vat_mode'                   => $vData['vat_mode']                   ?? 'use_parent',
                'vat_rate'                   => isset($vData['vat_rate']) ? (float) $vData['vat_rate'] : null,
                'refund_mode'                => $vData['refund_mode']                ?? 'use_parent',
            ];

            if (isset($vData['image'])) {
                $raw = trim((string) $vData['image']);
                if ($raw !== '') {
                    $imageId = $this->importImage($raw, $userId);
                    if ($imageId === null) {
                        throw new DomainException('Could not import a variation "image". Use a media ID, an absolute https:// URL, or base64 image data.');
                    }
                    $v['image_id'] = $imageId;
                }
            }

            if (isset($vData['download_files']) && is_array($vData['download_files'])) {
                $ids = [];
                foreach ($vData['download_files'] as $file) {
                    $raw = trim((string) $file);
                    if ($raw === '') {
                        continue;
                    }
                    $id = $this->importImage($raw, $userId);
                    if ($id === null) {
                        throw new DomainException('Could not import a variation "download_files" entry. Use a media ID, an absolute https:// URL, or base64 data.');
                    }
                    $ids[] = $id;
                }
                $v['download_file_ids'] = $ids;
            }

            $variations[] = $v;
        }
        $this->products->saveVariations((int) $p->getId(), $variations);
    }

    private function importImage(string $input, int $userId): ?int
    {
        return $this->imageImporter->importFromInput($input, $userId);
    }

    /**
     * Normalize WooCommerce-style stock flags to the canonical Vale value
     * (in_stock|out_of_stock|on_backorder).
     */
    private static function normalizeStockStatus(string $status): string
    {
        return match (strtolower(trim($status))) {
            'in_stock', 'instock', 'in stock'                                    => 'in_stock',
            'out_of_stock', 'outofstock', 'out of stock'                         => 'out_of_stock',
            'on_backorder', 'backorder', 'onbackorder', 'notify', 'on backorder' => 'on_backorder',
            default                                                              => trim($status) !== '' ? trim($status) : 'in_stock',
        };
    }

    /**
     * Accept a dimensions string as-is or a WooCommerce-style
     * {length, width, height} object, normalized to "L x W x H".
     */
    private static function normalizeDimensions(mixed $dimensions): ?string
    {
        if (is_array($dimensions)) {
            $parts = [];
            foreach (['length', 'width', 'height'] as $key) {
                if (isset($dimensions[$key]) && trim((string) $dimensions[$key]) !== '') {
                    $parts[] = trim((string) $dimensions[$key]);
                }
            }
            return $parts === [] ? null : implode(' x ', $parts);
        }
        $raw = trim((string) $dimensions);
        return $raw === '' ? null : $raw;
    }

    /**
     * @throws DomainException
     */
    private static function parseSaleDate(mixed $value, string $field): ?\DateTimeImmutable
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable(trim((string) $value));
        } catch (\Throwable) {
            throw new DomainException("Invalid datetime for \"{$field}\". Use ISO-8601, e.g. 2026-12-01T00:00:00+00:00.");
        }
    }

    private static function formatSaleDate(mixed $value): ?string
    {
        return self::parseSaleDate($value, 'sale_price date')?->format('Y-m-d H:i:s');
    }

    /**
     * @throws DomainException
     * @return int[]
     */
    private static function normalizeIdList(mixed $value, string $field): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value)) {
            throw new DomainException("\"{$field}\" must be an array of product IDs.");
        }
        $ids = [];
        foreach ($value as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $ids[] = (int) $id;
            }
        }
        return array_values(array_unique($ids));
    }

    /** @param array<string, mixed> $data */
    private function json(ResponseInterface $response, array $data): ResponseInterface
    {
        $response->getBody()->write(json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        ));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function error(ResponseInterface $response, int $status, string $message): ResponseInterface
    {
        return $this->json($response->withStatus($status), ['error' => $message]);
    }

    /** @return array<string, mixed> */
    private function serialize(Product $p): array
    {
        $siteUrl = __require_app_url();

        $getMediaUrl = function (?int $id) use ($siteUrl) {
            if (!$id) {
                return null;
            }
            $m = $this->mediaRepo->findById($id);
            return $m ? $siteUrl . $m->getUrl() : null;
        };

        $gallery = array_map(
            $getMediaUrl,
            $this->products->findGalleryImageIds((int) $p->getId())
        );
        $dlFiles = array_map(
            $getMediaUrl,
            $this->products->findDownloadFileIds((int) $p->getId())
        );

        $variations = array_map(function ($v) use ($getMediaUrl) {
            $attrs = is_string($v['attributes']) ? json_decode($v['attributes'], true) : $v['attributes'];
            $dlv   = array_map(
                $getMediaUrl,
                $this->products->findVariationDownloadFileIds((int) $v['id'])
            );

            return [
                'id'                         => $v['id'],
                'sku'                        => $v['sku'],
                'price'                      => (float) $v['price'],
                'sale_price'                 => isset($v['sale_price']) ? (float) $v['sale_price'] : null,
                'sale_price_start'           => !empty($v['sale_price_start'])
                    ? (new \DateTimeImmutable((string) $v['sale_price_start']))->format(\DateTimeInterface::ATOM) : null,
                'sale_price_end'             => !empty($v['sale_price_end'])
                    ? (new \DateTimeImmutable((string) $v['sale_price_end']))->format(\DateTimeInterface::ATOM) : null,
                'stock_quantity'             => (int) $v['stock_quantity'],
                'stock_status'               => $v['stock_status'],
                'image_url'                  => $getMediaUrl((int) ($v['image_id'] ?? 0)),
                'attributes'                 => $attrs,
                'download_mode'              => $v['download_mode'] ?? 'use_parent',
                'download_url'               => $v['download_url']  ?? null,
                'download_limit'             => (int) ($v['download_limit'] ?? 0),
                'download_expiry_days'       => (int) ($v['download_expiry_days'] ?? 0),
                'download_files'             => $dlv,
                'shipping_class'             => $v['shipping_class'] ?? 'use_parent',
                'shipping_cost'              => isset($v['shipping_cost']) ? (float) $v['shipping_cost'] : null,
                'shipping_country_overrides' => is_string($v['shipping_country_overrides'])
                    ? json_decode($v['shipping_country_overrides'], true)
                    : $v['shipping_country_overrides'],
                'vat_mode'                   => $v['vat_mode'] ?? 'use_parent',
                'vat_rate'                   => isset($v['vat_rate']) ? (float) $v['vat_rate'] : null,
                'refund_mode'                => $v['refund_mode'] ?? 'use_parent',
            ];
        }, $this->products->findVariationsByProductId((int) $p->getId()));

        return [
            'id'                 => $p->getId(),
            'slug'               => (string) $p->getSlug(),
            'name'               => $p->getName(),
            'sku'                => $p->getSku(),
            'type'               => $p->getType(),
            'price'              => $p->getPrice(),
            'sale_price'         => $p->getSalePrice(),
            'sale_price_start'   => $p->getSalePriceStart()?->format(\DateTimeInterface::ATOM),
            'sale_price_end'     => $p->getSalePriceEnd()?->format(\DateTimeInterface::ATOM),
            'on_sale'            => $p->isSaleActive(),
            'effective_price'    => $p->getEffectivePrice(),
            'short_description'  => $p->getShortDescription(),
            'description'        => $p->getDescription(),
            'status'             => $p->getStatus(),
            'stock_status'       => $p->getStockStatus(),
            'stock_quantity'     => $p->getStockQuantity(),
            'category_ids'       => $p->getCategoryIds(),
            'upsell_ids'         => $p->getUpsellIds(),
            'cross_sell_ids'     => $p->getCrossSellIds(),
            'featured_image_url' => $getMediaUrl($p->getFeaturedImageId()),
            'gallery'            => $gallery,
            'variations'         => $variations,
            // GMC
            'gtin'                    => $p->getGtin(),
            'mpn'                     => $p->getMpn(),
            'brand'                   => $p->getBrand(),
            'google_product_category' => $p->getGoogleProductCategory(),
            'condition'               => $p->getCondition(),
            'gender'                  => $p->getGender(),
            'age_group'               => $p->getAgeGroup(),
            'color'                   => $p->getColor(),
            'size'                    => $p->getSize(),
            'material'                => $p->getMaterial(),
            'pattern'                 => $p->getPattern(),
            'custom_label_0'          => $p->getCustomLabel0(),
            'custom_label_1'          => $p->getCustomLabel1(),
            'custom_label_2'          => $p->getCustomLabel2(),
            'custom_label_3'          => $p->getCustomLabel3(),
            'custom_label_4'          => $p->getCustomLabel4(),
            'gmc_exclude'             => $p->isGmcExclude(),
            // Digital
            'download_source'      => $p->getDownloadSource(),
            'download_url'         => $p->getDownloadUrl(),
            'download_limit'       => $p->getDownloadLimit(),
            'download_expiry_days' => $p->getDownloadExpiryDays(),
            'download_files'       => $dlFiles,
            // Meta/Other
            'meta_title'                 => $p->getMetaTitle(),
            'meta_description'           => $p->getMetaDescription(),
            'comment_status'             => $p->getCommentStatus(),
            'vote_status'                => $p->getVoteStatus(),
            'total_sold'                 => $p->getTotalSold(),
            'vat_mode'                   => $p->getVatMode(),
            'vat_rate'                   => $p->getVatRate(),
            'refund_mode'                => $p->getRefundMode(),
            'shipping_class'             => $p->getShippingClass(),
            'shipping_cost'              => $p->getShippingCost(),
            'weight'                     => $p->getWeight(),
            'dimensions'                 => $p->getDimensions(),
            'shipping_country_overrides' => $p->getShippingCountryOverrides(),
            'vat_country_overrides'      => $p->getVatCountryOverrides(),
            'created_at'                 => $p->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updated_at'                 => $p->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
