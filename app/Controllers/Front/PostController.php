<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Front;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use Twig\Error\LoaderError;
use ValeApp\Application\Content\BlogFeedService;
use ValeApp\Application\Seo\SeoState;
use ValeApp\Domain\Comment\Entity\Comment;
use ValeApp\Domain\Comment\Repository\CommentRepositoryInterface;
use ValeApp\Domain\Content\Entity\Category;
use ValeApp\Domain\Content\Entity\Post;
use ValeApp\Domain\Content\Entity\Tag;
use ValeApp\Domain\Content\Repository\CategoryRepositoryInterface;
use ValeApp\Domain\Content\Repository\ContentTypeRepositoryInterface;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Content\Repository\TagRepositoryInterface;
use ValeApp\Domain\Content\TreeHelper;
use ValeApp\Domain\Newsletter\Repository\SubscriberRepositoryInterface;
use ValeApp\Domain\Seo\Repository\SeoMetaRepositoryInterface;
use ValeApp\Domain\Shared\Exception\DomainException;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\User\Repository\UserRepositoryInterface;
use ValeApp\Infrastructure\Content\ContentMarkerNormalizer;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Translation\Translator;

final class PostController
{
    use CaptchaTrait;

    public function __construct(
        private readonly Twig $twig,
        private readonly PostRepositoryInterface $posts,
        private readonly CommentRepositoryInterface $comments,
        private readonly DbalSettingsRepository $settings,
        private readonly UserRepositoryInterface $users,
        private readonly SessionManager $session,
        private readonly CategoryRepositoryInterface $categories,
        private readonly TagRepositoryInterface $tags,
        private readonly SeoMetaRepositoryInterface $seoMeta,
        private readonly SeoState $seoState,
        private readonly BlogFeedService $blogFeed,
        private readonly ContentTypeRepositoryInterface $contentTypes,
        private readonly SubscriberRepositoryInterface $subscribers,
    ) {
    }

