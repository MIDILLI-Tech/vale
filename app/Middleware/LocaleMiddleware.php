<?php

declare(strict_types=1);

namespace ValeApp\App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use Slim\Views\Twig;
use Twig\Environment;
use ValeApp\Domain\Content\Repository\CategoryRepositoryInterface;
use ValeApp\Domain\Content\Repository\ContentTypeRepositoryInterface;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Content\RoutingPrefixTypes;
use ValeApp\Domain\Menu\Repository\MenuRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;
use ValeApp\Domain\User\Repository\UserRepositoryInterface;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Theme\ThemeManager;
use ValeApp\Infrastructure\Translation\Translator;

/**
 * Locale detection middleware.
 *
 * Detection order:
 * 1. URL query param: ?lang=tr (only if user_language_choice is enabled)
 * 2. Cookie: vl_locale (only if user_language_choice is enabled)
 * 3. Accept-Language header
 * 4. Config default
 */
final class LocaleMiddleware implements MiddlewareInterface
{
    private const COOKIE_NAME = 'vl_locale';

    public function __construct(
        private readonly Translator $translator,
        private readonly DbalSettingsRepository $settings,
        private readonly SessionManager $session,
        private readonly Twig $twig,
        private readonly MenuRepositoryInterface $menus,
        private readonly ThemeManager $themeManager,
        private readonly UserRepositoryInterface $users,
        private readonly PostRepositoryInterface $posts,
        private readonly ?CategoryRepositoryInterface $categories = null,
        private readonly ?ProductRepositoryInterface $products = null,
        private readonly ?ContentTypeRepositoryInterface $contentTypes = null,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $supported = array_values(array_map(
            static fn (string $locale): string => strtolower(trim($locale)),
            $this->translator->getSupportedLocales(),
        ));
        $params    = $request->getQueryParams();
        $fullPath  = $request->getUri()->getPath();
        $locale    = null;

        // Keep the visitor-facing path: the rewrites below strip the locale
        // and localized prefixes for internal routing, but canonical URLs,
        // og:url and JSON-LD must reflect what the visitor actually requested.
        $request   = $request->withAttribute('vl_requested_path', $fullPath);
        $langParam = isset($params['lang']) ? strtolower(trim((string) $params['lang'])) : null;

        // Ensure session is started for persistence logic
        $this->session->start();

        // Immediately persist user locale preference if passed via query param
        if ($langParam !== null && in_array($langParam, $supported, true)) {
            $this->session->set('user_locale', $langParam);

            $userId = $this->session->get('user_id');
            if ($userId !== null) {
                $user = $this->users->findById((int) $userId);
                if ($user !== null && $user->getLocale() !== $langParam) {
                    $user->setLocale($langParam);
                    $this->users->save($user);
                }
            }
        }

        // Normalize path by removing base path if present. The match must end
        // on a segment boundary: with a '/vale' install, '/vale-old-page' is a
        // page of its own, not '/old-page' behind the base path.
        $basePath = __app_base_path();
        $path     = $fullPath;
        if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
            $path = substr($path, strlen($basePath));
        }
        if ($path === '') {
            $path = '/';
        }

