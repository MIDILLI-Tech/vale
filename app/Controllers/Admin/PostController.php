<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use ValeApp\Application\Content\SavePostUseCase;
use ValeApp\Application\Seo\SaveSeoMetaUseCase;
use ValeApp\Application\Webhook\OutboundWebhookService;
use ValeApp\Domain\Content\Entity\Post;
use ValeApp\Domain\Content\Repository\CategoryRepositoryInterface;
use ValeApp\Domain\Content\Repository\ContentTypeRepositoryInterface;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Content\Repository\RevisionRepositoryInterface;
use ValeApp\Domain\Content\Repository\TagRepositoryInterface;
use ValeApp\Domain\Content\TreeHelper;
use ValeApp\Domain\Membership\Repository\PlanRepositoryInterface;
use ValeApp\Domain\Seo\Repository\SeoMetaRepositoryInterface;
use ValeApp\Domain\Shared\Event\EventDispatcherInterface;
use ValeApp\Domain\Shared\Exception\ValidationException;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;
use ValeApp\Domain\User\Repository\UserRepositoryInterface;
use ValeApp\Infrastructure\Content\RedirectService;
use ValeApp\Infrastructure\Content\SlugService;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Translation\Translator;

final class PostController
{
    use RevisionControllerTrait;
    use ContentPaginationTrait;

    protected function revisionUrlPrefix(): string
    {
        return 'posts';
    }

    private function contentBaseFor(string $contentType): ?string
    {
        if ($contentType === 'page') {
            $prefix = $this->settings->get('routing_page_prefix', '');
            return $prefix ? ('/' . ltrim($prefix, '/')) : '';
        }

        if ($contentType === 'post') {
            return '/' . ltrim($this->settings->get('routing_blog_prefix', '/blog'), '/');
        }

        $type = $this->contentTypes->findBySlug(new Slug($contentType));
        return $type?->getPublicBasePath();
    }

    private function contentCanonicalFor(Post $post): ?string
    {
        $base = $this->contentBaseFor($post->getContentType());
        if ($base === null) {
            return null;
        }

        $relative = $post->getContentType() === 'page'
            ? $this->posts->buildPagePath($post)
            : (string) $post->getSlug();

        return $this->redirects->contentCanonical($base, $relative);
    }

    /**
     * @return string[]
     */
    private function contentAliasLeafs(Post $post): array
    {
        $base      = $this->contentBaseFor($post->getContentType());
        $canonical = $this->contentCanonicalFor($post);
        if ($base === null || $canonical === null) {
            return [];
        }

        return $this->redirects->aliasLeafs($canonical, $base);
    }

    private function deleteContentAliases(Post $post): void
    {
        $canonical = $this->contentCanonicalFor($post);
        if ($canonical !== null) {
            $this->redirects->deleteManualByTarget($canonical);
        }
    }

