<?php

declare(strict_types=1);

namespace ValeApp\Application\Content;

use ValeApp\Domain\Content\Entity\Post;
use ValeApp\Domain\Content\Entity\Revision;
use ValeApp\Domain\Content\Repository\ContentTypeRepositoryInterface;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Content\Repository\RevisionRepositoryInterface;
use ValeApp\Domain\Content\RoutingValidator;
use ValeApp\Domain\Shared\Exception\DomainException;
use ValeApp\Domain\Shared\Exception\ValidationException;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Infrastructure\Content\RedirectService;
use ValeApp\Infrastructure\Content\SlugService;

final class SavePostUseCase
{
    private const KEY_ENABLED = 'revisions_enabled';
    private const KEY_MAX     = 'revisions_max';

    public function __construct(
        private readonly PostRepositoryInterface $posts,
        private readonly SlugService $slugs,
        private readonly RevisionRepositoryInterface $revisions,
        private readonly SettingsRepositoryInterface $settings,
        private readonly RedirectService $redirects,
        private readonly ContentTypeRepositoryInterface $contentTypes,
        private readonly ?RoutingValidator $routing = null,
    ) {
    }

    private function isRevisionsEnabled(): bool
    {
        return (bool) $this->settings->get(self::KEY_ENABLED, '1');
    }

    private function getMaxRevisions(): int
    {
        return max(0, (int) $this->settings->get(self::KEY_MAX, '10'));
    }

