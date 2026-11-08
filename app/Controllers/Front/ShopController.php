<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Front;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use ValeApp\Application\Seo\SeoState;
use ValeApp\Application\Shop\DeliveryEstimateService;
use ValeApp\Application\Shop\VariationPrice;
use ValeApp\Domain\Comment\Entity\Comment;
use ValeApp\Domain\Comment\Repository\CommentRepositoryInterface;
use ValeApp\Domain\Content\TreeHelper;
use ValeApp\Domain\Media\Repository\MediaRepositoryInterface;
use ValeApp\Domain\Newsletter\Repository\SubscriberRepositoryInterface;
use ValeApp\Domain\Seo\Repository\SeoMetaRepositoryInterface;
use ValeApp\Domain\Shared\Exception\DomainException;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\Shop\Entity\Product;
use ValeApp\Domain\Shop\Entity\ProductVote;
use ValeApp\Domain\Shop\Repository\CountryTaxRateRepositoryInterface;
use ValeApp\Domain\Shop\Repository\OrderRepositoryInterface;
use ValeApp\Domain\Shop\Repository\ProductCategoryRepositoryInterface;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;
use ValeApp\Domain\Shop\Repository\ProductVoteRepositoryInterface;
use ValeApp\Domain\User\Repository\UserRepositoryInterface;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Translation\Translator;

final class ShopController
{
    use CaptchaTrait;

    public function __construct(
        private readonly Twig $twig,
        private readonly ProductRepositoryInterface $products,
        private readonly ProductCategoryRepositoryInterface $categories,
        private readonly ProductVoteRepositoryInterface $votes,
        private readonly CommentRepositoryInterface $comments,
        private readonly SessionManager $session,
        private readonly MediaRepositoryInterface $media,
        private readonly DbalSettingsRepository $settings,
        private readonly SeoMetaRepositoryInterface $seoMeta,
        private readonly CountryTaxRateRepositoryInterface $countryTaxRates,
        private readonly Translator $translator,
        private readonly SeoState $seoState,
        private readonly UserRepositoryInterface $users,
        private readonly OrderRepositoryInterface $orders,
        private readonly DeliveryEstimateService $deliveryEstimate,
        private readonly SubscriberRepositoryInterface $subscribers,
    ) {
    }
    private function isFallbackNoticeEnabled(): bool
    {
        return $this->settings->get('multi_language_content', '0')          === '1'
            && $this->settings->get('i18n_display_originals_fallback', '1') === '1'
            && $this->settings->get('i18n_fallback_content_notice', '1')    === '1';
    }