    private function getCurrentLocale(): string
    {
        return $this->twig->getEnvironment()->getGlobals()['current_locale'] ?? 'en';
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

    private function getSidebarCategories(?string $contentType = 'post'): array
    {
        $currentLocale = $this->getCurrentLocale();
        $fallback      = $this->settings->get('i18n_display_originals_fallback', '1') === '1';
        $categories    = $this->categories->findAll(null, $contentType, $currentLocale, $fallback);
        if (empty($categories) && $this->settings->get('multi_language_content', '0') === '1' && !$fallback) {
            $defaultLocale = $this->settings->get('default_locale', 'en');
            if ($currentLocale !== $defaultLocale) {
                $categories = $this->categories->findAll(null, $contentType, $defaultLocale);
            }
        }

        // Filter to only categories used by published posts
        $usageCounts = $this->categories->getUsageCounts($contentType);
        $categories  = array_filter($categories, fn (Category $c) => ($usageCounts[$c->getId()] ?? 0) > 0);

        return TreeHelper::buildTree($categories);
    }

    private function getSidebarTags(?string $contentType = 'post'): array
    {
        $currentLocale = $this->getCurrentLocale();
        $fallback      = $this->settings->get('i18n_display_originals_fallback', '1') === '1';
        $tags          = $this->tags->findAll(null, $contentType, $currentLocale, $fallback);
        if (empty($tags) && $this->settings->get('multi_language_content', '0') === '1' && !$fallback) {
            $defaultLocale = $this->settings->get('default_locale', 'en');
            if ($currentLocale !== $defaultLocale) {
                $tags = $this->tags->findAll(null, $contentType, $defaultLocale);
            }
        }

        // Filter to only tags used by published posts
        $usageCounts = $this->tags->getUsageCounts($contentType);
        $tags        = array_filter($tags, fn (Tag $t) => ($usageCounts[$t->getId()] ?? 0) > 0);

        return array_values(array_filter($tags, static fn (Tag $tag) => $tag->getName() !== '' && $tag->getName() !== null));
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params  = $request->getQueryParams();
        $page    = max(1, (int) ($params['page'] ?? 1));
        $perPage = 10;

        $search            = trim($params['search'] ?? '');
        $searchContentType = trim($params['content_type'] ?? '');

        // If a CPT is selected, redirect to its archive with the search term
        if ($searchContentType !== '' && $searchContentType !== 'post' && $searchContentType !== 'all') {
            try {
                $type = $this->contentTypes->findBySlug(new Slug($searchContentType));
            } catch (DomainException) {
                $type = null;
            }
            if ($type !== null) {
                $prefix = $type->getPublicBasePath();
                $qs     = $search !== '' ? '?search=' . urlencode($search) : '';
                return $response->withHeader('Location', site_url($prefix . $qs))->withStatus(302);
            }
            // Unknown type -> fall back to blog default
            $searchContentType = 'post';
        }

        $filters = ['status' => 'published'];
        if ($this->settings->get('multi_language_content', '0') === '1') {
            $filters['language'] = $this->twig->getEnvironment()->getGlobals()['current_locale'] ?? 'en';
            if ($this->settings->get('i18n_display_originals_fallback', '1') === '1') {
                $filters['language_fallback'] = true;
            }
        }
        // "all" means all blog posts (content_type=post), not all content types
        // Pages should never appear in /blog
        if ($searchContentType === '' || $searchContentType === 'post' || $searchContentType === 'all') {
            $filters['content_type'] = 'post';
        }
        if ($search !== '') {
            $filters['search'] = $search;
        }

        $posts = $this->posts->findAll(new Pagination($page, $perPage), $filters);
        $total = $this->posts->count($filters);

        if ($this->settings->get('multi_language_content', '0') === '1') {
            $locales        = Translator::getInstance()?->getSupportedLocales() ?: ['en'];
            $translationMap = [];
            $blogPrefix     = $this->settings->get('routing_blog_prefix', '/blog');
            foreach ($locales as $l) {
                $translationMap[$l] = $blogPrefix;
            }
            $this->seoState->setTranslations($translationMap);
        }

        // Fallback to default locale if no posts in current locale (legacy, before the new fallback setting)
        if (empty($posts) && !empty($filters['language']) && empty($filters['language_fallback'])) {
            $defaultLocale = $this->settings->get('default_locale', 'en');
            if ($filters['language'] !== $defaultLocale) {
                $filters['language'] = $defaultLocale;
                $posts               = $this->posts->findAll(new Pagination($page, $perPage), $filters);
                $total               = $this->posts->count($filters);
            }
        }

        $allTypes   = $this->contentTypes->findActiveCustom();
        $cptEnabled = ($this->settings->get('custom_content_types_enabled', '1') === '1');

        $recentFilters = ['content_type' => 'post', 'status' => 'published'];
        if ($this->settings->get('multi_language_content', '0') === '1') {
            $recentFilters['language'] = $this->twig->getEnvironment()->getGlobals()['current_locale'] ?? 'en';
            if ($this->settings->get('i18n_display_originals_fallback', '1') === '1') {
                $recentFilters['language_fallback'] = true;
            }
        }
        $sidebarRecent = $this->posts->findAll(new Pagination(1, 5), $recentFilters);
        if (empty($sidebarRecent) && !empty($recentFilters['language']) && empty($recentFilters['language_fallback'])) {
            $defaultLocale = $this->settings->get('default_locale', 'en');
            if ($recentFilters['language'] !== $defaultLocale) {
                $recentFilters['language'] = $defaultLocale;
                $sidebarRecent             = $this->posts->findAll(new Pagination(1, 5), $recentFilters);
            }
        }

        return $this->twig->render($response, 'blog.twig', [
            'posts'                => $posts,
            'authors'              => $this->buildAuthors($posts),
            'total'                => $total,
            'page'                 => $page,
            'per_page'             => $perPage,
            'total_pages'          => (int) ceil($total / $perPage),
            'search'               => $search,
            'search_content_type'  => $searchContentType ?: 'post',
            'search_content_types' => $cptEnabled ? $allTypes : [],
            'sidebar_categories'   => $this->getSidebarCategories('post'),
            'sidebar_tags'         => $this->getSidebarTags('post'),
            'sidebar_recent_posts' => $sidebarRecent,
            'type'                 => $this->contentTypes->findBySlug(new Slug('post')),
        ]);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $lang = $request->getAttribute('locale');
        return $this->renderPost($request, $response, $args['slug'], 'post', $lang);
    }

    public function showByType(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $lang = $request->getAttribute('locale');
        return $this->renderPost($request, $response, $args['slug'], $args['type'], $lang);
    }

    public function byTypeArchive(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $type = $this->contentTypes->findBySlug(new Slug($args['type']));
        if ($type === null) {
            return $this->twig->render($response->withStatus(404), 'errors/404.twig');
        }

        $params      = $request->getQueryParams();
        $page        = max(1, (int) ($params['page'] ?? 1));
        $perPage     = 10;
        $contentType = $type->getSlug()->__toString();
        $search      = trim($params['search'] ?? '');

        $filters = ['content_type' => $contentType, 'status' => 'published'];
        if ($this->settings->get('multi_language_content', '0') === '1') {
            $filters['language'] = $this->twig->getEnvironment()->getGlobals()['current_locale'] ?? 'en';
            if ($this->settings->get('i18n_display_originals_fallback', '1') === '1') {
                $filters['language_fallback'] = true;
            }
        }
        if ($search !== '') {
            $filters['search'] = $search;
        }

        $posts = $this->posts->findAll(new Pagination($page, $perPage), $filters);
        $total = $this->posts->count($filters);

        if ($this->settings->get('multi_language_content', '0') === '1') {
            $locales        = Translator::getInstance()?->getSupportedLocales() ?: ['en'];
            $translationMap = [];
            $prefix         = $type->getPublicBasePath();
            foreach ($locales as $l) {
                $translationMap[$l] = $prefix;
            }
            $this->seoState->setTranslations($translationMap);
        }

        // Fallback to default locale if no posts in current locale
        if (empty($posts) && !empty($filters['language']) && empty($filters['language_fallback'])) {
            $defaultLocale = $this->settings->get('default_locale', 'en');
            if ($filters['language'] !== $defaultLocale) {
                $filters['language'] = $defaultLocale;
                $posts               = $this->posts->findAll(new Pagination($page, $perPage), $filters);
                $total               = $this->posts->count($filters);
            }
        }

        // Sidebar recent posts — filter by current locale with fallback
        $recentFilters = ['content_type' => $contentType, 'status' => 'published'];
        if ($this->settings->get('multi_language_content', '0') === '1') {
            $recentFilters['language'] = $this->twig->getEnvironment()->getGlobals()['current_locale'] ?? 'en';
            if ($this->settings->get('i18n_display_originals_fallback', '1') === '1') {
                $recentFilters['language_fallback'] = true;
            }
        }
        $sidebarRecent = $this->posts->findAll(new Pagination(1, 5), $recentFilters);
        if (empty($sidebarRecent) && !empty($recentFilters['language']) && empty($recentFilters['language_fallback'])) {
            $defaultLocale = $this->settings->get('default_locale', 'en');
            if ($recentFilters['language'] !== $defaultLocale) {
                $recentFilters['language'] = $defaultLocale;
                $sidebarRecent             = $this->posts->findAll(new Pagination(1, 5), $recentFilters);
            }
        }

        $this->seoState->setArchiveType('custom');

        return $this->twig->render($response, 'blog.twig', [
            'posts'                => $posts,
            'authors'              => $this->buildAuthors($posts),
            'total'                => $total,
            'page'                 => $page,
            'per_page'             => $perPage,
            'total_pages'          => (int) ceil($total / $perPage),
            'search'               => $search,
            'archive_type'         => 'custom',
            'archive_entity'       => $type,
            'type'                 => $type,
            'sidebar_categories'   => $this->getSidebarCategories($contentType),
            'sidebar_tags'         => $this->getSidebarTags($contentType),
            'sidebar_recent_posts' => $sidebarRecent,
        ]);
    }

    private function renderPost(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $slugStr,
        string $contentType,
        ?string $forceLang = null
    ): ResponseInterface {
        try {
            $slug = new Slug($slugStr);
        } catch (DomainException) {
            return $this->twig->render($response->withStatus(404), 'errors/404.twig');
        }

        $contentLang = $forceLang ?: (($this->settings->get('multi_language_content', '0') === '1')
            ? ($this->twig->getEnvironment()->getGlobals()['current_locale'] ?? 'en')
            : null);

        $post = $this->posts->findBySlug($slug, $contentType, $contentLang);

        // Fallback: If not found in current language, try finding it in any language
        if ($post === null) {
            $post = $this->posts->findBySlug($slug, $contentType);
        }

        if ($post && $contentLang && $post->getLanguage() !== $contentLang) {
            if ($this->settings->get('i18n_display_originals_fallback', '1') === '1' && $post->getTranslationOf() === null) {
                $originalId   = $post->getId();
                $translations = $this->posts->findTranslations($originalId);
                foreach ($translations as $t) {
                    if ($t->getLanguage() === $contentLang && $t->getStatus() === 'published') {
                        return $response->withHeader('Location', url($this->resolveContentPrefix($contentType) . '/' . $t->getSlug(), [], ['lang' => $contentLang]))->withStatus(302);
                    }
                }
            } else {
                $originalId   = $post->getTranslationOf() ?: $post->getId();
                $translations = $this->posts->findTranslations($originalId);

                foreach ($translations as $t) {
                    if ($t->getLanguage() === $contentLang && $t->getStatus() === 'published') {
                        return $response->withHeader('Location', url($this->resolveContentPrefix($contentType) . '/' . $t->getSlug(), [], ['lang' => $contentLang]))->withStatus(302);
                    }
                }

                if ($post->getTranslationOf() !== null) {
                    $original = $this->posts->findById($post->getTranslationOf());
                    if ($original && $original->getStatus() === 'published') {
                        return $response->withHeader('Location', url($this->resolveContentPrefix($contentType) . '/' . $original->getSlug(), [], ['lang' => $original->getLanguage()]))->withStatus(302);
                    }
                } else {
                    $post = null;
                }
            }
        }

        $fallbackFlags = $this->computeFallbackFlags(
            $contentLang ?? 'en',
            $post?->getTranslationOf(),
            $post?->getLanguage() ?? 'en',
            fn (int $originalId) => $originalId > 0 ? $this->posts->findTranslations($originalId) : []
        );

        if ($post === null || !in_array($post->getStatus(), ['published', 'unlisted'], true)) {
            return $this->twig->render($response->withStatus(404), 'errors/404.twig');
        }

        $params        = $request->getQueryParams();
        $cpage         = max(1, (int) ($params['cpage'] ?? 1));
        $commentsPP    = max(1, (int) ($this->settings->get('comments_per_page', '5')));
        $topLevel      = $this->comments->findTopLevelByEntityPaginated($contentType, $post->getId(), new Pagination($cpage, $commentsPP));
        $children      = $this->comments->findAllChildrenByEntity($contentType, $post->getId());
        $comments      = array_merge($topLevel, $children);

        $commentsTotal      = $this->comments->countByEntity($contentType, $post->getId());
        $topLevelTotal      = $this->comments->countTopLevelByEntity($contentType, $post->getId());
        $commentsTotalPages = max(1, (int) ceil($topLevelTotal / $commentsPP));

        $allTopLevel = $this->comments->findTopLevelByEntityPaginated($contentType, $post->getId(), new Pagination(1, 9999));
        $allChildren = $this->comments->findAllChildrenByEntity($contentType, $post->getId());

        $childrenByParent = [];
        foreach ($allChildren as $child) {
            $parentId = $child->getParentId();
            if ($parentId !== null) {
                $childrenByParent[$parentId]   = ($childrenByParent[$parentId] ?? []);
                $childrenByParent[$parentId][] = $child;
            }
        }

        $commentPageMap = [];
        foreach ($allTopLevel as $i => $tl) {
            $page = (int) floor($i / $commentsPP) + 1;
            $this->mapCommentPage($tl, $page, $childrenByParent, $commentPageMap);
        }

        $userId         = $this->session->get('user_id');
        $userComment    = null;
        $commentRatings = [];
        if ($userId !== null) {
            $userComment = $this->comments->findByUserAndEntity((int) $userId, $contentType, $post->getId());
            if ($userComment !== null) {
                $commentRatings = [$userComment->getId() => 0]; // posts don't have vote ratings
            }
        }

        // ── Membership access control ──────────────────────────────
        $accessTags      = $post->getAccessTags();
        $hasAccess       = true;
        $previewBody     = null;
        $moreLabel       = null;
        if (!empty($accessTags)) {
            $hasAccess = false;
            $userId    = $this->session->get('user_id');
            if ($userId !== null) {
                $subscriber = $this->subscribers->findByUserId((int) $userId);
                if ($subscriber !== null) {
                    $userTags  = $subscriber->getTags();
                    $hasAccess = !empty(array_intersect($accessTags, $userTags));
                }
            }
            if (!$hasAccess) {
                $split       = ContentMarkerNormalizer::splitOnMore($post->getBody());
                $previewBody = $split['has_more'] && $split['teaser'] !== '' ? $split['teaser'] : null;
                $moreLabel   = $split['label'];
            }
        }

        $globalCommentsOn     = $this->settings->get('comments_enabled', '1')      === '1';
        $allowCommentEditing  = $this->settings->get('allow_comment_editing', '1') === '1';
        $captcha              = $this->buildCaptchaData('comment_captcha_type', 'comment_captcha_');

        $seo = $this->seoMeta->findByEntity('post', $post->getId());
        $this->seoState->set($seo, $post);

        if ($this->settings->get('multi_language_content', '0') === '1') {
            $originalId     = $post->getTranslationOf() ?: $post->getId();
            $translations   = $this->posts->findTranslations($originalId);
            $translationMap = [];
            $prefix         = $this->resolveContentPrefix($contentType);
            foreach ($translations as $t) {
                $translationMap[$t->getLanguage()] = $prefix . '/' . $t->getSlug();
            }
            $this->seoState->setTranslations($translationMap);
        }

        $recentFilters = ['content_type' => $contentType, 'status' => 'published'];
        if ($this->settings->get('multi_language_content', '0') === '1') {
            $recentFilters['language'] = $this->twig->getEnvironment()->getGlobals()['current_locale'] ?? 'en';
            if ($this->settings->get('i18n_display_originals_fallback', '1') === '1') {
                $recentFilters['language_fallback'] = true;
            }
        }
        $recentPosts = $this->posts->findAll(new Pagination(1, 5), $recentFilters);
        if (empty($recentPosts) && !empty($recentFilters['language']) && empty($recentFilters['language_fallback'])) {
            $defaultLocale = $this->settings->get('default_locale', 'en');
            if ($recentFilters['language'] !== $defaultLocale) {
                $recentFilters['language'] = $defaultLocale;
                $recentPosts               = $this->posts->findAll(new Pagination(1, 5), $recentFilters);
            }
        }
        $relatedPosts = $this->posts->findRelated($post, 3, $contentLang, $this->settings->get('i18n_display_originals_fallback', '1') === '1');

        $paginationData = $this->setupContentPagination($post, $request);

        $template = 'post.twig';
        if ($contentType !== 'post' && $contentType !== 'page') {
            $type = $this->contentTypes->findBySlug(new Slug($contentType));
            // We should ideally check if file exists, but Twig will throw error if not found.
            try {
                return $this->twig->render($response, $contentType . '.twig', [
                    'post'                              => $post,
                    'type'                              => $type,
                    'authors'                           => $this->buildAuthors([$post]),
                    'seo'                               => $seo,
                    'comments'                          => $comments,
                    'cpage'                             => $cpage,
                    'comments_per_page'                 => $commentsPP,
                    'comments_total'                    => $commentsTotal,
                    'comments_total_pages'              => $commentsTotalPages,
                    'comment_page_map'                  => $commentPageMap,
                    'global_comments_enabled'           => $globalCommentsOn,
                    'allow_comment_editing'             => $allowCommentEditing,
                    'user_comment'                      => $userComment,
                    'comment_ratings'                   => $commentRatings,
                    'admin_edit_url'                    => admin_url('/posts/' . $post->getId() . '/edit'),
                    'sidebar_categories'                => $this->getSidebarCategories($contentType),
                    'sidebar_tags'                      => $this->getSidebarTags($contentType),
                    'sidebar_recent_posts'              => $recentPosts,
                    'related_posts'                     => $relatedPosts,
                    'has_access'                        => $hasAccess,
                    'preview_body'                      => $previewBody,
                    'more_label'                        => $moreLabel,
                    'access_tags'                       => $accessTags,
                    'is_fallback_lang'                  => $fallbackFlags['is_fallback'],
                    'content_language'                  => $fallbackFlags['content_language'],
                    'has_translation_in_current_locale' => $fallbackFlags['has_translation'],
                ] + $paginationData + $captcha);
            } catch (LoaderError) {
                // Fallback to standard post.twig
            }
        }

        return $this->twig->render($response, $template, [
            'post'                                 => $post,
            'type'                                 => $this->contentTypes->findBySlug(new Slug($contentType)),
            'authors'                              => $this->buildAuthors([$post]),
            'seo'                                  => $seo,
            'comments'                             => $comments,
            'cpage'                                => $cpage,
            'comments_per_page'                    => $commentsPP,
            'comments_total'                       => $commentsTotal,
            'comments_total_pages'                 => $commentsTotalPages,
            'comment_page_map'                     => $commentPageMap,
            'global_comments_enabled'              => $globalCommentsOn,
            'allow_comment_editing'                => $allowCommentEditing,
            'user_comment'                         => $userComment,
            'comment_ratings'                      => $commentRatings,
            'admin_edit_url'                       => admin_url('/posts/' . $post->getId() . '/edit'),
            'sidebar_categories'                   => $this->getSidebarCategories($contentType),
            'sidebar_tags'                         => $this->getSidebarTags($contentType),
            'sidebar_recent_posts'                 => $recentPosts,
            'related_posts'                        => $relatedPosts,
            'has_access'                           => $hasAccess,
            'preview_body'                         => $previewBody,
            'more_label'                           => $moreLabel,
            'access_tags'                          => $accessTags,
            'is_fallback_lang'                     => $fallbackFlags['is_fallback'],
            'content_language'                     => $fallbackFlags['content_language'],
            'has_translation_in_current_locale'    => $fallbackFlags['has_translation'],
        ] + $paginationData + $captcha);
    }

    /** GET /page/{slug} */
    public function showPage(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $path = trim($args['path'] ?? '', '/');
        if ($path === '') {
            return $this->twig->render($response->withStatus(404), 'errors/404.twig');
        }

        $forceLang   = $request->getAttribute('locale');
        $contentLang = $forceLang ?: (($this->settings->get('multi_language_content', '0') === '1')
            ? ($this->twig->getEnvironment()->getGlobals()['current_locale'] ?? 'en')
            : null);

        $page = $this->posts->findPageByPath($path, $contentLang);

        // Fallback: search in any language
        if ($page === null) {
            $page = $this->posts->findPageByPath($path);
        }

        if ($page && $contentLang && $page->getLanguage() !== $contentLang) {
            if ($this->settings->get('i18n_display_originals_fallback', '1') === '1' && $page->getTranslationOf() === null) {
                $originalId   = $page->getId();
                $translations = $this->posts->findTranslations($originalId);
                foreach ($translations as $t) {
                    if ($t->getLanguage() === $contentLang && $t->getStatus() === 'published') {
                        $prefix = $this->settings->get('routing_page_prefix', '');
                        $prefix = $prefix ? ('/' . ltrim($prefix, '/')) : '';
                        return $response->withHeader('Location', url($prefix . '/' . $this->posts->buildPagePath($t)))->withStatus(302);
                    }
                }
            } else {
                $originalId   = $page->getTranslationOf() ?: $page->getId();
                $translations = $this->posts->findTranslations($originalId);

                foreach ($translations as $t) {
                    if ($t->getLanguage() === $contentLang && $t->getStatus() === 'published') {
                        $prefix = $this->settings->get('routing_page_prefix', '');
                        $prefix = $prefix ? ('/' . ltrim($prefix, '/')) : '';
                        return $response->withHeader('Location', url($prefix . '/' . $this->posts->buildPagePath($t)))->withStatus(302);
                    }
                }
            }
        }

        $fallbackFlags = $this->computeFallbackFlags(
            $contentLang ?? 'en',
            $page?->getTranslationOf(),
            $page?->getLanguage() ?? 'en',
            fn (int $originalId) => $originalId > 0 ? $this->posts->findTranslations($originalId) : []
        );

        if ($page === null || !in_array($page->getStatus(), ['published', 'unlisted'], true)) {
            return $this->twig->render($response->withStatus(404), 'errors/404.twig');
        }

        $seo = $this->seoMeta->findByEntity('page', $page->getId());
        $this->seoState->set($seo, $page);

        if ($this->settings->get('multi_language_content', '0') === '1') {
            $originalId     = $page->getTranslationOf() ?: $page->getId();
            $translations   = $this->posts->findTranslations($originalId);
            $translationMap = [];
            $prefix         = $this->settings->get('routing_page_prefix', '');
            $prefix         = $prefix ? ('/' . ltrim($prefix, '/')) : '';
            foreach ($translations as $t) {
                $translationMap[$t->getLanguage()] = $prefix . '/' . $this->posts->buildPagePath($t);
            }
            $this->seoState->setTranslations($translationMap);
        }

        // ── Membership access control ──────────────────────────────
        $accessTags = $page->getAccessTags();
        $hasAccess  = true;
        if (!empty($accessTags)) {
            $hasAccess = false;
            $userId    = $this->session->get('user_id');
            if ($userId !== null) {
                $subscriber = $this->subscribers->findByUserId((int) $userId);
                if ($subscriber !== null) {
                    $userTags  = $subscriber->getTags();
                    $hasAccess = !empty(array_intersect($accessTags, $userTags));
                }
            }
        }

        $paginationData = $this->setupContentPagination($page, $request);

        return $this->twig->render($response, 'page.twig', [
            'page'                              => $page,
            'seo'                               => $seo,
            'admin_edit_url'                    => admin_url('/pages/' . $page->getId() . '/edit'),
            'has_access'                        => $hasAccess,
            'access_tags'                       => $accessTags,
            'is_fallback_lang'                  => $fallbackFlags['is_fallback'],
            'content_language'                  => $fallbackFlags['content_language'],
            'has_translation_in_current_locale' => $fallbackFlags['has_translation'],
        ] + $paginationData);
    }

    /** GET /preview/post/{id} — author or admin can preview the draft */
    public function preview(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $userId = $this->session->get('user_id');
        if (!$userId) {
            return $response->withHeader('Location', site_url('/login'))->withStatus(302);
        }
        $post = $this->posts->findById((int) $args['id']);
        if ($post === null || $post->getContentType() !== 'post') {
            return $this->twig->render($response->withStatus(404), 'errors/404.twig');
        }

        $userRole    = $this->session->get('user_role');
        $permissions = $this->session->get('user_permissions', []);
        $isAdmin     = in_array($userRole, ['admin', 'super_admin'], true);
        $canEditAll  = in_array('posts.edit_others', $permissions, true);
        $isAuthor    = (int) $post->getAuthorId() === (int) $userId;

        if (!$isAdmin && !$canEditAll && !$isAuthor) {
            return $this->twig->render($response->withStatus(403), 'errors/403.twig');
        }

        $commentsPP              = max(1, (int) ($this->settings->get('comments_per_page', '5')));
        $comments                = $this->comments->findByEntity('post', $post->getId());
        $commentsTotal           = count($comments);
        $globalCommentsOn        = $this->settings->get('comments_enabled', '1')      === '1';
        $allowCommentEditing     = $this->settings->get('allow_comment_editing', '1') === '1';
        $captcha                 = $this->buildCaptchaData('comment_captcha_type', 'comment_captcha_');

        $userId         = $this->session->get('user_id');
        $userComment    = null;
        $commentRatings = [];
        if ($userId !== null) {
            $userComment = $this->comments->findByUserAndEntity((int) $userId, 'post', $post->getId());
            if ($userComment !== null) {
                $commentRatings = [$userComment->getId() => 0];
            }
        }

        $seo = $this->seoMeta->findByEntity('post', $post->getId());
        $this->seoState->set($seo, $post);

        if ($this->settings->get('multi_language_content', '0') === '1') {
            $originalId     = $post->getTranslationOf() ?: $post->getId();
            $translations   = $this->posts->findTranslations($originalId);
            $translationMap = [];
            $prefix         = $this->settings->get('routing_blog_prefix', '/blog');
            foreach ($translations as $t) {
                $translationMap[$t->getLanguage()] = $prefix . '/' . $t->getSlug();
            }
            $this->seoState->setTranslations($translationMap);
        }

        $paginationData = $this->setupContentPagination($post, $request);

        return $this->twig->render($response, 'post.twig', [
            'post'                           => $post,
            'authors'                        => $this->buildAuthors([$post]),
            'seo'                            => $seo,
            'is_preview'                     => true,
            'comments'                       => $comments,
            'cpage'                          => 1,
            'comments_per_page'              => $commentsPP,
            'comments_total'                 => $commentsTotal,
            'comments_total_pages'           => max(1, (int) ceil($commentsTotal / $commentsPP)),
            'all_comment_ids_in_order'       => $allCommentIdsInOrder,
            'global_comments_enabled'        => $globalCommentsOn,
            'allow_comment_editing'          => $allowCommentEditing,
            'user_comment'                   => $userComment,
            'comment_ratings'                => $commentRatings,
            'admin_edit_url'                 => admin_url('/posts/' . $post->getId() . '/edit'),
            'sidebar_categories'             => $this->getSidebarCategories('post'),
            'sidebar_tags'                   => $this->getSidebarTags('post'),
            'sidebar_recent_posts'           => [],
            'related_posts'                  => null,
            'has_access'                     => true,
            'preview_body'                   => null,
            'access_tags'                    => [],
        ] + $paginationData + $captcha);
    }

    /** GET /preview/page/{id} — author or admin can preview the draft */
    public function previewPage(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $userId = $this->session->get('user_id');
        if (!$userId) {
            return $response->withHeader('Location', site_url('/login'))->withStatus(302);
        }
        $page = $this->posts->findById((int) $args['id']);
        if ($page === null || $page->getContentType() !== 'page') {
            return $this->twig->render($response->withStatus(404), 'errors/404.twig');
        }

        $userRole    = $this->session->get('user_role');
        $permissions = $this->session->get('user_permissions', []);
        $isAdmin     = in_array($userRole, ['admin', 'super_admin'], true);
        $canEdit     = in_array('pages.edit', $permissions, true);
        $isAuthor    = (int) $page->getAuthorId() === (int) $userId;

        if (!$isAdmin && !$canEdit && !$isAuthor) {
            return $this->twig->render($response->withStatus(403), 'errors/403.twig');
        }

        $seo = $this->seoMeta->findByEntity('page', $page->getId());
        $this->seoState->set($seo, $page);

        if ($this->settings->get('multi_language_content', '0') === '1') {
            $originalId     = $page->getTranslationOf() ?: $page->getId();
            $translations   = $this->posts->findTranslations($originalId);
            $translationMap = [];
            $prefix         = $this->settings->get('routing_page_prefix', '');
            $prefix         = $prefix ? ('/' . ltrim($prefix, '/')) : '';
            foreach ($translations as $t) {
                $translationMap[$t->getLanguage()] = $prefix . '/' . $this->posts->buildPagePath($t);
            }
            $this->seoState->setTranslations($translationMap);
        }

        $paginationData = $this->setupContentPagination($page, $request);

        return $this->twig->render($response, 'page.twig', [
            'page'       => $page,
            'seo'        => $seo,
            'is_preview' => true,
            'has_access' => true,
        ] + $paginationData);
    }

    public function feed(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (($this->settings->get('blog_rss_enabled', '1') ?? '1') !== '1') {
            $response->getBody()->write('<error>Blog RSS feed is disabled.</error>');
            return $response->withStatus(404)->withHeader('Content-Type', 'application/rss+xml; charset=utf-8');
        }

        $locale   = strtolower(trim((string) ($request->getAttribute('locale') ?? $this->getCurrentLocale())));
        $category = trim((string) ($request->getQueryParams()['category'] ?? ''));
        $tag      = trim((string) ($request->getQueryParams()['tag'] ?? ''));
        $feed     = $this->blogFeed->setLocale($locale);
        if ($category !== '') {
            $feed->setCategory($category);
        }
        if ($tag !== '') {
            $feed->setTag($tag);
        }
        $rss = $feed->generateRss();

        $response->getBody()->write($rss);

        return $response
            ->withHeader('Content-Type', 'application/rss+xml; charset=utf-8');
    }

    public function feedByType(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $type = $this->contentTypes->findBySlug(new Slug($args['type']));
        if ($type === null) {
            return $this->twig->render($response->withStatus(404), 'errors/404.twig');
        }

        if (!in_array('rss', $type->getSupports(), true)) {
            return $response->withStatus(404)->withHeader('Content-Type', 'application/rss+xml; charset=utf-8');
        }

        $prefix   = $type->getPublicBasePath();
        $locale   = strtolower(trim((string) ($request->getAttribute('locale') ?? $this->getCurrentLocale())));
        $category = trim((string) ($request->getQueryParams()['category'] ?? ''));
        $tag      = trim((string) ($request->getQueryParams()['tag'] ?? ''));
        if ($category !== '') {
            $this->blogFeed->setCategory($category);
        }
        if ($tag !== '') {
            $this->blogFeed->setTag($tag);
        }
        $rss = $this->blogFeed
            ->setFeedName($type->getName())
            ->setFeedSlug($args['type'])
            ->setRoutePrefix($prefix)
            ->setLocaleFilterEnabled(in_array('i18n', $type->getSupports(), true))
            ->setLocale($locale)
            ->generateRss();

        $response->getBody()->write($rss);

        return $response
            ->withHeader('Content-Type', 'application/rss+xml; charset=utf-8');
    }

    /** GET /{categoryPrefix}/{slug} (or /{cptPrefix}/{categoryPrefix}/{slug}) */
    public function byCategory(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $slug         = $args['slug'];
        $contentType  = $args['type'] ?? 'post';
        $forceLang    = $request->getAttribute('locale');
        $contentLang  = $forceLang ?: (($this->settings->get('multi_language_content', '0') === '1')
            ? ($this->twig->getEnvironment()->getGlobals()['current_locale'] ?? 'en')
            : null);
        $category     = $this->categories->findBySlug($slug, $contentType, $contentLang);

        // Fallback: search in any language
        if ($category === null) {
            $category = $this->categories->findBySlug($slug, $contentType);
        }

        if ($category && $contentLang && $category->getLanguage() !== $contentLang) {
            $originalId   = $category->getTranslationOf() ?: $category->getId();
            $translations = $this->categories->findTranslations($originalId);

            foreach ($translations as $t) {
                if ($t->getLanguage() === $contentLang) {
                    $type      = $this->contentTypes->findBySlug(new Slug($contentType));
                    $prefix    = $type?->getCategoryPrefix() ?: ($contentType === 'post' ? $this->settings->get('routing_category_prefix', '/category') : '/category');
                    $cptPrefix = ($contentType !== 'post' && $type) ? $type->getPublicBasePath() : '';
                    return $response->withHeader('Location', url($cptPrefix . $prefix . '/' . $t->getSlug()))->withStatus(302);
                }
            }
        }

        if ($category === null) {
            return $this->twig->render($response->withStatus(404), 'errors/404.twig');
        }

        if ($this->settings->get('multi_language_content', '0') === '1') {
            $originalId     = $category->getTranslationOf() ?: $category->getId();
            $translations   = $this->categories->findTranslations($originalId);
            $translationMap = [];
            $type           = $this->contentTypes->findBySlug(new Slug($contentType));
            $prefix         = $type?->getCategoryPrefix() ?: ($contentType === 'post' ? $this->settings->get('routing_category_prefix', '/category') : '/category');
            $cptPrefix      = ($contentType !== 'post' && $type) ? $type->getPublicBasePath() : '';

            foreach ($translations as $t) {
                $translationMap[$t->getLanguage()] = $cptPrefix . $prefix . '/' . $t->getSlug();
            }
            $this->seoState->setTranslations($translationMap);
        }

        $params   = $request->getQueryParams();
        $page     = max(1, (int) ($params['page'] ?? 1));
        $perPage  = 10;
        $search   = trim($params['search'] ?? '');
        $fallback = $this->settings->get('i18n_display_originals_fallback', '1') === '1';

        $posts = $this->posts->findByCategorySlug($slug, new Pagination($page, $perPage), $contentType, $contentLang, $fallback);
        $total = $this->posts->countByCategorySlug($slug, $contentType, $contentLang, $fallback);

        // Fallback to default locale if no posts in current locale
        if (empty($posts) && $contentLang !== null && !$fallback) {
            $defaultLocale = $this->settings->get('default_locale', 'en');
            if ($contentLang !== $defaultLocale) {
                $posts = $this->posts->findByCategorySlug($slug, new Pagination($page, $perPage), $contentType, $defaultLocale);
                $total = $this->posts->countByCategorySlug($slug, $contentType, $defaultLocale);
            }
        }

        $this->seoState->setArchiveType($contentType !== 'post' ? 'custom' : 'category');

        $fallbackFlags = [
            'is_fallback'      => false,
            'content_language' => $category->getLanguage(),
            'has_translation'  => false,
        ];
        if (
            $this->settings->get('multi_language_content', '0')             === '1'
            && $this->settings->get('i18n_display_originals_fallback', '1') === '1'
            && $this->settings->get('i18n_fallback_content_notice', '1')    === '1'
            && $contentLang && $category->getLanguage() !== $contentLang
        ) {
            $originalId     = $category->getTranslationOf() ?: $category->getId();
            $translations   = $this->categories->findTranslations($originalId);
            $hasTranslation = false;
            foreach ($translations as $t) {
                if ($t->getLanguage() === $contentLang) {
                    $hasTranslation = true;
                    break;
                }
            }
            $fallbackFlags = [
                'is_fallback'      => !$hasTranslation,
                'content_language' => $category->getLanguage(),
                'has_translation'  => $hasTranslation,
            ];
        }

        $view = [
            'posts'                             => $posts,
            'authors'                           => $this->buildAuthors($posts),
            'total'                             => $total,
            'page'                              => $page,
            'per_page'                          => $perPage,
            'total_pages'                       => (int) ceil($total / $perPage),
            'archive_type'                      => $contentType !== 'post' ? 'custom' : 'category',
            'archive_entity'                    => $category,
            'sidebar_categories'                => $this->getSidebarCategories($contentType),
            'sidebar_tags'                      => $this->getSidebarTags($contentType),
            'type'                              => $this->contentTypes->findBySlug(new Slug($contentType)),
            'is_fallback_lang'                  => $fallbackFlags['is_fallback'],
            'content_language'                  => $fallbackFlags['content_language'],
            'has_translation_in_current_locale' => $fallbackFlags['has_translation'],
        ];

        $recentFilters = ['content_type' => $contentType, 'status' => 'published'];
        if ($this->settings->get('multi_language_content', '0') === '1') {
            $recentFilters['language'] = $this->twig->getEnvironment()->getGlobals()['current_locale'] ?? 'en';
            if ($this->settings->get('i18n_display_originals_fallback', '1') === '1') {
                $recentFilters['language_fallback'] = true;
            }
        }
        $sidebarRecent = $this->posts->findAll(new Pagination(1, 5), $recentFilters);
        if (empty($sidebarRecent) && !empty($recentFilters['language']) && empty($recentFilters['language_fallback'])) {
            $defaultLocale = $this->settings->get('default_locale', 'en');
            if ($recentFilters['language'] !== $defaultLocale) {
                $recentFilters['language'] = $defaultLocale;
                $sidebarRecent             = $this->posts->findAll(new Pagination(1, 5), $recentFilters);
            }
        }
        $view['search']               = $search;
        $view['sidebar_recent_posts'] = $sidebarRecent;

        return $this->twig->render($response, 'blog.twig', $view);
    }

    /** GET /{tagPrefix}/{slug} (or /{cptPrefix}/{tagPrefix}/{slug}) */
    public function byTag(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $slug        = $args['slug'];
        $contentType = $args['type'] ?? 'post';
        $forceLang   = $request->getAttribute('locale');
        $contentLang = $forceLang ?: (($this->settings->get('multi_language_content', '0') === '1')
            ? ($this->twig->getEnvironment()->getGlobals()['current_locale'] ?? 'en')
            : null);
        $tag         = $this->tags->findBySlug($slug, $contentType, $contentLang);

        // Fallback: search in any language
        if ($tag === null) {
            $tag = $this->tags->findBySlug($slug, $contentType);
        }

        if ($tag && $contentLang && $tag->getLanguage() !== $contentLang) {
            $originalId   = $tag->getTranslationOf() ?: $tag->getId();
            $translations = $this->tags->findTranslations($originalId);

            foreach ($translations as $t) {
                if ($t->getLanguage() === $contentLang) {
                    $type      = $this->contentTypes->findBySlug(new Slug($contentType));
                    $prefix    = $type?->getTagPrefix() ?: ($contentType === 'post' ? $this->settings->get('routing_tag_prefix', '/tag') : '/tag');
                    $cptPrefix = ($contentType !== 'post' && $type) ? $type->getPublicBasePath() : '';
                    return $response->withHeader('Location', url($cptPrefix . $prefix . '/' . $t->getSlug()))->withStatus(302);
                }
            }
        }

        if ($tag === null) {
            return $this->twig->render($response->withStatus(404), 'errors/404.twig');
        }

        if ($this->settings->get('multi_language_content', '0') === '1') {
            $originalId     = $tag->getTranslationOf() ?: $tag->getId();
            $translations   = $this->tags->findTranslations($originalId);
            $translationMap = [];
            $type           = $this->contentTypes->findBySlug(new Slug($contentType));
            $prefix         = $type?->getTagPrefix() ?: ($contentType === 'post' ? $this->settings->get('routing_tag_prefix', '/tag') : '/tag');
            $cptPrefix      = ($contentType !== 'post' && $type) ? $type->getPublicBasePath() : '';

            foreach ($translations as $t) {
                $translationMap[$t->getLanguage()] = $cptPrefix . $prefix . '/' . $t->getSlug();
            }
            $this->seoState->setTranslations($translationMap);
        }

        $params   = $request->getQueryParams();
        $page     = max(1, (int) ($params['page'] ?? 1));
        $perPage  = 10;
        $search   = trim($params['search'] ?? '');
        $fallback = $this->settings->get('i18n_display_originals_fallback', '1') === '1';

        $posts = $this->posts->findByTagSlug($slug, new Pagination($page, $perPage), $contentType, $contentLang, $fallback);
        $total = $this->posts->countByTagSlug($slug, $contentType, $contentLang, $fallback);

        // Fallback to default locale if no posts in current locale
        if (empty($posts) && $contentLang !== null && !$fallback) {
            $defaultLocale = $this->settings->get('default_locale', 'en');
            if ($contentLang !== $defaultLocale) {
                $posts = $this->posts->findByTagSlug($slug, new Pagination($page, $perPage), $contentType, $defaultLocale);
                $total = $this->posts->countByTagSlug($slug, $contentType, $defaultLocale);
            }
        }

        $this->seoState->setArchiveType($contentType !== 'post' ? 'custom' : 'tag');

        $fallbackFlags = [
            'is_fallback'      => false,
            'content_language' => $tag->getLanguage(),
            'has_translation'  => false,
        ];
        if (
            $this->settings->get('multi_language_content', '0')             === '1'
            && $this->settings->get('i18n_display_originals_fallback', '1') === '1'
            && $this->settings->get('i18n_fallback_content_notice', '1')    === '1'
            && $contentLang && $tag->getLanguage() !== $contentLang
        ) {
            $originalId     = $tag->getTranslationOf() ?: $tag->getId();
            $translations   = $this->tags->findTranslations($originalId);
            $hasTranslation = false;
            foreach ($translations as $t) {
                if ($t->getLanguage() === $contentLang) {
                    $hasTranslation = true;
                    break;
                }
            }
            $fallbackFlags = [
                'is_fallback'      => !$hasTranslation,
                'content_language' => $tag->getLanguage(),
                'has_translation'  => $hasTranslation,
            ];
        }

        $view = [
            'posts'                             => $posts,
            'authors'                           => $this->buildAuthors($posts),
            'total'                             => $total,
            'page'                              => $page,
            'per_page'                          => $perPage,
            'total_pages'                       => (int) ceil($total / $perPage),
            'archive_type'                      => $contentType !== 'post' ? 'custom' : 'tag',
            'archive_entity'                    => $tag,
            'sidebar_categories'                => $this->getSidebarCategories($contentType),
            'sidebar_tags'                      => $this->getSidebarTags($contentType),
            'type'                              => $this->contentTypes->findBySlug(new Slug($contentType)),
            'is_fallback_lang'                  => $fallbackFlags['is_fallback'],
            'content_language'                  => $fallbackFlags['content_language'],
            'has_translation_in_current_locale' => $fallbackFlags['has_translation'],
        ];

        $recentFilters = ['content_type' => $contentType, 'status' => 'published'];
        if ($this->settings->get('multi_language_content', '0') === '1') {
            $recentFilters['language'] = $this->twig->getEnvironment()->getGlobals()['current_locale'] ?? 'en';
            if ($this->settings->get('i18n_display_originals_fallback', '1') === '1') {
                $recentFilters['language_fallback'] = true;
            }
        }
        $sidebarRecent = $this->posts->findAll(new Pagination(1, 5), $recentFilters);
        if (empty($sidebarRecent) && !empty($recentFilters['language']) && empty($recentFilters['language_fallback'])) {
            $defaultLocale = $this->settings->get('default_locale', 'en');
            if ($recentFilters['language'] !== $defaultLocale) {
                $recentFilters['language'] = $defaultLocale;
                $sidebarRecent             = $this->posts->findAll(new Pagination(1, 5), $recentFilters);
            }
        }
        $view['search']               = $search;
        $view['sidebar_recent_posts'] = $sidebarRecent;

        return $this->twig->render($response, 'blog.twig', $view);
    }

    private function buildAuthors(array $posts): array
    {
        $authors   = [];
        $authorIds = array_unique(array_map(fn ($post) => $post->getAuthorId(), $posts));
        foreach ($authorIds as $authorId) {
            $author = $this->users->findById($authorId);
            if ($author !== null) {
                $authors[$authorId] = $author;
            }
        }
        return $authors;
    }

    private function resolveContentPrefix(string $contentType): string
    {
        if ($contentType === 'post') {
            return $this->settings->get('routing_blog_prefix', '/blog');
        }
        if ($contentType === 'page') {
            $p = $this->settings->get('routing_page_prefix', '');
            return $p ? ('/' . ltrim($p, '/')) : '';
        }
        try {
            $type = $this->contentTypes->findBySlug(new Slug($contentType));
        } catch (DomainException) {
            $type = null;
        }
        return $type ? $type->getPublicBasePath() : '';
    }

    /**
     * Recursively map a comment and all its descendants to a page number.
     *
     * @param  Comment $node
     * @param  int $page
     * @param  array<int, Comment[]> $childrenByParent
     * @param  array<int, int> $map
     * @return void
     */
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

    /**
     * Processes content pagination for post/page/CPT.
     *
     * @param Post $entity
     * @param ServerRequestInterface $request
     * @return array<string, mixed> Pagination metadata array to pass to Twig
     */
    private function setupContentPagination(
        Post $entity,
        ServerRequestInterface $request
    ): array {
        $fullBody = $entity->getBody() ?? '';

        // Check if body contains nextpage break
        if (!preg_match('/<!--nextpage(?:\s+title=("|\'|&quot;)(.*?)\1)?\s*-->/i', $fullBody)) {
            return [
                'multi_page'      => false,
                'current_page'    => 1,
                'total_pages'     => 1,
                'page_titles'     => [$entity->getTitle()],
                'prev_page'       => null,
                'prev_page_title' => null,
                'next_page'       => null,
                'next_page_title' => null,
                'page_urls'       => [],
            ];
        }

        $split       = $this->splitContentIntoPages($fullBody);
        $pages       = $split['pages'];
        $titles      = $split['titles'];
        $totalPages  = $split['total_pages'];

        $params      = $request->getQueryParams();
        $currentPage = max(1, min($totalPages, (int) ($params['page'] ?? 1)));

        // Set entity body to active page segment
        $entity->setBody($pages[$currentPage - 1]);
        // Save original full body and current page in meta for TOC processor
        $entity->setMetaValue('_full_body', $fullBody);
        $entity->setMetaValue('_content_page', $currentPage);

        $queryParams = $params;
        unset($queryParams['page']);

        $uri  = $request->getUri();
        $path = $uri->getPath();

        // site_url() re-adds the APP_URL base path (stripped by the front controller)
        // and preserves the current locale prefix for multi-language content.
        $buildPageUrl = static function (int $pageNum) use ($path, $queryParams): string {
            $q = $queryParams;
            if ($pageNum > 1) {
                $q['page'] = $pageNum;
            }
            return site_url($path, [], $q);
        };

        $pageUrls = [];
        for ($i = 1; $i <= $totalPages; $i++) {
            $pageUrls[$i] = $buildPageUrl($i);
        }

        $prevPage = $currentPage > 1 ? $currentPage - 1 : null;
        $nextPage = $currentPage < $totalPages ? $currentPage + 1 : null;

        return [
            'multi_page'      => true,
            'current_page'    => $currentPage,
            'total_pages'     => $totalPages,
            'page_titles'     => $titles,
            'prev_page'       => $prevPage,
            'prev_page_title' => $prevPage ? ($titles[$prevPage - 1] ?? null) : null,
            'next_page'       => $nextPage,
            'next_page_title' => $nextPage ? ($titles[$nextPage - 1] ?? null) : null,
            'page_urls'       => $pageUrls,
        ];
    }

    /**
     * @param string $body
     * @return array{
     *   pages: string[],
     *   titles: string[],
     *   total_pages: int
     * }
     */
    private function splitContentIntoPages(string $body): array
    {
        if (trim($body) === '') {
            return [
                'pages'       => [''],
                'titles'      => [__('Page') . ' 1'],
                'total_pages' => 1,
            ];
        }

        $pattern = '/(?:<p\b[^>]*>\s*)?<!--nextpage(?:\s+title=("|\'|&quot;)(.*?)\1)?\s*-->(?:\s*<\/p>)?/i';

        preg_match_all($pattern, $body, $matches, PREG_OFFSET_CAPTURE);

        if (empty($matches[0])) {
            return [
                'pages'       => [$body],
                'titles'      => [$this->extractPageTitle($body, 1, null)],
                'total_pages' => 1,
            ];
        }

        $rawPages    = [];
        $breakTitles = [];
        $lastOffset  = 0;

        foreach ($matches[0] as $index => $matchCapture) {
            $fullMatchStr = $matchCapture[0];
            $matchOffset  = $matchCapture[1];

            $segment    = substr($body, $lastOffset, $matchOffset - $lastOffset);
            $rawPages[] = $segment;

            $titleCapture  = $matches[2][$index][0] ?? '';
            $breakTitles[] = html_entity_decode($titleCapture, ENT_QUOTES, 'UTF-8');

            $lastOffset = $matchOffset + strlen($fullMatchStr);
        }

        $rawPages[] = substr($body, $lastOffset);

        $totalPages = count($rawPages);
        $titles     = [];

        for ($i = 0; $i < $totalPages; $i++) {
            $explicitTitle = ($i > 0 && isset($breakTitles[$i - 1]) && trim($breakTitles[$i - 1]) !== '')
                ? trim($breakTitles[$i - 1])
                : null;

            $titles[] = $this->extractPageTitle($rawPages[$i], $i + 1, $explicitTitle);
        }

        return [
            'pages'       => $rawPages,
            'titles'      => $titles,
            'total_pages' => $totalPages,
        ];
    }

    private function extractPageTitle(string $pageSegment, int $pageNum, ?string $explicitTitle): string
    {
        if ($explicitTitle !== null && trim($explicitTitle) !== '') {
            return trim($explicitTitle);
        }

        if (preg_match('/<h[1-6]\b[^>]*>(.*?)<\/h[1-6]>/si', $pageSegment, $m)) {
            $headingText = trim(strip_tags($m[1]));
            if ($headingText !== '') {
                return html_entity_decode($headingText, ENT_QUOTES, 'UTF-8');
            }
        }

        return __('Page') . ' ' . $pageNum;
    }
}
