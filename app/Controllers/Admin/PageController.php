<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use ValeApp\Application\Content\SavePostUseCase;
use ValeApp\Application\Seo\SaveSeoMetaUseCase;
use ValeApp\Domain\Content\Entity\Post;
use ValeApp\Domain\Content\Repository\ContentTypeRepositoryInterface;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Content\Repository\RevisionRepositoryInterface;
use ValeApp\Domain\Membership\Repository\PlanRepositoryInterface;
use ValeApp\Domain\Seo\Repository\SeoMetaRepositoryInterface;
use ValeApp\Domain\Shared\Exception\ValidationException;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\User\Repository\UserRepositoryInterface;
use ValeApp\Infrastructure\Content\RedirectService;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Theme\ThemeManager;
use ValeApp\Infrastructure\Translation\Translator;

final class PageController
{
    use RevisionControllerTrait;
    use ContentPaginationTrait;

    protected function revisionUrlPrefix(): string
    {
        return 'pages';
    }

    public function __construct(
        private readonly Translator $translator,
        private readonly Twig $twig,
        private readonly PostRepositoryInterface $posts,
        private readonly SavePostUseCase $savePost,
        private readonly SaveSeoMetaUseCase $saveSeo,
        private readonly SeoMetaRepositoryInterface $seoRepo,
        private readonly SessionManager $session,
        private readonly RevisionRepositoryInterface $revisions,
        private readonly DbalSettingsRepository $settings,
        private readonly UserRepositoryInterface $users,
        private readonly ThemeManager $themeManager,
        private readonly ContentTypeRepositoryInterface $contentTypes,
        private readonly PlanRepositoryInterface $plans,
        private readonly RedirectService $redirects,
    ) {
    }

    /**
     * Map of page id => full public path (parent chain included), so the parent
     * picker can rebuild a child's shareable URL before anything is saved.
     *
     * @param Post[] $pages
     * @return array<int, string>
     */
    private function parentPagePaths(array $pages): array
    {
        $paths = [];
        foreach ($pages as $page) {
            $paths[(int) $page->getId()] = $this->posts->buildPagePath($page);
        }
        return $paths;
    }

    /**
     * Canonical public path of a page (global page prefix + parent chain).
     * Stored locale-agnostic on purpose: RedirectMiddleware strips the locale
     * before lookup, so alias targets stay valid across languages.
     */
    private function pageCanonicalPath(Post $post): string
    {
        return $this->redirects->contentCanonical(
            $this->pageBasePath(),
            $this->posts->buildPagePath($post)
        );
    }

    private function pageBasePath(): string
    {
        $prefix = $this->settings->get('routing_page_prefix', '');
        return $prefix ? ('/' . ltrim($prefix, '/')) : '';
    }