    /** @return array{is_fallback: bool, content_language: string, has_translation: bool} */
    private function computeFallbackFlags(string $contentLang, ?int $translationOf, string $entityLanguage, callable $findTranslations): array
    {
        $isFallback     = false;
        $hasTranslation = false;

        if ($contentLang !== '' && $this->isFallbackNoticeEnabled() && $entityLanguage !== $contentLang) {
            $originalId     = $translationOf ?: 0;
            $translations   = $findTranslations($originalId);
            foreach ($translations as $t) {
                if ($t->getLanguage() === $contentLang && $t->getStatus() === 'published') {
                    $hasTranslation = true;
                    break;
                }
            }
            $isFallback = !$hasTranslation;
        }

        return [
            'is_fallback'      => $isFallback,
            'content_language' => $entityLanguage,
            'has_translation'  => $hasTranslation,
        ];
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params      = $request->getQueryParams();
        $page        = max(1, (int) ($params['page'] ?? 1));
        $perPage     = max(1, (int) ($params['per_page'] ?? 12));
        $filters     = ['status' => 'published'];
        $contentLang = null;
        $fallback    = $this->settings->get('i18n_display_originals_fallback', '1') === '1';

        if ($this->settings->get('multi_language_content', '0') === '1') {
            $contentLang         = $this->twig->getEnvironment()->getGlobals()['current_locale'] ?? 'en';
            $filters['language'] = $contentLang;
            if ($fallback) {
                $filters['language_fallback'] = true;
            }
        }
        if (!empty($params['category'])) {
            $filters['category_slug'] = $params['category'];
        }
        if (!empty($params['search'])) {
            $filters['search'] = $params['search'];
        }
        if (!empty($params['sort'])) {
            $filters['sort'] = $params['sort'];
        }
        if (isset($params['exclude_stock']) && $params['exclude_stock'] !== 'false' && $params['exclude_stock'] !== '') {
            $filters['exclude_stock'] = true;
        }

        $pagination = new Pagination($page, $perPage);
        $products   = $this->products->findAll($pagination, $filters);
        $total      = $this->products->count($filters);
        $categories = $this->hideEmptyUncategorized(
            TreeHelper::buildTree($this->categories->findAll($contentLang, $fallback)),
            $filters,
        );

        $shopPrefix = $this->settings->get('routing_shop_prefix', '/shop');

        $categoryFallback = [
            'is_fallback'      => false,
            'content_language' => $contentLang ?? 'en',
            'has_translation'  => false,
        ];
        $activeCategory = null;

        if (!empty($params['category']) && $this->isFallbackNoticeEnabled() && $contentLang !== null) {
            try {
                $filteredCategory = $this->categories->findBySlug(new Slug($params['category']));
            } catch (DomainException) {
                $filteredCategory = null;
            }
            $activeCategory ??= $filteredCategory;
            if ($filteredCategory && $filteredCategory->getLanguage() !== $contentLang) {
                $originalId     = $filteredCategory->getTranslationOf() ?: $filteredCategory->getId();
                $translations   = $this->categories->findTranslations($originalId);
                $hasTranslation = false;
                foreach ($translations as $t) {
                    if ($t->getLanguage() === $contentLang) {
                        $hasTranslation = true;
                        break;
                    }
                }
                $categoryFallback = [
                    'is_fallback'      => !$hasTranslation,
                    'content_language' => $filteredCategory->getLanguage(),
                    'has_translation'  => $hasTranslation,
                ];
            }
        }

        if ($this->settings->get('multi_language_content', '0') === '1') {
            $locales        = $this->translator->getSupportedLocales();
            $translationMap = [];
            foreach ($locales as $l) {
                $translationMap[$l] = $shopPrefix;
            }
            $this->seoState->setTranslations($translationMap);
        }

        $formattedProducts = [];
        $productIds        = [];
        foreach ($products as $p) {
            $productIds[] = $p->getId();
        }
        $summaries = $this->votes->getSummaries($productIds);

        $variableIds = [];
        foreach ($products as $p) {
            if ($p->getType() === 'variable') {
                $variableIds[] = $p->getId();
            }
        }
        $variableRanges = $variableIds !== [] ? $this->variationPriceRanges($variableIds) : [];

        foreach ($products as $p) {
            $imgId   = $p->getFeaturedImageId();
            $img     = $imgId ? $this->resolveProductImage($imgId) : null;
            $pid     = $p->getId();
            $summary = $summaries[$pid] ?? ['average' => 0.0, 'count' => 0];

            $range = $variableRanges[$pid] ?? null;

            $formattedProducts[] = [
                'id'           => $pid,
                'name'         => $p->getName(),
                'slug'         => (string) $p->getSlug(),
                'price'        => (float) $p->getPrice(),
                'sale_price'   => $p->isSaleActive() && $p->getSalePrice() ? (float) $p->getSalePrice() : null,
                'min_price'    => $range['min'] ?? null,
                'max_price'    => $range['max'] ?? null,
                'stock_status' => $p->getStockStatus(),
                'thumbnail'    => $img['thumbnail'] ?? $img['url'] ?? null,
                'url'          => site_url($shopPrefix . '/' . (string) $p->getSlug()),
                'rating_avg'   => $summary['average'],
                'rating_count' => $summary['count'],
            ];
        }

        if ($this->isAjax($request)) {
            $payload = [
                'products'    => $formattedProducts,
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => (int) ceil($total / $perPage),
            ];
            $response->getBody()->write((string) json_encode($payload));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $shopConfig = require base_path('config/shop.php');
        $bufferSize = max(1, (int) ($shopConfig['buffer_size'] ?? 50));

        if ($activeCategory === null && !empty($params['category'])) {
            try {
                $activeCategory = $this->categories->findBySlug(new Slug($params['category']), $contentLang);
            } catch (DomainException) {
                $activeCategory = null;
            }
            if ($activeCategory === null) {
                try {
                    $activeCategory = $this->categories->findBySlug(new Slug($params['category']));
                } catch (DomainException) {
                    $activeCategory = null;
                }
            }
        }

        return $this->twig->render($response, 'shop/index.twig', [
            'products'                          => $products,
            'formattedProducts'                 => $formattedProducts,
            'categories'                        => $categories,
            'active_category'                   => $activeCategory,
            'total'                             => $total,
            'page'                              => $page,
            'per_page'                          => $perPage,
            'total_pages'                       => (int) ceil($total / $perPage),
            'buffer_size'                       => $bufferSize,
            'filters'                           => $params,
            'is_fallback_lang'                  => $categoryFallback['is_fallback'],
            'content_language'                  => $categoryFallback['content_language'],
            'has_translation_in_current_locale' => $categoryFallback['has_translation'],
        ]);
    }

    /**
     * Batched min/max effective variation price per product (honouring sale
     * windows). Used on the shop listing to show a range for variable products
     * that carry no parent price.
     *
     * @param array<int, int> $productIds
     * @return array<int, array{min: float, max: float}> productId => range
     */
    private function variationPriceRanges(array $productIds): array
    {
        $ranges    = [];
        $grouped   = $this->products->findVariationsByProductIds($productIds);
        foreach ($grouped as $productId => $rows) {
            $prices = [];
            foreach ($rows as $row) {
                $prices[] = VariationPrice::resolve($row);
            }
            $prices = array_filter($prices, static fn (float $p) => $p > 0.0);
            if ($prices !== []) {
                $ranges[$productId] = [
                    'min' => min($prices),
                    'max' => max($prices),
                ];
            }
        }

        return $ranges;
    }

    /**
     * The default "uncategorized" bucket is noise in the sidebar when it holds
     * no published products; other categories stay visible even when empty.
     *
     * @param array<int, ProductCategory> $categories
     * @param array<string, mixed> $filters
     * @return array<int, ProductCategory>
     */
    private function hideEmptyUncategorized(array $categories, array $filters): array
    {
        foreach ($categories as $category) {
            if ((string) $category->getSlug() === 'uncategorized') {
                if ($this->products->count(['category_slug' => 'uncategorized'] + $filters) === 0) {
                    return array_values(array_filter(
                        $categories,
                        static fn ($c) => (string) $c->getSlug() !== 'uncategorized',
                    ));
                }
                break;
            }
        }

        return $categories;
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $slug = new Slug($args['slug']);
        } catch (DomainException) {
            return $this->twig->render($response->withStatus(404), 'errors/404.twig');
        }

        $forceLang   = $request->getAttribute('locale');
        $contentLang = $forceLang ?: (($this->settings->get('multi_language_content', '0') === '1')
            ? ($this->twig->getEnvironment()->getGlobals()['current_locale'] ?? 'en')
            : null);

        $product = $this->products->findBySlug($slug, $contentLang);

        // Fallback: If not found in current language, try finding it in any language
        if ($product === null) {
            $product = $this->products->findBySlug($slug);
        }

        if ($product && $contentLang && $product->getLanguage() !== $contentLang) {
            if ($this->settings->get('i18n_display_originals_fallback', '1') === '1' && $product->getTranslationOf() === null) {
                $originalId   = $product->getId();
                $translations = $this->products->findTranslations($originalId);
                foreach ($translations as $t) {
                    if ($t->getLanguage() === $contentLang && $t->getStatus() === 'published') {
                        $shopPrefix = $this->settings->get('routing_shop_prefix', '/shop');
                        return $response->withHeader('Location', site_url($shopPrefix . '/' . $t->getSlug(), [], ['lang' => $contentLang]))->withStatus(302);
                    }
                }
            } else {
                $originalId   = $product->getTranslationOf() ?: $product->getId();
                $translations = $this->products->findTranslations($originalId);

                foreach ($translations as $t) {
                    if ($t->getLanguage() === $contentLang && $t->getStatus() === 'published') {
                        $shopPrefix = $this->settings->get('routing_shop_prefix', '/shop');
                        return $response->withHeader('Location', site_url($shopPrefix . '/' . $t->getSlug(), [], ['lang' => $contentLang]))->withStatus(302);
                    }
                }

                if ($product->getTranslationOf() !== null) {
                    $original = $this->products->findById($product->getTranslationOf());
                    if ($original && $original->getStatus() === 'published') {
                        $shopPrefix = $this->settings->get('routing_shop_prefix', '/shop');
                        return $response->withHeader('Location', site_url($shopPrefix . '/' . $original->getSlug(), [], ['lang' => $original->getLanguage()]))->withStatus(302);
                    }
                } else {
                    $product = null;
                }
            }
        }

        $fallbackFlags = $this->computeFallbackFlags(
            $contentLang ?? 'en',
            $product?->getTranslationOf(),
            $product?->getLanguage() ?? 'en',
            fn (int $originalId) => $originalId > 0 ? $this->products->findTranslations($originalId) : []
        );

        if ($product === null || !in_array($product->getStatus(), ['published', 'unlisted'], true)) {
            return $this->twig->render($response->withStatus(404), 'errors/404.twig');
        }

        $rawSummary    = $this->votes->getSummary($product->getId());
        $voteSummary   = ['avg' => $rawSummary['average'] ?? 0, 'count' => $rawSummary['count'] ?? 0];
        $sessionId     = $this->session->getId();
        $userVote      = $this->votes->findByProductAndSession($product->getId(), $sessionId);

        $params     = $request->getQueryParams();
        $cpage      = max(1, (int) ($params['cpage'] ?? 1));
        $commentsPP = max(1, (int) ($this->settings->get('comments_per_page', '5')));

        if ($product->isCommentsOpen()) {
            $productComments    = $this->comments->findByEntityPaginated('product', $product->getId(), new Pagination($cpage, $commentsPP), 'approved');
            $commentsTotal      = $this->comments->countByEntity('product', $product->getId(), 'approved');
            $allProductComments = $this->comments->findByEntityPaginated('product', $product->getId(), new Pagination(1, 9999), 'approved');
            $allCommentIds      = array_map(fn ($c) => $c->getId(), $allProductComments);

            // Build comment page map
            $childrenByParent = [];
            foreach ($allProductComments as $child) {
                $parentId = $child->getParentId();
                if ($parentId !== null) {
                    $childrenByParent[$parentId]   = ($childrenByParent[$parentId] ?? []);
                    $childrenByParent[$parentId][] = $child;
                }
            }
            $commentPageMap = [];
            foreach ($allProductComments as $i => $tl) {
                $page = (int) floor($i / $commentsPP) + 1;
                $this->mapCommentPage($tl, $page, $childrenByParent, $commentPageMap);
            }
        } else {
            $productComments = [];
            $commentsTotal   = 0;
            $allCommentIds   = [];
            $commentPageMap  = [];
        }

        $reviewType = $this->settings->get('review_captcha_type');
        $captchaKey = ($reviewType !== null && $reviewType !== '') ? 'review_captcha_type' : 'comment_captcha_type';
        $captcha    = $this->buildCaptchaData($captchaKey, 'comment_captcha_');

        $variations = $this->products->findVariationsByProductId($product->getId());
        // Decode attributes JSON for each variation and resolve image URL & delivery estimate
        foreach ($variations as &$v) {
            $v['attributes']        = is_string($v['attributes'] ?? null) ? json_decode($v['attributes'], true) : ($v['attributes'] ?? []);
            $v['image_url']         = $this->resolveProductImage(!empty($v['image_id']) ? (int) $v['image_id'] : null);
            $v['shipping_estimate'] = $this->deliveryEstimate->estimateForProduct($product, $v);
        }
        unset($v);

        $priceMin = null;
        $priceMax = null;
        if ($variations !== []) {
            $effective = [];
            foreach ($variations as $v) {
                $resolved = VariationPrice::resolve($v);
                if ($resolved > 0.0) {
                    $effective[] = $resolved;
                }
            }
            if ($effective !== []) {
                $priceMin = min($effective);
                $priceMax = max($effective);
            }
        }

        $galleryImageIds = $this->products->findGalleryImageIds($product->getId());
        $galleryImages   = array_values(array_filter(array_map(
            fn (int $id) => $this->resolveProductImage($id),
            $galleryImageIds,
        )));

        $userId           = $this->session->get('user_id');
        $userEmail        = $userId !== null ? (string) $this->users->findById($userId)?->getEmail() : null;
        $userComment      = null;
        $commentRatings   = [];
        $userHasPurchased = $userId !== null && $userEmail !== null ? $this->orders->hasPurchasedProduct($userEmail, $product->getId()) : false;

        // Fetch all votes for this product and its translations to build a comment-to-rating map
        $originalId    = $product->getTranslationOf() ?: $product->getId();
        $translations  = $this->products->findTranslations($originalId);
        $allProductIds = array_unique(array_merge([$originalId], array_map(fn (Product $p) => $p->getId(), $translations)));

        $userToRating = [];
        $allVotes     = $this->votes->findByProductIds($allProductIds);
        foreach ($allVotes as $v) {
            if ($v->getCommentId()) {
                $commentRatings[$v->getCommentId()] = $v->getRating();
            } elseif ($v->getUserId()) {
                $userToRating[$v->getUserId()] = $v->getRating();
            }
        }

        // Apply fallback user-to-rating map
        foreach ($productComments as $pc) {
            if (!isset($commentRatings[$pc->getId()]) && $pc->getUserId() && isset($userToRating[$pc->getUserId()])) {
                $commentRatings[$pc->getId()] = $userToRating[$pc->getUserId()];
            }
        }

        if ($userId !== null) {
            $userComment = $this->comments->findByUserAndEntity((int) $userId, 'product', $product->getId());
        }

        $lowStockWarningEnabled    = $this->settings->get('low_stock_warning_enabled', '0') === '1';
        $lowStockWarningThreshold  = max(1, (int) ($this->settings->get('low_stock_warning_threshold', '2') ?? '2'));
        $showSoldCount             = $this->settings->get('show_sold_count', '0')         === '1';
        $showStockCount            = $this->settings->get('show_stock_count', '1')        === '1';
        $productReviewsEnabled     = $this->settings->get('product_reviews_enabled', '1') === '1';
        $starRatingEnabled         = $this->settings->get('star_rating_enabled', '1')     === '1';
        $allowReviewEditing        = $this->settings->get('allow_review_editing', '1')    === '1';
        $showVerifiedBadge         = $this->settings->get('show_verified_badge', '1')     === '1';

        $taxPolicy = $this->getTaxPolicy();

        $crossSellIds = $product->getCrossSellIds();
        $crossSells   = [];
        if (!empty($crossSellIds)) {
            $shopPrefix = $this->settings->get('routing_shop_prefix', '/shop');
            foreach ($this->products->findByIds($crossSellIds) as $cs) {
                $imgId        = $cs->getFeaturedImageId();
                $img          = $imgId ? $this->resolveProductImage($imgId) : null;
                $crossSells[] = [
                    'id'         => $cs->getId(),
                    'name'       => $cs->getName(),
                    'slug'       => (string) $cs->getSlug(),
                    'price'      => (float) $cs->getPrice(),
                    'sale_price' => $cs->isSaleActive() && $cs->getSalePrice() ? (float) $cs->getSalePrice() : null,
                    'thumbnail'  => $img['thumbnail'] ?? $img['url'] ?? null,
                    'url'        => site_url($shopPrefix . '/' . (string) $cs->getSlug()),
                ];
            }
        }

        $seo = $this->seoMeta->findByEntity('product', $product->getId());
        $this->seoState->set($seo, $product);

        if ($this->settings->get('multi_language_content', '0') === '1') {
            $originalId     = $product->getTranslationOf() ?: $product->getId();
            $translations   = $this->products->findTranslations($originalId);
            $translationMap = [];
            $shopPrefix     = $this->settings->get('routing_shop_prefix', '/shop');
            foreach ($translations as $t) {
                $translationMap[$t->getLanguage()] = $shopPrefix . '/' . $t->getSlug();
            }
            $this->seoState->setTranslations($translationMap);
        }

        $shippingEstimate = $this->deliveryEstimate->estimateForProduct($product);

        // ── Membership access control (Approach 3: page visible, purchase gated)
        $accessTags = $product->getAccessTags();
        $hasAccess  = true;
        if (!empty($accessTags)) {
            $hasAccess = false;
            $uid       = $this->session->get('user_id');
            if ($uid !== null) {
                $subscriber = $this->subscribers->findByUserId((int) $uid);
                if ($subscriber !== null) {
                    $userTags  = $subscriber->getTags();
                    $hasAccess = !empty(array_intersect($accessTags, $userTags));
                }
            }
        }

        return $this->twig->render($response, 'shop/product.twig', [
            'product'                                                                                               => $product,
            'cross_sells'                                                                                           => $crossSells,
            'shipping_estimate'                                                                                     => $shippingEstimate,
            'seo'                                                                                                   => $seo,
            'vote'                                                                                                  => $voteSummary,
            'user_vote'                                                                                             => $userVote,
            'user_comment'                                                                                          => $userComment,
            'comment_ratings'                                                                                       => $commentRatings,
            'comments'                                                                                              => $productComments,
            'cpage'                                                                                                 => $cpage,
            'comments_per_page'                                                                                     => $commentsPP,
            'comments_total'                                                                                        => $commentsTotal,
            'comments_total_pages'                                                                                  => max(1, (int) ceil($commentsTotal / $commentsPP)),
            'all_comment_ids'                                                                                       => $allCommentIds,
            'comment_page_map'                                                                                      => $commentPageMap,
            'featured_image'                                                                                        => $this->resolveProductImage($product->getFeaturedImageId()),
            'gallery_images'                                                                                        => $galleryImages,
            'variations'                                                                                            => $variations,
            'price_min'                                                                                             => $priceMin,
            'price_max'                                                                                             => $priceMax,
            'low_stock_warning_enabled'                                                                             => $lowStockWarningEnabled,
            'low_stock_warning_threshold'                                                                           => $lowStockWarningThreshold,
            'show_sold_count'                                                                                       => $showSoldCount,
            'show_stock_count'                                                                                      => $showStockCount,
            'global_comments_enabled'                                                                               => $productReviewsEnabled,
            'global_star_ratings_enabled'                                                                           => $starRatingEnabled,
            'allow_review_editing'                                                                                  => $allowReviewEditing,
            'show_verified_badge'                                                                                   => $showVerifiedBadge,
            'only_logged_in_can_review'                                                                             => $this->settings->get('only_logged_in_can_review', '0')          === '1',
            'only_verified_buyers_can_review'                                                                       => $this->settings->get('only_verified_buyers_can_review', '0')    === '1',
            'user_has_purchased'                                                                                    => $userHasPurchased,
            'admin_edit_url'                                                                                        => admin_url('/products/' . $product->getId() . '/edit'),
            'has_access'                                                                                            => $hasAccess,
            'access_tags'                                                                                           => $accessTags,
            'tax_policy'                                                                                            => $taxPolicy,
            'product_tax_rate'                                                                                      => $this->resolveProductVatRate($product, $taxPolicy),
            'is_fallback_lang'                                                                                      => $fallbackFlags['is_fallback'],
            'content_language'                                                                                      => $fallbackFlags['content_language'],
            'has_translation_in_current_locale'                                                                     => $fallbackFlags['has_translation'],
        ] + $captcha);
    }