        // 0) URL Path Prefix (highest priority for Multi-Language Content)
        // Check if the first segment is a supported locale (e.g., /tr/blog-post)
        if ($this->settings->get('multi_language_content', '0') === '1') {
            $segments = explode('/', ltrim($path, '/'));
            $firstRaw = $segments[0] ?? '';
            $first    = strtolower(trim($firstRaw));
            if ($first !== '' && in_array($first, $supported, true)) {
                $locale = $first;

                // Path after locale
                $pathWithoutLocale = '/' . implode('/', array_slice($segments, 1));
                if ($pathWithoutLocale === '') {
                    $pathWithoutLocale = '/';
                }

                $defaultLocale = $this->settings->get('default_locale', 'en');

                // Logic for localized routing prefixes
                if ($locale !== $defaultLocale) {
                    $prefixTypes = RoutingPrefixTypes::ALL_TYPES;
                    $rewritten   = false;

                    // Rewrite combined localized membership checkout path first.
                    $globalMembership = '/' . ltrim((string) $this->settings->get('routing_membership_prefix', '/membership'), '/');
                    $globalCheckout   = '/' . ltrim((string) $this->settings->get('routing_checkout_prefix', '/checkout'), '/');
                    $localMembership  = $this->settings->get('routing_membership_prefix_' . $locale);
                    $localCheckout    = $this->settings->get('routing_checkout_prefix_' . $locale);

                    $globalPrefixesSet = $globalMembership !== '/'   && $globalCheckout !== '/';
                    $localPrefixesSet  = is_string($localMembership) && is_string($localCheckout)
                                                                     && $localMembership !== '' && $localMembership !== '/'
                                                                     && $localCheckout   !== '' && $localCheckout !== '/';

                    if ($globalPrefixesSet && $localPrefixesSet) {
                        $localComposite  = rtrim('/' . ltrim($localMembership, '/'), '/') . '/' . ltrim($localCheckout, '/');
                        $globalComposite = rtrim($globalMembership, '/') . $globalCheckout;

                        if ($pathWithoutLocale === $localComposite || str_starts_with($pathWithoutLocale, $localComposite . '/')) {
                            $suffix            = substr($pathWithoutLocale, strlen($localComposite));
                            $pathWithoutLocale = $globalComposite . $suffix;
                            if ($pathWithoutLocale === '') {
                                $pathWithoutLocale = '/';
                            }
                            $rewritten = true;
                        }
                    }

                    foreach ($prefixTypes as $type) {
                        if ($rewritten) {
                            break;
                        }
                        $globalPrefixKey = 'routing_' . $type . '_prefix';
                        $globalPrefix    = $this->settings->get($globalPrefixKey, ($type === 'page' ? '' : '/' . $type));
                        $globalPrefix    = '/' . ltrim((string) $globalPrefix, '/');

                        $localPrefixKey = $globalPrefixKey . '_' . $locale;
                        $localPrefix    = $this->settings->get($localPrefixKey);

                        if ($localPrefix !== null && $localPrefix !== '' && $localPrefix !== '/') {
                            $localPrefix = '/' . ltrim($localPrefix, '/');

                            // If we matched the localized prefix
                            if ($pathWithoutLocale === $localPrefix || str_starts_with($pathWithoutLocale, $localPrefix . '/')) {
                                $suffix = substr($pathWithoutLocale, strlen($localPrefix));
                                // Rewrite pathWithoutLocale to use global prefix for internal routing
                                $pathWithoutLocale = '/' . ltrim($globalPrefix . $suffix, '/');
                                if ($pathWithoutLocale === '') {
                                    $pathWithoutLocale = '/';
                                }
                                $rewritten = true;
                                break;
                            }
                        }
                    }

                    // Redirection logic: canonicalize CONTENT prefixes used in
                    // the wrong language. AUTH prefixes (login/register/...)
                    // are intentionally excluded — site_url() generates them
                    // without localized rewriting, so a 301 here would loop
                    // forever and strip query strings like ?token=...
                    if (!$rewritten) {
                        foreach (RoutingPrefixTypes::CONTENT_TYPES as $type) {
                            $globalPrefixKey = 'routing_' . $type . '_prefix';
                            $globalPrefix    = $this->settings->get($globalPrefixKey, ($type === 'page' ? '' : '/' . $type));
                            $globalPrefix    = '/' . ltrim((string) $globalPrefix, '/');

                            if ($globalPrefix !== '/' && ($pathWithoutLocale === $globalPrefix || str_starts_with($pathWithoutLocale, $globalPrefix . '/'))) {
                                // Check if this language HAS a localized version of this prefix
                                $localPrefixKey = $globalPrefixKey . '_' . $locale;
                                $localPrefix    = $this->settings->get($localPrefixKey);

                                if ($localPrefix !== null && $localPrefix !== '' && $localPrefix !== '/') {
                                    $localPrefix = '/' . ltrim($localPrefix, '/');
                                    $suffix      = substr($pathWithoutLocale, strlen($globalPrefix));
                                    $newPath     = site_url($globalPrefix . $suffix, [], ['lang' => $locale]);
                                    $origQuery   = $request->getUri()->getQuery();
                                    if ($origQuery !== '') {
                                        $newPath .= (str_contains($newPath, '?') ? '&' : '?') . $origQuery;
                                    }
                                    return $this->withLocaleCookie(
                                        (new Response(301))->withHeader('Location', $newPath),
                                        $langParam
                                    );
                                }
                            }
                        }
                    }
                }

                // Strip the locale (and possibly rewritten prefix) from path for routing
                $request = $request->withUri($request->getUri()->withPath($pathWithoutLocale));
            }
        }

