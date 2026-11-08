<?php

declare(strict_types=1);

namespace ValeApp\Domain\Content;

use ValeApp\Domain\Content\Repository\ContentTypeRepositoryInterface;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Pagination;

final class RoutingValidator
{
    private const RESERVED_PREFIXES = [
        '/admin', '/api',
        '/pay', '/downloads', '/form', '/newsletter',
        '/webhooks', '/cron', '/preview', '/sitemap.xml', '/robots.txt', '/ai-robots.txt',
    ];

    /** @var list<array{id: int|null, title: string, path: string}>|null */
    private ?array $pagePathCache = null;

    public function __construct(
        private readonly SettingsRepositoryInterface $settings,
        private readonly ContentTypeRepositoryInterface $contentTypes,
        private readonly PostRepositoryInterface $posts,
        private readonly array $supportedLocales = [],
    ) {
    }

    /**
     * Check if a prefix conflicts with system routes or other configured prefixes.
     *
     * Compares full public paths (not bare slugs): a Custom Post Type with
     * route_prefix "/d" and slug "test" lives at "/d/test" and does NOT
     * collide with a page at "/test". Locale URL prefixes ("/en/...") are
     * stripped before routing, so they never disambiguate a collision.
     *
     * @param string $prefix The prefix to check (e.g., '/blog')
     * @param string|null $contextKey If checking a global setting, its key (e.g., 'routing_blog_prefix')
     * @param int|null $contentTypeId If checking a Custom Post Type, its ID
     * @param array<string, string> $settingsOverlay Pending (not yet saved) setting values,
     *        so multi-key edits (e.g. swapping blog/shop prefixes) validate atomically
     * @return string|null Error message if conflict found, null otherwise
     */
    public function getConflictError(string $prefix, ?string $contextKey = null, ?int $contentTypeId = null, array $settingsOverlay = []): ?string
    {
        $prefix = '/' . ltrim(trim($prefix), '/');
        if ($prefix === '/') {
            // Page URLs may intentionally live at the site root.
            if ($contextKey === 'routing_page_prefix') {
                return null;
            }
            return 'Prefix cannot be empty.';
        }

        if ($error = $this->conflictsWithReserved($prefix)) {
            return $error;
        }

        if ($error = $this->conflictsWithLocalePrefix($prefix, $settingsOverlay)) {
            return $error;
        }

        if ($error = $this->conflictsWithGlobals($prefix, $contextKey, $settingsOverlay)) {
            return $error;
        }

        // Check against Pages (full public paths: page prefix + nested parent/child chain)
        foreach ($this->allPagePaths($settingsOverlay) as $pagePath) {
            if (self::pathsOverlap($prefix, $pagePath['path'])) {
                return sprintf('The prefix "%s" conflicts with the existing page "%s".', $prefix, $pagePath['path']);
            }
        }

        if ($error = $this->conflictsWithContentTypes($prefix, $contentTypeId)) {
            return $error;
        }

        return null;
    }

    /**
     * Check a page's full public URL path (page prefix + nested path) against
     * everything routable: reserved system routes, global routing settings
     * (blog, shop, ...) and Custom Post Type base paths.
     *
     * The pages table itself is intentionally NOT consulted — the caller owns
     * page-slug uniqueness and passes its own resolved path.
     *
     * @param string $fullPagePath Full public path, e.g. '/test' or '/pages/test'
     */
    public function getPagePathConflictError(string $fullPagePath, ?int $excludePostId = null): ?string
    {
        $fullPagePath = '/' . ltrim(trim($fullPagePath), '/');
        if ($fullPagePath === '/') {
            return null;
        }

        if ($error = $this->conflictsWithReserved($fullPagePath)) {
            return $error;
        }

        if ($error = $this->conflictsWithLocalePrefix($fullPagePath)) {
            return $error;
        }

        // Skip the page-prefix setting itself: the path under test already
        // contains it (e.g. '/pages/test' trivially starts with '/pages').
        if ($error = $this->conflictsWithGlobals($fullPagePath, 'routing_page_prefix')) {
            return $error;
        }

        if ($error = $this->conflictsWithContentTypes($fullPagePath, null)) {
            return $error;
        }

        return null;
    }