    /** POST /shop/{slug}/vote — AJAX or form */
    public function vote(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $slug = new Slug($args['slug']);
        } catch (DomainException) {
            return $response->withStatus(400);
        }

        $product = $this->products->findBySlug($slug);
        if ($product === null || !$product->isVoteEnabled()) {
            return $response->withStatus(403);
        }

        $body      = (array) $request->getParsedBody();
        $rating    = (int) ($body['rating'] ?? 0);
        $review    = trim($body['review'] ?? '');
        $sessionId = $this->session->getId();
        $userId    = $this->session->get('user_id');

        if ($rating < 1 || $rating > 5) {
            return $response->withStatus(422);
        }

        $existing = $this->votes->findByProductAndSession($product->getId(), $sessionId);
        if ($existing !== null) {
            $existing->setRating($rating);
            $existing->setReview($review ?: null);
            $this->votes->save($existing);
        } else {
            $vote = new ProductVote(
                $product->getId(),
                $sessionId,
                $rating,
                $userId,
                $review ?: null
            );
            $this->votes->save($vote);
        }

        $summary = $this->votes->getSummary($product->getId());

        // Respond JSON if XHR, else redirect
        if ($this->isAjax($request)) {
            $response->getBody()->write((string) json_encode($summary));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $shopPrefix = $this->settings->get('routing_shop_prefix', '/shop');
        return $response->withHeader('Location', site_url($shopPrefix . '/' . (string) $slug))->withStatus(302);
    }