        // Admin UI uses the configured admin language. The public-site visitor
        // language cookie (vl_locale) must not override the admin setting.
        if (str_starts_with($path, '/admin')) {
            // The interface language follows the available UI translations, not
            // the content languages a site serves to visitors.
            $locale = $this->resolveAdminLocale($langParam, $this->translator->getUiLocales());
        } else {
            $multiLang = $this->settings->get('multi_language_content', '0') === '1';

            // 1) Query param (highest priority, only when multi-language content is enabled)
            if ($multiLang && $langParam !== null && in_array($langParam, $supported, true)) {
                $locale        = $langParam;
                $defaultLocale = $this->settings->get('default_locale', 'en');

                // Strip locale prefix from path for localized-prefix detection
                $segments          = explode('/', ltrim($path, '/'));
                $firstSegment      = strtolower(trim($segments[0] ?? ''));
                $pathWithoutLocale = $path;
                if (in_array($firstSegment, $supported, true)) {
                    $pathWithoutLocale = '/' . implode('/', array_slice($segments, 1));
                    if ($pathWithoutLocale === '') {
                        $pathWithoutLocale = '/';
                    }
                }

                // If the path is a localized prefix (e.g. /giris for Turkish login),
                // redirect to the equivalent path for the target locale.
                if ($pathWithoutLocale !== '/') {
                    $prefixTypes = RoutingPrefixTypes::ALL_TYPES;

                    foreach ($prefixTypes as $type) {
                        $globalPrefixKey = 'routing_' . $type . '_prefix';
                        $globalPrefix    = '/' . ltrim((string) $this->settings->get($globalPrefixKey, ($type === 'page' ? '' : '/' . $type)), '/');

                        foreach ($supported as $candidateLocale) {
                            $localPrefixKey = $globalPrefixKey . '_' . $candidateLocale;
                            $localPrefix    = $this->settings->get($localPrefixKey);

                            if ($localPrefix !== null && $localPrefix !== '' && $localPrefix !== '/') {
                                $localPrefix = '/' . ltrim($localPrefix, '/');

                                if ($pathWithoutLocale === $localPrefix || str_starts_with($pathWithoutLocale, $localPrefix . '/')) {
                                    $suffix = substr($pathWithoutLocale, strlen($localPrefix));

                                    // Get the target locale's equivalent prefix
                                    $targetLocalKey = $globalPrefixKey . '_' . $langParam;
                                    $targetLocal    = $this->settings->get($targetLocalKey);

                                    if ($targetLocal !== null && $targetLocal !== '' && $targetLocal !== '/') {
                                        $targetPath = '/' . ltrim($targetLocal, '/') . $suffix;
                                    } else {
                                        $targetPath = $globalPrefix . $suffix;
                                    }

                                    // Preserve query string (verification tokens,
                                    // reset tokens, redirect targets, ...).
                                    $targetUrl = site_url($targetPath, [], ['lang' => $langParam]);
                                    $origQuery = $request->getUri()->getQuery();
                                    if ($origQuery !== '') {
                                        $targetUrl .= (str_contains($targetUrl, '?') ? '&' : '?') . $origQuery;
                                    }

                                    return $this->withLocaleCookie(
                                        (new Response(302))->withHeader(
                                            'Location',
                                            $targetUrl,
                                        ),
                                        $langParam
                                    );
                                }
                            }
                        }
                    }
                }

                // Redirect to localized URL if path doesn't already carry the locale prefix
                if ($locale !== $defaultLocale) {
                    if ($firstSegment !== $locale) {
                        $localizedUrl = site_url($path, [], ['lang' => $locale]);
                        $origQuery    = $request->getUri()->getQuery();
                        if ($origQuery !== '') {
                            $localizedUrl .= (str_contains($localizedUrl, '?') ? '&' : '?') . $origQuery;
                        }
                        return $this->withLocaleCookie(
                            (new Response(302))->withHeader('Location', $localizedUrl),
                            $langParam
                        );
                    }
                }
            }

            // 2) Logged-in user's preference (if saved in profile)
            if ($locale === null && $this->session->has('user_locale')) {
                $userLocale = strtolower(trim((string) $this->session->get('user_locale')));
                if (in_array($userLocale, $supported, true)) {
                    $locale = $userLocale;
                }
            }

            // 3) Cookie (only when multi-language content is enabled)
            if ($multiLang && $locale === null) {
                $cookies = $request->getCookieParams();
                if (isset($cookies[self::COOKIE_NAME])) {
                    $cookieLocale = strtolower(trim((string) $cookies[self::COOKIE_NAME]));
                    if (in_array($cookieLocale, $supported, true)) {
                        $locale = $cookieLocale;
                    }
                }
            }

            // 4) Accept-Language header
            if ($locale === null && $multiLang) {
                $accept = $request->getHeaderLine('Accept-Language');
                $locale = $this->parseAcceptLanguage($accept, $supported);
            }
        }

