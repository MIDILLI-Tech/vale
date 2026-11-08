<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use ValeApp\Domain\Content\TreeHelper;
use ValeApp\Domain\Shop\Entity\ProductCategory;
use ValeApp\Domain\Shop\Repository\ProductCategoryRepositoryInterface;
use ValeApp\Infrastructure\Content\RedirectService;
use ValeApp\Infrastructure\Content\SlugService;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Translation\Translator;

final class ProductCategoryController
{
    public function __construct(
        private readonly Translator $translator,
        private readonly Twig $twig,
        private readonly ProductCategoryRepositoryInterface $categories,
        private readonly SlugService $slugService,
        private readonly SessionManager $session,
        private readonly DbalSettingsRepository $settings,
        private readonly RedirectService $redirects,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $params      = $request->getQueryParams();
        $lang        = $params['language'] ?? $this->translator->getLocale();
        $all         = $this->categories->findAll($lang);
        $categories  = TreeHelper::buildTree($all);
        $usageCounts = $this->categories->countUsage();
        $total       = count($all);

        // Public URL for the "View" action (respects the configurable shop prefix)
        $shopPrefix = '/' . ltrim($this->settings->get('routing_shop_prefix', '/shop'), '/');
        $viewUrlMap = [];
        foreach ($all as $cat) {
            $viewUrlMap[$cat->getId()] = $shopPrefix . '?category=' . $cat->getSlug();
        }

        $multiLanguage   = $this->settings->get('multi_language_content', '0') === '1';
        $translationsMap = [];
        if ($multiLanguage) {
            $rootIds = [];
            foreach ($all as $cat) {
                $rootIds[] = $cat->getTranslationOf() ?: $cat->getId();
            }
            foreach ($this->categories->findTranslationsForRoots($rootIds) as $rootId => $rows) {
                $translationsMap[(string) $rootId] = array_map(fn ($t) => [
                    'id'            => $t->getId(),
                    'name'          => $t->getName(),
                    'slug'          => (string) $t->getSlug(),
                    'parentId'      => $t->getParentId(),
                    'description'   => $t->getDescription(),
                    'language'      => $t->getLanguage(),
                    'translationOf' => $t->getTranslationOf(),
                ], $rows);
            }
        }

        return $this->twig->render($response, 'admin/product-categories/index.twig', [
            'categories'             => $categories,
            'allCategories'          => $all,
            'usageCounts'            => $usageCounts,
            'view_url_map'           => $viewUrlMap,
            'total'                  => $total,
            'client_side'            => true,
            'page'                   => 1,
            'per_page'               => 50,
            'base_url'               => admin_url('/product-categories'),
            'flash_success'          => $this->session->flash('flash_success'),
            'flash_error'            => $this->session->flash('flash_error'),
            'multi_language_content' => $multiLanguage,
            'translations_map'       => $translationsMap,
            'supported_locales'      => $this->translator->getAvailableLocales(),
            'potential_originals'    => $this->categories->findPotentialOriginals(language: $lang),
            'current_locale'         => $lang,
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        return $response->withHeader('Location', admin_url('/product-categories'))->withStatus(302);
    }

    public function store(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $name = trim($body['name'] ?? '');
        if ($name === '') {
            $this->session->flash('error', $this->translator->get('Name is required.'));
            return $response->withHeader('Location', admin_url('/product-categories'))->withStatus(302);
        }

        $rawSlug = trim($body['slug'] ?? '');
        $slug    = $rawSlug !== ''
            ? $this->slugService->unique($rawSlug, VL_DB_PREFIX . 'product_categories', 'slug')
            : $this->slugService->generate($name, VL_DB_PREFIX . 'product_categories', 'slug');
        $cat  = new ProductCategory($name, $slug);
        $cat->setLanguage($body['language'] ?? 'en');

        $translationOf = isset($body['translation_of']) && $body['translation_of'] !== '' ? (int) $body['translation_of'] : null;
        if ($translationOf !== null) {
            $original = $this->categories->findById($translationOf);
            if ($original === null) {
                $this->session->flash('error', $this->translator->get('Selected original not found.'));
                return $response->withHeader('Location', admin_url('/product-categories'))->withStatus(302);
            }
            if ($original->getLanguage() === ($body['language'] ?? 'en')) {
                $this->session->flash('error', $this->translator->get('Cannot translate into the same language.'));
                return $response->withHeader('Location', admin_url('/product-categories'))->withStatus(302);
            }
            foreach ($this->categories->findTranslations($translationOf) as $existing) {
                if ($existing->getLanguage() === ($body['language'] ?? 'en')) {
                    $this->session->flash('error', $this->translator->get('This original already has a translation in this language.'));
                    return $response->withHeader('Location', admin_url('/product-categories'))->withStatus(302);
                }
            }
        }
        $cat->setTranslationOf($translationOf);
        $cat->setDescription(!empty($body['description']) ? $body['description'] : null);
        $cat->setParentId(!empty($body['parent_id']) ? (int) $body['parent_id'] : null);

        $this->categories->save($cat);
        $this->session->flash('success', $this->translator->get('Category created.'));
        return $response->withHeader('Location', admin_url('/product-categories'))->withStatus(302);
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        return $response->withHeader('Location', admin_url('/product-categories'))->withStatus(302);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $category = $this->categories->findById((int) $args['id']);
        if ($category === null) {
            return $response->withStatus(404);
        }

        $oldSlug = (string) $category->getSlug();

        $body = (array) $request->getParsedBody();
        $name = trim($body['name'] ?? '');
        if ($name !== '') {
            $category->setName($name);
        }
        $rawSlug = trim($body['slug'] ?? '');
        if ($rawSlug !== '') {
            $category->setSlug($this->slugService->unique($rawSlug, VL_DB_PREFIX . 'product_categories', 'slug', $category->getId()));
        }

        if ($oldSlug !== (string) $category->getSlug()) {
            $createRedirect = !empty($body['create_redirect']);
            if ($createRedirect) {
                $shopPrefix     = '/' . ltrim($this->settings->get('routing_shop_prefix', '/shop'), '/');
                $categoryPrefix = '/' . ltrim($this->settings->get('routing_category_prefix', '/category'), '/');
                $this->redirects->recordRedirect($shopPrefix . $categoryPrefix . '/' . $oldSlug, $shopPrefix . $categoryPrefix . '/' . (string) $category->getSlug());
            }
        }

        $targetLanguage = (string) ($body['language'] ?? $category->getLanguage());
        $translationOf  = $category->getTranslationOf();
        if (isset($body['translation_of']) && $body['translation_of'] !== '') {
            $newId = (int) $body['translation_of'];
            if ($newId > 0) {
                $original = $this->categories->findById($newId);
                if ($original === null) {
                    $this->session->flash('error', $this->translator->get('Selected original not found.'));
                    return $response->withHeader('Location', admin_url('/product-categories'))->withStatus(302);
                }
                if ($original->getLanguage() === $targetLanguage) {
                    $this->session->flash('error', $this->translator->get('Cannot translate into the same language.'));
                    return $response->withHeader('Location', admin_url('/product-categories'))->withStatus(302);
                }
                foreach ($this->categories->findTranslations($newId) as $existing) {
                    if ($existing->getLanguage() === $targetLanguage && (int) $existing->getId() !== $category->getId()) {
                        $this->session->flash('error', $this->translator->get('This original already has a translation in this language.'));
                        return $response->withHeader('Location', admin_url('/product-categories'))->withStatus(302);
                    }
                }
                $translationOf = $newId;
            } else {
                $translationOf = null;
            }
        }
        $category->setLanguage($targetLanguage);
        $category->setTranslationOf($translationOf);
        $category->setDescription(!empty($body['description']) ? $body['description'] : null);
        $category->setParentId(!empty($body['parent_id']) ? (int) $body['parent_id'] : null);

        $this->categories->save($category);
        $this->session->flash('success', $this->translator->get('Category updated.'));
        return $response->withHeader('Location', admin_url('/product-categories'))->withStatus(302);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $this->categories->delete((int) $args['id']);
        $this->session->flash('success', $this->translator->get('Category deleted.'));
        return $response->withHeader('Location', admin_url('/product-categories'))->withStatus(302);
    }

    public function getCategoryJson(Request $request, Response $response, array $args): Response
    {
        $category = $this->categories->findById((int) $args['id']);
        if ($category === null) {
            return $response->withStatus(404);
        }

        $data = [
            'id'          => $category->getId(),
            'name'        => $category->getName(),
            'description' => $category->getDescription(),
            'slug'        => (string) $category->getSlug(),
            'parentId'    => $category->getParentId(),
        ];

        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
