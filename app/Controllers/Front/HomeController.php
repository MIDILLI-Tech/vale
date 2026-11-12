<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Front;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use ValeApp\Application\Seo\SeoState;
use ValeApp\Domain\Content\Entity\Tag;
use ValeApp\Domain\Content\Repository\CategoryRepositoryInterface;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Content\Repository\TagRepositoryInterface;
use ValeApp\Domain\Content\TreeHelper;
use ValeApp\Domain\Seo\Repository\SeoMetaRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Domain\User\Repository\UserRepositoryInterface;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Translation\Translator;

final class HomeController
{
    public function __construct(
        private readonly Twig $twig,
        private readonly DbalSettingsRepository $settings,
        private readonly PostRepositoryInterface $posts,
        private readonly CategoryRepositoryInterface $blogCategories,
        private readonly TagRepositoryInterface $tags,
        private readonly SeoMetaRepositoryInterface $seoMeta,
        private readonly UserRepositoryInterface $users,
        private readonly SeoState $seoState,
        private readonly ShopController $shop,
    ) {
    }

    private function getSidebarCategories(): array
    {
        $currentLocale = $this->twig->getEnvironment()->getGlobals()['current_locale'] ?? 'en';
        $fallback      = $this->settings->get('i18n_display_originals_fallback', '1') === '1';
        $categories    = $this->blogCategories->findAll(null, 'post', $currentLocale, $fallback);
        if (empty($categories) && $this->settings->get('multi_language_content', '0') === '1' && !$fallback) {
            $defaultLocale = $this->settings->get('default_locale', 'en');
            if ($currentLocale !== $defaultLocale) {
                $categories = $this->blogCategories->findAll(null, 'post', $defaultLocale);
            }
        }
        return $categories;
    }

    private function getSidebarTags(): array
    {
        $currentLocale = $this->twig->getEnvironment()->getGlobals()['current_locale'] ?? 'en';
        $fallback      = $this->settings->get('i18n_display_originals_fallback', '1') === '1';
        $tags          = $this->tags->findAllWithUsageCount(null, 'post', $currentLocale, $fallback);
        if (empty($tags) && $this->settings->get('multi_language_content', '0') === '1' && !$fallback) {
            $defaultLocale = $this->settings->get('default_locale', 'en');
            if ($currentLocale !== $defaultLocale) {
                $tags = $this->tags->findAllWithUsageCount(null, 'post', $defaultLocale);
            }
        }

        return array_values(array_filter(array_map(
            static fn (array $item) => $item['tag'],
            array_filter($tags, static fn (array $item) => $item['usageCount'] > 0)
        ), static fn (Tag $tag) => $tag->getName() !== '' && $tag->getName() !== null));
    }

    private function getSidebarRecentPosts(): array
    {
        $currentLocale = $this->twig->getEnvironment()->getGlobals()['current_locale'] ?? 'en';
        $fallback      = $this->settings->get('i18n_display_originals_fallback', '1') === '1';
        $filters       = ['content_type' => 'post', 'status' => 'published'];
        if ($this->settings->get('multi_language_content', '0') === '1') {
            $filters['language'] = $currentLocale;
            if ($fallback) {
                $filters['language_fallback'] = true;
            }
        }
        $posts = $this->posts->findAll(new Pagination(1, 5), $filters);
        if (empty($posts) && !$fallback) {
            $defaultLocale = $this->settings->get('default_locale', 'en');
            if ($currentLocale !== $defaultLocale) {
                $filters['language'] = $defaultLocale;
                unset($filters['language_fallback']);
                $posts = $this->posts->findAll(new Pagination(1, 5), $filters);
            }
        }
        return $posts;
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $mode        = $this->settings->get('show_on_front', 'posts');
        $blogEnabled = ($this->settings->get('blog_enabled', '1') ?? '1') === '1';

        return match ($mode) {
            'shop'  => $this->shop->index($request, $response),
            'page'  => $this->renderPage($response),
            'posts' => $blogEnabled ? $this->renderBlog($request, $response) : $this->twig->render($response, 'home.twig', [
                'title'                => 'Welcome to Vale',
                'sidebar_recent_posts' => $this->getSidebarRecentPosts(),
            ]),
            default => $this->twig->render($response, 'home.twig', [
                'title'                => 'Welcome to Vale',
                'sidebar_recent_posts' => $this->getSidebarRecentPosts(),
            ]),
        };
    }