        if ($locale !== null) {
            $this->translator->setLocale($locale);
            $request = $request->withAttribute('locale', $locale);
            try {
                $this->setGlobalSafe($this->twig->getEnvironment(), 'current_locale', $locale);
            } catch (\LogicException) {
                // Twig runtime frozen (long-lived process) — skip.
            }
        }

        $resolvedLocale = $locale ?? $this->translator->getLocale();
        $this->injectLocalizedSiteGlobals($resolvedLocale);

        // Reload menus with the current locale (handles multi-language filtering)
        $this->injectMenus($resolvedLocale);

        $response = $handler->handle($request);

        if ($langParam !== null && in_array($langParam, $supported, true)) {
            $response = $this->withLocaleCookie($response, $langParam);
        }

        return $response;
    }

    private function withLocaleCookie(ResponseInterface $response, ?string $locale): ResponseInterface
    {
        if ($locale === null) {
            return $response;
        }

        return $response->withHeader(
            'Set-Cookie',
            self::COOKIE_NAME . '=' . $locale . '; Path=/; Max-Age=31536000; SameSite=Lax'
        );
    }

    /**
     * Resolve admin locale from an explicit query override or the admin setting.
     *
     * @param string[] $supported
     */
    private function resolveAdminLocale(?string $queryLocale, array $supported): string
    {
        $normalizedQueryLocale = $queryLocale !== null ? strtolower(trim($queryLocale)) : null;
        if ($normalizedQueryLocale !== null && in_array($normalizedQueryLocale, $supported, true)) {
            return $normalizedQueryLocale;
        }

        $locale = strtolower(trim((string) ($this->settings->get('locale', $this->settings->get('default_locale', 'en')) ?? 'en')));
        return in_array($locale, $supported, true) ? $locale : 'en';
    }

    private function injectLocalizedSiteGlobals(string $locale): void
    {
        $env            = $this->twig->getEnvironment();
        $appConfig      = require dirname(__DIR__, 2) . '/config/app.php';
        $defaultName    = is_string($appConfig['name'] ?? null) ? $appConfig['name'] : 'Vale';
        $localizedName  = $this->settings->getLocalized('site_name', $locale, $defaultName);

        $this->setGlobalSafe($env, 'site_name', $localizedName ?? $defaultName);
        $this->setGlobalSafe($env, 'site_tagline', $this->settings->getLocalized('site_tagline', $locale, '') ?? '');
        $this->setGlobalSafe($env, 'footer_copyright', $this->settings->getLocalized('footer_copyright', $locale, '') ?? '');
        $this->setGlobalSafe($env, 'cookie_warning_text', $this->settings->getLocalized('cookie_warning_text', $locale, '') ?? '');
    }

    private function injectMenus(string $locale): void
    {
        $multiLang   = $this->settings->get('multi_language_content', '0') === '1';
        $contentLang = $multiLang ? $locale : null;

        $definedMenus = $this->themeManager->getDefinedMenus();

        $locationsToInject = array_unique(array_merge(
            array_keys($definedMenus),
            ['primary', 'footer', 'sidebar']
        ));

        foreach ($locationsToInject as $location) {
            $menus = $this->menus->findAllByLocation($location, $contentLang);

            if (empty($menus) && $contentLang !== null) {
                $defaultLocale = $this->settings->get('default_locale', 'en');
                if ($contentLang !== $defaultLocale) {
                    $menus = $this->menus->findAllByLocation($location, $defaultLocale);
                }
            }

            $allItems       = [];
            $groups         = [];
            $pagePrefix     = (string) $this->settings->get('routing_page_prefix', '');
            $pagePrefix     = $pagePrefix ? ('/' . ltrim($pagePrefix, '/')) : '';
            $blogPrefix     = '/' . ltrim((string) $this->settings->get('routing_blog_prefix', '/blog'), '/');
            $categoryPrefix = '/' . ltrim((string) $this->settings->get('routing_category_prefix', '/category'), '/');
            $shopPrefix     = '/' . ltrim((string) $this->settings->get('routing_shop_prefix', '/shop'), '/');
            $memberPrefix   = '/' . ltrim((string) $this->settings->get('routing_membership_prefix', '/membership'), '/');
            $shopEnabled    = ($this->settings->get('shop_enabled', '1') ?? '1')          === '1';
            $subsEnabled    = ($this->settings->get('subscriptions_enabled', '1') ?? '1') === '1';

            foreach ($menus as $navMenu) {
                $items = $this->menus->findItemsByMenuId($navMenu->getId());

                // Hide items whose feature is disabled (independent of stored URL)
                $items = array_values(array_filter($items, function ($item) use ($shopEnabled, $subsEnabled): bool {
                    return match ($item->getType()) {
                        'shop'       => $shopEnabled,
                        'membership' => $subsEnabled,
                        default      => true,
                    };
                }));

                // Resolve content paths dynamically and localize menu item URLs if relative
                $validItems = [];
                foreach ($items as $item) {
                    $type  = $item->getType();
                    $refId = $item->getReferenceId();

                    if ($type === 'shop') {
                        $item->setUrl(site_url($shopPrefix));
                    } elseif ($type === 'membership') {
                        $item->setUrl(site_url($memberPrefix));
                    } elseif ($type === 'blog') {
                        $item->setUrl(site_url($blogPrefix));
                    } elseif ($type === 'home') {
                        $item->setUrl(site_url('/'));
                    } elseif ($refId !== null) {
                        $isValid = false;
                        if ($type === 'page') {
                            $page = $this->posts->findById($refId);
                            if ($page !== null && $page->getStatus() !== 'trash') {
                                $item->setUrl(site_url($pagePrefix . '/') . $this->posts->buildPagePath($page));
                                $isValid = true;
                            }
                        } elseif ($type === 'post') {
                            $p = $this->posts->findById($refId);
                            if ($p !== null && $p->getStatus() !== 'trash') {
                                $item->setUrl(site_url($blogPrefix . '/') . $p->getSlug());
                                $isValid = true;
                            }
                        } elseif ($type === 'category' && $this->categories !== null) {
                            $c = $this->categories->findById($refId);
                            if ($c !== null) {
                                $item->setUrl(site_url($categoryPrefix . '/') . $c->getSlug());
                                $isValid = true;
                            }
                        } elseif ($type === 'product' && $this->products !== null) {
                            $prod = $this->products->findById($refId);
                            if ($prod !== null && $prod->getStatus() !== 'trash') {
                                $item->setUrl(site_url($shopPrefix . '/') . $prod->getSlug());
                                $isValid = true;
                            }
                        } else {
                            $cpt = $this->posts->findById($refId);
                            if ($cpt !== null && $cpt->getStatus() !== 'trash') {
                                $cptBase = '/' . $type;
                                if ($this->contentTypes !== null) {
                                    try {
                                        $ctObj = $this->contentTypes->findBySlug(new Slug($type));
                                        if ($ctObj !== null) {
                                            $cptBase = $ctObj->getPublicBasePath();
                                        }
                                    } catch (\Throwable) {
                                    }
                                }
                                $item->setUrl(site_url('/' . trim($cptBase, '/') . '/') . $cpt->getSlug());
                                $isValid = true;
                            }
                        }

                        if (!$isValid) {
                            continue;
                        }
                    }

                    $itemUrl = $item->getUrl();
                    if ($itemUrl !== null && str_starts_with($itemUrl, '/') && !str_starts_with($itemUrl, '//')) {
                        $item->setUrl(url($itemUrl, [], ['lang' => $locale]));
                    }

                    $validItems[] = $item;
                }

                $allItems = array_merge($allItems, $validItems);
                $groups[] = ['name' => $navMenu->getName(), 'items' => $validItems];
            }
            $env = $this->twig->getEnvironment();
            $this->setGlobalSafe($env, 'menu_' . $location, $allItems);
            $this->setGlobalSafe($env, 'menu_' . $location . '_groups', $groups);
        }
    }

    /**
     * Parse Accept-Language header and return best matching locale.
     *
     * @param string[] $supported
     */

    /**
     * Register a Twig global safely. The Twig runtime freezes after the first
     * render in long-lived processes (test harnesses, workers); swallow the
     * LogicException there instead of failing the request.
     */
    private function setGlobalSafe(Environment $env, string $name, mixed $value): void
    {
        try {
            $env->addGlobal($name, $value);
        } catch (\LogicException) {
        }
    }

    private function parseAcceptLanguage(string $header, array $supported): ?string
    {
        if ($header === '') {
            return null;
        }

        // Parse "en-US,en;q=0.9,tr;q=0.8" format
        $parts  = explode(',', $header);
        $ranked = [];

        foreach ($parts as $part) {
            $segments = explode(';q=', trim($part));
            $lang     = strtolower(trim($segments[0]));
            $quality  = isset($segments[1]) ? (float) $segments[1] : 1.0;

            // Normalize: "en-US" -> "en"
            $short = explode('-', $lang)[0];
            if (in_array($short, $supported, true)) {
                $ranked[$short] = max($ranked[$short] ?? 0, $quality);
            }
        }

        if ($ranked === []) {
            return null;
        }

        arsort($ranked);
        return array_key_first($ranked);
    }
}