    /**
     * Alias leaf slugs (last URL segment) for a canonical path.
     *
     * @return string[]
     */
    private function pageAliasLeafs(string $canonical): array
    {
        return $this->redirects->aliasLeafs($canonical, $this->pageBasePath());
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

    public function index(Request $request, Response $response): Response
    {
        $params  = $request->getQueryParams();
        $filters = ['content_type' => 'page'];

        if (!empty($params['status'])) {
            $filters['status'] = $params['status'];
        }

        if (!empty($params['language'])) {
            $filters['language'] = $params['language'];
        }

        $total                             = $this->posts->count($filters);
        [$pagination, $clientSide, $total] = $this->resolvePagination($params, $filters, $total);

        $pages = $this->posts->findAll($pagination, $filters);

        // Build full path map (id => "parent/child") and parent title map (id => title)
        $pathMap  = [];
        $titleMap = [];
        foreach ($pages as $p) {
            $pathMap[$p->getId()]  = $this->posts->buildPagePath($p);
            $titleMap[$p->getId()] = $p->getTitle();
        }

        $carry   = array_diff_key($params, array_flip(['page', 'sort', 'dir', 'search', 'search_body', 'per_page']));
        $baseUrl = admin_url('/pages') . ($carry ? '?' . http_build_query($carry) : '');

        $pagePrefix = $this->settings->get('routing_page_prefix', '');
        $pagePrefix = $pagePrefix ? ('/' . ltrim($pagePrefix, '/')) : '';

        return $this->twig->render($response, 'admin/pages/index.twig', [
            'pages'                  => $pages,
            'path_map'               => $pathMap,
            'titleMap'               => $titleMap,
            'page_prefix'            => $pagePrefix,
            'total'                  => $total,
            'filters'                => $params,
            'client_side'            => $clientSide,
            'page'                   => $clientSide ? 1 : $pagination->page,
            'per_page'               => $clientSide ? 20 : $pagination->perPage,
            'base_url'               => $baseUrl,
            'multi_language_content' => $this->settings->get('multi_language_content', '0') === '1',
            'supported_locales'      => $this->translator->getAvailableLocales(),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $lang          = $request->getQueryParams()['language']       ?? $this->translator->getLocale();
        $translationOf = $request->getQueryParams()['translation_of'] ?? null;
        $translations  = $translationOf ? $this->posts->findTranslations((int) $translationOf) : [];

        $potentialOriginals = $this->posts->findPotentialOriginals('page', language: $lang);
        if ($translationOf) {
            $found = false;
            foreach ($potentialOriginals as $po) {
                if ((int) $po->getId() === (int) $translationOf) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $original = $this->posts->findById((int) $translationOf);
                if ($original && $original->getTranslationOf() === null) {
                    array_unshift($potentialOriginals, $original);
                }
            }
        }

        $allPages      = $this->posts->findAll(new Pagination(1, 100), ['content_type' => 'page', 'status' => 'published', 'language' => $lang]);

        $type = $this->contentTypes->findBySlug(new Slug('page'));

        return $this->twig->render($response, 'admin/pages/form.twig', [
            'post'                       => null,
            'type'                       => $type,
            'is_edit'                    => false,
            'parent_pages'               => $allPages,
            'parent_page_paths'          => $this->parentPagePaths($allPages),
            'seo'                        => null,
            'page_templates'             => $this->themeManager->discoverPageTemplates(),
            'auto_redirect_enabled'      => $this->settings->get('auto_redirect_enabled', '1')  === '1',
            'multi_language_content'     => $this->settings->get('multi_language_content', '0') === '1',
            'supported_locales'          => $this->translator->getAvailableLocales(),
            'potential_originals'        => $potentialOriginals,
            'current_locale'             => $lang,
            'translations'               => $translations,
            'original_id'                => $translationOf,
            'membership_tags'            => $this->getMembershipTagNames(),
            'page_aliases'               => [],
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $body                 = (array) $request->getParsedBody();
        $body['author_id']    = $this->session->get('user_id');
        $body['content_type'] = 'page';
        $body['access_tags']  = !empty($body['access_tags']) ? (array) $body['access_tags'] : [];

        $role = $request->getAttribute('user_role', $this->session->get('user_role', 'subscriber'));
        if (in_array($body['status'] ?? 'draft', ['published', 'scheduled', 'unlisted'], true) && $role !== 'super_admin') {
            $permissions = $request->getAttribute('user_permissions', $this->session->get('user_permissions', []));
            if (!in_array('pages.publish', $permissions, true)) {
                return $response->withStatus(403);
            }
        }

        $seoData              = (array) ($body['seo'] ?? []);

        $body['clear_schedule'] = empty($body['set_schedule']);

        try {
            $post = $this->savePost->execute($body);
            $this->saveSeo->execute('page', $post->getId(), $seoData);
            $this->flashTemplateFallbackWarning($body, $post);
            $this->session->set('flash_success', $this->translator->get('Page created successfully.'));
            return $response->withHeader('Location', admin_url('/pages'))->withStatus(302);
        } catch (ValidationException $e) {
            $allPages           = $this->posts->findAll(new Pagination(1, 100), ['content_type' => 'page', 'status' => 'published']);
            $type               = $this->contentTypes->findBySlug(new Slug('page'));
            $lang               = $body['language']       ?? $this->translator->getLocale();
            $translationOf      = $body['translation_of'] ?? null;
            $translations       = $translationOf ? $this->posts->findTranslations((int) $translationOf) : [];
            $potentialOriginals = $this->posts->findPotentialOriginals('page', language: $lang);
            if ($translationOf) {
                $found = false;
                foreach ($potentialOriginals as $po) {
                    if ((int) $po->getId() === (int) $translationOf) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $original = $this->posts->findById((int) $translationOf);
                    if ($original && $original->getTranslationOf() === null) {
                        array_unshift($potentialOriginals, $original);
                    }
                }
            }
            return $this->twig->render($response->withStatus(422), 'admin/pages/form.twig', [
                'errors'                 => $e->getErrors(),
                'old'                    => $body,
                'post'                   => null,
                'type'                   => $type,
                'is_edit'                => false,
                'parent_pages'           => $allPages,
                'parent_page_paths'      => $this->parentPagePaths($allPages),
                'seo'                    => null,
                'page_templates'         => $this->themeManager->discoverPageTemplates(),
                'auto_redirect_enabled'  => $this->settings->get('auto_redirect_enabled', '1')  === '1',
                'multi_language_content' => $this->settings->get('multi_language_content', '0') === '1',
                'supported_locales'      => $this->translator->getAvailableLocales(),
                'potential_originals'    => $potentialOriginals,
                'current_locale'         => $lang,
                'translations'           => $translations,
                'original_id'            => $translationOf,
                'membership_tags'        => $this->getMembershipTagNames(),
                'page_aliases'           => [],
            ]);
        }
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $post = $this->posts->findById((int) $args['id']);
        if ($post === null) {
            return $response->withStatus(404);
        }

        $allPages      = $this->posts->findAll(new Pagination(1, 100), ['content_type' => 'page', 'status' => 'published', 'language' => $post->getLanguage()]);
        $translationOf = $post->getTranslationOf();
        $translations  = $translationOf ? $this->posts->findTranslations((int) $translationOf) : ($post->getId() ? $this->posts->findTranslations($post->getId()) : []);

        $type = $this->contentTypes->findBySlug(new Slug('page'));

        $potentialOriginals = $this->posts->findPotentialOriginals('page', $post->getId(), $post->getLanguage());
        if ($translationOf) {
            $found = false;
            foreach ($potentialOriginals as $po) {
                if ((int) $po->getId() === (int) $translationOf) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $original = $this->posts->findById((int) $translationOf);
                if ($original && $original->getTranslationOf() === null) {
                    array_unshift($potentialOriginals, $original);
                }
            }
        }

        return $this->twig->render($response, 'admin/pages/form.twig', [
            'post'                   => $post,
            'type'                   => $type,
            'is_edit'                => true,
            'parent_pages'           => $allPages,
            'parent_page_paths'      => $this->parentPagePaths($allPages),
            'page_path'              => $this->posts->buildPagePath($post),
            'seo'                    => $this->seoRepo->findByEntity('page', $post->getId()),
            'page_templates'         => $this->themeManager->discoverPageTemplates(),
            'auto_redirect_enabled'  => $this->settings->get('auto_redirect_enabled', '1')  === '1',
            'multi_language_content' => $this->settings->get('multi_language_content', '0') === '1',
            'supported_locales'      => $this->translator->getAvailableLocales(),
            'potential_originals'    => $potentialOriginals,
            'current_locale'         => $post->getLanguage(),
            'translations'           => $translations,
            'original_id'            => $translationOf ?: $post->getId(),
            'membership_tags'        => $this->getMembershipTagNames(),
            'page_aliases'           => $this->pageAliasLeafs($this->pageCanonicalPath($post)),
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id       = (int) $args['id'];
        $existing = $this->posts->findById($id);
        if ($existing === null) {
            return $response->withStatus(404);
        }

        $body                 = (array) $request->getParsedBody();
        $body['author_id']    = $this->session->get('user_id');
        $body['content_type'] = 'page';
        $body['access_tags']  = !empty($body['access_tags']) ? (array) $body['access_tags'] : [];

        $role      = $request->getAttribute('user_role', $this->session->get('user_role', 'subscriber'));
        $oldStatus = $existing->getStatus() ?? 'draft';
        $newStatus = $body['status']        ?? $oldStatus;
        if (in_array($newStatus, ['published', 'scheduled', 'unlisted'], true) && $newStatus !== $oldStatus && $role !== 'super_admin') {
            $permissions = $request->getAttribute('user_permissions', $this->session->get('user_permissions', []));
            if (!in_array('pages.publish', $permissions, true)) {
                return $response->withStatus(403);
            }
        }

        $seoData              = (array) ($body['seo'] ?? []);

        $body['clear_schedule'] = empty($body['set_schedule']);

        try {
            $post = $this->savePost->execute($body, $id);
            $this->saveSeo->execute('page', $id, $seoData);
            $this->flashTemplateFallbackWarning($body, $post);
            $this->session->set('flash_success', $this->translator->get('Page updated successfully.'));
            return $response->withHeader('Location', admin_url('/pages/' . $id . '/edit'))->withStatus(302);
        } catch (ValidationException $e) {
            $post          = $this->posts->findById($id);
            $allPages      = $this->posts->findAll(new Pagination(1, 100), ['content_type' => 'page', 'status' => 'published']);
            $type          = $this->contentTypes->findBySlug(new Slug('page'));
            $lang          = $body['language']       ?? ($post ? $post->getLanguage() : $this->translator->getLocale());
            $translationOf = $body['translation_of'] ?? ($post ? $post->getTranslationOf() : null);
            $originalId    = $translationOf ?: $id;
            $translations  = $this->posts->findTranslations((int) $originalId);
            return $this->twig->render($response->withStatus(422), 'admin/pages/form.twig', [
                'errors'                 => $e->getErrors(),
                'old'                    => $body,
                'post'                   => $post,
                'type'                   => $type,
                'is_edit'                => true,
                'parent_pages'           => $allPages,
                'parent_page_paths'      => $this->parentPagePaths($allPages),
                'page_path'              => $post ? $this->posts->buildPagePath($post) : '',
                'seo'                    => $this->seoRepo->findByEntity('page', $id),
                'page_templates'         => $this->themeManager->discoverPageTemplates(),
                'auto_redirect_enabled'  => $this->settings->get('auto_redirect_enabled', '1')  === '1',
                'multi_language_content' => $this->settings->get('multi_language_content', '0') === '1',
                'supported_locales'      => $this->translator->getAvailableLocales(),
                'potential_originals'    => $this->posts->findPotentialOriginals('page', $id, $lang),
                'current_locale'         => $lang,
                'translations'           => $translations,
                'original_id'            => $originalId,
                'membership_tags'        => $this->getMembershipTagNames(),
                'page_aliases'           => [],
            ]);
        }
    }

    /**
     * Warn when an over-long template name was clamped to 'standard' by SavePostUseCase.
     */
    private function flashTemplateFallbackWarning(array $body, Post $post): void
    {
        $requested = isset($body['page_template']) && is_string($body['page_template']) ? $body['page_template'] : 'standard';
        if ($requested === 'standard' || $post->getPageTemplate() !== 'standard') {
            return;
        }
        if (mb_strlen($requested) > Post::MAX_PAGE_TEMPLATE_LENGTH) {
            $this->session->set('flash_warning', $this->translator->get(
                'Page template name too long (max :max characters) — standard layout used instead.',
                [':max' => (string) Post::MAX_PAGE_TEMPLATE_LENGTH]
            ));
        }
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $id       = (int) $args['id'];
        $existing = $this->posts->findById($id);
        if ($existing === null) {
            return $response->withStatus(404);
        }

        $this->posts->delete($id);
        $this->redirects->deleteManualByTarget($this->pageCanonicalPath($existing));
        $this->session->set('flash_success', $this->translator->get('Page deleted.'));
        return $response->withHeader('Location', admin_url('/pages'))->withStatus(302);
    }

    /** GET /admin/pages/{id}/json */
    public function getPageJson(Request $request, Response $response, array $args): Response
    {
        $page = $this->posts->findById((int) $args['id']);
        if ($page === null) {
            return $response->withStatus(404);
        }

        $data = [
            'id'      => $page->getId(),
            'title'   => $page->getTitle(),
            'body'    => $page->getBody(),
            'excerpt' => $page->getExcerpt(),
        ];

        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /** POST /admin/pages/preview — render unsaved content without saving */
    public function preview(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();

        $fake                  = new \stdClass();
        $fake->id              = (int) ($body['id'] ?? 0);
        $fake->title           = $body['title']                    ?? 'Untitled';
        $fake->slug            = $body['slug']                     ?? '';
        $fake->excerpt         = $body['excerpt']                  ?? null;
        $fake->body            = $body['body']                     ?? '';
        $fake->pageTemplate    = $body['page_template']            ?? 'standard';
        $fake->hideTitle       = !empty($body['hide_title']);
        $fake->metaTitle       = $body['seo']['meta_title']        ?? null;
        $fake->metaDescription = $body['seo']['meta_description']  ?? null;

        return $this->twig->render($response, 'page.twig', [
            'page'       => $fake,
            'is_preview' => true,
        ]);
    }
}