    public function __construct(
        private readonly Translator $translator,
        private readonly Twig $twig,
        private readonly PostRepositoryInterface $posts,
        private readonly CategoryRepositoryInterface $categories,
        private readonly TagRepositoryInterface $tags,
        private readonly UserRepositoryInterface $users,
        private readonly SavePostUseCase $savePost,
        private readonly SaveSeoMetaUseCase $saveSeo,
        private readonly SeoMetaRepositoryInterface $seoRepo,
        private readonly SessionManager $session,
        private readonly ProductRepositoryInterface $products,
        private readonly RevisionRepositoryInterface $revisions,
        private readonly DbalSettingsRepository $settings,
        private readonly ContentTypeRepositoryInterface $contentTypes,
        private readonly PlanRepositoryInterface $plans,
        private readonly EventDispatcherInterface $events,
        private readonly OutboundWebhookService $webhookService,
        private readonly SlugService $slugService,
        private readonly RedirectService $redirects,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $params      = $request->getQueryParams();
        $contentType = $params['content_type'] ?? 'post';
        if (!is_string($contentType)) {
            $contentType = 'post';
        }
        // "all" means all blog posts (content_type=post), not all content types
        // Pages have their own management at /admin/pages
        $repoCt  = ($contentType === 'all' || $contentType === '') ? 'post' : $contentType;
        $filters = ['content_type' => $repoCt];

        if ($contentType !== 'all') {
            $role = $request->getAttribute('user_role', $this->session->get('user_role', 'subscriber'));
            if ($role !== 'super_admin') {
                $permissions = $request->getAttribute('user_permissions', $this->session->get('user_permissions', []));
                if ($contentType === 'page') {
                    if (!in_array('pages.list', $permissions, true)) {
                        return $response->withStatus(403);
                    }
                } elseif (!in_array($contentType, ['post'], true)) {
                    if (!in_array("cpt.{$contentType}.list", $permissions, true)) {
                        return $response->withStatus(403);
                    }
                }
            }
        }

        if (!empty($params['status'])) {
            $filters['status'] = $params['status'];
        }
        if (!empty($params['language'])) {
            $filters['language'] = $params['language'];
        }

        if (!empty($params['tag_id'])) {
            $filters['tag_id'] = (int) $params['tag_id'];
        }
        if (!empty($params['category_id'])) {
            $filters['category_id'] = (int) $params['category_id'];
        }

        $total                             = $this->posts->count($filters);
        [$pagination, $clientSide, $total] = $this->resolvePagination($params, $filters, $total);

        $posts = $this->posts->findAll($pagination, $filters);

        // Public URL for the "View" action (respects routing prefixes + page parent chain)
        $pagePrefix   = $this->settings->get('routing_page_prefix', '');
        $pagePrefix   = $pagePrefix ? ('/' . ltrim($pagePrefix, '/')) : '';
        $blogPrefix   = '/' . ltrim($this->settings->get('routing_blog_prefix', '/blog'), '/');
        $viewUrlMap   = [];
        foreach ($posts as $p) {
            $ct = $p->getContentType();
            if ($ct === 'page') {
                $path = $pagePrefix . '/' . $this->posts->buildPagePath($p);
            } elseif ($ct === 'post') {
                $path = $blogPrefix . '/' . $p->getSlug();
            } else {
                $path = $this->contentTypes->findBySlug(new Slug($ct))?->getPublicBasePath()
                    ?? '/' . $ct;
                $path = rtrim($path, '/') . '/' . $p->getSlug();
            }
            $viewUrlMap[$p->getId()] = $path;
        }

        $carry   = array_diff_key($params, array_flip(['page', 'sort', 'dir', 'search', 'search_body', 'per_page']));
        $baseUrl = admin_url('/posts') . ($carry ? '?' . http_build_query($carry) : '');

        if ($contentType === 'all') {
            $type = null;
        } else {
            $type = $this->contentTypes->findBySlug(new Slug($contentType))
                    ?? $this->contentTypes->findBySlug(new Slug('post'));
        }

        $categories  = $this->categories->findAll(null, $repoCt);
        $tags        = $this->tags->findAll(null, $repoCt);
        $users       = $this->users->findAllPlain();
        $categoryMap = [];
        foreach ($categories as $c) {
            $categoryMap[$c->getId()] = $c->getName();
        }
        $tagMap = [];
        foreach ($tags as $t) {
            $tagMap[$t->getId()] = $t->getName();
        }
        $userMap = [];
        foreach ($users as $u) {
            $userMap[$u->getId()] = $u->getName();
        }

        // Resolve filter tag/category name for display
        $filterTagName = null;
        if (!empty($filters['tag_id'])) {
            $tag           = $this->tags->findById((int) $filters['tag_id']);
            $filterTagName = $tag?->getName();
        }
        $filterCategoryName = null;
        if (!empty($filters['category_id'])) {
            $cat                = $this->categories->findById((int) $filters['category_id']);
            $filterCategoryName = $cat?->getName();
        }

        $allCts = $this->contentTypes->findAll();
        $role   = $request->getAttribute('user_role', $this->session->get('user_role', 'subscriber'));
        $perms  = $role === 'super_admin' ? null : $request->getAttribute('user_permissions', $this->session->get('user_permissions', []));
        $cts    = array_filter($allCts, function ($ct) use ($role, $perms) {
            if ($ct->getSlug()->__toString() === 'post') {
                return true;
            }
            if ($ct->isSystem()) {
                return false;
            }
            if ($role === 'super_admin') {
                return true;
            }
            return in_array('cpt.' . $ct->getSlug()->__toString() . '.list', $perms, true);
        });

        return $this->twig->render($response, 'admin/posts/index.twig', [
            'posts'              => $posts,
            'total'              => $total,
            'type'               => $type,
            'contentType'        => $contentType,
            'filters'            => $params,
            'client_side'        => $clientSide,
            'page'               => $clientSide ? 1 : $pagination->page,
            'per_page'           => $clientSide ? 20 : $pagination->perPage,
            'base_url'           => $baseUrl,
            'categoryMap'        => $categoryMap,
            'tagMap'             => $tagMap,
            'userMap'            => $userMap,
            'view_url_map'       => $viewUrlMap,
            'filterTagName'      => $filterTagName,
            'filterCategoryName' => $filterCategoryName,
            'contentTypes'       => $cts,
            'cptEnabled'         => $this->settings->get('custom_content_types_enabled', '1') === '1',
            'blog_enabled'       => $this->settings->get('blog_enabled', '1')                 === '1',
        ]);
    }

    /** @return string[] */
    private function getAllTagNames(?string $contentType = null): array
    {
        return array_map(fn ($t) => $t->getName(), $this->tags->findAll(null, $contentType));
    }