    public function cart(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $products  = $this->getHydratedCartItems();
        $taxPolicy = $this->getTaxPolicy();

        $upsellIds = [];
        foreach ($products as $row) {
            $product = $row['product'] ?? null;
            if ($product) {
                $upsellIds = array_merge($upsellIds, $product->getUpsellIds());
            }
        }
        $upsellIds = array_unique(array_filter(array_map('intval', $upsellIds)));
        $upsells   = [];
        if (!empty($upsellIds)) {
            $shopPrefix = $this->settings->get('routing_shop_prefix', '/shop');
            foreach ($this->products->findByIds($upsellIds) as $up) {
                $imgId     = $up->getFeaturedImageId();
                $img       = $imgId ? $this->resolveProductImage($imgId) : null;
                $upsells[] = [
                    'id'         => $up->getId(),
                    'name'       => $up->getName(),
                    'slug'       => (string) $up->getSlug(),
                    'price'      => (float) $up->getPrice(),
                    'sale_price' => $up->isSaleActive() && $up->getSalePrice() ? (float) $up->getSalePrice() : null,
                    'thumbnail'  => $img['thumbnail'] ?? $img['url'] ?? null,
                    'url'        => site_url($shopPrefix . '/' . (string) $up->getSlug()),
                ];
            }
        }

        $country          = strtoupper((string) ($this->session->get('last_billing_country') ?? ''));
        $shippingEstimate = $this->deliveryEstimate->estimateForOrder($products, $country !== '' ? $country : null);

        return $this->twig->render($response, 'shop/cart.twig', [
            'items'             => $products,
            'tax_policy'        => $taxPolicy,
            'cart_tax_amount'   => $this->calculateIncludedTaxForCartRows($products, $taxPolicy),
            'upsells'           => $upsells,
            'shipping_estimate' => $shippingEstimate,
        ]);
    }