    /**
     * @param array{
     *     title: string,
     *     slug?: string,
     *     body?: string,
     *     excerpt?: string,
     *     status?: string,
     *     content_type?: string,
     *     author_id: int,
     *     featured_image_id?: int|null,
     *     parent_id?: int|null,
     *     sort_order?: int,
     *     comment_status?: string,
     *     meta_title?: string,
     *     meta_description?: string,
     *     rss_exclude?: bool|string,
     *     category_ids?: int[],
     *     tag_ids?: int[],
     *     meta?: array<string, mixed>,
     *     expires_at?: string,
     *     swap_post_id?: int|string,
     *     set_schedule?: bool|string,
     *     clear_schedule?: bool|string,
     *     published_at?: string,
     *     aliases?: string|string[],
     * } $data
     */
    public function execute(array $data, ?int $id = null): Post
    {
        $this->validate($data);

        $contentType  = $data['content_type'] ?? 'post';
        $oldTitle     = null;
        $oldBody      = null;
        $oldExcerpt   = null;
        $oldSlug      = null;
        $oldCanonical = null;

        if ($id !== null) {
            $post = $this->posts->findById($id);
            if ($post === null) {
                throw ValidationException::withMessages(['id' => 'Post not found.']);
            }
            $oldTitle   = $post->getTitle();
            $oldBody    = $post->getBody();
            $oldExcerpt = $post->getExcerpt();
            $oldSlug    = (string) $post->getSlug();
        } else {
            $lang = $data['language'] ?? 'en';
            $slug = $this->resolveSlug($data['title'], $data['slug'] ?? '', $contentType, $lang, null);
            $post = new Post($data['title'], $slug, (int) $data['author_id']);
            $post->setContentType($contentType);
            $post->setLanguage($lang);

            $translationOf = isset($data['translation_of']) && $data['translation_of'] !== '' ? (int) $data['translation_of'] : null;
            if ($translationOf !== null) {
                $original = $this->posts->findById($translationOf);
                if ($original === null) {
                    throw ValidationException::withMessages(['translation_of' => 'Selected original not found.']);
                }
                if ($original->getLanguage() === $lang) {
                    throw ValidationException::withMessages(['translation_of' => 'Cannot translate into the same language.']);
                }
                foreach ($this->posts->findTranslations($translationOf) as $existing) {
                    if ($existing->getLanguage() === $lang) {
                        throw ValidationException::withMessages(['translation_of' => 'This original already has a translation in this language.']);
                    }
                }
                $post->setTranslationOf($translationOf);
            } else {
                $post->setTranslationOf(null);
            }
            $this->assertPagePathAvailable($post, $slug, $this->effectiveParentId($data, null), null);
        }

        if ($id !== null) {
            $post->setTitle($data['title']);
            $post->setLanguage($data['language'] ?? $post->getLanguage());

            $translationOf = $post->getTranslationOf();
            if (array_key_exists('translation_of', $data)) {
                $raw = $data['translation_of'];
                if ($raw === '' || $raw === null) {
                    $translationOf = null;
                } else {
                    $newId = (int) $raw;
                    if ($newId > 0) {
                        $original = $this->posts->findById($newId);
                        if ($original === null) {
                            throw ValidationException::withMessages(['translation_of' => 'Selected original not found.']);
                        }
                        if ($original->getLanguage() === ($data['language'] ?? $post->getLanguage())) {
                            throw ValidationException::withMessages(['translation_of' => 'Cannot translate into the same language.']);
                        }
                        foreach ($this->posts->findTranslations($newId) as $existing) {
                            if ($existing->getLanguage() === ($data['language'] ?? $post->getLanguage()) && (int) $existing->getId() !== $id) {
                                throw ValidationException::withMessages(['translation_of' => 'This original already has a translation in this language.']);
                            }
                        }
                        $translationOf = $newId;
                    } else {
                        $translationOf = null;
                    }
                }
            }
            $post->setTranslationOf($translationOf);

            $pagePrefix = '';
            if ($contentType === 'page') {
                $pagePrefix = $this->settings->get('routing_page_prefix', '');
                $pagePrefix = $pagePrefix ? ('/' . ltrim($pagePrefix, '/')) : '';
            }
            $oldPath      = $contentType === 'page' ? $pagePrefix . '/' . $this->posts->buildPagePath($post) : null;
            $oldCanonical = $oldPath;
            if ($oldCanonical === null && $oldSlug !== null && array_key_exists('aliases', $data)) {
                $base = $this->contentBasePath($contentType);
                if ($base !== null) {
                    $oldCanonical = $this->redirects->contentCanonical($base, $oldSlug);
                }
            }

            $slug = $this->resolveSlug($data['title'], $data['slug'] ?? '', $contentType, $post->getLanguage(), $id);
            $post->setSlug($slug);
            $this->assertPagePathAvailable($post, $slug, $this->effectiveParentId($data, $post->getParentId()), $id);

            if ($oldSlug !== null && $oldSlug !== (string) $slug) {
                $createRedirect = $data['create_redirect'] ?? true;
                if ($createRedirect) {
                    if ($contentType === 'post') {
                        $blogPrefix = '/' . ltrim($this->settings->get('routing_blog_prefix', '/blog'), '/');
                        $this->redirects->recordRedirect($blogPrefix . '/' . $oldSlug, $blogPrefix . '/' . (string) $slug);
                    } elseif ($contentType === 'page') {
                        $newPath = $pagePrefix . '/' . $this->posts->buildPagePath($post);
                        $this->redirects->recordRedirect($oldPath, $newPath);
                    } else {
                        $base = $this->contentBasePath($contentType);
                        if ($base !== null) {
                            $this->redirects->recordRedirect(
                                $this->redirects->contentCanonical($base, $oldSlug),
                                $this->redirects->contentCanonical($base, (string) $slug)
                            );
                        }
                    }
                }
            }
        }

        $post->setBody($data['body'] ?? null);
        $post->setExcerpt($data['excerpt'] ?? null);
        $featuredImageId = isset(
            $data['featured_image_id']
        ) && $data['featured_image_id'] !== '' ? (int) $data['featured_image_id'] : null;
        $post->setFeaturedImageId($featuredImageId);
        $parentId = isset($data['parent_id']) && $data['parent_id'] !== '' ? (int) $data['parent_id'] : null;
        $post->setParentId($parentId);
        $post->setSortOrder((int) ($data['sort_order'] ?? 0));
        $post->setCommentStatus($data['comment_status'] ?? 'use_global');
        if ($post->getContentType() === 'page') {
            $post->setPageTemplate($this->normalizePageTemplate($data['page_template'] ?? 'standard'));
            $post->setHideTitle(!empty($data['hide_title']));
        }
        $seoInput = is_array($data['seo'] ?? null) ? $data['seo'] : [];
        $post->setMetaTitle($data['meta_title'] ?? $seoInput['meta_title'] ?? null);
        $post->setMetaDescription($data['meta_description'] ?? $seoInput['meta_description'] ?? null);
        $post->setRssExclude(!empty($data['rss_exclude']));
        $post->setAccessTags(!empty($data['access_tags']) ? (array) $data['access_tags'] : []);
        $post->setCategoryIds($data['category_ids'] ?? []);
        $post->setTagIds($data['tag_ids'] ?? []);
        $post->setMeta($data['meta'] ?? []);

        // Handle status transitions.
        // The admin form no longer offers 'scheduled' as a status choice; it
        // sends set_schedule + dates instead and the mapping below restores
        // the scheduled state. A direct status='scheduled' is still honored
        // for API/backward compatibility.
        $newStatus  = $data['status'] ?? 'draft';
        $scheduling = !empty($data['set_schedule']);
        $rawPublish = is_string($data['published_at'] ?? null) ? trim($data['published_at']) : '';
        $publishAt  = $rawPublish !== ''
            ? \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $rawPublish)
            : null;
        if ($publishAt === false) {
            $publishAt = null;
        }
        $takeSchedulePath = $scheduling && $publishAt !== null && in_array($newStatus, ['draft', 'unlisted'], true);