    /**
     * Scan all routable content for collisions that slipped in before
     * validation existed (legacy data, imports). Also flags stored routing
     * prefixes that are empty/root (except the page prefix, which may
     * intentionally live at the root) or duplicated across settings —
     * either of which breaks route registration at runtime.
     *
     * @return list<array{scope: string, name: string, path: string, error: string}>
     */
    public function findAllConflicts(): array
    {
        $conflicts = [];

        foreach ($this->storedPrefixProblems() as $problem) {
            $conflicts[] = $problem;
        }

        foreach ($this->contentTypes->findActiveCustom() as $type) {
            $basePath = $type->getPublicBasePath();
            $error    = $this->getConflictError($basePath, null, $type->getId());
            if ($error !== null) {
                $conflicts[] = [
                    'scope' => 'content-type',
                    'name'  => $type->getName(),
                    'path'  => $basePath,
                    'error' => $error,
                ];
            }
        }

        foreach ($this->allPagePaths() as $pagePath) {
            $error = $this->getPagePathConflictError($pagePath['path'], $pagePath['id']);
            if ($error !== null) {
                $conflicts[] = [
                    'scope' => 'page',
                    'name'  => $pagePath['title'],
                    'path'  => $pagePath['path'],
                    'error' => $error,
                ];
            }
        }

        return $conflicts;
    }

    /**
     * Stored routing settings that can never produce working routes:
     * empty/root values (page prefix excepted) and duplicates.
     *
     * @return list<array{scope: string, name: string, path: string, error: string}>
     */
    private function storedPrefixProblems(): array
    {
        $problems = [];
        $prefixes = $this->globalPrefixes();
        $byValue  = [];

        foreach (RoutingPrefixTypes::ALL_TYPES as $type) {
            $key = 'routing_' . $type . '_prefix';
            $val = $prefixes[$key] ?? null;
            if ($type === 'page') {
                continue;
            }
            if ($val === null || trim((string) $val) === '' || trim((string) $val) === '/') {
                $problems[] = [
                    'scope' => 'setting',
                    'name'  => $key,
                    'path'  => (string) $val,
                    'error' => sprintf('The prefix "%s" is empty, which breaks route registration.', $key),
                ];
            }
        }

        foreach ($prefixes as $key => $val) {
            $normalized = '/' . ltrim(trim((string) $val), '/');
            if ($normalized === '/') {
                continue;
            }
            $byValue[$normalized][] = $key;
        }

        foreach ($byValue as $value => $keys) {
            if (count($keys) < 2) {
                continue;
            }
            // Localized variants agreeing with their own base setting (e.g.
            // routing_blog_prefix_tr === routing_blog_prefix) are harmless.
            $bases = [];
            foreach ($keys as $k) {
                $bases[] = $this->stripLocaleSuffix($k);
            }
            if (count(array_unique($bases)) < 2) {
                continue;
            }
            $problems[] = [
                'scope' => 'setting',
                'name'  => implode(', ', $keys),
                'path'  => $value,
                'error' => sprintf('The prefix "%s" is used by multiple routing settings (%s).', $value, implode(', ', $keys)),
            ];
        }

        return $problems;
    }

    private function stripLocaleSuffix(string $key): string
    {
        foreach ($this->supportedLocales as $lang) {
            $suffix = '_' . $lang;
            if (str_ends_with($key, $suffix)) {
                return substr($key, 0, -strlen($suffix));
            }
        }

        return $key;
    }

    private function conflictsWithReserved(string $prefix): ?string
    {
        foreach (self::RESERVED_PREFIXES as $reserved) {
            if (self::pathsOverlap($prefix, $reserved)) {
                return sprintf('The prefix "%s" conflicts with a system route.', $prefix);
            }
        }

        return null;
    }

    /**
     * A first URL segment matching a supported locale code (e.g. '/en') is
     * stripped by LocaleMiddleware before routing whenever multi-language
     * content is enabled — so no prefix or page path may start with one.
     * Only enforced while multi-language content is on; single-language
     * sites route '/en' like any other segment.
     *
     * @param array<string, string> $settingsOverlay
     */
    private function conflictsWithLocalePrefix(string $prefix, array $settingsOverlay = []): ?string
    {
        if ($this->setting('multi_language_content', '0', $settingsOverlay) !== '1') {
            return null;
        }

        $segments = explode('/', ltrim($prefix, '/'));
        $first    = strtolower(trim($segments[0] ?? ''));
        foreach ($this->supportedLocales as $locale) {
            if ($first !== '' && $first === strtolower(trim($locale))) {
                return sprintf('The prefix "%s" conflicts with a language code.', $prefix);
            }
        }

        return null;
    }