    public function getCartJson(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $items     = $this->getHydratedCartItems();
        $taxPolicy = $this->getTaxPolicy();

        $total          = 0;
        $formattedItems = [];
        foreach ($items as $item) {
            $subtotal = (float) ($item['price'] * $item['qty']);
            $total += $subtotal;

            $imgId = !empty($item['variation_row']['image_id']) ? (int) $item['variation_row']['image_id'] : $item['product']->getFeaturedImageId();

            $formattedItems[] = [
                'cart_key'     => $item['cart_key'],
                'product_id'   => $item['product']->getId(),
                'name'         => $item['product']->getName(),
                'slug'         => (string) $item['product']->getSlug(),
                'qty'          => $item['qty'],
                'price'        => (float) $item['price'],
                'subtotal'     => $subtotal,
                'variation'    => $item['variation'],
                'custom_attrs' => $item['custom_attrs'],
                'image'        => $this->resolveProductImage($imgId),
                'max_qty'      => $item['max_qty'],
            ];
        }

        $payload = [
            'items'      => $formattedItems,
            'total'      => (float) $total,
            'tax_amount' => $this->calculateIncludedTaxForCartRows($items, $taxPolicy),
            'count'      => array_sum(array_column($formattedItems, 'qty')),
        ];

        $response->getBody()->write((string) json_encode($payload));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function getHydratedCartItems(): array
    {
        $cartItems = $this->getCart();
        if (empty($cartItems)) {
            return [];
        }

        $pIds = [];
        foreach (array_keys($cartItems) as $key) {
            $pIds[] = (int) explode('_', (string) $key)[0];
        }
        $pIds = array_unique($pIds);

        // Batch load products
        $loadedProducts = [];
        foreach ($pIds as $id) {
            $p = $this->products->findById($id);
            if ($p) {
                $loadedProducts[$id] = $p;
            }
        }

        $hydrated    = [];
        $cartChanged = false;
        $cartFlash   = [];

        foreach ($cartItems as $cartKey => $data) {
            $qty           = is_array($data) ? (int) ($data['qty'] ?? 1) : (int) $data;
            $customAttrs   = is_array($data) ? ($data['custom'] ?? []) : [];
            $selectedAttrs = is_array($data) ? ($data['selected'] ?? []) : [];

            $parts       = explode('_', (string) $cartKey, 2);
            $productId   = (int) $parts[0];
            $variationId = isset($parts[1]) ? (int) $parts[1] : 0;

            $product = $loadedProducts[$productId] ?? null;
            if ($product === null) {
                continue;
            }

            if (!$product->isInStock()) {
                unset($cartItems[$cartKey]);
                $cartChanged = true;
                $cartFlash[] = $this->translator->get('":name" is out of stock and was removed from your cart.', [':name' => htmlspecialchars($product->getName(), ENT_QUOTES, 'UTF-8')]);
                continue;
            }

            $variationData = null;
            $price         = $product->getEffectivePrice();
            $variationRow  = null;
            if ($variationId > 0) {
                $variationRow = $this->findVariationRow($productId, $variationId);
                if ($variationRow === null || !$this->isVariationPurchasable($variationRow)) {
                    unset($cartItems[$cartKey]);
                    $cartChanged = true;
                    $cartFlash[] = $this->translator->get('A selected variation for ":name" is out of stock and was removed from your cart.', [':name' => htmlspecialchars($product->getName(), ENT_QUOTES, 'UTF-8')]);
                    continue;
                }

                $variationData = is_string($variationRow['attributes'] ?? null) ? json_decode($variationRow['attributes'], true) : ($variationRow['attributes'] ?? []);
                $variationData = $this->resolveVariationDisplayAttributes(
                    is_array($variationData) ? $variationData : [],
                    is_array($selectedAttrs) ? $selectedAttrs : [],
                );
                $price = !empty($variationRow['sale_price']) && (!empty($variationRow['sale_price_start']) || !empty($variationRow['sale_price_end']))
                    ? (function () use ($variationRow) {
                        $now   = new \DateTimeImmutable();
                        $start = !empty($variationRow['sale_price_start']) ? new \DateTimeImmutable($variationRow['sale_price_start']) : null;
                        $end   = !empty($variationRow['sale_price_end']) ? new \DateTimeImmutable($variationRow['sale_price_end']) : null;
                        if ($start !== null && $now < $start) {
                            return (float) $variationRow['price'];
                        }
                        if ($end !== null && $now >= $end) {
                            return (float) $variationRow['price'];
                        }
                        return (float) $variationRow['sale_price'];
                    })()
                    : (!empty($variationRow['sale_price']) ? (float) $variationRow['sale_price'] : (float) $variationRow['price']);
            }

            $maxQty = $this->resolveEffectiveMaxQty($product, $variationRow);
            if ($maxQty !== null && $qty > $maxQty) {
                $qty = $maxQty;
                if (is_array($cartItems[$cartKey])) {
                    $cartItems[$cartKey]['qty'] = $qty;
                } else {
                    $cartItems[$cartKey] = $qty;
                }
                $cartChanged = true;
                $cartFlash[] = $this->translator->get('":name" stock changed. Cart updated to max available.', [':name' => htmlspecialchars($product->getName(), ENT_QUOTES, 'UTF-8')]);
            }

            $isNonRefundable = false;
            $varRefundMode   = $variationRow['refund_mode'] ?? 'use_parent';
            if ($varRefundMode !== 'use_parent') {
                $isNonRefundable = ($varRefundMode === 'non_refundable');
            } elseif ($product->getRefundMode() !== 'use_global') {
                $isNonRefundable = ($product->getRefundMode() === 'non_refundable');
            }

            $hydrated[] = [
                'product'           => $product,
                'qty'               => $qty,
                'variation_id'      => $variationId,
                'variation'         => $variationData,
                'variation_row'     => $variationRow,
                'price'             => $price,
                'cart_key'          => $cartKey,
                'custom_attrs'      => $customAttrs,
                'max_qty'           => $maxQty,
                'is_non_refundable' => $isNonRefundable,
            ];
        }

        if ($cartChanged) {
            $this->session->set('cart', $cartItems);
            $this->syncCartCookie($cartItems);
            $this->session->flash('error', implode('<br>', $cartFlash));
        }

        return $hydrated;
    }

    public function addToCart(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body          = (array) $request->getParsedBody();
        $productId     = (int) ($body['product_id'] ?? 0);
        $variationId   = (int) ($body['variation_id'] ?? 0);
        $qty           = max(1, (int) ($body['qty'] ?? 1));
        $customAttrs   = array_filter(array_map('trim', (array) ($body['custom_attrs'] ?? [])));
        $selectedAttrs = [];
        foreach ((array) ($body['selected_attrs'] ?? []) as $name => $value) {
            $attrName  = trim((string) $name);
            $attrValue = trim((string) $value);
            if ($attrName !== '' && $attrValue !== '') {
                $selectedAttrs[$attrName] = $attrValue;
            }
        }

        $shopPrefix = $this->settings->get('routing_shop_prefix', '/shop');
        if ($productId < 1) {
            return $response->withHeader('Location', site_url($shopPrefix))->withStatus(302);
        }

        // Server-side stock check — cannot be bypassed by disabling JS or direct POST
        $product = $this->products->findById($productId);
        if ($product === null) {
            $this->session->flash('error', $this->translator->get('Product not found.'));
            $referer = $request->getHeaderLine('Referer') ?: site_url($shopPrefix);
            return $response->withHeader('Location', $referer)->withStatus(302);
        }

        // Membership access control for restricted products
        $accessTags = $product->getAccessTags();
        if ($accessTags !== []) {
            $userId    = $this->session->get('user_id');
            $hasAccess = false;
            if ($userId !== null) {
                $subscriber = $this->subscribers->findByUserId((int) $userId);
                if ($subscriber !== null) {
                    $hasAccess = !empty(array_intersect($accessTags, $subscriber->getTags()));
                }
            }
            if (!$hasAccess) {
                $this->session->flash('error', $this->translator->get('This product requires an active membership.'));
                return $response->withHeader('Location', site_url($shopPrefix . '/' . (string) $product->getSlug()))->withStatus(302);
            }
        }

        if (!$product->isInStock()) {
            $this->session->flash('error', __('This product is out of stock.'));
            $referer = $request->getHeaderLine('Referer') ?: site_url($shopPrefix);
            return $response->withHeader('Location', $referer)->withStatus(302);
        }

        $variationRow = null;
        if ($variationId > 0) {
            $variationRow = $this->findVariationRow($productId, $variationId);
            if ($variationRow === null) {
                $this->session->flash('error', __('Selected variation was not found.'));
                $referer = $request->getHeaderLine('Referer') ?: site_url($shopPrefix);
                return $response->withHeader('Location', $referer)->withStatus(302);
            }
            if (!$this->isVariationPurchasable($variationRow)) {
                $this->session->flash('error', __('This variation is out of stock.'));
                $referer = $request->getHeaderLine('Referer') ?: site_url($shopPrefix);
                return $response->withHeader('Location', $referer)->withStatus(302);
            }
        }

        $cartKey = $variationId > 0 ? $productId . '_' . $variationId : (string) $productId;
        if (!empty($selectedAttrs)) {
            ksort($selectedAttrs);
            $cartKey .= '_v' . substr(md5(serialize($selectedAttrs)), 0, 8);
        }
        // Items with different customizations are separate cart entries
        if (!empty($customAttrs)) {
            ksort($customAttrs);
            $cartKey .= '_c' . substr(md5(serialize($customAttrs)), 0, 8);
        }

        $cart     = $this->getCart();
        $existing = $cart[$cartKey] ?? null;
        $prevQty  = is_array($existing) ? (int) ($existing['qty'] ?? 0) : (int) ($existing ?? 0);
        $newQty   = $prevQty + $qty;

        // Quantity-aware stock check: use min(product, variation) when both are tracked.
        $available = $this->resolveEffectiveMaxQty($product, $variationRow);
        if ($available !== null) {
            if ($newQty > $available) {
                $newQty = $available;
                $this->session->flash('error', $this->translator->get('Only :count unit(s) available. Cart updated to maximum.', [':count' => (string) $available]));
            }
        }

        $cart[$cartKey] = [
            'qty'      => $newQty,
            'custom'   => $customAttrs ?: (is_array($existing) ? ($existing['custom'] ?? []) : []),
            'selected' => $selectedAttrs ?: (is_array($existing) ? ($existing['selected'] ?? []) : []),
        ];
        $this->session->set('cart', $cart);
        $this->syncCartCookie($cart);

        if (!empty($body['buy_now'])) {
            $checkoutPrefix = (string) ($this->settings->get('routing_checkout_prefix', '/checkout')
                ?? '/checkout');
            return $response->withHeader('Location', site_url($checkoutPrefix))->withStatus(302);
        }

        $msg = $this->translator->get('Product added to cart. <a href=":cart" class="font-medium underline hover:underline">Go to cart</a>.', [':cart' => site_url('/cart')]);
        $this->session->flash('success', $msg);
        $referer = $request->getHeaderLine('Referer') ?: site_url($shopPrefix);
        return $response->withHeader('Location', $referer)->withStatus(302);
    }

    public function updateCart(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body    = (array) $request->getParsedBody();
        $cartKey = (string) ($body['cart_key'] ?? '');
        $qty     = max(1, (int) ($body['qty'] ?? 1));

        $cart = $this->getCart();
        if (isset($cart[$cartKey])) {
            // Stock check for in_stock products
            $parts       = explode('_', $cartKey, 2);
            $productId   = (int) $parts[0];
            $variationId = isset($parts[1]) ? (int) $parts[1] : 0;
            $product     = $this->products->findById($productId);
            if ($product === null || !$product->isInStock()) {
                unset($cart[$cartKey]);
                $this->session->set('cart', $cart);
                $this->session->flash('error', $this->translator->get('This item is out of stock and was removed from your cart.'));
                return $response->withHeader('Location', site_url('/cart'))->withStatus(302);
            }

            $variationRow = null;
            if ($variationId > 0) {
                $variationRow = $this->findVariationRow($productId, $variationId);
                if ($variationRow === null || !$this->isVariationPurchasable($variationRow)) {
                    unset($cart[$cartKey]);
                    $this->session->set('cart', $cart);
                    $this->session->flash('error', $this->translator->get('This variation is out of stock and was removed from your cart.'));
                    return $response->withHeader('Location', site_url('/cart'))->withStatus(302);
                }
            }

            $available = $this->resolveEffectiveMaxQty($product, $variationRow);
            if ($available !== null) {
                if ($qty > $available) {
                    $qty = $available;
                    $this->session->flash('error', $this->translator->get('Only :count unit(s) available. Quantity adjusted.', [':count' => (string) $available]));
                }
            }

            if (is_array($cart[$cartKey])) {
                $cart[$cartKey]['qty'] = $qty;
            } else {
                $cart[$cartKey] = $qty;
            }
            $this->session->set('cart', $cart);
            $this->syncCartCookie($cart);
        }

        return $response->withHeader('Location', site_url('/cart'))->withStatus(302);
    }

    public function removeFromCart(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body    = (array) $request->getParsedBody();
        $cartKey = (string) ($body['cart_key'] ?? '');

        $cart = $this->getCart();
        unset($cart[$cartKey]);
        $this->session->set('cart', $cart);
        $this->syncCartCookie($cart);

        if ($this->isAjax($request)) {
            return $this->getCartJson($request, $response);
        }

        $this->session->flash('success', $this->translator->get('Item removed from cart.'));
        return $response->withHeader('Location', site_url('/cart'))->withStatus(302);
    }

    /** GET /preview/product/{id} — admin users can preview unsaved form changes */
    public function preview(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $userId = $this->session->get('user_id');
        if (!$userId) {
            return $response->withHeader('Location', site_url('/login'))->withStatus(302);
        }

        $userRole    = $this->session->get('user_role');
        $permissions = $this->session->get('user_permissions', []);
        $isAdmin     = in_array($userRole, ['admin', 'super_admin'], true);
        $canEdit     = in_array('products.edit', $permissions, true);

        if (!$isAdmin && !$canEdit) {
            return $this->twig->render($response->withStatus(403), 'errors/403.twig');
        }

        $id      = (int) $args['id'];
        $product = $this->products->findById($id);
        if ($product === null) {
            return $this->twig->render($response->withStatus(404), 'errors/404.twig');
        }

        // Check for a session draft stored by previewDraft()
        $draftKey    = 'preview_product_' . $id;
        $draft       = $this->session->get($draftKey);
        $galleryIds  = null;

        if ($draft !== null && isset($draft['row'])) {
            // Reconstruct product from draft row without touching the DB
            $product    = Product::fromRow($draft['row'], $draft['categories'] ?? []);
            $galleryIds = $draft['gallery'] ?? [];
        }

        $voteSummary = $this->votes->getSummary($product->getId());
        $voteSummary = [
            'avg'   => $voteSummary['average'],
            'count' => $voteSummary['count'],
        ];
        $sessionId   = $this->session->getId();
        $userVote    = $this->votes->findByProductAndSession($id, $sessionId);

        if ($galleryIds === null) {
            $galleryIds = $this->products->findGalleryImageIds($id);
        }
        $galleryImages = array_values(array_filter(array_map(
            fn (int $gid) => $this->resolveProductImage($gid),
            $galleryIds,
        )));

        $seo = $this->seoMeta->findByEntity('product', $product->getId());
        $this->seoState->set($seo, $product);

        if ($this->settings->get('multi_language_content', '0') === '1') {
            $originalId     = $product->getTranslationOf() ?: $product->getId();
            $translations   = $this->products->findTranslations($originalId);
            $translationMap = [];
            $shopPrefix     = $this->settings->get('routing_shop_prefix', '/shop');
            foreach ($translations as $t) {
                $translationMap[$t->getLanguage()] = $shopPrefix . '/' . $t->getSlug();
            }
            $this->seoState->setTranslations($translationMap);
        }

        return $this->twig->render($response, 'shop/product.twig', [
            'product'          => $product,
            'seo'              => $seo,
            'vote'             => $voteSummary,
            'user_vote'        => $userVote,
            'comments'         => [],
            'is_preview'       => true,
            'featured_image'   => $this->resolveProductImage($product->getFeaturedImageId()),
            'gallery_images'   => $galleryImages,
            'tax_policy'       => $this->getTaxPolicy(),
            'product_tax_rate' => $this->resolveProductVatRate($product, $this->getTaxPolicy()),
        ]);
    }

    /** @param array{regime:string,rate:float,zero_reason:string,display_mode:string} $taxPolicy */
    private function resolveProductVatRate(Product $product, array $taxPolicy): float
    {
        if (($taxPolicy['regime'] ?? 'normal_vat') !== 'normal_vat') {
            return 0.0;
        }
        if ($product->getVatMode() === 'custom') {
            return $this->normalizeVatRate($product->getVatRate() ?? 0.0);
        }
        return $this->normalizeVatRate($taxPolicy['rate'] ?? 0.0);
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function calculateIncludedTaxForCartRows(array $rows, array $taxPolicy): float
    {
        if (($taxPolicy['regime'] ?? 'normal_vat') !== 'normal_vat') {
            return 0.0;
        }

        $tax = 0.0;
        foreach ($rows as $row) {
            $lineGross = max(0.0, ((float) ($row['price'] ?? 0.0)) * (int) ($row['qty'] ?? 0));
            if ($lineGross <= 0.0) {
                continue;
            }
            $rate = $this->resolveCartRowVatRate($row, $taxPolicy);
            if ($rate <= 0.0) {
                continue;
            }
            $factor = 1 + ($rate / 100.0);
            $tax += $lineGross - ($lineGross / $factor);
        }
        return round($tax, 2);
    }

    /** @param array<string,mixed> $row */
    private function resolveCartRowVatRate(array $row, array $taxPolicy): float
    {
        $globalRate = $this->normalizeVatRate($taxPolicy['rate'] ?? 0.0);
        if (($taxPolicy['regime'] ?? 'normal_vat') !== 'normal_vat') {
            return 0.0;
        }

        $variation = is_array($row['variation_row'] ?? null) ? $row['variation_row'] : null;
        if ($variation !== null) {
            $mode = (string) ($variation['vat_mode'] ?? 'use_parent');
            if ($mode === 'custom') {
                return $this->normalizeVatRate($variation['vat_rate'] ?? 0.0);
            }
            if ($mode === 'use_global') {
                return $globalRate;
            }
        }

        $product = $row['product'] ?? null;
        if ($product instanceof Product) {
            return $this->resolveProductVatRate($product, $taxPolicy);
        }

        return $globalRate;
    }

    private function normalizeVatRate(mixed $value): float
    {
        $normalized = is_string($value) ? str_replace(',', '.', $value) : $value;
        return max(0.0, (float) $normalized);
    }

    /** @return array{regime:string,rate:float,zero_reason:string,display_mode:string} */
    private function getTaxPolicy(?string $countryCode = null): array
    {
        $displayMode = (string) ($this->settings->get('tax_display_mode', 'incl_and_breakdown') ?? 'incl_and_breakdown');
        if (!in_array($displayMode, ['incl_only', 'incl_and_breakdown'], true)) {
            $displayMode = 'incl_and_breakdown';
        }

        $legacyRate    = (string) ($this->settings->get('tax_rate', '19') ?? '19');
        $globalRateRaw = (string) ($this->settings->get('tax_default_rate', $legacyRate) ?? $legacyRate);
        $globalRate    = max(0.0, (float) str_replace(',', '.', $globalRateRaw));

        $companyCountry = strtoupper(trim((string) ($this->settings->get('shop_country', '') ?? '')));

        if ($countryCode !== null) {
            $countryCode = strtoupper($countryCode);
            $countryTax  = $this->countryTaxRates->findByCountry($countryCode);

            if ($countryTax !== null) {
                $regime = $countryTax->getRegime();
                if ($regime === 'normal_vat') {
                    $rate       = $countryTax->getRate() ?? $globalRate;
                    $zeroReason = '';
                } else {
                    $zeroReason = trim($countryTax->getZeroReason() ?? '');
                    if ($zeroReason === '') {
                        $zeroReason = 'No tax applied (0%).';
                    }
                    $rate = 0.0;
                }
                return [
                    'regime'       => $regime,
                    'rate'         => $rate,
                    'zero_reason'  => $zeroReason,
                    'display_mode' => $displayMode,
                ];
            }

            $fallback = $this->countryTaxRates->findFallback();
            if ($fallback !== null) {
                $fbRegime = $fallback->getRegime();
                if ($fbRegime === 'use_global') {
                    $countryRate = $this->countryTaxRates->findByCountry($companyCountry);
                    if ($countryRate !== null && $countryRate->getRegime() === 'normal_vat') {
                        $rate = $countryRate->getRate() ?? $globalRate;
                        return [
                            'regime'       => 'normal_vat',
                            'rate'         => $rate,
                            'zero_reason'  => '',
                            'display_mode' => $displayMode,
                        ];
                    }
                    return $this->buildGlobalTaxPolicy($globalRate, $displayMode);
                }
                if ($fbRegime === 'normal_vat') {
                    $rate = $fallback->getRate() ?? $globalRate;
                    return [
                        'regime'       => 'normal_vat',
                        'rate'         => $rate,
                        'zero_reason'  => '',
                        'display_mode' => $displayMode,
                    ];
                }
                $zeroReason = trim($fallback->getZeroReason() ?? '');
                if ($zeroReason === '') {
                    $zeroReason = 'No tax applied (0%).';
                }
                return [
                    'regime'       => 'no_tax',
                    'rate'         => 0.0,
                    'zero_reason'  => $zeroReason,
                    'display_mode' => $displayMode,
                ];
            }
        }

        return $this->buildGlobalTaxPolicy($globalRate, $displayMode);
    }

    private function buildGlobalTaxPolicy(float $globalRate, string $displayMode): array
    {
        $regime = (string) ($this->settings->get('tax_regime', 'normal_vat') ?? 'normal_vat');
        if (in_array($regime, ['kleinunternehmer', 'vat_exempt'], true)) {
            $regime = 'no_tax';
        }
        if (!in_array($regime, ['normal_vat', 'no_tax'], true)) {
            $regime = 'normal_vat';
        }

        $rate = $regime === 'normal_vat' ? $globalRate : 0.0;

        $zeroReason = trim((string) ($this->settings->get('tax_zero_reason_default', '') ?? ''));
        if ($regime === 'no_tax' && $zeroReason === '') {
            $zeroReason = 'No tax applied (0%).';
        }

        return [
            'regime'       => $regime,
            'rate'         => $rate,
            'zero_reason'  => $zeroReason,
            'display_mode' => $displayMode,
        ];
    }

    private function resolveProductImage(?int $mediaId): ?array
    {
        if (!$mediaId) {
            return null;
        }
        $m = $this->media->findById($mediaId);
        if ($m === null) {
            return null;
        }
        $uploadsBase = site_url('/uploads');
        $url         = $m->getUrl($uploadsBase);
        $mime        = strtolower((string) $m->getMimeType());
        return [
            // Prefer WebP renditions when they exist; fall back to the original.
            'url'       => $m->getThumbnailUrl('webp', $uploadsBase) ?? $url,
            // Fall back to the original when the size variant was never generated
            'thumbnail' => $m->getThumbnailUrl('webp_medium', $uploadsBase)
                ?? $m->getThumbnailUrl('medium', $uploadsBase)
                ?? $url,
            'mime'     => $mime,
            'is_video' => $m->isVideo(),
        ];
    }

    /** @return array<string,mixed>|null */
    private function findVariationRow(int $productId, int $variationId): ?array
    {
        $rows = $this->products->findVariationsByProductId($productId);
        foreach ($rows as $row) {
            if ((int) ($row['id'] ?? 0) === $variationId) {
                return $row;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $variation */
    private function isVariationPurchasable(array $variation): bool
    {
        $status = (string) ($variation['stock_status'] ?? 'in_stock');
        return in_array($status, ['in_stock', 'on_backorder'], true);
    }

    /**
     * @param array<string,mixed> $variationAttrs
     * @param array<string,mixed> $selectedAttrs
     * @return array<string,mixed>
     */
    private function resolveVariationDisplayAttributes(array $variationAttrs, array $selectedAttrs): array
    {
        if ($variationAttrs === []) {
            return $variationAttrs;
        }

        $display = [];
        foreach ($variationAttrs as $name => $rawValue) {
            $label = trim((string) $name);
            if ($label === '') {
                continue;
            }

            if (array_key_exists($label, $selectedAttrs)) {
                $selected = trim((string) $selectedAttrs[$label]);
                if ($selected !== '') {
                    $display[$label] = $selected;
                    continue;
                }
            }

            $display[$label] = $rawValue;
        }

        return $display;
    }

    /**
     * Effective tracked limit:
     * - both tracked: min(product_qty, variation_qty)
     * - one tracked: tracked qty
     * - none tracked: null (unlimited)
     *
     * @param array<string,mixed>|null $variation
     */
    private function resolveEffectiveMaxQty(Product $product, ?array $variation): ?int
    {
        $productTracked = $product->isStockTracked();
        $productQty     = $product->getStockQuantity();

        $variationTracked = false;
        $variationQty     = 0;
        if ($variation !== null) {
            $variationStatus  = (string) ($variation['stock_status'] ?? 'in_stock');
            $variationQty     = (int) ($variation['stock_quantity'] ?? 0);
            $variationTracked = ($variationStatus === 'in_stock' && $variationQty > 0);
        }

        if ($productTracked && $variationTracked) {
            return min($productQty, $variationQty);
        }
        if ($productTracked) {
            return $productQty;
        }
        if ($variationTracked) {
            return $variationQty;
        }

        return null;
    }

    private function getCart(): array
    {
        $cart = $this->session->get('cart', []);
        if (empty($cart)) {
            $cookieData = $_COOKIE['vl_cart'] ?? '';
            if ($cookieData !== '') {
                $decoded = json_decode(base64_decode($cookieData), true);
                if (is_array($decoded)) {
                    $cart = $decoded;
                    $this->session->set('cart', $cart);
                }
            }
        }
        return $cart;
    }

    private function syncCartCookie(array $cart): void
    {
        if (empty($cart)) {
            $this->clearCartCookie();
            return;
        }

        setcookie('vl_cart', base64_encode((string) json_encode($cart)), [
            'expires'  => time() + (7 * 24 * 60 * 60), // 1 week
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function clearCartCookie(): void
    {
        setcookie('vl_cart', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function isAjax(ServerRequestInterface $request): bool
    {
        return strtolower($request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest';
    }

    private function mapCommentPage(
        Comment $node,
        int $page,
        array $childrenByParent,
        array &$map
    ): void {
        $map[$node->getId()] = $page;
        $children            = $childrenByParent[$node->getId()] ?? [];
        foreach ($children as $child) {
            $this->mapCommentPage($child, $page, $childrenByParent, $map);
        }
    }
}