    /** @return string[] */
    private function getPostTagNames(?int $postId): array
    {
        if ($postId === null) {
            return [];
        }
        $tagIds = $this->posts->findById($postId)?->getTagIds() ?? [];
        $names  = [];
        foreach ($tagIds as $tid) {
            $tag = $this->tags->findById($tid);
            if ($tag !== null) {
                $names[] = $tag->getName();
            }
        }
        return $names;
    }

    public function create(Request $request, Response $response): Response
    {
        $contentType = $request->getQueryParams()['content_type'] ?? 'post';
        if (!is_string($contentType)) {
            $contentType = 'post';
        }
        if (!in_array($contentType, ['post', 'page'], true)) {
            $role = $request->getAttribute('user_role', $this->session->get('user_role', 'subscriber'));
            if ($role !== 'super_admin') {
                $permissions = $request->getAttribute('user_permissions', $this->session->get('user_permissions', []));
                if (!in_array("cpt.{$contentType}.create", $permissions, true)) {
                    return $response->withStatus(403);
                }
            }
        }

        $type        = $this->contentTypes->findBySlug(new Slug($contentType))
                ?? $this->contentTypes->findBySlug(new Slug('post'));

        $lang          = $request->getQueryParams()['language']       ?? $this->translator->getLocale();
        $translationOf = $request->getQueryParams()['translation_of'] ?? null;
        $translations  = $translationOf ? $this->posts->findTranslations((int) $translationOf) : [];

        $potentialOriginals = $this->posts->findPotentialOriginals($contentType, language: $lang);
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

        $originalCategoryIds = [];
        $originalTagNames    = '';
        if ($translationOf) {
            $original = $this->posts->findById((int) $translationOf);
            if ($original) {
                $originalCategoryIds = $this->categories->findTranslatedIds($original->getCategoryIds(), $lang);
                $originalTagNames    = implode(',', $this->tags->findTranslatedNames(
                    $this->getPostTagNames((int) $translationOf),
                    $lang
                ));
            }
        }

        return $this->twig->render($response, 'admin/posts/form.twig', [
            'post'                   => null,
            'is_edit'                => false,
            'type'                   => $type,
            'categories'             => TreeHelper::buildTree($this->categories->findAll(null, $contentType, $lang)),
            'tags'                   => $this->tags->findAll(null, $contentType, $lang),
            'seo'                    => null,
            'allTagNames'            => $this->getAllTagNames($contentType),
            'initialTagNames'        => $originalTagNames,
            'original_category_ids'  => $originalCategoryIds,
            'auto_redirect_enabled'  => $this->settings->get('auto_redirect_enabled', '1') === '1',
            'routing_page_prefix'    => $this->settings->get('routing_page_prefix', ''),
            'routing_blog_prefix'    => $this->settings->get('routing_blog_prefix', '/blog'),
            'membership_tags'        => $this->getMembershipTagNames(),
            'multi_language_content' => $this->settings->get('multi_language_content', '0') === '1',
            'supported_locales'      => $this->translator->getAvailableLocales(),
            'potential_originals'    => $this->posts->findPotentialOriginals($contentType, language: $lang),
            'current_locale'         => $lang,
            'translations'           => $translations,
            'original_id'            => $translationOf,
            'prefill_translation_of' => $translationOf,
            'post_aliases'           => [],
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $body                 = (array) $request->getParsedBody();
        $body['author_id']    = $this->session->get('user_id');
        $contentType          = $body['content_type'] ?? 'post';
        if (!is_string($contentType)) {
            $contentType = 'post';
        }
        $role                 = $request->getAttribute('user_role', $this->session->get('user_role', 'subscriber'));

        if (!in_array($contentType, ['post', 'page'], true)) {
            if ($role !== 'super_admin') {
                $permissions = $request->getAttribute('user_permissions', $this->session->get('user_permissions', []));
                if (!in_array("cpt.{$contentType}.create", $permissions, true)) {
                    return $response->withStatus(403);
                }
            }
        }

        if (in_array($body['status'] ?? 'draft', ['published', 'scheduled', 'unlisted'], true) && $role !== 'super_admin') {
            $permissions = $request->getAttribute('user_permissions', $this->session->get('user_permissions', []));
            if (!in_array('posts.publish', $permissions, true)) {
                return $response->withStatus(403);
            }
        }

        $body['category_ids'] = array_map('intval', (array) ($body['category_ids'] ?? []));
        if (!empty($body['translation_of']) && empty($body['category_ids'])) {
            $original = $this->posts->findById((int) $body['translation_of']);
            if ($original) {
                $body['category_ids'] = $this->categories->findTranslatedIds(
                    $original->getCategoryIds(),
                    $body['language'] ?? 'en'
                );
            }
        }
        $tagNames = !empty($body['tag_names']) ? array_map('trim', explode(',', $body['tag_names'])) : [];
        if (!empty($body['translation_of']) && empty($tagNames)) {
            $original = $original ?? $this->posts->findById((int) $body['translation_of']);
            if ($original) {
                $tagNames = $this->tags->findTranslatedNames(
                    $this->getPostTagNames((int) $body['translation_of']),
                    $body['language'] ?? 'en'
                );
            }
        }
        $body['tag_ids']        = array_map(fn ($t) => (int) $t->getId(), $this->tags->findOrCreateByNames($tagNames, $contentType, $body['language'] ?? null));
        $body['access_tags']    = !empty($body['access_tags']) ? (array) $body['access_tags'] : [];
        $body['clear_schedule'] = empty($body['set_schedule']);
        $seoData                = (array) ($body['seo'] ?? []);
        $type                   = $this->contentTypes->findBySlug(new Slug($contentType));
        $hasSeo                 = $type !== null && in_array('seo', $type->getSupports(), true);

        try {
            $post = $this->savePost->execute($body);
            if ($hasSeo) {
                $this->saveSeo->execute('post', $post->getId(), $seoData);
            }
            $this->events->doAction('post.created', $post);
            if ($post->getStatus() === 'published') {
                $this->events->doAction('post.published', $post);
            }
            $this->session->set('flash_success', $this->translator->get('Post created successfully.'));
            return $response->withHeader(
                'Location',
                admin_url('/posts?content_type=' . $contentType)
            )->withStatus(302);
        } catch (ValidationException $e) {
            $type          = $this->contentTypes->findBySlug(new Slug($contentType));
            $lang          = $body['language']       ?? $this->translator->getLocale();
            $translationOf = $body['translation_of'] ?? null;
            $translations  = $translationOf ? $this->posts->findTranslations((int) $translationOf) : [];

            $potentialOriginals = $this->posts->findPotentialOriginals($contentType, language: $lang);
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

            $originalCategoryIds = [];
            $originalTagNames    = '';
            if ($translationOf) {
                $original = $this->posts->findById((int) $translationOf);
                if ($original) {
                    $originalCategoryIds = $original->getCategoryIds();
                    $originalTagNames    = implode(',', $this->getPostTagNames((int) $translationOf));
                }
            }

            return $this->twig->render($response->withStatus(422), 'admin/posts/form.twig', [
                'errors'                 => $e->getErrors(),
                'old'                    => $body,
                'post'                   => null,
                'is_edit'                => false,
                'type'                   => $type,
                'categories'             => TreeHelper::buildTree($this->categories->findAll(null, $contentType, $lang)),
                'tags'                   => $this->tags->findAll(null, $contentType, $lang),
                'seo'                    => null,
                'allTagNames'            => $this->getAllTagNames($contentType),
                'initialTagNames'        => $originalTagNames,
                'original_category_ids'  => $originalCategoryIds,
                'auto_redirect_enabled'  => $this->settings->get('auto_redirect_enabled', '1') === '1',
                'routing_page_prefix'    => $this->settings->get('routing_page_prefix', ''),
                'routing_blog_prefix'    => $this->settings->get('routing_blog_prefix', '/blog'),
                'membership_tags'        => $this->getMembershipTagNames(),
                'multi_language_content' => $this->settings->get('multi_language_content', '0') === '1',
                'supported_locales'      => $this->translator->getAvailableLocales(),
            'potential_originals'        => $potentialOriginals,
                'current_locale'         => $lang,
                'translations'           => $translations,
                'original_id'            => $translationOf,
                'prefill_translation_of' => $translationOf,
                'post_aliases'           => [],
            ]);
        }
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

    public function edit(Request $request, Response $response, array $args): Response
    {
        $post = $this->posts->findById((int) $args['id']);
        if ($post === null) {
            return $response->withStatus(404);
        }

        $type = $this->contentTypes->findBySlug(new Slug($post->getContentType()))
                ?? $this->contentTypes->findBySlug(new Slug('post'));

        $hasSeo = $type !== null && in_array('seo', $type->getSupports(), true);

        $role = $request->getAttribute('user_role', $this->session->get('user_role', 'subscriber'));
        if ($role !== 'super_admin') {
            $permissions = $request->getAttribute('user_permissions', $this->session->get('user_permissions', []));
            $ct          = $post->getContentType();
            $ctp         = in_array($ct, ['post', 'page'], true) ? null : "cpt.{$ct}.edit";
            if (
                !in_array($ctp ?? 'posts.edit_others', $permissions, true)
                && $post->getAuthorId() !== (int) $this->session->get('user_id')
            ) {
                return $response->withStatus(403);
            }
        }

        $originalId   = $post->getTranslationOf() ?: $post->getId();
        $translations = $this->posts->findTranslations((int) $originalId);

        $potentialOriginals = $this->posts->findPotentialOriginals($post->getContentType(), $post->getId(), $post->getLanguage());
        $translationOf      = $post->getTranslationOf();
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

        return $this->twig->render($response, 'admin/posts/form.twig', [
            'post'                   => $post,
            'is_edit'                => true,
            'type'                   => $type,
            'categories'             => TreeHelper::buildTree($this->categories->findAll(null, $post->getContentType(), $post->getLanguage())),
            'tags'                   => $this->tags->findAll(null, $post->getContentType(), $post->getLanguage()),
            'seo'                    => $hasSeo ? $this->seoRepo->findByEntity('post', $post->getId()) : null,
            'allTagNames'            => $this->getAllTagNames($post->getContentType()),
            'initialTagNames'        => implode(',', $this->getPostTagNames($post->getId())),
            'auto_redirect_enabled'  => $this->settings->get('auto_redirect_enabled', '1') === '1',
            'routing_page_prefix'    => $this->settings->get('routing_page_prefix', ''),
            'routing_blog_prefix'    => $this->settings->get('routing_blog_prefix', '/blog'),
            'membership_tags'        => $this->getMembershipTagNames(),
            'multi_language_content' => $this->settings->get('multi_language_content', '0') === '1',
            'supported_locales'      => $this->translator->getAvailableLocales(),
            'potential_originals'    => $potentialOriginals,
            'current_locale'         => $post->getLanguage(),
            'translations'           => $translations,
            'original_id'            => $originalId,
            'post_aliases'           => $this->contentAliasLeafs($post),
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];

        $existing = $this->posts->findById($id);
        if ($existing === null) {
            return $response->withStatus(404);
        }

        $role = $request->getAttribute('user_role', $this->session->get('user_role', 'subscriber'));
        if ($role !== 'super_admin') {
            $permissions = $request->getAttribute('user_permissions', $this->session->get('user_permissions', []));
            $ct          = $existing->getContentType();
            $ctp         = in_array($ct, ['post', 'page'], true) ? null : "cpt.{$ct}.edit";
            if (
                !in_array($ctp ?? 'posts.edit_others', $permissions, true)
                && $existing->getAuthorId() !== (int) $this->session->get('user_id')
            ) {
                return $response->withStatus(403);
            }
        }

        $body                 = (array) $request->getParsedBody();
        $body['author_id']    = $this->session->get('user_id');
        $body['content_type'] = $existing->getContentType();
        $body['category_ids'] = array_map('intval', (array) ($body['category_ids'] ?? []));

        $oldStatus = $existing->getStatus() ?? 'draft';
        $newStatus = $body['status']        ?? $oldStatus;
        if (in_array($newStatus, ['published', 'scheduled', 'unlisted'], true) && $newStatus !== $oldStatus && $role !== 'super_admin') {
            $permissions = $request->getAttribute('user_permissions', $this->session->get('user_permissions', []));
            if (!in_array('posts.publish', $permissions, true)) {
                return $response->withStatus(403);
            }
        }

        $tagNames               = !empty($body['tag_names']) ? array_map('trim', explode(',', $body['tag_names'])) : [];
        $body['tag_ids']        = array_map(fn ($t) => (int) $t->getId(), $this->tags->findOrCreateByNames($tagNames, $existing->getContentType(), $existing->getLanguage()));
        $body['access_tags']    = !empty($body['access_tags']) ? (array) $body['access_tags'] : [];
        $body['clear_schedule'] = empty($body['set_schedule']);
        $seoData                = (array) ($body['seo'] ?? []);
        $type                   = $this->contentTypes->findBySlug(new Slug($existing->getContentType()));
        $hasSeo                 = $type !== null && in_array('seo', $type->getSupports(), true);

        try {
            $updated = $this->savePost->execute($body, $id);
            if ($hasSeo) {
                $this->saveSeo->execute('post', $id, $seoData);
            }
            $this->events->doAction('post.updated', $updated);
            if ($oldStatus !== 'published' && $updated->getStatus() === 'published') {
                $this->events?->doAction('post.published', $updated);
            }

            $this->session->set('flash_success', $this->translator->get('Post updated successfully.'));
            return $response->withHeader('Location', admin_url('/posts/' . $id . '/edit'))->withStatus(302);
        } catch (ValidationException $e) {
            $post = $this->posts->findById($id);
            return $this->twig->render($response->withStatus(422), 'admin/posts/form.twig', [
                'errors'                => $e->getErrors(),
                'old'                   => $body,
                'post'                  => $post,
                'is_edit'               => true,
                'type'                  => $type,
                'categories'            => TreeHelper::buildTree($this->categories->findAll(null, $existing->getContentType())),
                'tags'                  => $this->tags->findAll(null, $existing->getContentType()),
                'seo'                   => $hasSeo ? $this->seoRepo->findByEntity('post', $id) : null,
                'allTagNames'           => $this->getAllTagNames($existing->getContentType()),
                'initialTagNames'       => $body['tag_names'] ?? implode(',', $this->getPostTagNames($id)),
                'routing_page_prefix'   => $this->settings->get('routing_page_prefix', ''),
                'routing_blog_prefix'   => $this->settings->get('routing_blog_prefix', '/blog'),
                'post_aliases'          => [],
            ]);
        }
    }

    /** POST /admin/bulk/posts */
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
            return $response->withHeader('Location', admin_url('/posts'))->withStatus(302);
        }

        $role        = $request->getAttribute('user_role', $this->session->get('user_role', 'subscriber'));
        $permissions = $request->getAttribute('user_permissions', $this->session->get('user_permissions', []));
        $count       = 0;

        foreach ($ids as $id) {
            $id   = (int) $id;
            $post = $this->posts->findById($id);
            if ($post === null) {
                continue;
            }

            if ($action === 'delete') {
                $ct  = $post->getContentType();
                $ctp = in_array($ct, ['post', 'page'], true) ? null : "cpt.{$ct}.delete";
                if (
                    $role !== 'super_admin'
                    && !in_array($ctp ?? 'posts.edit_others', $permissions, true)
                    && $post->getAuthorId() !== (int) $this->session->get('user_id')
                ) {
                    continue;
                }
                $this->deleteContentAliases($post);
                $this->posts->delete($id);
                $count++;
            } elseif (in_array($action, ['publish', 'draft', 'unlisted', 'archive'], true)) {
                if (in_array($action, ['publish', 'unlisted', 'archive'], true) && $role !== 'super_admin') {
                    $publishPerm = $post->getContentType() === 'page' ? 'pages.publish' : 'posts.publish';
                    if (!in_array($publishPerm, $permissions, true)) {
                        continue;
                    }
                }
                $status = $action === 'publish' ? 'published' : ($action === 'archive' ? 'archived' : $action);
                if ($action === 'publish' || $action === 'unlisted') {
                    if ($post->getPublishedAt() === null) {
                        $post->setPublishedAt(new \DateTimeImmutable());
                    }
                }
                $post->setStatus($status);
                $this->posts->save($post);
                $count++;
            }
        }

        $labels = [
            'delete'   => ':count post(s) deleted.',
            'publish'  => ':count post(s) published.',
            'draft'    => ':count post(s) moved to draft.',
            'unlisted' => ':count post(s) moved to unlisted.',
            'archive'  => ':count post(s) archived.',
        ];
        $msg = $labels[$action] ?? ':count post(s) updated.';
        $this->session->set('flash_success', $this->translator->get($msg, [':count' => (string) $count]));

        $referer = $request->getHeaderLine('Referer');
        if ($referer && str_contains($referer, admin_url('/pages'))) {
            return $response->withHeader('Location', admin_url('/pages'))->withStatus(302);
        }

        return $response->withHeader('Location', admin_url('/posts'))->withStatus(302);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $id   = (int) $args['id'];
        $post = $this->posts->findById($id);
        if ($post === null) {
            return $response->withStatus(404);
        }
        $contentType = $post->getContentType();

        $role = $request->getAttribute('user_role', $this->session->get('user_role', 'subscriber'));
        if ($role !== 'super_admin') {
            $permissions = $request->getAttribute('user_permissions', $this->session->get('user_permissions', []));
            $ct          = $post->getContentType();
            $ctp         = in_array($ct, ['post', 'page'], true) ? null : "cpt.{$ct}.delete";
            if (
                !in_array($ctp ?? 'posts.edit_others', $permissions, true)
                && $post->getAuthorId() !== (int) $this->session->get('user_id')
            ) {
                return $response->withStatus(403);
            }
        }

        $this->deleteContentAliases($post);
        $this->posts->delete($id);
        $this->session->set('flash_success', $this->translator->get('Post deleted.'));
        return $response->withHeader('Location', admin_url('/posts?content_type=' . $contentType))->withStatus(302);
    }

    /** POST /admin/posts/{id}/clone (and /admin/pages/{id}/clone) — duplicate as draft */
    public function clone(Request $request, Response $response, array $args): Response
    {
        $source = $this->posts->findById((int) $args['id']);
        if ($source === null) {
            return $response->withStatus(404);
        }

        $role = $request->getAttribute('user_role', $this->session->get('user_role', 'subscriber'));
        if ($role !== 'super_admin') {
            $permissions = $request->getAttribute('user_permissions', $this->session->get('user_permissions', []));
            $ct          = $source->getContentType();
            $ctp         = in_array($ct, ['post', 'page'], true) ? null : "cpt.{$ct}.edit";
            if (
                !in_array($ctp ?? 'posts.edit_others', $permissions, true)
                && $source->getAuthorId() !== (int) $this->session->get('user_id')
            ) {
                return $response->withStatus(403);
            }
        }

        $userId = (int) $this->session->get('user_id');
        $copy   = new Post(
            $source->getTitle() . ' (Copy)',
            $this->uniqueCloneSlug($source),
            $userId > 0 ? $userId : $source->getAuthorId(),
        );
        $copy->setContentType($source->getContentType());
        $copy->setLanguage($source->getLanguage());
        $copy->setExcerpt($source->getExcerpt());
        $copy->setBody($source->getBody());
        $copy->setFeaturedImageId($source->getFeaturedImageId());
        $copy->setParentId($source->getParentId());
        $copy->setCommentStatus($source->getCommentStatus());
        $copy->setPageTemplate($source->getPageTemplate());
        $copy->setHideTitle($source->isHideTitle());
        $copy->setMetaTitle($source->getMetaTitle());
        $copy->setMetaDescription($source->getMetaDescription());
        $copy->setRssExclude($source->isRssExcluded());
        $copy->setAccessTags($source->getAccessTags());
        $copy->setCategoryIds($source->getCategoryIds());
        $copy->setTagIds($source->getTagIds());
        $copy->setMeta($source->getMeta());
        $saved = $this->posts->save($copy);

        $params = $request->getQueryParams();
        if (!empty($params['link_swap'])) {
            $saved->setSwapPostId($source->getId());
            $this->posts->save($saved);
        }

        $this->session->set('flash_success', $this->translator->get('Content cloned as draft.'));

        $editPath = $source->getContentType() === 'page'
            ? '/pages/' . $saved->getId() . '/edit'
            : '/posts/' . $saved->getId() . '/edit';
        return $response->withHeader('Location', admin_url($editPath))->withStatus(302);
    }

    private function uniqueCloneSlug(Post $source): Slug
    {
        return $this->slugService->unique(
            (string) $source->getSlug() . '-copy',
            VL_DB_PREFIX . 'posts',
            'slug',
            null,
            'content_type = ? AND language = ?',
            [$source->getContentType(), $source->getLanguage()],
        );
    }

    /** POST /admin/posts/preview — render unsaved content without saving */
    public function preview(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();

        $fake                   = new \stdClass();
        $fake->id               = (int) ($body['id'] ?? 0);
        $fake->title            = $body['title']   ?? 'Untitled';
        $fake->slug             = $body['slug']    ?? '';
        $fake->excerpt          = $body['excerpt'] ?? null;
        $fake->body             = $body['body']    ?? '';
        $fake->featuredImageUrl = null;
        $fake->publishedAt      = null;
        $fake->authorName       = '';
        $fake->commentStatus    = $body['comment_status']          ?? 'use_global';
        $fake->metaTitle        = $body['seo']['meta_title']       ?? null;
        $fake->metaDescription  = $body['seo']['meta_description'] ?? null;

        return $this->twig->render($response, 'post.twig', [
            'post'                    => $fake,
            'is_preview'              => true,
            'comments'                => [],
            'global_comments_enabled' => $this->settings->get('comments_enabled', '1') === '1',
            'sidebar_categories'      => [],
            'sidebar_tags'            => [],
            'sidebar_recent_posts'    => [],
        ]);
    }

    /** GET /admin/posts/list-json — search posts+pages+products for internal link picker */
    public function listJson(Request $request, Response $response): Response
    {
        $params  = $request->getQueryParams();
        $q       = trim($params['q'] ?? '');
        $rawType = $params['type'] ?? '';
        $type    = is_string($rawType) ? $rawType : '';
        $page    = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(200, max(1, (int) ($params['per_page'] ?? 100)));

        $list     = [];
        $total    = 0;

        // Static links (site homepage + shop homepage, cart, checkout,
        // membership plans) — searchable in the editor's internal-link
        // picker. Page pickers (type=page) are excluded. Shop links are
        // only listed when shop is enabled.
        if ($type === '') {
            $staticLinks = [
                [
                    'id'    => 'homepage',
                    'title' => $this->translator->get('Homepage'),
                    'slug'  => '',
                    'type'  => 'home',
                    'url'   => site_url('/'),
                ],
            ];
            if ($this->settings->get('shop_enabled', '1') === '1') {
                $staticLinks[] = [
                    'id'    => 'shop-homepage',
                    'title' => $this->translator->get('Shop Homepage'),
                    'slug'  => '',
                    'type'  => 'shop',
                    'url'   => site_url('/' . ltrim($this->settings->get('routing_shop_prefix', '/shop'), '/')),
                ];
                $staticLinks[] = [
                    'id'    => 'cart',
                    'title' => $this->translator->get('Cart'),
                    'slug'  => '',
                    'type'  => 'shop',
                    'url'   => site_url('/' . ltrim($this->settings->get('routing_cart_prefix', '/cart'), '/')),
                ];
                $staticLinks[] = [
                    'id'    => 'checkout',
                    'title' => $this->translator->get('Checkout'),
                    'slug'  => '',
                    'type'  => 'shop',
                    'url'   => site_url('/' . ltrim($this->settings->get('routing_checkout_prefix', '/checkout'), '/')),
                ];
                if ($this->settings->get('subscriptions_enabled', '1') !== '0') {
                    $staticLinks[] = [
                        'id'    => 'membership-plans',
                        'title' => $this->translator->get('Membership Plans'),
                        'slug'  => '',
                        'type'  => 'shop',
                        'url'   => site_url('/' . ltrim($this->settings->get('routing_membership_prefix', '/membership'), '/')),
                    ];
                }
            }
            if ($q !== '') {
                $qLower      = mb_strtolower($q);
                $staticLinks = array_values(array_filter(
                    $staticLinks,
                    static fn (array $l): bool => mb_stripos($l['title'], $qLower) !== false
                        || mb_stripos($l['type'], $qLower)                         !== false
                ));
            }
            array_push($list, ...$staticLinks);
        }

        // Posts, pages & CPT records (unknown type slugs simply return nothing)
        $postTypes = ['post', 'page'];
        if ($type !== '' && $type !== 'product' && !in_array($type, $postTypes, true)) {
            $postTypes = [$type];
        } elseif ($type === '') {
            // Empty type = the editor's internal-link picker: also list records
            // of every active custom content type.
            foreach ($this->contentTypes->findActiveCustom() as $customType) {
                $postTypes[] = (string) $customType->getSlug();
            }
        }

        // Posts & pages
        foreach ($postTypes as $contentType) {
            if ($type !== '' && $type !== $contentType) {
                continue;
            }
            $filters = ['content_type' => $contentType, 'status' => 'published'];
            if ($q !== '') {
                $filters['search'] = $q;
            }
            $total += $this->posts->count($filters);
            $items  = $this->posts->findAll(new Pagination($page, $perPage), $filters);
            foreach ($items as $item) {
                $itemUrl = null;
                if ($contentType === 'page') {
                    // buildPagePath() includes the full parent chain, so nested
                    // pages expose their real address to the picker.
                    $itemUrl = site_url(($this->settings->get('routing_page_prefix', '') ? ('/' . ltrim($this->settings->get('routing_page_prefix', ''), '/')) : '') . '/' . $this->posts->buildPagePath($item));
                } elseif ($contentType === 'post') {
                    $itemUrl = site_url('/' . ltrim($this->settings->get('routing_blog_prefix', '/blog'), '/') . '/' . $item->getSlug());
                } else {
                    // Custom content type — resolve its public base path.
                    $customBase = $this->contentTypes->findBySlug(new Slug($contentType))?->getPublicBasePath() ?? '';
                    $itemUrl    = site_url('/' . trim($customBase, '/') . '/' . $item->getSlug());
                }
                $list[] = [
                    'id'     => $item->getId(),
                    'title'  => $item->getTitle(),
                    'slug'   => (string) $item->getSlug(),
                    'type'   => $contentType,
                    'status' => $item->getStatus(),
                    'url'    => $itemUrl,
                ];
            }
        }

        // Products
        if ($type === '' || $type === 'product') {
            $productFilters = ['status' => 'published'];
            if ($q !== '') {
                $productFilters['search'] = $q;
            }
            $total     += $this->products->count($productFilters);
            $products   = $this->products->findAll(new Pagination($page, $perPage), $productFilters);
            $shopPrefix = '/' . ltrim($this->settings->get('routing_shop_prefix', '/shop'), '/');
            foreach ($products as $product) {
                $list[] = [
                    'id'    => $product->getId(),
                    'title' => $product->getName(),
                    'slug'  => (string) $product->getSlug(),
                    'type'  => 'product',
                    'url'   => site_url($shopPrefix . '/') . $product->getSlug(),
                ];
            }
        }

        $payload = json_encode([
            'items'       => $list,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ], JSON_THROW_ON_ERROR);

        $response->getBody()->write($payload);
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getPostJson(Request $request, Response $response, array $args): Response
    {
        $post = $this->posts->findById((int) $args['id']);
        if ($post === null) {
            return $response->withStatus(404);
        }

        $data = [
            'id'      => $post->getId(),
            'title'   => $post->getTitle(),
            'body'    => $post->getBody(),
            'excerpt' => $post->getExcerpt(),
            'slug'    => (string) $post->getSlug(),
            'meta'    => $post->getMeta(),
        ];

        // Public address (parent chain included) — used by record pickers to
        // rebuild dependent URLs before anything is saved.
        $contentType = $post->getContentType();
        $pagePrefix  = $this->settings->get('routing_page_prefix', '');
        $blogPrefix  = $this->settings->get('routing_blog_prefix', '/blog');
        if ($contentType === 'page') {
            $data['url'] = site_url(($pagePrefix ? '/' . ltrim($pagePrefix, '/') : '') . '/' . $this->posts->buildPagePath($post));
        } elseif ($contentType === 'post') {
            $data['url'] = site_url('/' . ltrim($blogPrefix, '/') . '/' . $post->getSlug());
        } else {
            $data['url'] = null;
        }

        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