    /**
     * Everything that would break the moment multi-language content is
     * switched on: stored prefixes, CPT base paths and page paths whose
     * first segment is a supported locale code.
     *
     * @param array<string, string> $settingsOverlay Pending values (must contain multi_language_content=1)
     * @return list<string> Error messages
     */
    public function findLocaleConflictsOnEnable(array $settingsOverlay = []): array
    {
        $overlay = array_merge($settingsOverlay, ['multi_language_content' => '1']);
        $errors  = [];

        foreach ($this->globalPrefixes($overlay) as $key => $val) {
            $candidate = '/' . ltrim((string) $val, '/');
            if ($candidate === '/') {
                continue;
            }
            if ($error = $this->conflictsWithLocalePrefix($candidate, $overlay)) {
                $errors[] = $error . " ({$key})";
            }
        }

        foreach ($this->contentTypes->findActiveCustom() as $type) {
            $basePath = $type->getPublicBasePath();
            if ($error = $this->conflictsWithLocalePrefix($basePath, $overlay)) {
                $errors[] = $error . " (content type \"{$type->getName()}\")";
            }
        }

        foreach ($this->allPagePaths($overlay) as $pagePath) {
            if ($error = $this->conflictsWithLocalePrefix($pagePath['path'], $overlay)) {
                $errors[] = $error . " (page \"{$pagePath['title']}\")";
            }
        }

        return $errors;
    }

    /** @param array<string, string> $settingsOverlay */
    private function conflictsWithGlobals(string $prefix, ?string $contextKey, array $settingsOverlay = []): ?string
    {
        foreach ($this->globalPrefixes($settingsOverlay) as $key => $val) {
            if ($contextKey === $key || $val === '' || $val === '/') {
                continue;
            }
            $val = '/' . ltrim((string) $val, '/');
            if (self::pathsOverlap($prefix, $val)) {
                return sprintf('The prefix "%s" conflicts with another routing setting (%s).', $prefix, $key);
            }
        }

        return null;
    }

    private function conflictsWithContentTypes(string $prefix, ?int $contentTypeId): ?string
    {
        $types = $this->contentTypes->findAll();
        foreach ($types as $type) {
            if ($contentTypeId !== null && $type->getId() === $contentTypeId) {
                continue;
            }

            $typePrefix = $type->getPublicBasePath();
            if (self::pathsOverlap($prefix, $typePrefix)) {
                return sprintf('The prefix "%s" conflicts with the content type "%s".', $prefix, $type->getName());
            }
        }

        return null;
    }

    /** @param array<string, string> $settingsOverlay @return array<string, string> setting key => prefix value */
    private function globalPrefixes(array $settingsOverlay = []): array
    {
        $prefixTypes    = RoutingPrefixTypes::ALL_TYPES;
        $globalPrefixes = [];

        foreach ($prefixTypes as $type) {
            $key                  = 'routing_' . $type . '_prefix';
            $globalPrefixes[$key] = $this->setting($key, RoutingPrefixTypes::DEFAULT_PREFIXES[$type] ?? '/' . $type, $settingsOverlay);

            foreach ($this->supportedLocales as $lang) {
                $langKey = $key . '_' . $lang;
                $val     = $this->setting($langKey, null, $settingsOverlay);
                if ($val !== null && $val !== '' && $val !== '/') {
                    $globalPrefixes[$langKey] = $val;
                }
            }
        }

        return $globalPrefixes;
    }

    /** @param array<string, string> $settingsOverlay */
    private function setting(string $key, ?string $default, array $settingsOverlay = []): ?string
    {
        if (array_key_exists($key, $settingsOverlay)) {
            return $settingsOverlay[$key];
        }

        return $this->settings->get($key, $default);
    }

    /** @param array<string, string> $settingsOverlay */
    private function pageRootPrefix(array $settingsOverlay = []): string
    {
        $rootPrefix = $this->setting('routing_page_prefix', '', $settingsOverlay);
        return $rootPrefix ? ('/' . ltrim($rootPrefix, '/')) : '';
    }

    /**
     * All non-trash pages as full public paths (page prefix + nested chain).
     * Memoized per request: route registration calls this once per CPT.
     * The memo is bypassed when a settings overlay is given (pending values).
     *
     * @param array<string, string> $settingsOverlay
     * @return list<array{id: int|null, title: string, path: string}>
     */
    private function allPagePaths(array $settingsOverlay = []): array
    {
        if ($settingsOverlay === [] && $this->pagePathCache !== null) {
            return $this->pagePathCache;
        }

        $rootPrefix = $this->pageRootPrefix($settingsOverlay);
        $paths      = [];
        $page       = 1;

        do {
            $rows = $this->posts->findAll(new Pagination($page, 200), ['content_type' => 'page']);
            foreach ($rows as $row) {
                if ($row->getStatus() === 'trash') {
                    continue;
                }
                $paths[] = [
                    'id'    => $row->getId(),
                    'title' => $row->getTitle(),
                    'path'  => $rootPrefix . '/' . $this->posts->buildPagePath($row),
                ];
            }
            $page++;
        } while (count($rows) === 200);

        if ($settingsOverlay === []) {
            $this->pagePathCache = $paths;
        }

        return $paths;
    }

    private static function pathsOverlap(string $a, string $b): bool
    {
        return $a === $b || str_starts_with($a, $b . '/') || str_starts_with($b, $a . '/');
    }
}
