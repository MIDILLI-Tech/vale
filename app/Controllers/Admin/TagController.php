<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use ValeApp\Application\Content\SaveTagUseCase;
use ValeApp\Domain\Content\Entity\Tag;
use ValeApp\Domain\Content\Repository\ContentTypeRepositoryInterface;
use ValeApp\Domain\Content\Repository\TagRepositoryInterface;
use ValeApp\Domain\Shared\Exception\ValidationException;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Infrastructure\Cache\AdminSearchCache;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Translation\Translator;

final class TagController
{
    public function __construct(
        private readonly Translator $translator,
        private readonly Twig $twig,
        private readonly TagRepositoryInterface $tags,
        private readonly SaveTagUseCase $saveTag,
        private readonly SessionManager $session,
        private readonly ContentTypeRepositoryInterface $contentTypes,
        private readonly DbalSettingsRepository $settings,
        private readonly AdminSearchCache $searchCache,
    ) {
    }

    private const TAG_SEARCH_NAMESPACE = 'tag_search';
    private const TAG_SEARCH_TTL       = 120;

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
        $search = isset($params['search']) ? trim((string) $params['search']) : '';

        $total      = $this->tags->count($repoCt, $lang);
        $clientSide = $total <= client_side_search_fetch_limit();

        if ($clientSide) {
            $pagination = new Pagination(1, 100000);
            $tags       = $this->tags->findAllWithUsageCount(
                $pagination,
                $repoCt,
                $lang,
                false,
                $search ?: null,
            );
            if ($search !== '') {
                $total = $this->tags->count($repoCt, $lang, false, $search);
            }
        } else {
            $page       = max(1, (int) ($params['page'] ?? 1));
            $perPage    = max(1, min(100, (int) ($params['per_page'] ?? 50)));
            $pagination = new Pagination(
                $page,
                $perPage,
                $params['sort'] ?? null,
                $params['dir']  ?? 'asc',
            );
            $searchKey  = $search !== ''
                ? $this->tagSearchKeyParts($repoCt, $lang, $search, $pagination)
                : null;
            $cached = $searchKey !== null
                ? $this->searchCache->get(self::TAG_SEARCH_NAMESPACE, $searchKey)
                : null;
            $tags = $cached !== null ? $this->hydrateCachedTags($cached['rows']) : null;
            if ($tags === null) {
                $total = $this->tags->count($repoCt, $lang, false, $search ?: null);
                $rows  = $this->tags->findAllWithUsageCount(
                    $pagination,
                    $repoCt,
                    $lang,
                    false,
                    $search ?: null,
                );
                $tags  = $rows;
                if ($searchKey !== null) {
                    $this->searchCache->set(
                        self::TAG_SEARCH_NAMESPACE,
                        $searchKey,
                        $total,
                        array_map(self::cacheableTagRow(...), $rows),
                        self::TAG_SEARCH_TTL,
                    );
                }
            }
        }

        $multiLanguage   = $this->settings->get('multi_language_content', '0') === '1';
        $translationsMap = [];
        if ($multiLanguage) {
            $rootIds = [];
            foreach ($tags as $item) {
                $rootIds[] = $item['tag']->getTranslationOf() ?: $item['tag']->getId();
            }
            foreach ($this->tags->findTranslationsForRoots($rootIds) as $rootId => $rows) {
                $translationsMap[(string) $rootId] = array_map(fn ($t) => [
                    'id'            => $t->getId(),
                    'name'          => $t->getName(),
                    'slug'          => (string) $t->getSlug(),
                    'language'      => $t->getLanguage(),
                    'translationOf' => $t->getTranslationOf(),
                    'contentType'   => $t->getContentType(),
                ], $rows);
            }
        }

        $allCts = $this->contentTypes->findAll();
        $cts    = array_filter(
            $allCts,
            fn ($ct) => $ct->getSlug()->__toString() !== 'post'
                && in_array('tags', $ct->getSupports(), true)
        );

        $carry = array_diff_key(
            $params,
            array_flip(['page', 'sort', 'dir', 'search', 'per_page']),
        );
        $baseUrl = admin_url('/tags') . ($carry ? '?' . http_build_query($carry) : '');