        if ($takeSchedulePath) {
            if ($newStatus === 'unlisted') {
                if (!$post->isUnlisted()) {
                    $post->makeUnlisted();
                }
            } else {
                $post->setStatus('scheduled');
            }
            $post->setPublishedAt($publishAt);
        } else {
            if ($newStatus === 'published' && !$post->isPublished()) {
                $post->publish();
            } elseif ($newStatus === 'unlisted' && !$post->isUnlisted()) {
                $post->makeUnlisted();
            } elseif ($newStatus === 'scheduled') {
                $post->setStatus('scheduled');
                if ($publishAt !== null) {
                    $post->setPublishedAt($publishAt);
                }
            } elseif ($newStatus === 'trash') {
                $post->trash();
            } else {
                $post->setStatus($newStatus);
            }
            // Leaving schedule state drops a pending future marker, if any.
            // Historical (past) publish dates are never touched.
            if (!empty($data['clear_schedule'])) {
                $current = $post->getPublishedAt();
                if ($current !== null && $current > new \DateTimeImmutable()) {
                    $post->setPublishedAt(null);
                }
            }
        }

        $this->applyExpiryAndSwap($post, $data, $contentType);

        $saved = $this->posts->save($post);

        if (array_key_exists('aliases', $data)) {
            $this->syncContentAliases($saved, $contentType, $oldCanonical, $data['aliases']);
        }

        if ($id !== null && $this->isRevisionsEnabled()) {
            $this->createRevision(
                (int) $post->getId(),
                (int) $data['author_id'],
                $oldTitle ?? '',
                $oldBody,
                $oldExcerpt,
            );
        }

