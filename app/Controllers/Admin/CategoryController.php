<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use ValeApp\Application\Content\SaveCategoryUseCase;
use ValeApp\Domain\Content\Repository\CategoryRepositoryInterface;
use ValeApp\Domain\Content\Repository\ContentTypeRepositoryInterface;
use ValeApp\Domain\Content\TreeHelper;
use ValeApp\Domain\Shared\Exception\ValidationException;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Infrastructure\Cache\PageCache;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Translation\Translator;

final class CategoryController
{
    public function __construct(
        private readonly Translator $translator,
        private readonly Twig $twig,
        private readonly CategoryRepositoryInterface $categories,
        private readonly SaveCategoryUseCase $saveCategory,
        private readonly SessionManager $session,
        private readonly ContentTypeRepositoryInterface $contentTypes,
        private readonly DbalSettingsRepository $settings,
        private readonly ?PageCache $pageCache = null,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $params      = $request->getQueryParams();
        $contentType = $params['content_type'] ?? 'all';
        if (!is_string($contentType)) {
            $contentType = 'all';
        }
        $repoCt      = $contentType === 'all' ? null : $contentType;

        $lang        = $params['language'] ?? null;
        if ($lang === '') {
            $lang = null;
        }
        $all         = $this->categories->findAll(null, null, $lang);
        $filtered    = $repoCt === null ? $all : $this->categories->findAll(null, $repoCt, $lang);
        $categories  = TreeHelper::buildTree($filtered);
        $usageCounts = $this->categories->getUsageCounts($repoCt);

        // Public URL for the "View" action (respects routing prefixes + per-type base path)
        $categoryPrefix = '/' . ltrim($this->settings->get('routing_category_prefix', '/category'), '/');
        $viewUrlMap     = [];
        foreach ($filtered as $cat) {
            $ct = $cat->getContentType();
            if ($ct === 'post') {
                $path = $categoryPrefix . '/' . $cat->getSlug();
            } else {
                $type   = $this->contentTypes->findBySlug(new Slug($ct));
                $base   = $type?->getPublicBasePath() ?? '/' . $ct;
                $prefix = $type?->getCategoryPrefix() ?? $categoryPrefix;
                $path   = rtrim($base, '/') . '/' . ltrim($prefix, '/') . '/' . $cat->getSlug();
            }
            $viewUrlMap[$cat->getId()] = $path;
        }

        $multiLanguage    = $this->settings->get('multi_language_content', '0') === '1';
        $translationsMap  = [];
        if ($multiLanguage) {
            $rootIds = [];
            foreach ($filtered as $cat) {
                $rootIds[] = $cat->getTranslationOf() ?: $cat->getId();
            }
            foreach ($this->categories->findTranslationsForRoots($rootIds) as $rootId => $rows) {
                $translationsMap[(string) $rootId] = array_map(fn ($t) => [
                    'id'            => $t->getId(),
                    'name'          => $t->getName(),
                    'slug'          => (string) $t->getSlug(),
                    'description'   => $t->getDescription(),
                    'language'      => $t->getLanguage(),
                    'translationOf' => $t->getTranslationOf(),
                    'parentId'      => $t->getParentId(),
                    'sortOrder'     => $t->getSortOrder(),
                    'contentType'   => $t->getContentType(),
                ], $rows);
            }
        }

        $allCts = $this->contentTypes->findAll();
        $cts    = array_filter(
            $allCts,
            fn ($ct) => $ct->getSlug()->__toString() !== 'post'
                && in_array('categories', $ct->getSupports(), true)
        );

        return $this->twig->render($response, 'admin/categories/index.twig', [
            'categories'             => $categories,
            'usageCounts'            => $usageCounts,
            'allCategories'          => $all,
            'view_url_map'           => $viewUrlMap,
            'content_type'           => $contentType,
            'contentTypes'           => $cts,
            'allContentTypes'        => $allCts,
            'filters'                => $params,
            'flash_success'          => $this->session->flash('flash_success'),
            'flash_error'            => $this->session->flash('flash_error'),
            'multi_language_content' => $multiLanguage,
            'translations_map'       => $translationsMap,
            'supported_locales'      => $this->translator->getAvailableLocales(),
            'potential_originals'    => $this->categories->findPotentialOriginals(
                $repoCt,
                language: $lang ?: $this->translator->getLocale(),
            ),
            'current_locale'         => $lang,
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        return $response->withHeader('Location', admin_url('/categories'))->withStatus(302);
    }

    public function store(Request $request, Response $response): Response
    {
        $body                 = (array) $request->getParsedBody();
        $rawCt                = $body['content_type'] ?? 'post';
        $body['content_type'] = $rawCt === 'all' ? 'post' : $rawCt;
        $redirect             = admin_url('/categories?content_type=' . $rawCt);

        try {
            $this->saveCategory->execute($body);
            $this->flushPageCache();
            $this->session->set('flash_success', $this->translator->get('Category created successfully.'));
            return $response->withHeader('Location', $redirect)->withStatus(302);
        } catch (ValidationException $e) {
            $errors = implode('; ', array_map(fn ($m) => is_array($m) ? implode(', ', $m) : (string) $m, $e->getErrors()));
            $this->session->set('flash_error', $this->translator->get($errors));
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        return $response->withHeader('Location', admin_url('/categories'))->withStatus(302);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id                   = (int) $args['id'];
        $body                 = (array) $request->getParsedBody();
        $rawCt                = $body['content_type'] ?? 'post';
        $body['content_type'] = $rawCt === 'all' ? 'post' : $rawCt;
        $redirect             = admin_url('/categories?content_type=' . $rawCt);

        try {
            $this->saveCategory->execute($body, $id);
            $this->flushPageCache();
            $this->session->set('flash_success', $this->translator->get('Category updated successfully.'));
            return $response->withHeader('Location', $redirect)->withStatus(302);
        } catch (ValidationException $e) {
            $errors = implode('; ', array_map(fn ($m) => is_array($m) ? implode(', ', $m) : (string) $m, $e->getErrors()));
            $this->session->set('flash_error', $this->translator->get($errors));
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $this->categories->delete((int) $args['id']);
        $this->flushPageCache();
        $this->session->set('flash_success', $this->translator->get('Category deleted.'));
        return $response->withHeader('Location', admin_url('/categories'))->withStatus(302);
    }


    /**
     * A category renders in archive listings, post cards and menus, so a rename
     * or delete makes cached HTML stale beyond the archive page itself.
     */
    private function flushPageCache(): void
    {
        $this->pageCache?->flush();
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

    public function postsJson(Request $request, Response $response, array $args): Response
    {
        $cat = $this->categories->findById((int) $args['id']);
        if ($cat === null) {
            $response->getBody()->write(json_encode(['error' => 'Not found'], JSON_THROW_ON_ERROR));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $posts = $this->categories->findPostsByCategoryId($cat->getId());
        $response->getBody()->write(json_encode($posts, JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }


    public function listJson(Request $request, Response $response): Response
    {
        $params      = $request->getQueryParams();
        $contentType = $params['content_type'] ?? null;
        if ($contentType === '') {
            $contentType = null;
        }
        $lang = $params['language'] ?? null;
        if ($lang === '') {
            $lang = null;
        }
        $q          = isset($params['q']) ? mb_strtolower(trim((string) $params['q'])) : '';
        $slugsParam = isset($params['slugs']) ? trim((string) $params['slugs']) : '';

        if ($slugsParam !== '') {
            $all = [];
            foreach (array_filter(array_map('trim', explode(',', $slugsParam))) as $slug) {
                $c = $this->categories->findBySlug($slug, $contentType, $lang);
                if ($c !== null) {
                    $all[] = $c;
                }
            }
        } else {
            $all = $this->categories->findAll(null, $contentType, $lang);
        }

        if ($q !== '') {
            $all = array_values(array_filter($all, function ($c) use ($q) {
                return str_contains(mb_strtolower($c->getName()), $q)
                    || str_contains(mb_strtolower((string) $c->getSlug()), $q);
            }));
        }

        $total     = count($all);
        $limit     = max(1, (int) ($params['limit'] ?? client_side_search_fetch_limit()));
        $truncated = $total > $limit;
        if ($truncated) {
            $all = array_slice($all, 0, $limit);
        }

        $data = array_map(fn ($c) => [
            'id'          => $c->getId(),
            'name'        => $c->getName(),
            'slug'        => (string) $c->getSlug(),
            'contentType' => $c->getContentType(),
        ], $all);

        $payload = ['data' => $data, 'total' => $total, 'truncated' => $truncated];

        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
