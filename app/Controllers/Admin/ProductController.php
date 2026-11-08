<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use ValeApp\Application\Seo\SaveSeoMetaUseCase;
use ValeApp\Application\Shop\VariationPrice;
use ValeApp\Domain\Content\TreeHelper;
use ValeApp\Domain\Media\Repository\MediaRepositoryInterface;
use ValeApp\Domain\Membership\Repository\PlanRepositoryInterface;
use ValeApp\Domain\Seo\Repository\SeoMetaRepositoryInterface;
use ValeApp\Domain\Shared\Exception\DomainException;
use ValeApp\Domain\Shared\Exception\ValidationException;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\Shop\Entity\Product;
use ValeApp\Domain\Shop\Repository\ProductCategoryRepositoryInterface;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;
use ValeApp\Infrastructure\Cache\AdminSearchCache;
use ValeApp\Infrastructure\Content\RedirectService;
use ValeApp\Infrastructure\Content\SlugService;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Translation\Translator;

final class ProductController
{
    public function __construct(
        private readonly Translator $translator,
        private readonly Twig $twig,
        private readonly ProductRepositoryInterface $products,
        private readonly ProductCategoryRepositoryInterface $productCategories,
        private readonly MediaRepositoryInterface $media,
        private readonly SlugService $slugService,
        private readonly SessionManager $session,
        private readonly DbalSettingsRepository $settings,
        private readonly SeoMetaRepositoryInterface $seoRepo,
        private readonly SaveSeoMetaUseCase $saveSeo,
        private readonly RedirectService $redirects,
        private readonly AdminSearchCache $searchCache,
        private readonly PlanRepositoryInterface $plans,
    ) {
    }
    private const PRODUCT_SEARCH_NAMESPACE = 'product_search';
    private const PRODUCT_SEARCH_TTL       = 120;

    private function productBasePath(): string
    {
        $prefix = $this->settings->get('routing_shop_prefix', '/shop') ?? '/shop';
        return '/' . ltrim($prefix, '/');
    }

    private function productCanonicalFor(Product $product): string
    {
        return $this->redirects->contentCanonical($this->productBasePath(), (string) $product->getSlug());
    }

    /**
     * @return string[]
     */
    private function productAliasLeafs(Product $product): array
    {
        return $this->redirects->aliasLeafs($this->productCanonicalFor($product), $this->productBasePath());
    }

    /**
     * Sync manually curated product aliases (same shop section only) into
     * the redirects table. All-or-nothing: every alias is validated first.
     *
     * @param string|string[]|null $input
     */
    private function syncProductAliases(Product $product, ?string $oldCanonical, mixed $input): void
    {
        $base      = $this->productBasePath();
        $canonical = $this->productCanonicalFor($product);

        if ($oldCanonical !== null && $oldCanonical !== $canonical) {
            $this->redirects->repointManualAliases($oldCanonical, $canonical);
        }

        $leafs = $this->redirects->parseAliasInput($input);

        $errors  = [];
        $desired = [];
        foreach ($leafs as $leaf) {
            try {
                $slug = new Slug(mb_strtolower($leaf));
            } catch (DomainException) {
                $errors[] = sprintf('Alias "%s" is not a valid slug (use lowercase letters, numbers and hyphens).', $leaf);
                continue;
            }

            if (str_contains((string) $slug, '/')) {
                $errors[] = sprintf('Alias "%s" must be a single slug without slashes.', $leaf);
                continue;
            }

            $aliasPath = $this->redirects->contentCanonical($base, (string) $slug);

            if ($aliasPath === $canonical) {
                $errors[] = sprintf('Alias "%s" is the same as the current slug.', $leaf);
                continue;
            }

            $hit = $this->products->findBySlug($slug, $product->getLanguage())
                ?? $this->products->findBySlug($slug);
            if ($hit !== null && (int) $hit->getId() !== (int) $product->getId()) {
                $errors[] = sprintf('Alias "%s" is already used by another entry.', $leaf);
                continue;
            }

            $taken = $this->redirects->findTargetForOldPath($aliasPath);
            if ($taken !== null && (string) $taken['new_path'] !== $canonical) {
                $errors[] = sprintf('Alias "%s" is already used as a redirect.', $leaf);
                continue;
            }

            $desired[] = $aliasPath;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['aliases' => implode(' ', $errors)]);
        }

        $current = $this->redirects->findManualAliases($canonical);

        foreach (array_diff($current, $desired) as $removed) {
            $this->redirects->deleteManualAlias($removed);
        }