        return $saved;
    }

    public function createRevision(int $postId, int $authorId, string $title, ?string $body, ?string $excerpt): void
    {
        if (!$this->isRevisionsEnabled()) {
            return;
        }

        $max    = $this->getMaxRevisions();
        $latest = $this->revisions->findByPostId($postId);

        if (!empty($latest)) {
            $last = $latest[0];
            if ($last->getTitle() === $title && $last->getBody() === $body && $last->getExcerpt() === $excerpt) {
                return;
            }
        }

        $rev = new Revision(
            $postId,
            $authorId,
            $title,
            $body,
            $excerpt,
            $this->revisions->nextRevisionNumber($postId),
        );
        $this->revisions->save($rev);

        if ($max > 0) {
            $this->revisions->deleteOldestByPostId($postId, $max);
        }
    }

    /**
     * @param array{expires_at?: string, swap_post_id?: int|string, clear_schedule?: bool|string} $data
     */
    private function applyExpiryAndSwap(Post $post, array $data, string $contentType): void
    {
        // The schedule block was switched off in the form: drop any hidden
        // expiry/swap values instead of persisting stale data. Callers that
        // never send the flag (e.g. the API) keep the previous behavior.
        if (!empty($data['clear_schedule'])) {
            $post->setExpiresAt(null);
            $post->setSwapPostId(null);
            return;
        }

        $expiresAt = null;
        if (!empty($data['expires_at'])) {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', (string) $data['expires_at']);
            if ($parsed !== false) {
                $expiresAt = $parsed;
            }
        }

        $swapId = !empty($data['swap_post_id']) ? (int) $data['swap_post_id'] : null;

        if ($swapId !== null && !$this->supportsSwap($contentType)) {
            throw ValidationException::withMessages(['swap_post_id' => 'Scheduled replacement is not enabled for this content type.']);
        }

        if ($swapId !== null) {
            $partner = $this->posts->findById($swapId);
            if ($partner === null) {
                throw ValidationException::withMessages(['swap_post_id' => 'Replacement page not found.']);
            }
            if ($post->getId() !== null && $partner->getId() === $post->getId()) {
                throw ValidationException::withMessages(['swap_post_id' => 'A page cannot replace itself.']);
            }
            if ($partner->getStatus() === 'trash') {
                throw ValidationException::withMessages(['swap_post_id' => 'Replacement page is in trash.']);
            }
            if ($partner->getContentType() !== $post->getContentType() || $partner->getLanguage() !== $post->getLanguage()) {
                throw ValidationException::withMessages(['swap_post_id' => 'Replacement must be the same content type and language.']);
            }
        }

        // An expiry that is already in the past (or before the publish date) would
        // make the item vanish on the very next cron run, so reject it up front.
        if ($expiresAt !== null) {
            $publishedAt = $post->getPublishedAt();
            if ($publishedAt === null) {
                if ($expiresAt <= new \DateTimeImmutable()) {
                    throw ValidationException::withMessages(['expires_at' => 'Expiry must be in the future.']);
                }
            } elseif ($expiresAt <= $publishedAt) {
                throw ValidationException::withMessages(['expires_at' => 'Expiry must be after the publish date.']);
            }
        }

        $post->setExpiresAt($expiresAt);
        $post->setSwapPostId($swapId);
    }

    private function supportsSwap(string $contentType): bool
    {
        // Pages support scheduled replacement by default (matches the seeder,
        // and keeps upgrades working without a data migration). Other types
        // opt in via the scheduled_swap support flag.
        if ($contentType === 'page') {
            return true;
        }

        try {
            $type = $this->contentTypes->findBySlug(new Slug($contentType));
        } catch (\Throwable) {
            return false;
        }

        return $type !== null && \in_array('scheduled_swap', $type->getSupports(), true);
    }

    /**
     * Section base path for alias URLs: the global (non-localized) prefix.
     * Returns null for unknown content types (aliases skipped silently).
     */
    private function contentBasePath(string $contentType): ?string
    {
        if ($contentType === 'page') {
            $prefix = $this->settings->get('routing_page_prefix', '') ?? '';
            return $prefix !== '' ? ('/' . ltrim($prefix, '/')) : '';
        }

        if ($contentType === 'post') {
            $prefix = $this->settings->get('routing_blog_prefix', '/blog') ?? '/blog';
            return '/' . ltrim($prefix, '/');
        }

        try {
            $type = $this->contentTypes->findBySlug(new Slug($contentType));
        } catch (\Throwable) {
            return null;
        }

        return $type?->getPublicBasePath();
    }

    /**
     * Sync manually curated content aliases (same section only) into the
     * redirects table. All-or-nothing: every alias is validated first so a
     * failure never leaves half-written redirect rows behind.
     *
     * Paths are stored locale-agnostic (no /tr, /en prefix) and with the
     * global section prefix — RedirectMiddleware strips the locale before
     * lookup and re-applies it to the target, so aliases keep working when
     * multi-language content or localized prefixes change.
     *
     * @param string|string[]|null $input Comma-separated string or list of alias slugs
     */
    private function syncContentAliases(Post $saved, string $contentType, ?string $oldCanonical, mixed $input): void
    {
        $basePath = $this->contentBasePath($contentType);
        if ($basePath === null) {
            return;
        }

        $isPage = $contentType === 'page';

        $fullPath  = $isPage ? $this->posts->buildPagePath($saved) : (string) $saved->getSlug();
        $canonical = $this->redirects->contentCanonical($basePath, $fullPath);

        if ($oldCanonical !== null && $oldCanonical !== $canonical) {
            $this->redirects->repointManualAliases($oldCanonical, $canonical);
        }

        $leafs = $this->redirects->parseAliasInput($input);

        $parentChain = $isPage && str_contains($fullPath, '/') ? substr($fullPath, 0, (int) strrpos($fullPath, '/')) : '';

        $errors       = [];
        $desiredPaths = [];
        foreach ($leafs as $leaf) {
            try {
                $slug = new Slug(mb_strtolower($leaf));
            } catch (DomainException) {
                $errors[] = sprintf('Alias "%s" is not a valid slug (use lowercase letters, numbers and hyphens).', $leaf);
                continue;
            }

            if (!$isPage && str_contains((string) $slug, '/')) {
                $errors[] = sprintf('Alias "%s" must be a single slug without slashes.', $leaf);
                continue;
            }

            $relative  = $isPage && $parentChain !== '' ? $parentChain . '/' . $slug : (string) $slug;
            $aliasPath = $this->redirects->contentCanonical($basePath, $relative);

            if ($aliasPath === $canonical) {
                $errors[] = sprintf('Alias "%s" is the same as the current slug.', $leaf);
                continue;
            }

            if ($isPage) {
                $hit = $this->posts->findPageByPath($relative, $saved->getLanguage())
                    ?? $this->posts->findPageByPath($relative);
                $takenLabel = 'another page';
            } else {
                $hit = $this->posts->findBySlug($slug, $contentType, $saved->getLanguage())
                    ?? $this->posts->findBySlug($slug, $contentType);
                $takenLabel = 'another entry';
            }
            if ($hit !== null && (int) $hit->getId() !== (int) $saved->getId()) {
                $errors[] = sprintf('Alias "%s" is already used by %s.', $leaf, $takenLabel);
                continue;
            }

            if ($isPage && $this->routing !== null && ($conflict = $this->routing->getPagePathConflictError($aliasPath, $saved->getId())) !== null) {
                $errors[] = $this->friendlyPagePathError($aliasPath, $conflict);
                continue;
            }

            $taken = $this->redirects->findTargetForOldPath($aliasPath);
            if ($taken !== null && (string) $taken['new_path'] !== $canonical) {
                $errors[] = sprintf('Alias "%s" is already used as a redirect.', $leaf);
                continue;
            }

            $desiredPaths[] = $aliasPath;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['aliases' => implode(' ', $errors)]);
        }

        $current = $this->redirects->findManualAliases($canonical);

        foreach (array_diff($current, $desiredPaths) as $removed) {
            $this->redirects->deleteManualAlias($removed);
        }

        foreach (array_diff($desiredPaths, $current) as $added) {
            $this->redirects->recordRedirect($added, $canonical, false, true);
        }
    }

    private function resolveSlug(string $title, string $slugInput, string $contentType, string $language, ?int $excludeId): Slug
    {
        $text = $slugInput !== '' ? $slugInput : $title;
        return $this->slugs->unique(
            $text,
            VL_DB_PREFIX . 'posts',
            'slug',
            $excludeId,
            'content_type = ? AND language = ?',
            [$contentType, $language]
        );
    }

    /**
     * Block page URLs that would be shadowed by a Custom Post Type archive,
     * a global routing prefix (blog, shop, ...) or a reserved system route.
     * Only the full public path matters: a page at '/pages/test' does NOT
     * collide with a CPT at '/test'.
     */
    private function assertPagePathAvailable(Post $post, Slug $slug, ?int $parentId, ?int $excludeId): void
    {
        if ($this->routing === null || $post->getContentType() !== 'page') {
            return;
        }
        if ($post->getTranslationOf() !== null) {
            return;
        }

        $segments = [(string) $slug];
        if ($parentId !== null) {
            $parent = $this->posts->findById($parentId);
            if ($parent !== null) {
                array_unshift($segments, $this->posts->buildPagePath($parent));
            }
        }

        $pagePrefix = $this->settings->get('routing_page_prefix', '');
        $pagePrefix = $pagePrefix ? ('/' . ltrim($pagePrefix, '/')) : '';
        $fullPath   = $pagePrefix . '/' . implode('/', $segments);

        if ($error = $this->routing->getPagePathConflictError($fullPath, $excludeId)) {
            throw ValidationException::withMessages(['slug' => $this->friendlyPagePathError($fullPath, $error)]);
        }
    }

    /**
     * Rephrase validator errors in page terms: admins set a page slug, so
     * messages must name the page URL — never internal setting keys like
     * routing_reset_password_prefix.
     */
    private function friendlyPagePathError(string $fullPath, string $validatorError): string
    {
        if (preg_match('/another routing setting \((routing_[A-Za-z0-9_]+_prefix)\)/', $validatorError, $m) === 1) {
            $name = ucwords(str_replace('_', ' ', (string) preg_replace(['/^routing_/', '/_prefix$/'], '', $m[1])));
            return sprintf('The page URL "%s" conflicts with the "%s" section of the site. Please choose a different slug.', $fullPath, $name);
        }
        if (preg_match('/content type "([^"]+)"/', $validatorError, $m) === 1) {
            return sprintf('The page URL "%s" conflicts with the "%s" content type. Please choose a different slug.', $fullPath, $m[1]);
        }
        if (str_contains($validatorError, 'language code')) {
            return sprintf('The page URL "%s" starts with a reserved language code. Please choose a different slug.', $fullPath);
        }
        return sprintf('The page URL "%s" is reserved for system use. Please choose a different slug.', $fullPath);
    }

    /** @param array<string, mixed> $data */
    private function effectiveParentId(array $data, ?int $fallback): ?int
    {
        if (!array_key_exists('parent_id', $data)) {
            return $fallback;
        }
        $parentId = $data['parent_id'];
        return $parentId !== '' && $parentId !== null ? (int) $parentId : null;
    }

    /**
     * Clamp over-long template names to 'standard' so saves never hit the
     * page_template column limit (dropdown values come from theme file names
     * and are otherwise unvalidated).
     */
    private function normalizePageTemplate(mixed $template): string
    {
        if (!is_string($template) || mb_strlen($template) > Post::MAX_PAGE_TEMPLATE_LENGTH) {
            return 'standard';
        }
        return $template;
    }

    private function validate(array $data): void
    {
        $errors = [];
        if (empty($data['title'])) {
            $errors['title'] = 'Title is required.';
        }
        if (empty($data['author_id'])) {
            $errors['author_id'] = 'Author is required.';
        }
        // A publish date in an unexpected format can never be picked up by
        // CronService, so reject it up front. It is only required for a
        // direct status='scheduled' (legacy/API path); the form's schedule
        // checkbox also accepts an expiry-only state with an empty date.
        $rawPublishedAt = is_string($data['published_at'] ?? null) ? trim($data['published_at']) : '';
        if ($rawPublishedAt !== '' && \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $rawPublishedAt) === false) {
            $errors['published_at'] = 'Publish date & time is not a valid date.';
        } elseif (($data['status'] ?? 'draft') === 'scheduled' && $rawPublishedAt === '') {
            $errors['published_at'] = 'Publish date & time is required when the status is Scheduled.';
        }
        if (!empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }
}