    private function renderBlog(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params   = $request->getQueryParams();
        $page     = max(1, (int) ($params['page'] ?? 1));
        $perPage  = (int) $this->settings->get('posts_per_page', '10');
        $filters  = ['content_type' => 'post', 'status' => 'published'];
        $fallback = $this->settings->get('i18n_display_originals_fallback', '1') === '1';

        if ($this->settings->get('multi_language_content', '0') === '1') {
            $filters['language'] = $this->twig->getEnvironment()->getGlobals()['current_locale'] ?? 'en';
            if ($fallback) {
                $filters['language_fallback'] = true;
            }
        }

        $search = trim($params['search'] ?? '');
        if ($search !== '') {
            $filters['search'] = $search;
        }

        $posts = $this->posts->findAll(new Pagination($page, $perPage), $filters);
        $total = $this->posts->count($filters);

        if ($this->settings->get('multi_language_content', '0') === '1') {
            $locales        = Translator::getInstance()?->getSupportedLocales() ?: ['en'];
            $translationMap = [];
            foreach ($locales as $l) {
                $translationMap[$l] = '/';
            }
            $this->seoState->setTranslations($translationMap);
        }

        // Fallback to default locale if no posts in current locale
        if (empty($posts) && !empty($filters['language']) && !$fallback) {
            $defaultLocale = $this->settings->get('default_locale', 'en');
            if ($filters['language'] !== $defaultLocale) {
                $filters['language'] = $defaultLocale;
                $posts               = $this->posts->findAll(new Pagination($page, $perPage), $filters);
                $total               = $this->posts->count($filters);
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
            'sidebar_categories'   => TreeHelper::buildTree($this->getSidebarCategories()),
            'sidebar_tags'         => $this->getSidebarTags(),
            'sidebar_recent_posts' => $this->getSidebarRecentPosts(),
        ]);
    }

    private function renderPage(ResponseInterface $response): ResponseInterface
    {
        $pageId = (int) $this->settings->get('homepage_page_id', '0');
        if ($pageId <= 0) {
            return $this->twig->render($response, 'home.twig', [
                'title'                => 'Welcome to Vale',
                'sidebar_recent_posts' => $this->getSidebarRecentPosts(),
            ]);
        }

        $page = $this->posts->findById($pageId);
        if ($page === null || !in_array($page->getStatus(), ['published', 'unlisted'], true)) {
            return $this->twig->render($response, 'home.twig', [
                'title'                => 'Welcome to Vale',
                'sidebar_recent_posts' => $this->getSidebarRecentPosts(),
            ]);
        }

        $currentLocale = $this->twig->getEnvironment()->getGlobals()['current_locale'] ?? 'en';
        if ($this->settings->get('multi_language_content', '0') === '1' && $page->getLanguage() !== $currentLocale) {
            $originalId   = $page->getTranslationOf() ?: $page->getId();
            $translations = $this->posts->findAll(new Pagination(1, 1), [
                'content_type'   => 'page',
                'language'       => $currentLocale,
                'status'         => 'published',
                'translation_of' => $originalId,
            ]);
            if ($translations) {
                $page = $translations[0];
            }
        }

        $fallbackFlags = [
            'is_fallback'      => false,
            'content_language' => $page->getLanguage(),
            'has_translation'  => false,
        ];
        if (
            $this->settings->get('multi_language_content', '0')             === '1'
            && $this->settings->get('i18n_display_originals_fallback', '1') === '1'
            && $this->settings->get('i18n_fallback_content_notice', '1')    === '1'
            && $page->getLanguage() !== $currentLocale
        ) {
            $originalId     = $page->getTranslationOf() ?: $page->getId();
            $translations   = $this->posts->findTranslations($originalId);
            $hasTranslation = false;
            foreach ($translations as $t) {
                if ($t->getLanguage() === $currentLocale && $t->getStatus() === 'published') {
                    $hasTranslation = true;
                    break;
                }
            }
            $fallbackFlags = [
                'is_fallback'      => !$hasTranslation,
                'content_language' => $page->getLanguage(),
                'has_translation'  => $hasTranslation,
            ];
        }

        $seo = $this->seoMeta->findByEntity('page', $page->getId());
        $this->seoState->set($seo, $page);

        if ($this->settings->get('multi_language_content', '0') === '1') {
            $originalId      = $page->getTranslationOf() ?: $page->getId();
            $allTranslations = $this->posts->findTranslations($originalId);
            $translationMap  = [];
            foreach ($allTranslations as $t) {
                $translationMap[$t->getLanguage()] = '/';
            }
            $this->seoState->setTranslations($translationMap);
        }

        return $this->twig->render($response, 'page.twig', [
            'page'                              => $page,
            'seo'                               => $seo,
            'is_fallback_lang'                  => $fallbackFlags['is_fallback'],
            'content_language'                  => $fallbackFlags['content_language'],
            'has_translation_in_current_locale' => $fallbackFlags['has_translation'],
        ]);
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
}