        foreach (array_diff($desired, $current) as $added) {
            $this->redirects->recordRedirect($added, $canonical, false, true);
        }
    }

    public function index(Request $request, Response $response): Response
    {
        $params  = $request->getQueryParams();
        $filters = [];
        if (!empty($params['status'])) {
            if ($params['status'] === 'on_sale') {
                $filters['on_sale'] = true;
            } else {
                $filters['status'] = $params['status'];
            }
        }
        if (!empty($params['language'])) {
            $filters['language'] = $params['language'];
        }
        if (!empty($params['category_id'])) {
            $filters['category_id'] = (int) $params['category_id'];
        }
        if (!empty($params['search'])) {
            $filters['search'] = $params['search'];
            if (!empty($params['search_body'])) {
                $filters['search_body'] = true;
            }
        }

        $baseCount  = $this->products->count(array_diff_key($filters, ['search' => true, 'search_body' => true]));
        $clientSide = $baseCount <= client_side_search_fetch_limit() && empty($params['search_body']);

        if ($clientSide) {
            $pagination = new Pagination(1, 100000);
            $products   = $this->products->findAll($pagination, $filters);
            $total      = !empty($filters['search'])
                ? $this->products->count($filters)
                : $baseCount;
        } else {
            $page       = max(1, (int) ($params['page'] ?? 1));
            $perPage    = max(1, min(100, (int) ($params['per_page'] ?? 20)));
            $pagination = new Pagination(
                $page,
                $perPage,
                $params['sort'] ?? null,
                $params['dir']  ?? 'desc',
            );
            $searchKey  = $this->productSearchKeyParts($filters, $pagination);
            $cached     = $searchKey !== null
                ? $this->searchCache->getObjects(
                    self::PRODUCT_SEARCH_NAMESPACE,
                    $searchKey,
                    [Product::class, Slug::class, \DateTimeImmutable::class],
                )
                : null;
            $products = $cached !== null ? $this->validProducts($cached['items']) : null;
            if ($products === null) {
                $total    = $this->products->count($filters);
                $products = $this->products->findAll($pagination, $filters);
                if ($searchKey !== null) {
                    $this->searchCache->setObjects(
                        self::PRODUCT_SEARCH_NAMESPACE,
                        $searchKey,
                        $total,
                        $products,
                        self::PRODUCT_SEARCH_TTL,
                    );
                }
            } else {
                $total = $cached['total'];
            }
        }

        $filterCategoryName = null;
        if (!empty($params['category_id'])) {
            $cat = $this->productCategories->findById((int) $params['category_id']);
            if ($cat !== null) {
                $filterCategoryName = $cat->getName();
            }
        }

        $allCategories = $this->productCategories->findAll();
        $categoryMap   = [];
        foreach ($allCategories as $c) {
            $categoryMap[$c->getId()] = $c->getName();
        }

        $carry = array_diff_key(
            $params,
            array_flip(['page', 'sort', 'dir', 'search', 'search_body', 'per_page']),
        );
        $baseUrl = admin_url('/products') . ($carry ? '?' . http_build_query($carry) : '');

        return $this->twig->render($response, 'admin/products/index.twig', [
            'products'           => $products,
            'total'              => $total,
            'filters'            => $params,
            'client_side'        => $clientSide,
            'page'               => $clientSide ? 1 : $pagination->page,
            'per_page'           => $clientSide ? 20 : $pagination->perPage,
            'base_url'           => $baseUrl,
            'filterCategoryName' => $filterCategoryName,
            'categoryMap'        => $categoryMap,
        ]);
    }

    /**
     * Cache key parts for a server-side product search listing, or null
     * when the request should bypass the search cache (no search term).
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>|null
     */
    private function productSearchKeyParts(array $filters, Pagination $pagination): ?array
    {
        $search = isset($filters['search']) ? trim((string) $filters['search']) : '';
        if ($search === '') {
            return null;
        }
        return [
            'v'        => 1,
            'search'   => mb_strtolower($search, 'UTF-8'),
            'body'     => !empty($filters['search_body']) ? '1' : '0',
            'status'   => (string) ($filters['status'] ?? ''),
            'on_sale'  => !empty($filters['on_sale']) ? '1' : '0',
            'language' => (string) ($filters['language'] ?? ''),
            'category' => (string) ($filters['category_id'] ?? ''),
            'page'     => $pagination->page,
            'per'      => $pagination->perPage,
            'sort'     => (string) ($pagination->sortBy ?? ''),
            'dir'      => strtolower((string) $pagination->sortDir),
        ];
    }

    /**
     * Validate a revived search-cache payload. Anything that is not a
     * list of Product entities (e.g. a class-map change mid-TTL) is
     * rejected so the caller falls through to a fresh query.
     *
     * @return Product[]|null
     */
    private function validProducts(mixed $items): ?array
    {
        if (!is_array($items)) {
            return null;
        }
        foreach ($items as $item) {
            if (!$item instanceof Product) {
                return null;
            }
        }
        /** @var Product[] $items */
        return $items;
    }

    public function create(Request $request, Response $response): Response
    {
        $referenceData = require dirname(__DIR__, 3) . '/config/reference-data.php';
        $lang          = $request->getQueryParams()['language']       ?? $this->translator->getLocale();
        $translationOf = $request->getQueryParams()['translation_of'] ?? null;
        $translations  = $translationOf ? $this->products->findTranslations((int) $translationOf) : [];

        $potentialOriginals = $this->products->findPotentialOriginals(language: $lang);
        if ($translationOf) {
            $found = false;
            foreach ($potentialOriginals as $po) {
                if ((int) $po->getId() === (int) $translationOf) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $original = $this->products->findById((int) $translationOf);
                if ($original && $original->getTranslationOf() === null) {
                    array_unshift($potentialOriginals, $original);
                }
            }
        }

        return $this->twig->render($response, 'admin/products/form.twig', [
            'product'                => null,
            'is_edit'                => false,
            'categories'             => TreeHelper::buildTree($this->productCategories->findAll($lang)),
            'seo'                    => null,
            'downloadFileIds'        => [],
            'gmc_default_category'   => $this->settings->get('google_merchant_default_category', ''),
            'gmc_enabled'            => $this->settings->get('google_merchant_enabled', '0') === '1',
            'auto_redirect_enabled'  => $this->settings->get('auto_redirect_enabled', '1')   === '1',
            'country_codes'          => is_array($referenceData['country_codes'] ?? null) ? $referenceData['country_codes'] : [],
            'shipping_countries'     => $this->settings->get('shipping_countries', ''),
            'shop_country'           => $this->settings->get('shop_country', ''),
            'multi_language_content' => $this->settings->get('multi_language_content', '0') === '1',
            'supported_locales'      => $this->translator->getAvailableLocales(),
            'potential_originals'    => $potentialOriginals,
            'current_locale'         => $lang,
            'translations'           => $translations,
            'original_id'            => $translationOf,
            'prefill_translation_of' => $translationOf,
            'product_aliases'        => [],
            'membership_tags'        => $this->getMembershipTagNames(),
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();

        $name = trim($body['name'] ?? '');
        if ($name === '') {
            $this->session->flash('error', $this->translator->get('Product name is required.'));
            return $response->withHeader('Location', admin_url('/products/create'))->withStatus(302);
        }

        if (($scheduledError = $this->scheduledPublishError($body)) !== null) {
            $this->session->flash('error', $this->translator->get($scheduledError));
            return $response->withHeader('Location', admin_url('/products/create'))->withStatus(302);
        }

        if (!empty($body['set_schedule']) && ($expirationError = $this->expirationError($body)) !== null) {
            $this->session->flash('error', $this->translator->get($expirationError));
            return $response->withHeader('Location', admin_url('/products/create'))->withStatus(302);
        }

        $lang = $body['language'] ?? 'en';
        if (isset($body['translation_of']) && $body['translation_of'] !== '') {
            $newId    = (int) $body['translation_of'];
            $original = $this->products->findById($newId);
            if ($original === null) {
                $this->session->flash('error', $this->translator->get('Selected original not found.'));
                return $response->withHeader('Location', admin_url('/products/create'))->withStatus(302);
            }
            if ($original->getLanguage() === $lang) {
                $this->session->flash('error', $this->translator->get('Cannot translate into the same language.'));
                return $response->withHeader('Location', admin_url('/products/create'))->withStatus(302);
            }
            foreach ($this->products->findTranslations($newId) as $existing) {
                if ($existing->getLanguage() === $lang) {
                    $this->session->flash('error', $this->translator->get('This original already has a translation in this language.'));
                    return $response->withHeader('Location', admin_url('/products/create'))->withStatus(302);
                }
            }
        }

        $slugText = trim($body['slug'] ?? '');
        $slug     = $slugText !== ''
            ? $this->slugService->unique($slugText, VL_DB_PREFIX . 'products', 'slug', null, 'language = ?', [$lang])
            : $this->slugService->unique($name, VL_DB_PREFIX . 'products', 'slug', null, 'language = ?', [$lang]);
        $product = new Product($name, $slug);
        $this->fillProduct($product, $body);
        $product->setCategoryIds(array_map('intval', (array) ($body['category_ids'] ?? [])));

        $variations = $this->parseVariations($body);
        $product->setType($this->deriveProductType($product->getShippingClass(), $variations));
        $this->products->save($product);

        try {
            $this->syncProductAliases($product, null, $body['aliases'] ?? '');
        } catch (ValidationException $e) {
            $this->session->flash('error', implode(' ', $e->getErrors()['aliases'] ?? ['Invalid aliases.']));
            return $response->withHeader('Location', admin_url('/products/create'))->withStatus(302);
        }

        if (!empty($variations)) {
            $this->products->saveVariations($product->getId(), $variations);
        }

        $galleryIds = $this->filterGalleryMediaIds(
            array_map('intval', (array) ($body['gallery_image_ids'] ?? []))
        );
        $this->products->saveGalleryImages($product->getId(), array_values($galleryIds));

        $downloadFileIds = $this->extractDownloadFileIds($body);
        if ($product->getShippingClass() === 'digital' && $product->getDownloadSource() === 'internal') {
            $this->products->saveDownloadFileIds($product->getId(), $downloadFileIds);
        } else {
            $this->products->saveDownloadFileIds($product->getId(), []);
        }

        $this->searchCache->bump(self::PRODUCT_SEARCH_NAMESPACE);
        $this->session->flash('success', $this->translator->get('Product created.'));
        $this->saveSeo->execute('product', $product->getId(), (array) ($body['seo'] ?? []));
        return $response->withHeader('Location', admin_url('/products'))->withStatus(302);
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $product = $this->products->findById((int) $args['id']);
        if ($product === null) {
            return $response->withStatus(404);
        }

        $variations        = $this->products->findVariationsByProductId($product->getId());
        $galleryImageIds   = $this->products->findGalleryImageIds($product->getId());
        $downloadFileIds   = $this->products->findDownloadFileIds($product->getId());

        $referenceData = require dirname(__DIR__, 3) . '/config/reference-data.php';

        $originalId   = $product->getTranslationOf() ?: $product->getId();
        $translations = $this->products->findTranslations((int) $originalId);

        $potentialOriginals = $this->products->findPotentialOriginals($product->getId(), $product->getLanguage());
        $translationOf      = $product->getTranslationOf();
        if ($translationOf) {
            $found = false;
            foreach ($potentialOriginals as $po) {
                if ((int) $po->getId() === (int) $translationOf) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $original = $this->products->findById((int) $translationOf);
                if ($original && $original->getTranslationOf() === null) {
                    array_unshift($potentialOriginals, $original);
                }
            }
        }

        return $this->twig->render($response, 'admin/products/form.twig', [
            'product'                => $product,
            'is_edit'                => true,
            'categories'             => TreeHelper::buildTree($this->productCategories->findAll($product->getLanguage())),
            'variations'             => $variations,
            'galleryImageIds'        => $galleryImageIds,
            'downloadFileIds'        => $downloadFileIds,
            'seo'                    => $this->seoRepo->findByEntity('product', $product->getId()),
            'gmc_default_category'   => $this->settings->get('google_merchant_default_category', ''),
            'gmc_enabled'            => $this->settings->get('google_merchant_enabled', '0') === '1',
            'auto_redirect_enabled'  => $this->settings->get('auto_redirect_enabled', '1')   === '1',
            'country_codes'          => is_array($referenceData['country_codes'] ?? null) ? $referenceData['country_codes'] : [],
            'shipping_countries'     => $this->settings->get('shipping_countries', ''),
            'shop_country'           => $this->settings->get('shop_country', ''),
            'multi_language_content' => $this->settings->get('multi_language_content', '0') === '1',
            'supported_locales'      => $this->translator->getAvailableLocales(),
            'potential_originals'    => $potentialOriginals,
            'current_locale'         => $product->getLanguage(),
            'translations'           => $translations,
            'original_id'            => $originalId,
            'product_aliases'        => $this->productAliasLeafs($product),
            'membership_tags'        => $this->getMembershipTagNames(),
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $product = $this->products->findById((int) $args['id']);
        if ($product === null) {
            return $response->withStatus(404);
        }

        $body = (array) $request->getParsedBody();
        $name = trim($body['name'] ?? '');
        if ($name === '') {
            $this->session->flash('error', $this->translator->get('Product name is required.'));
            return $response->withHeader('Location', admin_url('/products/' . $args['id'] . '/edit'))->withStatus(302);
        }

        if (($scheduledError = $this->scheduledPublishError($body)) !== null) {
            $this->session->flash('error', $this->translator->get($scheduledError));
            return $response->withHeader('Location', admin_url('/products/' . $args['id'] . '/edit'))->withStatus(302);
        }

        if (!empty($body['set_schedule']) && ($expirationError = $this->expirationError($body)) !== null) {
            $this->session->flash('error', $this->translator->get($expirationError));
            return $response->withHeader('Location', admin_url('/products/' . $args['id'] . '/edit'))->withStatus(302);
        }

        $oldSlug        = (string) $product->getSlug();
        $targetLanguage = (string) ($body['language'] ?? $product->getLanguage());
        if (isset($body['translation_of']) && $body['translation_of'] !== '') {
            $newId    = (int) $body['translation_of'];
            $original = $this->products->findById($newId);
            if ($original === null) {
                $this->session->flash('error', $this->translator->get('Selected original not found.'));
                return $response->withHeader('Location', admin_url('/products/' . $args['id'] . '/edit'))->withStatus(302);
            }
            if ($original->getLanguage() === $targetLanguage) {
                $this->session->flash('error', $this->translator->get('Cannot translate into the same language.'));
                return $response->withHeader('Location', admin_url('/products/' . $args['id'] . '/edit'))->withStatus(302);
            }
            foreach ($this->products->findTranslations($newId) as $existing) {
                if ($existing->getLanguage() === $targetLanguage && (int) $existing->getId() !== (int) $args['id']) {
                    $this->session->flash('error', $this->translator->get('This original already has a translation in this language.'));
                    return $response->withHeader('Location', admin_url('/products/' . $args['id'] . '/edit'))->withStatus(302);
                }
            }
        }

        $product->setName($name);
        $this->fillProduct($product, $body);

        // Handle slug from form
        $slugText = trim($body['slug'] ?? '');
        if ($slugText !== '') {
            $slug = $this->slugService->unique($slugText, VL_DB_PREFIX . 'products', 'slug', $product->getId(), 'language = ?', [$product->getLanguage()]);
            $product->setSlug($slug);
        }

        $product->setCategoryIds(array_map('intval', (array) ($body['category_ids'] ?? [])));

        $variations = $this->parseVariations($body);
        $product->setType($this->deriveProductType($product->getShippingClass(), $variations));
        $this->products->save($product);

        $newSlug = (string) $product->getSlug();
        if ($oldSlug !== $newSlug) {
            $createRedirect = !empty($body['create_redirect']);
            if ($createRedirect) {
                $shopPrefix = '/' . ltrim($this->settings->get('routing_shop_prefix', '/shop'), '/');
                $this->redirects->recordRedirect($shopPrefix . '/' . $oldSlug, $shopPrefix . '/' . $newSlug);
            }
        }

        try {
            $oldCanonical = $this->redirects->contentCanonical($this->productBasePath(), $oldSlug);
            $this->syncProductAliases($product, $oldCanonical, $body['aliases'] ?? '');
        } catch (ValidationException $e) {
            $this->session->flash('error', implode(' ', $e->getErrors()['aliases'] ?? ['Invalid aliases.']));
            return $response->withHeader('Location', admin_url('/products/' . $args['id'] . '/edit'))->withStatus(302);
        }

        $this->products->saveVariations($product->getId(), $variations);

        $galleryIds = $this->filterGalleryMediaIds(
            array_map('intval', (array) ($body['gallery_image_ids'] ?? []))
        );
        $this->products->saveGalleryImages($product->getId(), array_values($galleryIds));

        $downloadFileIds = $this->extractDownloadFileIds($body);
        if ($product->getShippingClass() === 'digital' && $product->getDownloadSource() === 'internal') {
            $this->products->saveDownloadFileIds($product->getId(), $downloadFileIds);
        } else {
            $this->products->saveDownloadFileIds($product->getId(), []);
        }

        $this->searchCache->bump(self::PRODUCT_SEARCH_NAMESPACE);
        $this->session->flash('success', $this->translator->get('Product updated.'));
        $this->saveSeo->execute('product', $product->getId(), (array) ($body['seo'] ?? []));
        return $response->withHeader('Location', admin_url('/products/' . $args['id'] . '/edit'))->withStatus(302);
    }

    /** GET /admin/products/list-json — returns products for the product picker */
    public function listJson(Request $request, Response $response): Response
    {
        $params  = $request->getQueryParams();
        $filters = [];

        $withVariations      = !empty($params['with_variations']);
        $variationsByProduct = [];

        $resolve = function (Product $p) use ($withVariations, &$variationsByProduct): array {
            $imgUrl = null;
            $imgId  = $p->getFeaturedImageId();
            if ($imgId) {
                $m = $this->media->findById($imgId);
                if ($m) {
                    $imgUrl = (string) $m->getUrl(site_url('/uploads'));
                }
            }
            $data = [
                'id'                 => $p->getId(),
                'name'               => $p->getName(),
                'slug'               => (string) $p->getSlug(),
                'sku'                => $p->getSku() ?? '',
                'price'              => $p->getPrice(),
                'effective_price'    => $p->getEffectivePrice(),
                'on_sale'            => $p->isSaleActive() && $p->getSalePrice() !== null,
                'status'             => $p->getStatus(),
                'short_description'  => $p->getShortDescription() ?? '',
                'featured_image_url' => $imgUrl,
            ];
            if ($withVariations) {
                $data['variations'] = array_map(
                    static fn (array $v): array => [
                        'id'           => (int) $v['id'],
                        'sku'          => (string) ($v['sku'] ?? ''),
                        'price'        => VariationPrice::resolve($v),
                        'attributes'   => self::decodeVariationAttributes($v),
                        'stock_status' => (string) ($v['stock_status'] ?? 'in_stock'),
                    ],
                    $variationsByProduct[$p->getId()] ?? [],
                );
            }
            return $data;
        };

        if (!empty($params['ids'])) {
            $ids      = array_filter(array_map('intval', explode(',', $params['ids'])));
            $products = [];
            foreach ($ids as $id) {
                $p = $this->products->findById($id);
                if ($p !== null) {
                    $products[] = $p;
                }
            }
            if ($withVariations) {
                $variationsByProduct = $this->products->findVariationsByProductIds(
                    array_map(static fn (Product $p): int => (int) $p->getId(), $products),
                );
            }
            $data = array_map($resolve, $products);
            $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json');
        }

        if (!empty($params['q'])) {
            $filters['search'] = $params['q'];
        }

        $page     = max(1, (int) ($params['page'] ?? 1));
        $perPage  = min(200, max(1, (int) ($params['per_page'] ?? 100)));
        $total    = $this->products->count($filters);
        $products = $this->products->findAll(new Pagination($page, $perPage), $filters);

        if ($withVariations) {
            $variationsByProduct = $this->products->findVariationsByProductIds(
                array_map(static fn (Product $p): int => (int) $p->getId(), $products),
            );
        }

        $data = array_map($resolve, $products);

        $response->getBody()->write(json_encode([
            'data'     => $data,
            'page'     => $page,
            'per_page' => $perPage,
            'total'    => $total,
        ], JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /** @return array<string, string> */
    private static function decodeVariationAttributes(array $row): array
    {
        $raw     = $row['attributes'] ?? null;
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        return is_array($decoded) ? $decoded : [];
    }

    /** Light variation rows for the editor embed picker/preview. @return array<int, array<string, mixed>> */
    private function resolveVariationJson(int $productId): array
    {
        $out = [];
        foreach ($this->products->findVariationsByProductId($productId) as $v) {
            $price     = (float) ($v['price'] ?? 0.0);
            $effective = VariationPrice::resolve($v);
            $imgUrl    = null;
            if (!empty($v['image_id'])) {
                $m = $this->media->findById((int) $v['image_id']);
                if ($m) {
                    $imgUrl = (string) $m->getUrl(site_url('/uploads'));
                }
            }
            $out[] = [
                'id'              => (int) ($v['id'] ?? 0),
                'sku'             => (string) ($v['sku'] ?? ''),
                'attributes'      => self::decodeVariationAttributes($v),
                'price'           => $price,
                'effective_price' => $effective,
                'on_sale'         => $effective < $price,
                'stock_status'    => (string) ($v['stock_status'] ?? 'in_stock'),
                'image_url'       => $imgUrl,
            ];
        }

        return $out;
    }

    /** POST /admin/products/{id}/preview-draft — stores form data in session, does NOT touch the DB */
    public function previewDraft(Request $request, Response $response, array $args): Response
    {
        $product = $this->products->findById((int) $args['id']);
        if ($product === null) {
            return $response->withStatus(404);
        }

        $body = (array) $request->getParsedBody();
        $name = trim($body['name'] ?? '') ?: $product->getName();

        // Build a DB-compatible row using form values, preserving original status
        $row = [
            'id'                      => $product->getId(),
            'name'                    => $name,
            'slug'                    => (string) $product->getSlug(),
            'sku'                     => !empty($body['sku']) ? trim($body['sku']) : $product->getSku(),
            'description'             => $body['description']       ?? $product->getDescription(),
            'short_description'       => $body['short_description'] ?? $product->getShortDescription(),
            'price'                   => (float) str_replace(',', '.', (string) ($body['price'] ?? $product->getPrice())),
            'sale_price'              => !empty($body['sale_price']) ? (float) str_replace(',', '.', $body['sale_price']) : $product->getSalePrice(),
            'sale_price_start'        => !empty($body['sale_price_start']) ? $body['sale_price_start'] : ($product->getSalePriceStart()?->format('Y-m-d\TH:i')),
            'sale_price_end'          => !empty($body['sale_price_end']) ? $body['sale_price_end'] : ($product->getSalePriceEnd()?->format('Y-m-d\TH:i')),
            'stock_quantity'          => (int) ($body['stock_quantity'] ?? $product->getStockQuantity()),
            'stock_status'            => $body['stock_status'] ?? $product->getStockStatus(),
            'total_sold'              => (int) ($body['total_sold'] ?? $product->getTotalSold()),
            'weight'                  => !empty($body['weight']) ? (float) str_replace(',', '.', $body['weight']) : $product->getWeight(),
            'length'                  => !empty($body['length']) ? (float) str_replace(',', '.', $body['length']) : $product->getLength(),
            'width'                   => !empty($body['width']) ? (float) str_replace(',', '.', $body['width']) : $product->getWidth(),
            'height'                  => !empty($body['height']) ? (float) str_replace(',', '.', $body['height']) : $product->getHeight(),
            'dimensions'              => $product->getDimensions(),
            'featured_image_id'       => !empty($body['featured_image_id']) ? (int) $body['featured_image_id'] : $product->getFeaturedImageId(),
            'status'                  => $product->getStatus(), // keep original — do NOT publish
            'comment_status'          => $body['comment_status'] ?? $product->getCommentStatus(),
            'vote_status'             => $body['vote_status']    ?? $product->getVoteStatus(),
            'type'                    => $product->getType(),
            'shipping_class'          => $body['shipping_class'] ?? $product->getShippingClass(),
            'shipping_cost'           => !empty($body['shipping_cost']) ? (float) str_replace(',', '.', $body['shipping_cost']) : $product->getShippingCost(),
            'shipping_time_min'       => !empty($body['shipping_time_min']) ? (int) $body['shipping_time_min'] : $product->getShippingTimeMin(),
            'shipping_time_max'       => !empty($body['shipping_time_max']) ? (int) $body['shipping_time_max'] : $product->getShippingTimeMax(),
            'vat_mode'                => $body['vat_mode'] ?? $product->getVatMode(),
            'vat_rate'                => !empty($body['vat_rate']) ? (float) str_replace(',', '.', $body['vat_rate']) : $product->getVatRate(),
            'meta_title'              => ($body['seo']['meta_title'] ?? null) ?: $product->getMetaTitle(),
            'meta_description'        => ($body['seo']['meta_description'] ?? null) ?: $product->getMetaDescription(),
            'gtin'                    => !empty($body['gtin']) ? trim($body['gtin']) : $product->getGtin(),
            'mpn'                     => !empty($body['mpn']) ? trim($body['mpn']) : $product->getMpn(),
            'brand'                   => !empty($body['brand']) ? trim($body['brand']) : $product->getBrand(),
            'google_product_category' => (!empty($body['google_product_category']) && $body['google_product_category'] !== '__global__') ? trim($body['google_product_category']) : $product->getGoogleProductCategory(),
            'condition'               => $body['condition'] ?? $product->getCondition(),
            'gender'                  => $body['gender']    ?? $product->getGender(),
            'age_group'               => $body['age_group'] ?? $product->getAgeGroup(),
            'color'                   => !empty($body['color']) ? trim($body['color']) : $product->getColor(),
            'size'                    => !empty($body['size']) ? trim($body['size']) : $product->getSize(),
            'material'                => !empty($body['material']) ? trim($body['material']) : $product->getMaterial(),
            'pattern'                 => !empty($body['pattern']) ? trim($body['pattern']) : $product->getPattern(),
            'custom_label_0'          => !empty($body['custom_label_0']) ? trim($body['custom_label_0']) : $product->getCustomLabel0(),
            'custom_label_1'          => !empty($body['custom_label_1']) ? trim($body['custom_label_1']) : $product->getCustomLabel1(),
            'custom_label_2'          => !empty($body['custom_label_2']) ? trim($body['custom_label_2']) : $product->getCustomLabel2(),
            'custom_label_3'          => !empty($body['custom_label_3']) ? trim($body['custom_label_3']) : $product->getCustomLabel3(),
            'custom_label_4'          => !empty($body['custom_label_4']) ? trim($body['custom_label_4']) : $product->getCustomLabel4(),
            'gmc_exclude'             => isset($body['gmc_exclude']) ? 1 : (int) $product->isGmcExclude(),
            'download_url'            => $body['download_url']       ?? $product->getDownloadUrl(),
            'download_url_proxy'      => !empty($body['download_url_proxy']) ? 1 : 0,
        ];

        $galleryIds  = $this->filterGalleryMediaIds(
            array_map('intval', (array) ($body['gallery_image_ids'] ?? []))
        );
        $categoryIds = array_map('intval', (array) ($body['category_ids'] ?? []));

        $this->session->set('preview_product_' . $product->getId(), [
            'row'        => $row,
            'categories' => $categoryIds,
            'gallery'    => $galleryIds,
        ]);

        return $response->withHeader('Location', site_url('/preview/product/' . $product->getId()))->withStatus(302);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $product   = $this->products->findById((int) $args['id']);
        $canonical = $product !== null ? $this->productCanonicalFor($product) : null;
        $this->products->delete((int) $args['id']);
        if ($canonical !== null) {
            $this->redirects->deleteManualByTarget($canonical);
        }
        $this->searchCache->bump(self::PRODUCT_SEARCH_NAMESPACE);
        $this->session->flash('success', $this->translator->get('Product deleted.'));
        return $response->withHeader('Location', admin_url('/products'))->withStatus(302);
    }

    /** POST /admin/bulk/products */
    public function bulkAction(Request $request, Response $response): Response
    {
        $body   = (array) $request->getParsedBody();
        $rawIds = $body['ids'] ?? '[]';
        if (is_string($rawIds) && str_contains($rawIds, ',')) {
            $ids = array_filter(array_map('intval', explode(',', $rawIds)));
        } else {
            $ids = array_filter(array_map('intval', is_array($rawIds) ? $rawIds : (array) json_decode((string) $rawIds, true)));
        }
        $action = $body['action'] ?? '';

        if ($ids === []) {
            $this->session->set('flash_error', $this->translator->get('No items selected.'));
            return $response->withHeader('Location', admin_url('/products'))->withStatus(302);
        }

        $count = 0;
        foreach ($ids as $id) {
            $id      = (int) $id;
            $product = $this->products->findById($id);
            if ($product === null) {
                continue;
            }

            if ($action === 'delete') {
                $canonical = $this->productCanonicalFor($product);
                $this->products->delete($id);
                $this->redirects->deleteManualByTarget($canonical);
                $count++;
            } elseif (in_array($action, ['publish', 'draft', 'archive'], true)) {
                $status = $action === 'publish' ? 'published' : ($action === 'draft' ? 'draft' : 'archived');
                $product->setStatus($status);
                $this->products->save($product);
                $count++;
            }
        }

        $this->session->set('flash_success', $this->translator->get(':count product(s) updated.', [':count' => (string) $count]));
        if ($count > 0) {
            $this->searchCache->bump(self::PRODUCT_SEARCH_NAMESPACE);
        }
        return $response->withHeader('Location', admin_url('/products'))->withStatus(302);
    }

    /**
     * Validate the schedule publish date. It is only required for a direct
     * status='scheduled' (legacy/API path); the form's schedule checkbox
     * also accepts an expiry-only state with an empty date.
     */
    private function scheduledPublishError(array $body): ?string
    {
        $raw = is_string($body['published_at'] ?? null) ? trim($body['published_at']) : '';
        if ($raw !== '' && \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $raw) === false) {
            return 'Publish date & time is not a valid date.';
        }
        if (($body['status'] ?? 'draft') === 'scheduled' && $raw === '') {
            return 'Publish date & time is required when the status is Scheduled.';
        }
        return null;
    }

    /**
     * Validate expires_at: must be a valid date and in the future.
     */
    private function expirationError(array $body): ?string
    {
        $raw = is_string($body['expires_at'] ?? null) ? trim($body['expires_at'] ?? '') : '';
        if ($raw === '') {
            return null;
        }
        $expiresAt = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $raw);
        if ($expiresAt === false) {
            return 'Expiry date & time is not a valid date.';
        }
        if ($expiresAt <= new \DateTimeImmutable()) {
            return 'Expiry must be in the future.';
        }
        return null;
    }

    /** @return string[] */
    private function getMembershipTagNames(): array
    {
        $plans = $this->plans->findAll();
        $tags  = [];
        foreach ($plans as $plan) {
            if ($plan->getTag() !== '' && !in_array($plan->getTag(), $tags, true)) {
                $tags[] = $plan->getTag();
            }
            if ($plan->getTrialTag() !== null && $plan->getTrialTag() !== '' && !in_array($plan->getTrialTag(), $tags, true)) {
                $tags[] = $plan->getTrialTag();
            }
        }
        sort($tags);
        return $tags;
    }

    private function fillProduct(Product $product, array $body): void
    {
        $product->setLanguage($body['language'] ?? $product->getLanguage());
        if (array_key_exists('translation_of', $body)) {
            $product->setTranslationOf($body['translation_of'] !== '' ? (int) $body['translation_of'] : null);
        }
        $product->setSku(!empty($body['sku']) ? trim($body['sku']) : null);
        $product->setDescription(!empty($body['description']) ? $body['description'] : null);
        $product->setShortDescription(!empty($body['short_description']) ? $body['short_description'] : null);
        $product->setPrice((float) str_replace(',', '.', (string) ($body['price'] ?? 0)));
        $product->setSalePrice(!empty($body['sale_price']) ? (float) str_replace(',', '.', $body['sale_price']) : null);

        $saleStartRaw = $body['sale_price_start'] ?? '';
        $saleEndRaw   = $body['sale_price_end']   ?? '';
        $product->setSalePriceStart(
            $saleStartRaw !== '' ? \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $saleStartRaw) ?: null : null
        );
        $product->setSalePriceEnd(
            $saleEndRaw !== '' ? \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $saleEndRaw) ?: null : null
        );

        $product->setStockQuantity((int) ($body['stock_quantity'] ?? 0));
        // Allow-list: a garbage status would silently make the product
        // unsellable (sellability checks match exact enum values).
        $stockStatus = in_array($body['stock_status'] ?? '', ['in_stock', 'out_of_stock', 'on_backorder'], true)
            ? (string) $body['stock_status']
            : 'in_stock';
        $product->setStockStatus($stockStatus);
        $product->setTotalSold((int) ($body['total_sold'] ?? 0));
        $product->setWeight(!empty($body['weight']) ? (float) str_replace(',', '.', $body['weight']) : null);
        $product->setFeaturedImageId(!empty($body['featured_image_id']) ? (int) $body['featured_image_id'] : null);
        $allowedStatuses = ['draft', 'published', 'unlisted', 'archived', 'scheduled'];
        $status          = in_array($body['status'] ?? 'draft', $allowedStatuses, true) ? ($body['status'] ?? 'draft') : 'draft';
        // The admin form sends set_schedule + dates instead of a 'scheduled'
        // status choice; the mapping below restores it. A direct
        // status='scheduled' is still honored for backward compatibility.
        $scheduling = !empty($body['set_schedule']);
        $publishAt  = !empty($body['published_at'])
            ? \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $body['published_at'])
            : null;
        if ($publishAt === false) {
            $publishAt = null;
        }
        if ($scheduling && $publishAt !== null && $status === 'draft') {
            $product->schedule($publishAt);
        } elseif ($scheduling && $publishAt !== null && $status === 'unlisted') {
            $product->setStatus('unlisted');
            $product->setPublishedAt($publishAt);
        } elseif ($status === 'scheduled' && $publishAt !== null) {
            $product->schedule($publishAt);
        } else {
            if ($status !== 'scheduled') {
                $product->setStatus($status);
            } else {
                $product->setStatus('draft');
            }
            // Leaving schedule state drops a pending future marker, if any.
            // Historical (past) publish dates are never touched.
            $current = $product->getPublishedAt();
            if ($current !== null && $current > new \DateTimeImmutable()) {
                $product->setPublishedAt(null);
            }
        }

        if (!$scheduling && $status !== 'scheduled') {
            $product->setExpiresAt(null);
        } else {
            $expiresAt = !empty($body['expires_at'])
                ? \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $body['expires_at'])
                : false;
            $product->setExpiresAt($expiresAt !== false ? $expiresAt : null);
        }
        $product->setCommentStatus($body['comment_status'] ?? 'use_global');
        $allowedVoteStatuses = ['use_global', 'enabled', 'disabled'];
        $voteStatus          = $body['vote_status'] ?? 'use_global';
        $product->setVoteStatus(in_array($voteStatus, $allowedVoteStatuses, true) ? $voteStatus : 'use_global');
        $allowedShippingClasses = ['use_global', 'free', 'digital', 'custom', 'do_not_ship'];
        $shClass                = $body['shipping_class'] ?? 'use_global';
        $product->setShippingClass(in_array($shClass, $allowedShippingClasses, true) ? $shClass : 'use_global');
        $product->setShippingCost(!empty($body['shipping_cost']) ? (float) str_replace(',', '.', $body['shipping_cost']) : null);

        // Estimated delivery time (min/max days, integer, optional)
        $product->setShippingTimeMin(!empty($body['shipping_time_min']) ? (int) $body['shipping_time_min'] : null);
        $product->setShippingTimeMax(!empty($body['shipping_time_max']) ? (int) $body['shipping_time_max'] : null);

        // Parse and validate shipping_country_overrides_json
        $overridesJson = $body['shipping_country_overrides_json'] ?? '{}';
        $overrides     = json_decode($overridesJson, true);
        if (!is_array($overrides)) {
            $overrides = [];
        }
        $cleanOverrides = [];
        $allowedClasses = ['use_global', 'free', 'custom', 'digital', 'do_not_ship'];
        foreach ($overrides as $country => $rule) {
            $country = strtoupper(trim((string) $country));
            if (strlen($country) === 2 && is_array($rule)) {
                $class = $rule['class'] ?? '';
                if (in_array($class, $allowedClasses, true)) {
                    $cleanOverrides[$country] = [
                        'class' => $class,
                    ];
                    if ($class === 'custom') {
                        $cleanOverrides[$country]['cost'] = isset($rule['cost']) ? max(0.0, (float) str_replace(',', '.', (string) $rule['cost'])) : 0.0;
                        if (!empty($rule['shipping_time_min'])) {
                            $cleanOverrides[$country]['shipping_time_min'] = max(1, (int) $rule['shipping_time_min']);
                        }
                        if (!empty($rule['shipping_time_max'])) {
                            $cleanOverrides[$country]['shipping_time_max'] = max(1, (int) $rule['shipping_time_max']);
                        }
                    }
                }
            }
        }
        $product->setShippingCountryOverrides($cleanOverrides);

        $allowedVatModes = ['use_global', 'custom'];
        $vatMode         = (string) ($body['vat_mode'] ?? 'use_global');
        if (!in_array($vatMode, $allowedVatModes, true)) {
            $vatMode = 'use_global';
        }
        $product->setVatMode($vatMode);
        $product->setVatRate($vatMode === 'custom' ? max(0.0, (float) str_replace(',', '.', (string) ($body['vat_rate'] ?? 0.0))) : null);

        $allowedRefundModes = ['use_global', 'allow', 'non_refundable'];
        $refundMode         = (string) ($body['refund_mode'] ?? 'use_global');
        if (!in_array($refundMode, $allowedRefundModes, true)) {
            $refundMode = 'use_global';
        }
        $product->setRefundMode($refundMode);

        // Parse VAT country overrides
        $vatOverridesJson  = $body['vat_country_overrides_json'] ?? '{}';
        $vatOverrides      = json_decode($vatOverridesJson, true);
        $cleanVatOverrides = [];
        if (is_array($vatOverrides)) {
            $allowedVatModes = ['use_global', 'custom', 'none'];
            foreach ($vatOverrides as $country => $rule) {
                $country = strtoupper(trim((string) $country));
                if (strlen($country) === 2 && is_array($rule)) {
                    $mode = $rule['mode'] ?? '';
                    if (in_array($mode, $allowedVatModes, true)) {
                        $cleanVatOverrides[$country] = ['mode' => $mode];
                        if ($mode === 'custom') {
                            $cleanVatOverrides[$country]['rate'] = isset($rule['rate']) ? max(0.0, (float) str_replace(',', '.', (string) $rule['rate'])) : 0.0;
                        }
                    }
                }
            }
        }
        $product->setVatCountryOverrides($cleanVatOverrides);

        // Digital download config — only applied when shipping_class = digital
        if ($shClass === 'digital') {
            $allowedSources = ['none', 'internal', 'external'];
            $dlSource       = $body['download_source'] ?? 'none';
            $product->setDownloadSource(in_array($dlSource, $allowedSources, true) ? $dlSource : 'none');
            $downloadFileIds = $this->extractDownloadFileIds($body);
            if ($product->getDownloadSource() === 'internal' && $downloadFileIds !== []) {
                $product->setDownloadFileId($downloadFileIds[0]);
            } elseif ($product->getDownloadSource() === 'internal') {
                $product->setDownloadFileId(!empty($body['download_file_id']) ? (int) $body['download_file_id'] : null);
            } else {
                $product->setDownloadFileId(null);
            }
            // Validate external URL scheme
            $dlUrl = $body['download_url'] ?? null;
            if ($dlUrl !== null && $dlUrl !== '') {
                $scheme = parse_url($dlUrl, PHP_URL_SCHEME) ?? '';
                $product->setDownloadUrl(in_array(strtolower($scheme), ['http', 'https'], true) ? $dlUrl : null);
            } else {
                $product->setDownloadUrl(null);
            }
            $product->setDownloadUrlProxy(!empty($body['download_url_proxy']));
            $product->setDownloadLimit(max(0, (int) ($body['download_limit'] ?? 0)));
            $product->setDownloadExpiryDays(max(0, (int) ($body['download_expiry_days'] ?? 0)));
        } else {
            $product->setDownloadSource('none');
            $product->setDownloadFileId(null);
            $product->setDownloadUrl(null);
            $product->setDownloadLimit(0);
            $product->setDownloadExpiryDays(0);
        }

        $product->setMetaTitle(!empty($body['seo']['meta_title']) ? $body['seo']['meta_title'] : null);
        $product->setMetaDescription(!empty($body['seo']['meta_description']) ? $body['seo']['meta_description'] : null);

        // GMC fields
        $product->setGtin(!empty($body['gtin']) ? trim($body['gtin']) : null);
        $product->setMpn(!empty($body['mpn']) ? trim($body['mpn']) : null);
        $product->setBrand(!empty($body['brand']) ? trim($body['brand']) : null);
        $rawCategory = !empty($body['google_product_category']) ? trim($body['google_product_category']) : null;
        if ($rawCategory === '__global__') {
            $defaultCategory = $this->settings->get('google_merchant_default_category', '');
            $product->setGoogleProductCategory($defaultCategory ?: null);
        } else {
            $product->setGoogleProductCategory($rawCategory);
        }
        $allowedConditions = ['new', 'refurbished', 'used'];
        $condition         = $body['condition'] ?? 'new';
        $product->setCondition(in_array($condition, $allowedConditions, true) ? $condition : 'new');
        $allowedGenders = ['male', 'female', 'unisex'];
        $product->setGender(!empty($body['gender']) && in_array($body['gender'], $allowedGenders, true) ? $body['gender'] : null);
        $allowedAgeGroups = ['newborn', 'infant', 'toddler', 'kids', 'adult'];
        $product->setAgeGroup(!empty($body['age_group']) && in_array($body['age_group'], $allowedAgeGroups, true) ? $body['age_group'] : null);
        $product->setColor(!empty($body['color']) ? trim($body['color']) : null);
        $product->setSize(!empty($body['size']) ? trim($body['size']) : null);
        $product->setMaterial(!empty($body['material']) ? trim($body['material']) : null);
        $product->setPattern(!empty($body['pattern']) ? trim($body['pattern']) : null);
        $product->setCustomLabel0(!empty($body['custom_label_0']) ? trim($body['custom_label_0']) : null);
        $product->setCustomLabel1(!empty($body['custom_label_1']) ? trim($body['custom_label_1']) : null);
        $product->setCustomLabel2(!empty($body['custom_label_2']) ? trim($body['custom_label_2']) : null);
        $product->setCustomLabel3(!empty($body['custom_label_3']) ? trim($body['custom_label_3']) : null);
        $product->setCustomLabel4(!empty($body['custom_label_4']) ? trim($body['custom_label_4']) : null);
        $product->setGmcExclude(!empty($body['gmc_exclude']));

        // Upsells / Cross-sells
        $upsellIds = isset($body['upsell_ids']) && is_string($body['upsell_ids'])
            ? array_filter(array_map('intval', json_decode($body['upsell_ids'], true) ?: []))
            : [];
        $product->setUpsellIds(array_values($upsellIds));

        $crossSellIds = isset($body['cross_sell_ids']) && is_string($body['cross_sell_ids'])
            ? array_filter(array_map('intval', json_decode($body['cross_sell_ids'], true) ?: []))
            : [];
        $product->setCrossSellIds(array_values($crossSellIds));

        // Membership access control
        $product->setAccessTags(!empty($body['access_tags']) ? (array) $body['access_tags'] : []);

        // Dimensions
        $product->setLength(!empty($body['length']) ? (float) str_replace(',', '.', $body['length']) : null);
        $product->setWidth(!empty($body['width']) ? (float) str_replace(',', '.', $body['width']) : null);
        $product->setHeight(!empty($body['height']) ? (float) str_replace(',', '.', $body['height']) : null);
    }

    private function deriveProductType(string $shippingClass, array $variations): string
    {
        if ($shippingClass === 'digital') {
            return 'digital';
        }
        return !empty($variations) ? 'variable' : 'simple';
    }

    public function getProductJson(Request $request, Response $response, array $args): Response
    {
        $product = $this->products->findById((int) $args['id']);
        if ($product === null) {
            return $response->withStatus(404);
        }

        $imgUrl = null;
        $imgId  = $product->getFeaturedImageId();
        if ($imgId) {
            $m = $this->media->findById($imgId);
            if ($m) {
                $imgUrl = (string) $m->getUrl(site_url('/uploads'));
            }
        }

        $data = [
            'id'                 => $product->getId(),
            'name'               => $product->getName(),
            'description'        => $product->getDescription(),
            'short_description'  => $product->getShortDescription(),
            'price'              => $product->getPrice(),
            'sale_price'         => $product->getSalePrice(),
            'effective_price'    => $product->getEffectivePrice(),
            'on_sale'            => $product->isSaleActive() && $product->getSalePrice() !== null,
            'sku'                => $product->getSku(),
            'slug'               => (string) $product->getSlug(),
            'status'             => $product->getStatus(),
            'expires_at'         => $product->getExpiresAt()?->format(DATE_ATOM),
            'featured_image_url' => $imgUrl,
            'variations'         => $this->resolveVariationJson($product->getId()),
        ];

        $prices = array_filter(
            array_map(
                static fn (array $v): float => (float) ($v['effective_price'] ?? 0.0),
                $data['variations']
            ),
            static fn (float $p): bool => $p > 0.0,
        );
        $data['price_min'] = $prices !== [] ? min($prices) : null;
        $data['price_max'] = $prices !== [] ? max($prices) : null;

        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /** @return int[] */
    private function extractDownloadFileIds(array $body): array
    {
        $ids = array_values(array_filter(array_map(
            'intval',
            (array) ($body['download_file_ids'] ?? []),
        ), static fn (int $id): bool => $id > 0));

        if ($ids === [] && !empty($body['download_file_id'])) {
            $ids = [(int) $body['download_file_id']];
        }

        return array_values(array_unique($ids));
    }

    /**
     * Keep gallery IDs that point at an image or video; anything else
     * (documents, audio, missing rows) cannot render as a gallery slide.
     *
     * @param int[] $ids
     * @return int[]
     */
    private function filterGalleryMediaIds(array $ids): array
    {
        $kept = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= 0 || in_array($id, $kept, true)) {
                continue;
            }
            $media = $this->media->findById($id);
            if ($media === null) {
                continue;
            }
            if ($media->isImage() || $media->isVideo()) {
                $kept[] = $id;
            }
        }

        return $kept;
    }

    /** @return array<int,array<string,mixed>> */
    private function parseVariations(array $body): array
    {
        $raw = $body['variations'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        $variations = [];
        foreach ($raw as $v) {
            if (!is_array($v)) {
                continue;
            }
            // Build attributes from name/value pairs
            $attrs  = [];
            $names  = $v['attr_name']  ?? [];
            $values = $v['attr_value'] ?? [];
            if (is_array($names)) {
                foreach ($names as $i => $name) {
                    $name = trim((string) $name);
                    $val  = trim((string) ($values[$i] ?? ''));
                    if ($name !== '') {
                        $attrs[$name] = $val;
                    }
                }
            }
            if (empty($attrs)) {
                // Preserve already-persisted variations even without attributes
                // (they must not be silently deleted on save). Only drop
                // brand-new placeholder rows that carry no attribute data.
                if (empty($v['id'])) {
                    continue;
                }
            }

            $allowedDlModes = ['use_parent', 'internal', 'external', 'physical', 'non_digital'];
            $dlMode         = $v['download_mode'] ?? 'use_parent';
            if ($dlMode === 'non_digital') {
                $dlMode = 'physical';
            }
            $dlVarUrl = $v['download_url'] ?? null;
            if ($dlVarUrl !== null && $dlVarUrl !== '') {
                $scheme = parse_url($dlVarUrl, PHP_URL_SCHEME) ?? '';
                if (!in_array(strtolower($scheme), ['http', 'https'], true)) {
                    $dlVarUrl = null;
                }
            } else {
                $dlVarUrl = null;
            }

            $dlVarProxy = !empty($v['download_url_proxy']);

            $dlFileIds = array_values(array_filter(array_map(
                'intval',
                (array) ($v['download_file_ids'] ?? []),
            ), static fn (int $id): bool => $id > 0));
            if ($dlFileIds === [] && !empty($v['download_file_id'])) {
                $dlFileIds = [(int) $v['download_file_id']];
            }
            $dlFileIds = array_values(array_unique($dlFileIds));

            $allowedShClasses = ['use_parent', 'use_global', 'free', 'digital', 'custom', 'do_not_ship'];
            $varShClass       = $v['shipping_class'] ?? 'use_parent';
            $allowedVatModes  = ['use_parent', 'use_global', 'custom'];
            $varVatMode       = $v['vat_mode'] ?? 'use_parent';
            if (!in_array($varVatMode, $allowedVatModes, true)) {
                $varVatMode = 'use_parent';
            }

            $allowedRefundModes = ['use_parent', 'allow', 'non_refundable'];
            $varRefundMode      = $v['refund_mode'] ?? 'use_parent';
            if (!in_array($varRefundMode, $allowedRefundModes, true)) {
                $varRefundMode = 'use_parent';
            }

            $variations[] = [
                'id'                         => !empty($v['id']) ? (int) $v['id'] : null,
                'attributes'                 => $attrs,
                'sku'                        => trim($v['sku'] ?? ''),
                'price'                      => (float) str_replace(',', '.', (string) ($v['price'] ?? 0)),
                'sale_price'                 => !empty($v['sale_price']) ? (float) str_replace(',', '.', $v['sale_price']) : null,
                'sale_price_start'           => !empty($v['sale_price_start']) ? trim($v['sale_price_start']) : null,
                'sale_price_end'             => !empty($v['sale_price_end']) ? trim($v['sale_price_end']) : null,
                'stock_quantity'             => (int) ($v['stock_quantity'] ?? 0),
                'stock_status'               => in_array($v['stock_status'] ?? '', ['in_stock', 'out_of_stock', 'on_backorder'], true)
                    ? (string) ($v['stock_status'] ?? 'in_stock')
                    : 'in_stock',
                'image_id'                   => !empty($v['image_id']) ? (int) $v['image_id'] : null,
                'download_mode'              => in_array($dlMode, $allowedDlModes, true) ? $dlMode : 'use_parent',
                'download_file_id'           => $dlFileIds !== [] ? $dlFileIds[0] : (!empty($v['download_file_id']) ? (int) $v['download_file_id'] : null),
                'download_file_ids'          => $dlFileIds,
                'download_url'               => $dlVarUrl,
                'download_url_proxy'         => $dlVarProxy,
                'download_limit'             => max(0, (int) ($v['download_limit'] ?? 0)),
                'download_expiry_days'       => max(0, (int) ($v['download_expiry_days'] ?? 0)),
                'shipping_class'             => in_array($varShClass, $allowedShClasses, true) ? $varShClass : 'use_parent',
                'shipping_cost'              => !empty($v['shipping_cost']) ? max(0.0, (float) str_replace(',', '.', $v['shipping_cost'])) : null,
                'shipping_time_min'          => !empty($v['shipping_time_min']) ? (int) $v['shipping_time_min'] : null,
                'shipping_time_max'          => !empty($v['shipping_time_max']) ? (int) $v['shipping_time_max'] : null,
                'shipping_country_overrides' => (function () use ($v) {
                    $varOverridesJson = $v['shipping_country_overrides'] ?? '{}';
                    $varOverrides     = is_array($varOverridesJson) ? $varOverridesJson : json_decode((string) $varOverridesJson, true);
                    if (!is_array($varOverrides)) {
                        $varOverrides = [];
                    }
                    $cleanVarOverrides = [];
                    $allowedClasses    = ['use_global', 'free', 'custom', 'digital', 'do_not_ship'];
                    foreach ($varOverrides as $country => $rule) {
                        $country = strtoupper(trim((string) $country));
                        if (strlen($country) === 2 && is_array($rule)) {
                            $class = $rule['class'] ?? '';
                            if (in_array($class, $allowedClasses, true)) {
                                $cleanVarOverrides[$country] = [
                                    'class' => $class,
                                ];
                                if ($class === 'custom') {
                                    $cleanVarOverrides[$country]['cost'] = isset($rule['cost']) ? max(0.0, (float) str_replace(',', '.', (string) $rule['cost'])) : 0.0;
                                    if (!empty($rule['shipping_time_min'])) {
                                        $cleanVarOverrides[$country]['shipping_time_min'] = max(1, (int) $rule['shipping_time_min']);
                                    }
                                    if (!empty($rule['shipping_time_max'])) {
                                        $cleanVarOverrides[$country]['shipping_time_max'] = max(1, (int) $rule['shipping_time_max']);
                                    }
                                }
                            }
                        }
                    }
                    return json_encode($cleanVarOverrides ?: new \stdClass());
                })(),
                'vat_mode'             => $varVatMode,
                'vat_rate'             => $varVatMode === 'custom' ? max(0.0, (float) str_replace(',', '.', (string) ($v['vat_rate'] ?? 0.0))) : null,
                'refund_mode'          => $varRefundMode,
                'weight'               => isset($v['weight']) && $v['weight'] !== '' ? (float) str_replace(',', '.', (string) $v['weight']) : null,
                'length'               => isset($v['length']) && $v['length'] !== '' ? (float) str_replace(',', '.', (string) $v['length']) : null,
                'width'                => isset($v['width'])  && $v['width']  !== '' ? (float) str_replace(',', '.', (string) $v['width']) : null,
                'height'               => isset($v['height']) && $v['height'] !== '' ? (float) str_replace(',', '.', (string) $v['height']) : null,
            ];
        }
        return $variations;
    }

    public function exportCsv(Request $request, Response $response, array $args): Response
    {
        $id  = (int) $args['id'];
        $row = $this->buildExportRow($id);
        if ($row === null) {
            return $response->withHeader('Location', admin_url('/products'))->withStatus(302);
        }

        $filename = 'product-' . ($row['slug'] ?? $id) . '.csv';

        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, array_keys($row), ',', '"', '\\');
        $this->writeCsvRow($stream, $row);
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        $response->getBody()->write($csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withStatus(200);
    }

    /**
     * Builds one export row (shared by single and bulk CSV export).
     *
     * @return array<string, mixed>|null null when the product does not exist
     */
    private function buildExportRow(int $id): ?array
    {
        $product = $this->products->findById($id);
        if (!$product) {
            return null;
        }

        $siteUrl = __require_app_url();

        $getMediaUrl = function (?int $mediaId) use ($siteUrl): ?string {
            if (!$mediaId) {
                return null;
            }
            $media = $this->media->findById($mediaId);
            return $media ? rtrim($siteUrl, '/') . '/' . ltrim($media->getUrl(), '/') : null;
        };

        $galleryUrls = array_values(array_filter(array_map(
            $getMediaUrl,
            $this->products->findGalleryImageIds($id)
        )));

        $downloadUrls = array_values(array_filter(array_map(
            $getMediaUrl,
            $this->products->findDownloadFileIds($id)
        )));

        $variations     = $this->products->findVariationsByProductId($id);
        $variationsData = [];
        foreach ($variations as $v) {
            $attrs = is_string($v['attributes']) ? json_decode($v['attributes'], true) : $v['attributes'];
            $dlv   = array_values(array_filter(array_map(
                $getMediaUrl,
                $this->products->findVariationDownloadFileIds((int) $v['id'])
            )));

            $variationsData[] = [
                'sku'                        => $v['sku'],
                'price'                      => (float) $v['price'],
                'sale_price'                 => isset($v['sale_price']) ? (float) $v['sale_price'] : null,
                'stock_quantity'             => (int) $v['stock_quantity'],
                'stock_status'               => $v['stock_status'],
                'attributes'                 => $attrs ?: new \stdClass(),
                'image_url'                  => $getMediaUrl((int) ($v['image_id'] ?? 0)),
                'download_mode'              => $v['download_mode'] ?? 'use_parent',
                'download_url'               => $v['download_url']  ?? null,
                'download_limit'             => (int) ($v['download_limit'] ?? 0),
                'download_expiry_days'       => (int) ($v['download_expiry_days'] ?? 0),
                'download_files'             => $dlv,
                'shipping_class'             => $v['shipping_class'] ?? 'use_parent',
                'shipping_cost'              => isset($v['shipping_cost']) ? (float) $v['shipping_cost'] : null,
                'shipping_country_overrides' => is_string($v['shipping_country_overrides'])
                    ? json_decode($v['shipping_country_overrides'], true)
                    : ($v['shipping_country_overrides'] ?: new \stdClass()),
                'vat_mode'                   => $v['vat_mode'] ?? 'use_parent',
                'vat_rate'                   => isset($v['vat_rate']) ? (float) $v['vat_rate'] : null,
            ];
        }

        $categoryIds = array_map('strval', $product->getCategoryIds());

        $row = [
            'id'                         => $product->getId(),
            'name'                       => $product->getName(),
            'slug'                       => (string) $product->getSlug(),
            'sku'                        => $product->getSku(),
            'type'                       => $product->getType(),
            'status'                     => $product->getStatus(),
            'language'                   => $product->getLanguage(),
            'price'                      => $product->getPrice(),
            'sale_price'                 => $product->getSalePrice(),
            'sale_price_start'           => $product->getSalePriceStart()?->format('Y-m-d H:i:s') ?? '',
            'sale_price_end'             => $product->getSalePriceEnd()?->format('Y-m-d H:i:s')   ?? '',
            'short_description'          => $product->getShortDescription(),
            'description'                => $product->getDescription(),
            'stock_quantity'             => $product->getStockQuantity(),
            'stock_status'               => $product->getStockStatus(),
            'weight'                     => $product->getWeight(),
            'length'                     => $product->getLength(),
            'width'                      => $product->getWidth(),
            'height'                     => $product->getHeight(),
            'dimensions'                 => $product->getDimensions(),
            'featured_image_id'          => $product->getFeaturedImageId(),
            'featured_image_url'         => $getMediaUrl($product->getFeaturedImageId()),
            'gallery'                    => json_encode($galleryUrls ?: new \stdClass()),
            'shipping_class'             => $product->getShippingClass(),
            'shipping_cost'              => $product->getShippingCost(),
            'shipping_country_overrides' => json_encode($product->getShippingCountryOverrides() ?: new \stdClass()),
            'vat_mode'                   => $product->getVatMode(),
            'vat_rate'                   => $product->getVatRate(),
            'vat_country_overrides'      => json_encode($product->getVatCountryOverrides() ?: new \stdClass()),
            'download_source'            => $product->getDownloadSource(),
            'download_url'               => $product->getDownloadUrl(),
            'download_limit'             => $product->getDownloadLimit(),
            'download_expiry_days'       => $product->getDownloadExpiryDays(),
            'download_files'             => json_encode($downloadUrls ?: new \stdClass()),
            'meta_title'                 => $product->getMetaTitle(),
            'meta_description'           => $product->getMetaDescription(),
            'comment_status'             => $product->getCommentStatus(),
            'vote_status'                => $product->getVoteStatus(),
            'total_sold'                 => $product->getTotalSold(),
            'gtin'                       => $product->getGtin(),
            'mpn'                        => $product->getMpn(),
            'brand'                      => $product->getBrand(),
            'google_product_category'    => $product->getGoogleProductCategory(),
            'condition'                  => $product->getCondition(),
            'gender'                     => $product->getGender(),
            'age_group'                  => $product->getAgeGroup(),
            'color'                      => $product->getColor(),
            'size'                       => $product->getSize(),
            'material'                   => $product->getMaterial(),
            'pattern'                    => $product->getPattern(),
            'custom_label_0'             => $product->getCustomLabel0(),
            'custom_label_1'             => $product->getCustomLabel1(),
            'custom_label_2'             => $product->getCustomLabel2(),
            'custom_label_3'             => $product->getCustomLabel3(),
            'custom_label_4'             => $product->getCustomLabel4(),
            'gmc_exclude'                => $product->isGmcExclude() ? 1 : 0,
            'category_ids'               => implode(',', $categoryIds),
            'upsell_ids'                 => implode(',', $product->getUpsellIds()),
            'cross_sell_ids'             => implode(',', $product->getCrossSellIds()),
            'variations'                 => json_encode($variationsData ?: new \stdClass()),
            'created_at'                 => $product->getCreatedAt()?->format('Y-m-d H:i:s') ?? '',
            'updated_at'                 => $product->getUpdatedAt()?->format('Y-m-d H:i:s') ?? '',
        ];

        return $row;
    }

    /**
     * Bulk CSV export of products (newsletter-style export_scope contract).
     *
     * Scopes: "selected" (ids posted by the bulk bar), "filtered" (the
     * current list filters) or "all" (entire catalog).
     */
    public function bulkExport(Request $request, Response $response): Response
    {
        $params      = array_merge($request->getQueryParams(), (array) $request->getParsedBody());
        $isSelectAll = ($params['select_all'] ?? '0') === '1' || ($params['export_scope'] ?? '') === 'all';

        $rawIds = $params['ids'] ?? [];
        if (is_string($rawIds)) {
            $ids = array_filter(array_map('intval', explode(',', $rawIds)));
        } else {
            $ids = array_filter(array_map('intval', (array) $rawIds));
        }

        $hasFilters = !empty($params['status'])
            || !empty($params['language'])
            || !empty($params['category_id'])
            || !empty($params['search'])
            || !empty($params['on_sale']);

        if ($isSelectAll) {
            $exportScope = $hasFilters ? 'filtered' : 'all';
        } else {
            $exportScope = (string) ($params['export_scope'] ?? 'selected');
        }

        if ($exportScope === 'selected' && $ids === []) {
            $this->session->set('flash_error', $this->translator->get('No items selected.'));
            return $response->withHeader('Location', admin_url('/products'))->withStatus(302);
        }

        if ($exportScope === 'filtered') {
            $filters = [];
            if (!empty($params['status'])) {
                if ($params['status'] === 'on_sale') {
                    $filters['on_sale'] = true;
                } else {
                    $filters['status'] = $params['status'];
                }
            }
            if (!empty($params['on_sale'])) {
                $filters['on_sale'] = true;
            }
            if (!empty($params['language'])) {
                $filters['language'] = $params['language'];
            }
            if (!empty($params['category_id'])) {
                $filters['category_id'] = (int) $params['category_id'];
            }
            if (!empty($params['search'])) {
                $filters['search'] = $params['search'];
                if (!empty($params['search_body'])) {
                    $filters['search_body'] = true;
                }
            }
            $products = $this->products->findAll(new Pagination(1, 100000), $filters);
            $ids      = array_map(static fn (Product $p): int => (int) $p->getId(), $products);
        } elseif ($exportScope !== 'selected') {
            $products = $this->products->findAll(new Pagination(1, 100000));
            $ids      = array_map(static fn (Product $p): int => (int) $p->getId(), $products);
        }

        $rows = [];
        foreach ($ids as $id) {
            $row = $this->buildExportRow((int) $id);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        if ($rows === []) {
            $this->session->set('flash_error', $this->translator->get('No items selected.'));
            return $response->withHeader('Location', admin_url('/products'))->withStatus(302);
        }

        $stream = fopen('php://temp', 'r+');
        // UTF-8 BOM for Excel compatibility
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, array_keys($rows[0]), ',', '"', '\\');
        foreach ($rows as $row) {
            $this->writeCsvRow($stream, $row);
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        $filename = 'products-export-' . date('Y-m-d') . '.csv';

        $response->getBody()->write($csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withStatus(200);
    }

    public function exportTemplate(Request $request, Response $response): Response
    {
        $headers = [
            'name', 'slug', 'sku', 'type', 'status', 'language',
            'price', 'sale_price', 'sale_price_start', 'sale_price_end',
            'short_description', 'description',
            'stock_quantity', 'stock_status',
            'weight', 'length', 'width', 'height', 'dimensions',
            'featured_image_id', 'featured_image_url', 'gallery',
            'shipping_class', 'shipping_cost', 'shipping_country_overrides',
            'vat_mode', 'vat_rate', 'vat_country_overrides',
            'download_source', 'download_url', 'download_limit', 'download_expiry_days', 'download_files',
            'meta_title', 'meta_description',
            'comment_status', 'vote_status', 'total_sold',
            'gtin', 'mpn', 'brand', 'google_product_category', 'condition',
            'gender', 'age_group', 'color', 'size', 'material', 'pattern',
            'custom_label_0', 'custom_label_1', 'custom_label_2', 'custom_label_3', 'custom_label_4', 'gmc_exclude',
            'category_ids', 'upsell_ids', 'cross_sell_ids',
            'variations',
            'created_at', 'updated_at',
        ];

        $exampleSimple = [
            'name'                       => 'Example T-Shirt',
            'slug'                       => 'example-t-shirt',
            'sku'                        => 'TSHIRT-001',
            'type'                       => 'simple',
            'status'                     => 'draft',
            'language'                   => 'en',
            'price'                      => 29.99,
            'sale_price'                 => '',
            'sale_price_start'           => '',
            'sale_price_end'             => '',
            'short_description'          => 'A comfortable cotton t-shirt',
            'description'                => '<p>Full product description here.</p>',
            'stock_quantity'             => 100,
            'stock_status'               => 'in_stock',
            'weight'                     => 0.2,
            'length'                     => '',
            'width'                      => '',
            'height'                     => '',
            'dimensions'                 => '',
            'featured_image_id'          => '',
            'featured_image_url'         => 'https://example.com/tshirt.jpg',
            'gallery'                    => json_encode(['https://example.com/tshirt-2.jpg', 'https://example.com/tshirt-3.jpg']),
            'shipping_class'             => 'use_global',
            'shipping_cost'              => '',
            'shipping_country_overrides' => json_encode(new \stdClass()),
            'vat_mode'                   => 'use_global',
            'vat_rate'                   => '',
            'vat_country_overrides'      => json_encode(new \stdClass()),
            'download_source'            => 'none',
            'download_url'               => '',
            'download_limit'             => 0,
            'download_expiry_days'       => 0,
            'download_files'             => json_encode(new \stdClass()),
            'meta_title'                 => '',
            'meta_description'           => '',
            'comment_status'             => 'use_global',
            'vote_status'                => 'use_global',
            'total_sold'                 => 0,
            'gtin'                       => '',
            'mpn'                        => '',
            'brand'                      => 'My Brand',
            'google_product_category'    => '',
            'condition'                  => 'new',
            'gender'                     => '',
            'age_group'                  => '',
            'color'                      => 'Red',
            'size'                       => 'M',
            'material'                   => 'Cotton',
            'pattern'                    => 'Solid',
            'custom_label_0'             => '',
            'custom_label_1'             => '',
            'custom_label_2'             => '',
            'custom_label_3'             => '',
            'custom_label_4'             => '',
            'gmc_exclude'                => 0,
            'category_ids'               => '5',
            'upsell_ids'                 => '',
            'cross_sell_ids'             => '',
            'variations'                 => json_encode(new \stdClass()),
            'created_at'                 => '',
            'updated_at'                 => '',
        ];

        $exampleVariable = [
            'name'                       => 'Example Sneaker',
            'slug'                       => 'example-sneaker',
            'sku'                        => 'SNEAKER-001',
            'type'                       => 'variable',
            'status'                     => 'draft',
            'language'                   => 'en',
            'price'                      => 89.99,
            'sale_price'                 => 79.99,
            'sale_price_start'           => '',
            'sale_price_end'             => '',
            'short_description'          => 'Lightweight running sneaker',
            'description'                => '<p>Full description here.</p>',
            'stock_quantity'             => 50,
            'stock_status'               => 'in_stock',
            'weight'                     => 0.8,
            'length'                     => '',
            'width'                      => '',
            'height'                     => '',
            'dimensions'                 => '',
            'featured_image_id'          => '',
            'featured_image_url'         => 'https://example.com/sneaker.jpg',
            'gallery'                    => json_encode(['https://example.com/sneaker-2.jpg']),
            'shipping_class'             => 'use_global',
            'shipping_cost'              => '',
            'shipping_country_overrides' => json_encode(new \stdClass()),
            'vat_mode'                   => 'use_global',
            'vat_rate'                   => '',
            'vat_country_overrides'      => json_encode(new \stdClass()),
            'download_source'            => 'none',
            'download_url'               => '',
            'download_limit'             => 0,
            'download_expiry_days'       => 0,
            'download_files'             => json_encode(new \stdClass()),
            'meta_title'                 => '',
            'meta_description'           => '',
            'comment_status'             => 'use_global',
            'vote_status'                => 'use_global',
            'total_sold'                 => 0,
            'gtin'                       => '',
            'mpn'                        => '',
            'brand'                      => 'My Brand',
            'google_product_category'    => '',
            'condition'                  => 'new',
            'gender'                     => 'unisex',
            'age_group'                  => 'adult',
            'color'                      => '',
            'size'                       => '',
            'material'                   => 'Mesh',
            'pattern'                    => '',
            'custom_label_0'             => 'Summer Sale',
            'custom_label_1'             => '',
            'custom_label_2'             => '',
            'custom_label_3'             => '',
            'custom_label_4'             => '',
            'gmc_exclude'                => 0,
            'category_ids'               => '8',
            'upsell_ids'                 => '',
            'cross_sell_ids'             => '12',
            'variations'                 => json_encode([
                [
                    'sku'                        => 'SNEAKER-001-BLK-40',
                    'price'                      => 89.99,
                    'sale_price'                 => 79.99,
                    'stock_quantity'             => 10,
                    'stock_status'               => 'in_stock',
                    'attributes'                 => ['Color' => 'Black', 'Size' => '40'],
                    'image_url'                  => 'https://example.com/sneaker-black.jpg',
                    'download_mode'              => 'use_parent',
                    'download_url'               => null,
                    'download_limit'             => 0,
                    'download_expiry_days'       => 0,
                    'download_files'             => [],
                    'shipping_class'             => 'use_parent',
                    'shipping_cost'              => null,
                    'shipping_country_overrides' => new \stdClass(),
                    'vat_mode'                   => 'use_parent',
                    'vat_rate'                   => null,
                ],
                [
                    'sku'                        => 'SNEAKER-001-WHT-42',
                    'price'                      => 89.99,
                    'sale_price'                 => '',
                    'stock_quantity'             => 5,
                    'stock_status'               => 'in_stock',
                    'attributes'                 => ['Color' => 'White', 'Size' => '42'],
                    'image_url'                  => 'https://example.com/sneaker-white.jpg',
                    'download_mode'              => 'use_parent',
                    'download_url'               => null,
                    'download_limit'             => 0,
                    'download_expiry_days'       => 0,
                    'download_files'             => [],
                    'shipping_class'             => 'use_parent',
                    'shipping_cost'              => null,
                    'shipping_country_overrides' => new \stdClass(),
                    'vat_mode'                   => 'use_parent',
                    'vat_rate'                   => null,
                ],
            ]),
            'created_at'                 => '',
            'updated_at'                 => '',
        ];

        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, $headers, ',', '"', '\\');
        $this->writeCsvRow($stream, $exampleSimple);
        $this->writeCsvRow($stream, $exampleVariable);
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        $response->getBody()->write($csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="products-template.csv"')
            ->withStatus(200);
    }

    private function writeCsvRow($stream, array $row): void
    {
        $line = [];
        foreach ($row as $value) {
            if ($value === null || $value === '') {
                $line[] = '';
            } elseif (is_array($value) || is_object($value)) {
                $str = json_encode($value, JSON_UNESCAPED_UNICODE);
                if ($str !== '' && in_array($str[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                    $str = "'" . $str;
                }
                $line[] = $str;
            } else {
                $str = (string) $value;
                if ($str !== '' && in_array($str[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                    $str = "'" . $str;
                }
                $line[] = $str;
            }
        }
        fputcsv($stream, $line, ',', '"', '\\');
    }
}