        return $this->twig->render($response, 'admin/tags/index.twig', [
            'tags'                   => $tags,
            'total'                  => $total,
            'filters'                => $params,
            'client_side'            => $clientSide,
            'page'                   => $clientSide ? 1 : $pagination->page,
            'per_page'               => $clientSide ? 50 : $pagination->perPage,
            'base_url'               => $baseUrl,
            'contentTypes'           => $cts,
            'allContentTypes'        => $allCts,
            'flash_success'          => $this->session->flash('flash_success'),
            'flash_error'            => $this->session->flash('flash_error'),
            'multi_language_content' => $multiLanguage,
            'translations_map'       => $translationsMap,
            'supported_locales'      => $this->translator->getAvailableLocales(),
            'potential_originals'    => $this->tags->findPotentialOriginals(
                $repoCt,
                language: $lang ?: $this->translator->getLocale(),
            ),
            'current_locale'         => $lang,
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        return $response->withHeader('Location', admin_url('/tags'))->withStatus(302);
    }

    public function store(Request $request, Response $response): Response
    {
        $body                 = (array) $request->getParsedBody();
        $rawCt                = $body['content_type'] ?? 'post';
        $body['content_type'] = $rawCt === 'all' ? 'post' : $rawCt;

        try {
            $this->saveTag->execute($body);
            $this->searchCache->bump(self::TAG_SEARCH_NAMESPACE);
            $this->session->set('flash_success', $this->translator->get('Tag created successfully.'));
            return $response->withHeader('Location', admin_url('/tags?content_type=' . $rawCt))->withStatus(302);
        } catch (ValidationException $e) {
            $errors = implode('; ', array_map(fn ($m) => is_array($m) ? implode(', ', $m) : (string) $m, $e->getErrors()));
            $this->session->set('flash_error', $this->translator->get($errors));
            return $response->withHeader('Location', admin_url('/tags?content_type=' . $rawCt))->withStatus(302);
        }
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        return $response->withHeader('Location', admin_url('/tags'))->withStatus(302);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id                   = (int) $args['id'];
        $body                 = (array) $request->getParsedBody();
        $rawCt                = $body['content_type'] ?? 'post';
        $body['content_type'] = $rawCt === 'all' ? 'post' : $rawCt;

        try {
            $this->saveTag->execute($body, $id);
            $this->searchCache->bump(self::TAG_SEARCH_NAMESPACE);
            $this->session->set('flash_success', $this->translator->get('Tag updated successfully.'));
            return $response->withHeader('Location', admin_url('/tags?content_type=' . $rawCt))->withStatus(302);
        } catch (ValidationException $e) {
            $errors = implode('; ', array_map(fn ($m) => is_array($m) ? implode(', ', $m) : (string) $m, $e->getErrors()));
            $this->session->set('flash_error', $this->translator->get($errors));
            return $response->withHeader('Location', admin_url('/tags?content_type=' . $rawCt))->withStatus(302);
        }
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $this->tags->delete((int) $args['id']);
        $this->searchCache->bump(self::TAG_SEARCH_NAMESPACE);
        $this->session->set('flash_success', $this->translator->get('Tag deleted.'));
        return $response->withHeader('Location', admin_url('/tags'))->withStatus(302);
    }

    /**
     * Cache key parts for a server-side tag search listing, or null when
     * the request should bypass the search cache (no search term).
     *
     * @return array<string, mixed>|null
     */
    private function tagSearchKeyParts(
        ?string $repoCt,
        ?string $lang,
        string $search,
        Pagination $pagination,
    ): ?array {
        $search = trim($search);
        if ($search === '') {
            return null;
        }
        return [
            'search' => mb_strtolower($search, 'UTF-8'),
            'type'   => (string) ($repoCt ?? ''),
            'lang'   => (string) ($lang ?? ''),
            'page'   => $pagination->page,
            'per'    => $pagination->perPage,
            'sort'   => (string) ($pagination->sortBy ?? ''),
            'dir'    => strtolower((string) $pagination->sortDir),
        ];
    }

    /**
     * Repository row → scalar-only shape for JSON-safe caching.
     * Mirrors Tag::fromRow() so hits re-hydrate losslessly.
     *
     * @param array{tag: Tag, usageCount: int} $row
     * @return array{tag: array<string, mixed>, usageCount: int}
     */
    private static function cacheableTagRow(array $row): array
    {
        $tag = $row['tag'];
        return [
            'tag' => [
                'id'             => $tag->getId(),
                'name'           => $tag->getName(),
                'slug'           => (string) $tag->getSlug(),
                'content_type'   => $tag->getContentType(),
                'language'       => $tag->getLanguage(),
                'translation_of' => $tag->getTranslationOf(),
                'created_at'     => $tag->getCreatedAt()?->format('Y-m-d H:i:s'),
            ],
            'usageCount' => (int) $row['usageCount'],
        ];
    }

    /**
     * Re-hydrate cached tag rows. Returns null on any shape mismatch so
     * the caller falls through to a fresh database query.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<array{tag: Tag, usageCount: int}>|null
     */
    private function hydrateCachedTags(array $rows): ?array
    {
        try {
            $tags = [];
            foreach ($rows as $row) {
                if (!is_array($row) || !is_array($row['tag'] ?? null)) {
                    return null;
                }
                if (!isset($row['usageCount'])) {
                    return null;
                }
                $tags[] = [
                    'tag'        => Tag::fromRow($row['tag']),
                    'usageCount' => (int) $row['usageCount'],
                ];
            }
            return $tags;
        } catch (\Throwable) {
            return null;
        }
    }

    public function getTagJson(Request $request, Response $response, array $args): Response
    {
        $tag = $this->tags->findById((int) $args['id']);
        if ($tag === null) {
            return $response->withStatus(404);
        }

        $data = [
            'id'   => $tag->getId(),
            'name' => $tag->getName(),
            'slug' => (string) $tag->getSlug(),
        ];

        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function postsJson(Request $request, Response $response, array $args): Response
    {
        $tag = $this->tags->findById((int) $args['id']);
        if ($tag === null) {
            $response->getBody()->write(json_encode(['error' => 'Not found'], JSON_THROW_ON_ERROR));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $posts = $this->tags->findPostsByTagId($tag->getId());
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
                $t = $this->tags->findBySlug($slug, $contentType, $lang);
                if ($t !== null) {
                    $all[] = $t;
                }
            }
        } else {
            $all = $this->tags->findAll(null, $contentType, $lang);
        }

        if ($q !== '') {
            $all = array_values(array_filter($all, function ($t) use ($q) {
                return str_contains(mb_strtolower($t->getName()), $q)
                    || str_contains(mb_strtolower((string) $t->getSlug()), $q);
            }));
        }

        $total     = count($all);
        $limit     = max(1, (int) ($params['limit'] ?? client_side_search_fetch_limit()));
        $truncated = $total > $limit;
        if ($truncated) {
            $all = array_slice($all, 0, $limit);
        }

        $data = array_map(fn ($t) => [
            'id'          => $t->getId(),
            'name'        => $t->getName(),
            'slug'        => (string) $t->getSlug(),
            'contentType' => $t->getContentType(),
        ], $all);

        $payload = ['data' => $data, 'total' => $total, 'truncated' => $truncated];

        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
