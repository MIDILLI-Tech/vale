<?php

declare(strict_types=1);

namespace ValeApp\App\Middleware;

use Doctrine\DBAL\Connection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use Slim\Views\Twig;
use Twig\Markup;
use ValeApp\Application\Plugin\PluginManager;
use ValeApp\Application\Seo\SeoState;
use ValeApp\Application\Update\UpdateService;
use ValeApp\Domain\Content\Entity\Category;
use ValeApp\Domain\Content\Entity\ContentType;
use ValeApp\Domain\Content\Entity\Post;
use ValeApp\Domain\Content\Entity\Tag;
use ValeApp\Domain\Content\Repository\ContentTypeRepositoryInterface;
use ValeApp\Domain\Media\Repository\MediaRepositoryInterface;
use ValeApp\Domain\Shared\Event\EventDispatcherInterface;
use ValeApp\Domain\Shop\Entity\Product;
use ValeApp\Domain\Shop\Entity\ProductCategory;
use ValeApp\Domain\Shop\Repository\CouponRepositoryInterface;
use ValeApp\Domain\Shop\Repository\ProductVoteRepositoryInterface;
use ValeApp\Domain\User\Repository\UserRepositoryInterface;
use ValeApp\Infrastructure\Admin\IconHelper;
use ValeApp\Infrastructure\Admin\NavOrderService;
use ValeApp\Infrastructure\Content\ContentSummary;
use ValeApp\Infrastructure\ExchangeRate\ExchangeRateService;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Theme\ColorValueValidator;
use ValeApp\Infrastructure\Theme\ThemeManager;

/**
 * Reads flash messages from session and injects them into Twig globals.
 */
final class FlashMiddleware implements MiddlewareInterface
{
    private const FLASH_TYPES       = ['success', 'error', 'info', 'warning'];
    private ?array $currencySymbols = null;

    /** @var array<string, mixed>|null */
    private ?array $referenceData = null;

    public function __construct(
        private readonly SessionManager $session,
        private readonly Twig $twig,
        private readonly DbalSettingsRepository $settings,
        private readonly Connection $db,
        private readonly ExchangeRateService $exchangeRates,
        private readonly NavOrderService $navOrderService,
        private readonly UpdateService $updater,
        private readonly ContentTypeRepositoryInterface $contentTypes,
        private readonly ThemeManager $themes,
        private readonly SeoState $seoState,
        private readonly MediaRepositoryInterface $media,
        private readonly ProductVoteRepositoryInterface $productVotes,
        private readonly UserRepositoryInterface $users,
        private readonly CouponRepositoryInterface $coupons,
        private readonly EventDispatcherInterface $events,
        private readonly PluginManager $plugins,
    ) {
    }

    /**
     * Drop the core Plugins entry while the admin global switch is off. The
     * screen itself stays reachable (Settings → General → Features) and warns.
     *
     * @param list<array<string, mixed>> $navGroups
     * @return list<array<string, mixed>>
     */
    public static function hidePluginsNav(array $navGroups): array
    {
        foreach ($navGroups as &$item) {
            if (isset($item['children']) && is_array($item['children'])) {
                $item['children'] = array_values(array_filter(
                    $item['children'],
                    static fn (array $child): bool => ($child['id'] ?? '') !== 'plugins',
                ));
            }
        }
        unset($item);

        return array_values(array_filter($navGroups, static function (array $item): bool {
            if (isset($item['children'])) {
                return $item['children'] !== [];
            }

            return ($item['id'] ?? '') !== 'plugins';
        }));
    }

    /**
     * Drop plugin links hidden by the operator while keeping core entries.
     *
     * @param list<array<string, mixed>> $navGroups
     * @param list<string> $hiddenSlugs
     * @return list<array<string, mixed>>
     */
    public static function hidePluginNav(array $navGroups, array $hiddenSlugs): array
    {
        $hiddenIds = array_map(
            static fn (string $slug): string => 'plugin-' . $slug,
            $hiddenSlugs,
        );

        foreach ($navGroups as &$item) {
            if (isset($item['children']) && is_array($item['children'])) {
                $item['children'] = array_values(array_filter(
                    $item['children'],
                    static fn (array $child): bool => !in_array((string) ($child['id'] ?? ''), $hiddenIds, true),
                ));
            }
        }
        unset($item);

        return array_values(array_filter(
            $navGroups,
            static fn (array $item): bool => !in_array((string) ($item['id'] ?? ''), $hiddenIds, true),
        ));
    }

    /**
     * Central reference data (config/reference-data.php), loaded once per instance.
     *
     * @return array<string, mixed>
     */
    private function referenceData(): array
    {
        if ($this->referenceData === null) {
            $this->referenceData = require __DIR__ . '/../../config/reference-data.php';
        }

        return $this->referenceData;
    }

    private function currencySymbols(): array
    {
        if ($this->currencySymbols !== null) {
            return $this->currencySymbols;
        }

        $refData = $this->referenceData();
        $symbols = [];
        foreach (($refData['currencies'] ?? []) as $currency) {
            $code   = strtoupper((string) ($currency['code'] ?? ''));
            $symbol = (string) ($currency['symbol'] ?? '');
            if ($code !== '' && $symbol !== '') {
                $symbols[$code] = $symbol;
            }
        }

        return $this->currencySymbols = $symbols;
    }

    /**
     * Register a Twig global safely. In long-lived processes (test harnesses,
     * queue workers) the Twig runtime freezes after the first render and
     * addGlobal() throws LogicException — swallow it there instead of
     * killing the request. Regular FPM requests always run pre-render.
     */
    private function setGlobal(string $name, mixed $value): void
    {
        try {
            $this->twig->getEnvironment()->addGlobal($name, $value);
        } catch (\LogicException) {
            // Runtime frozen — globals from this request cannot be injected.
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $flash = [];

        foreach (self::FLASH_TYPES as $type) {
            $msg = $this->session->flash("flash_{$type}");
            if ($msg !== null) {
                $flash[$type] = [new Markup($msg, 'UTF-8')];
                // Backward-compatible scalar global (e.g. checkout.twig reads
                // {% if flash_error %}). Controller-passed values still win in
                // the render context, so this only fills pages that don't.
                $this->setGlobal("flash_{$type}", new Markup($msg, 'UTF-8'));
            }
        }

        if (!empty($flash)) {
            $this->setGlobal('flash', $flash);
        }

        // Also inject current user info for templates
        if ($this->session->has('user_id')) {
            $storedVersion = (int) $this->session->get('session_version', 0);
            if ($storedVersion > 0) {
                $dbVersion = $this->db->fetchOne(
                    'SELECT session_version FROM ' . VL_DB_PREFIX . 'users WHERE id = ?',
                    [(int) $this->session->get('user_id')],
                );
                if ($dbVersion === false || (int) $dbVersion !== $storedVersion) {
                    $this->session->destroy();
                    $response = new Response(302);
                    return $response->withHeader('Location', site_url('/login'));
                }
            }

            $this->setGlobal('user_role', $this->session->get('user_role'));
            $this->setGlobal('user', [
                'id'    => $this->session->get('user_id'),
                'name'  => $this->session->get('user_name'),
                'email' => $this->session->get('user_email'),
                'role'  => $this->session->get('user_role'),
            ]);
            $this->setGlobal(
                'user_permissions',
                $this->session->get('user_permissions', [])
            );
        }

        // Registration & SSO availability for templates
        $regEnabled = $this->settings->get('registration_enabled', '1');
        $this->setGlobal('registration_enabled', $regEnabled);

        $emailRegEnabled = $this->settings->get('email_registration_enabled', '1');
        $ssoRegEnabled   = $this->settings->get('sso_registration_enabled', '0');
        $ssoLoginEnabled = $this->settings->get('sso_login_enabled', '0');
        $this->setGlobal('email_registration_enabled', $emailRegEnabled);
        $this->setGlobal('sso_registration_enabled', $ssoRegEnabled);
        $this->setGlobal('sso_login_enabled', $ssoLoginEnabled);

        // Build enabled SSO providers lists — login reuses the same providers as registration
        $refData      = require __DIR__ . '/../../config/reference-data.php';
        $ssoProviders = $refData['sso_providers'] ?? [];
        $this->setGlobal('sso_providers', $ssoProviders);

        $activeRegProviders = [];
        foreach ($ssoProviders as $key => $info) {
            if ($this->settings->get('sso_reg_provider_' . $key, '0') === '1') {
                $activeRegProviders[] = $key;
            }
        }
        $this->setGlobal('sso_reg_providers_active', $activeRegProviders);
        $this->setGlobal('sso_login_providers_active', $activeRegProviders);

        // Captcha config for login / registration (keys shared with discussion tab)
        $captchaKeys = [
            'hcaptcha_site_key', 'hcaptcha_secret_key',
            'recaptcha_site_key', 'recaptcha_secret_key',
            'turnstile_site_key', 'turnstile_secret_key',
        ];
        $loginCaptchaConfig = [
            'type' => $this->settings->get('login_captcha_type', 'none'),
        ];
        $registerCaptchaConfig = [
            'type' => $this->settings->get('register_captcha_type', 'none'),
        ];
        foreach ($captchaKeys as $key) {
            $val = $this->settings->get($key, '');
            if (str_contains($key, 'secret')) {
                $loginCaptchaConfig[$key]    = $val ? '••••••••' : '';
                $registerCaptchaConfig[$key] = $val ? '••••••••' : '';
            } else {
                $loginCaptchaConfig[$key]    = $val;
                $registerCaptchaConfig[$key] = $val;
            }
        }
        $this->setGlobal('login_captcha_config', $loginCaptchaConfig);
        $this->setGlobal('register_captcha_config', $registerCaptchaConfig);

        // Inject cart item count for header badge
        $cart      = $this->session->get('cart', []);
        $cartCount = 0;
        foreach ($cart as $data) {
            $cartCount += is_array($data) ? (int) ($data['qty'] ?? 1) : (int) $data;
        }
        $this->setGlobal('cart_count', $cartCount);

        // ── Currency ────────────────────────────────────────────────────
        $defaultCurrency    = strtoupper($this->settings->get('currency', 'USD') ?? 'USD');
        $sessionCurrency    = strtoupper($this->session->get('selected_currency', '') ?? '');
        $currentCurrency    = $sessionCurrency !== '' ? $sessionCurrency : $defaultCurrency;
        $currencySymbol     = $this->currencySymbols()[$currentCurrency] ?? $currentCurrency;

        $availableCurrencies = $this->buildAvailableCurrencies($defaultCurrency);

        $this->setGlobal('current_currency', $currentCurrency);
        $this->setGlobal('currency_symbol', $currencySymbol);
        $this->setGlobal('available_currencies', $availableCurrencies);
        $this->setGlobal('default_currency', $defaultCurrency);
        $this->setGlobal('default_currency_symbol', $this->currencySymbols()[$defaultCurrency] ?? $defaultCurrency);
        // ── Exchange rate multiplier ───────────────────────────────────
        // fx_rate = how many units of $currentCurrency = 1 unit of $defaultCurrency
        // Templates multiply all stored prices (in store default currency) by fx_rate.
        $fxRate   = 1.0;
        $provider = $this->settings->get('fx_provider', 'none') ?? 'none';
        if ($provider !== 'none' && $currentCurrency !== $defaultCurrency) {
            // Auto-refresh stale cache silently (avoids manual cron setup)
            if ($this->exchangeRates->isCacheStale()) {
                try {
                    $this->exchangeRates->fetchAndCache();
                } catch (\Throwable) {
                    // Non-fatal: fall back to 1.0
                }
            }
            $fxRate = $this->exchangeRates->getRate($defaultCurrency, $currentCurrency);
        }
        $this->setGlobal('fx_rate', $fxRate);

        // ── Admin nav order ──────────────────────────────────────────
        $savedNavOrder = $this->settings->get('admin_nav_order', '') ?? '';
        $navGroups     = $this->navOrderService->getOrderedGroups($savedNavOrder);

        // Plugin filter point. Runs before the base-path prefixing below, so
        // entries added by a plugin get the same href treatment as core links.
        $filteredNav = $this->events->applyFilter('admin_nav_groups', $navGroups);
        if (is_array($filteredNav)) {
            $navGroups = $filteredNav;
        }

        $savedChildOrder = $this->settings->get('admin_nav_child_order', '') ?? '';
        $navGroups       = $this->navOrderService->applyChildOrder($navGroups, $savedChildOrder);

        $hiddenPluginNav = $this->plugins->getHiddenNavSlugs();
        if ($hiddenPluginNav !== []) {
            $navGroups = self::hidePluginNav($navGroups, $hiddenPluginNav);
        }

        // Prepend base_path to all admin hrefs
        $basePath = $_ENV['APP_BASE_PATH'] ?? '';
        if ($basePath === '') {
            $parsed   = parse_url(__require_app_url());
            $basePath = isset($parsed['path']) ? rtrim($parsed['path'], '/') : '';
        }
        $basePath = rtrim($basePath, '/');
        foreach ($navGroups as &$item) {
            if (isset($item['href'])) {
                $item['href'] = $basePath . $item['href'];
            }
            if (isset($item['children'])) {
                foreach ($item['children'] as &$child) {
                    if (isset($child['href'])) {
                        $child['href'] = $basePath . $child['href'];
                    }
                }
                unset($child);
            }
        }
        unset($item);

        // Hide nav items based on site feature settings.
        $shopEnabled          = ($this->settings->get('shop_enabled', '1') ?? '1')          === '1';
        $blogEnabled          = ($this->settings->get('blog_enabled', '1') ?? '1')          === '1';
        $seoEnabled           = ($this->settings->get('seo_enabled', '1') ?? '1')           === '1';
        $membershipEnabled    = ($this->settings->get('membership_enabled', '1') ?? '1')    === '1';
        $subscriptionsEnabled = ($this->settings->get('subscriptions_enabled', '1') ?? '1') === '1';
        $pagesEnabled         = ($this->settings->get('pages_enabled', '1') ?? '1')         === '1';
        $newsletterEnabled    = ($this->settings->get('newsletter_enabled', '1') ?? '1')    === '1';
        $formBuilderEnabled   = ($this->settings->get('form_builder_enabled', '1') ?? '1')  === '1';
        $pluginsEnabled       = ($this->settings->get('plugins_enabled', '1') ?? '1')       !== '0';

        $this->setGlobal('shop_enabled', $shopEnabled);
        $this->setGlobal('plugins_enabled', $pluginsEnabled);
        $this->setGlobal('blog_enabled', $blogEnabled);
        $this->setGlobal('seo_enabled', $seoEnabled);
        $this->setGlobal('membership_enabled', $membershipEnabled);
        $this->setGlobal('subscriptions_enabled', $subscriptionsEnabled);
        $this->setGlobal('pages_enabled', $pagesEnabled);
        $this->setGlobal('newsletter_enabled', $newsletterEnabled);
        $this->setGlobal('form_builder_enabled', $formBuilderEnabled);
        $this->setGlobal('buy_now_enabled', ($this->settings->get('buy_now_enabled', '0') === '1'));

        $couponsData      = [];
        $couponsTruncated = false;
        if ($shopEnabled || $membershipEnabled) {
            $limit            = client_side_search_fetch_limit();
            $allCoupons       = $this->coupons->findAll();
            $couponsTruncated = count($allCoupons) > $limit;
            foreach (array_slice($allCoupons, 0, $limit) as $c) {
                $couponsData[] = [
                    'code'       => $c->getCode(),
                    'type'       => $c->getType(),
                    'amount'     => $c->getAmount(),
                    'expires_at' => $c->getExpiresAt()?->format('Y-m-d'),
                    'enabled'    => $c->isEnabled(),
                ];
            }
        }
        $this->setGlobal('_VL_COUPONS', $couponsData);
        $this->setGlobal('_VL_COUPONS_TRUNCATED', $couponsTruncated);

        $shopOnlyChildren = ['products', 'product_categories'];
        $subsOnlyChildren = ['subscriptions'];

        if (!$shopEnabled && !$subscriptionsEnabled) {
            $navGroups = array_values(array_filter($navGroups, fn (array $item): bool
                => $item['id'] !== 'group_shop'));
        } else {
            foreach ($navGroups as &$item) {
                if (($item['id'] ?? '') === 'group_shop' && isset($item['children']) && is_array($item['children'])) {
                    $item['children'] = array_values(array_filter($item['children'], function (array $child) use ($shopEnabled, $subscriptionsEnabled, $shopOnlyChildren, $subsOnlyChildren): bool {
                        $id = $child['id'] ?? '';
                        if (in_array($id, $shopOnlyChildren, true) && !$shopEnabled) {
                            return false;
                        }
                        if (in_array($id, $subsOnlyChildren, true) && !$subscriptionsEnabled) {
                            return false;
                        }
                        return true;
                    }));
                    break;
                }
            }
            unset($item);

            if (!$shopEnabled && $subscriptionsEnabled) {
                foreach ($navGroups as &$item) {
                    if (($item['id'] ?? '') === 'group_shop') {
                        $item['label'] = 'Orders & Membership';
                        break;
                    }
                }
                unset($item);
            }
        }

        if (!$pagesEnabled) {
            foreach ($navGroups as &$item) {
                if (($item['id'] ?? '') === 'group_content' && isset($item['children'])) {
                    $item['children'] = array_values(array_filter($item['children'], fn (array $child): bool
                        => $child['id'] !== 'pages'));
                }
            }
            unset($item);
        }

        if (!$newsletterEnabled) {
            $navGroups = array_values(array_filter($navGroups, fn (array $item): bool
                => $item['id'] !== 'group_newsletter'));
        }

        if (!$formBuilderEnabled) {
            $navGroups = array_values(array_filter($navGroups, fn (array $item): bool
                => $item['id'] !== 'group_forms'));
        }

        if (!$blogEnabled) {
            foreach ($navGroups as &$item) {
                if (($item['id'] ?? '') === 'group_content' && isset($item['children'])) {
                    $item['children'] = array_values(array_filter($item['children'], fn (array $child): bool
                        => $child['id'] !== 'posts'));
                }
            }
            unset($item);
        }

        // Hide categories and tags only when BOTH blog AND custom types are disabled
        $customTypesEnabled = ($this->settings->get('custom_content_types_enabled', '1') ?? '1') === '1';
        $hideTaxonomies     = !$blogEnabled && !$customTypesEnabled;
        if ($hideTaxonomies) {
            foreach ($navGroups as &$item) {
                if (($item['id'] ?? '') === 'group_content' && isset($item['children'])) {
                    $item['children'] = array_values(array_filter($item['children'], fn (array $child): bool
                        => !in_array($child['id'], ['categories', 'tags'], true)));
                }
            }
            unset($item);
        }

        // Hide comments sidebar items when both blog AND custom types are disabled
        if ($hideTaxonomies) {
            $navGroups = array_values(array_filter($navGroups, fn (array $item): bool
                => $item['id'] !== 'group_comments'));
        }

        // Hide analytics nav item if disabled
        $analyticsEnabled = ($this->settings->get('analytics_enabled', '1') ?? '1') === '1';
        if (!$analyticsEnabled) {
            $navGroups = array_values(array_filter($navGroups, fn (array $item): bool
                => $item['id'] !== 'analytics'));
        }

        // Hide SEO nav item if disabled
        $seoEnabled = ($this->settings->get('seo_enabled', '1') ?? '1') === '1';
        if (!$seoEnabled) {
            $navGroups = array_values(array_filter($navGroups, fn (array $item): bool
                => $item['id'] !== 'seo'));
        }

        // Hide Registration nav item if disabled
        $registrationEnabled = ($this->settings->get('registration_enabled', '1') ?? '1') === '1';
        if (!$registrationEnabled) {
            $navGroups = array_values(array_filter($navGroups, fn (array $item): bool
                => $item['id'] !== 'registration'));
        }

        // Inject active custom content types list for editor pickers
        $activeCptTypes = [];
        if ($customTypesEnabled) {
            foreach ($this->contentTypes->findActiveCustom() as $ct) {
                $activeCptTypes[] = [
                    'slug' => (string) $ct->getSlug(),
                    'name' => $ct->getName(),
                ];
            }
        }
        $this->setGlobal('_VL_ACTIVE_CPTS', $activeCptTypes);

        // Hide custom content types nav children if disabled
        if (!$customTypesEnabled) {
            foreach ($navGroups as &$item) {
                if (($item['id'] ?? '') === 'group_content' && isset($item['children'])) {
                    $item['children'] = array_values(array_filter($item['children'], fn (array $child): bool
                        => !str_starts_with($child['id'] ?? '', 'cpt_') && $child['id'] !== 'content_types'));
                }
            }
            unset($item);
        }

        // ── Custom Content Types in Sidebar ──────────────────────────
        if ($customTypesEnabled) {
            $allContentTypes = $this->contentTypes->findActiveCustom();
            foreach ($navGroups as &$item) {
                if (($item['id'] ?? '') === 'group_content' && isset($item['children'])) {
                    if (!empty($allContentTypes)) {
                        $cptChildren = [];
                        foreach ($allContentTypes as $ct) {
                            $cptChildren[] = [
                                'id'         => 'cpt_' . $ct->getSlug(),
                                'type'       => 'link',
                                'label'      => $ct->getName(),
                                'href'       => $basePath . '/admin/posts?content_type=' . $ct->getSlug(),
                                'icon'       => IconHelper::resolveIcon($ct->getIcon()),
                                'permission' => 'cpt.' . $ct->getSlug() . '.list',
                            ];
                        }
                        // Insert after Posts (before categories/tags)
                        $insertIdx = count($item['children']);
                        foreach ($item['children'] as $idx => $child) {
                            if ($child['id'] === 'posts') {
                                $insertIdx = $idx + 1;
                                break;
                            }
                        }
                        array_splice($item['children'], $insertIdx, 0, $cptChildren);
                    }

                    // Add Content Types management link
                    $item['children'][] = [
                        'id'         => 'content_types',
                        'type'       => 'link',
                        'label'      => 'Content Types',
                        'href'       => $basePath . '/admin/content-types',
                        'icon'       => 'M12 6v6m0 0v6m0-6h6m-6 0H6',
                        'permission' => 'settings.view',
                    ];
                }
            }
            unset($item);
        }

        // ── Custom Content Type Comments in Sidebar ──────────────────
        if ($blogEnabled || $customTypesEnabled) {
            $allContentTypes    = $this->contentTypes->findActiveCustom();
            $cptCommentChildren = [];
            foreach ($allContentTypes as $ct) {
                if (!in_array('comments', $ct->getSupports(), true)) {
                    continue;
                }
                $cptCommentChildren[] = [
                    'id'           => 'comments_cpt_' . $ct->getSlug(),
                    'type'         => 'link',
                    'label'        => ':cpt Comments',
                    'label_cpt'    => $ct->getName(),
                    'href'         => $basePath . '/admin/comments?entity_type=' . $ct->getSlug(),
                    'icon'         => IconHelper::resolveIcon($ct->getIcon()),
                    'permission'   => 'cpt.' . $ct->getSlug() . '.list',
                ];
            }
            usort($cptCommentChildren, static fn (array $a, array $b): int => strcasecmp($a['label_cpt'], $b['label_cpt']));

            if ($cptCommentChildren !== []) {
                foreach ($navGroups as &$item) {
                    if (($item['id'] ?? '') === 'group_comments' && isset($item['children'])) {
                        $item['children'] = array_merge($item['children'], $cptCommentChildren);
                        break;
                    }
                }
                unset($item);
            }
        }

        // Rename "Comments" to "Comments & Reviews" when shop is enabled
        if ($shopEnabled) {
            foreach ($navGroups as &$item) {
                if (($item['id'] ?? '') === 'group_comments') {
                    $item['label'] = 'Comments & Reviews';
                    break;
                }
            }
            unset($item);
        }

        // Remove "Reviews" child from comments group when shop is disabled
        if (!$shopEnabled) {
            foreach ($navGroups as &$item) {
                if (($item['id'] ?? '') === 'group_comments' && isset($item['children'])) {
                    $item['children'] = array_values(array_filter($item['children'], fn (array $child): bool
                        => $child['id'] !== 'reviews'));
                    break;
                }
            }
            unset($item);
        }

        if (!$shopEnabled && !$blogEnabled) {
            $navGroups = array_values(array_filter($navGroups, fn (array $item): bool
                => $item['id'] !== 'group_comments'));
        }

        if (!$pluginsEnabled) {
            $navGroups = self::hidePluginsNav($navGroups);
        }

        // Filter nav items by user permissions
        $userRole        = $this->session->get('user_role', 'subscriber');
        $bypassNavFilter = in_array($userRole, ['super_admin'], true);
        if (!$bypassNavFilter) {
            $userPerms = $this->session->get('user_permissions', []);
            foreach ($navGroups as &$item) {
                if (isset($item['children'])) {
                    $item['children'] = array_values(
                        array_filter($item['children'], function (array $child) use ($userPerms): bool {
                            $perm = $child['permission'] ?? null;
                            return $perm === null || in_array($perm, $userPerms, true);
                        })
                    );
                }
            }
            unset($item);

            $navGroups = array_values(array_filter($navGroups, function (array $item) use ($userPerms): bool {
                // If group has children, only show if at least one child is visible
                if (isset($item['children'])) {
                    return !empty($item['children']);
                }
                $perm = $item['permission'] ?? null;
                return $perm === null || in_array($perm, $userPerms, true);
            }));
        }

        $this->setGlobal('nav_groups', $navGroups);

        // ── Quick-actions data (dashboard grid + header user menu) ───
        // The dashboard grid and the header user menu (admin/layout.twig)
        // share these globals so both always show the same actions in the
        // same order. The order is edited on the dashboard via drag & drop
        // and stored in the admin_quick_actions_order setting.
        $qaStatic = [
            'new_post'     => ['label' => 'New Post', 'path' => '/admin/posts/create', 'perm' => 'posts.list', 'color' => 'blue', 'feature' => 'blog',
                'icon'                 => 'M12 4.5v15m7.5-7.5h-15'],
            'new_page'     => ['label' => 'New Page', 'path' => '/admin/pages/create', 'perm' => 'pages.list', 'color' => 'green', 'feature' => 'pages',
                'icon'                 => 'M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125 1.125V11.25a9 9 0 00-9-9z'],
            'new_user'     => ['label' => 'New User', 'path' => '/admin/users?modal=add', 'perm' => 'users.create', 'color' => 'blue', 'feature' => null,
                'icon'                 => 'M18 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM3 19.235v-.11a6.375 6.375 0 0 1 12.75 0v.109A12.318 12.318 0 0 1 9.374 21c-2.331 0-4.512-.645-6.374-1.766Z'],
            'new_product'  => ['label' => 'New Product', 'path' => '/admin/products/create', 'perm' => 'products.list', 'color' => 'purple', 'feature' => 'shop',
                'icon'                 => 'M15.75 10.5V6a3.75 3.75 0 10-7.5 0v4.5m11.356-1.993l1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 01-1.12-1.243l1.264-12A1.125 1.125 0 015.513 7.5h12.974c.576 0 1.059.435 1.119 1.007z'],
            'view_orders'  => ['label' => 'New Order', 'path' => '/admin/orders/create', 'perm' => 'orders.list', 'color' => 'amber', 'feature' => 'shop',
                'icon'                 => 'M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z'],
            'new_campaign' => ['label' => 'New Campaign', 'path' => '/admin/newsletter/campaigns/create', 'perm' => 'newsletter.manage', 'color' => 'cyan', 'feature' => 'newsletter',
                'icon'                 => 'M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75'],
            'new_subscriber' => ['label' => 'New Subscriber', 'path' => '/admin/newsletter/subscribers?modal=add', 'perm' => 'newsletter.manage', 'color' => 'pink', 'feature' => 'newsletter',
                'icon'                   => 'M16.5 12a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0Zm0 0c0 1.657 1.007 3 2.25 3s2.25-1.343 2.25-3a9 9 0 1 0-2.636 6.364M16.5 12V8.25'],
            'new_coupon'   => ['label' => 'New Coupon', 'path' => '/admin/coupons?modal=add', 'perm' => 'products.edit', 'color' => 'amber', 'feature' => 'coupons',
                'icon'                 => 'M16.5 6v.75m0 3v.75m0 3v.75m0 3V18m-9-5.25h5.25M7.5 15h3M3.375 5.25c-.621 0-1.125.504-1.125 1.125v3.026a2.999 2.999 0 0 1 0 5.198v3.026c0 .621.504 1.125 1.125 1.125h17.25c.621 0 1.125-.504 1.125-1.125v-3.026a2.999 2.999 0 0 1 0-5.198V6.375c0-.621-.504-1.125-1.125-1.125H3.375Z'],
            'optimize'     => ['label' => 'Optimize', 'path' => '/admin/optimize', 'perm' => 'settings.view', 'color' => 'red', 'feature' => null,
                'icon'                 => 'M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456z'],
            'warm_cache'   => ['label' => 'Warm Cache', 'path' => '/admin/cache/warm', 'perm' => 'settings.view', 'color' => 'orange', 'feature' => null,
                'icon'                 => 'M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z'],
        ];
        $this->setGlobal('qa_icon_colors', [
            'blue'   => 'text-blue-500 dark:text-blue-400', 'green' => 'text-green-500 dark:text-green-400',
            'purple' => 'text-purple-500 dark:text-purple-400', 'amber' => 'text-amber-500 dark:text-amber-400',
            'red'    => 'text-red-500 dark:text-red-400', 'cyan' => 'text-cyan-500 dark:text-cyan-400',
            'pink'   => 'text-pink-500 dark:text-pink-400', 'orange' => 'text-orange-500 dark:text-orange-400',
            'indigo' => 'text-indigo-500 dark:text-indigo-400',
        ]);
        $qaOrdered = [];
        // Only for logged-in users with a panel role (same audience as nav_groups above).
        $qaUserId = $this->session->get('user_id');
        if ($qaUserId !== null && $userRole !== 'subscriber') {
            $qaDefs     = $qaStatic;
            $qaShowCpts = $blogEnabled || $customTypesEnabled;
            if ($qaShowCpts) {
                foreach ($this->contentTypes->findActiveCustom() as $ct) {
                    $slug                       = (string) $ct->getSlug();
                    $qaDefs['new_cpt_' . $slug] = [
                        'label'   => 'New ' . (string) $ct->getName(),
                        'path'    => '/admin/posts/create?content_type=' . $slug,
                        'icon'    => IconHelper::resolveIcon($ct->getIcon()),
                        'perm'    => 'cpt.' . $slug . '.list',
                        'color'   => 'indigo',
                        'feature' => 'cpt',
                    ];
                }
            }
            $savedQaOrder = $this->settings->get('admin_quick_actions_order', '') ?? '';
            $qaOrder      = $savedQaOrder !== '' ? (json_decode($savedQaOrder, true) ?? []) : [];
            $qaOrder      = is_array($qaOrder) ? array_values(array_filter($qaOrder, 'is_string')) : [];
            $orderedKeys  = array_values(array_intersect($qaOrder, array_keys($qaDefs)));
            foreach (array_keys($qaDefs) as $k) {
                if (!in_array($k, $orderedKeys, true)) {
                    $orderedKeys[] = $k;
                }
            }
            $featureOn = [
                'blog' => $blogEnabled, 'pages' => $pagesEnabled,
                'shop' => $shopEnabled, 'newsletter' => $newsletterEnabled,
                'cpt'  => $qaShowCpts,
                // Coupons exist whenever there is something to discount:
                // shop products or membership/subscription plans — same
                // visibility as the sidebar's Coupons link.
                'coupons' => $shopEnabled || $subscriptionsEnabled,
            ];
            $isSuperAdmin = ($userRole === 'super_admin');
            $qaUserPerms  = $userPerms ?? [];
            foreach ($orderedKeys as $k) {
                $a = $qaDefs[$k];
                if ($a['feature'] !== null && empty($featureOn[$a['feature']])) {
                    continue;
                }
                if (!$isSuperAdmin && !in_array($a['perm'], $qaUserPerms, true)) {
                    continue;
                }
                $qaOrdered[] = [
                    'key'  => $k, 'label' => $a['label'], 'path' => $a['path'],
                    'icon' => $a['icon'], 'perm' => $a['perm'], 'color' => $a['color'],
                ];
            }
        }
        $this->setGlobal('qa_ordered', $qaOrdered);

        // Inject all autoloaded settings into frontend templates
        $this->setGlobal('settings', $this->settings->getAutoloaded());

        // Documentation link for the admin header user menu
        $docsUrl = (string) ($this->referenceData()['documentation']['url'] ?? '');
        $this->setGlobal('docs_url', $docsUrl);

        // ── Prepare SEO Globals ──────────────────────────────────────
        $seoEnabled = ($this->settings->get('seo_enabled', '1') ?? '1') === '1';
        $siteName   = $this->settings->get('site_name', 'Vale');

        if (!$seoEnabled) {
            $effectiveTitle = (string) $this->events->applyFilter(
                'front_page_title',
                $siteName,
                null,
                $request->getUri()->getPath(),
            );
            $this->setGlobal('effective_title', $effectiveTitle);
            $this->setGlobal('effective_desc', '');
        } else {
            $seo    = $this->seoState->getSeo();
            $entity = $this->seoState->getEntity();

            $entityTitle = null;
            $entityDesc  = null;

            $teaserWords = (int) ($this->settings->get('listing_teaser_words', '55') ?? '55');

            if ($entity instanceof Post) {
                $entityTitle = $entity->getTitle();
                $entityDesc  = $entity->getExcerpt() ?: ($teaserWords > 0
                    ? ContentSummary::plainTeaser($entity->getBody(), null, 160)
                    : null);
            } elseif ($entity instanceof Product) {
                $entityTitle = $entity->getName();
                $entityDesc  = $entity->getShortDescription() ?: ($teaserWords > 0
                    ? ContentSummary::plainTeaser($entity->getDescription(), null, 160)
                    : null);
            } elseif ($entity instanceof Category || $entity instanceof ProductCategory) {
                $entityTitle = $entity->getName();
                $entityDesc  = $entity->getDescription();
            } elseif ($entity instanceof Tag) {
                $entityTitle = $entity->getName();
            } elseif ($entity instanceof ContentType) {
                $entityTitle = $entity->getName();
                $entityDesc  = $entity->getDescription();
            }

            $sep            = $this->settings->get('seo_title_separator', ' — ');
            $effectiveTitle = ($seo && $seo->getMetaTitle()) ? $seo->getMetaTitle() : ($entityTitle ? $entityTitle . $sep . $siteName : $siteName);
            $effectiveDesc  = ($seo && $seo->getMetaDescription()) ? $seo->getMetaDescription() : ($entityDesc ?? '');

            $effectiveTitle = (string) $this->events->applyFilter(
                'front_page_title',
                $effectiveTitle,
                $entityTitle,
                $request->getUri()->getPath(),
            );

            $this->setGlobal('effective_title', $effectiveTitle);
            $this->setGlobal('effective_desc', $effectiveDesc);
            $this->setGlobal('entity_title', $entityTitle);
            $this->setGlobal('entity_description', $entityDesc);
            // Inject the state object itself so Twig can pull fresh translations after Controller runs
            $this->setGlobal('seo_state', $this->seoState);
        }

        // ── Injected current URL for SEO ────────────────────────────
        $uri = $request->getUri();
        // LocaleMiddleware rewrites the URI (locale segment and localized
        // routing prefixes) before routing — prefer the path the visitor
        // actually requested so canonical / og:url keep the locale.
        $requestedPath = (string) $request->getAttribute('vl_requested_path', '');
        $rawPath       = $requestedPath !== '' ? $requestedPath : $uri->getPath();

        // The front controller strips the base path from REQUEST_URI before routing,
        // so re-add it here unless it is already part of the path (e.g. docroot installs).
        $basePath = __app_base_path();
        if ($basePath !== '' && $rawPath !== $basePath && !str_starts_with($rawPath, $basePath . '/')) {
            $rawPath = $basePath . $rawPath;
        }
        $currentUrl = $uri->getScheme() . '://' . $uri->getHost();
        if ($uri->getPort()) {
            $currentUrl .= ':' . $uri->getPort();
        }
        $currentUrl .= $rawPath;
        $currentPath = $rawPath;
        if ($uri->getQuery()) {
            $currentUrl  .= '?' . $uri->getQuery();
            $currentPath .= '?' . $uri->getQuery();
        }
        $this->setGlobal('current_url', $currentUrl);
        $this->setGlobal('current_path', $currentPath);

        // ── Register Theme Colors Hook ───────────────────────────────
        $previewTheme     = $this->session->get('preview_theme', '');
        $colorsThemeSlug  = $previewTheme !== '' ? $previewTheme : $this->themes->getActiveTheme();
        $hasCustomColors  = false;
        $savedColorsRaw   = $this->settings->get('theme_' . $colorsThemeSlug . '_colors', '');
        if ($savedColorsRaw) {
            $savedColors = json_decode((string) $savedColorsRaw, true);
            if (is_array($savedColors)) {
                foreach ($savedColors as $varName => $varValue) {
                    if (str_starts_with((string) $varName, '--') && ColorValueValidator::isValid((string) $varValue)) {
                        $hasCustomColors = true;
                        break;
                    }
                }
            }
        }
        $this->setGlobal('theme_has_custom_colors', $hasCustomColors);

        // ── Resolved Theme Colors Global (editor color picker) ───────
        $savedColorsDecoded = $savedColorsRaw !== '' ? json_decode((string) $savedColorsRaw, true) : null;
        $this->setGlobal(
            'vl_theme_colors',
            $this->themes->resolveColorVariables(
                $colorsThemeSlug,
                is_array($savedColorsDecoded) ? $savedColorsDecoded : [],
            ),
        );

        // ── Table palette (editor preview parity with the active theme) ──
        $this->setGlobal('vl_table_palette', $this->themes->getTablePalette($colorsThemeSlug));

        $this->themes->addHook('head', function (): string {
            $activeTheme = $this->session->get('preview_theme', $this->themes->getActiveTheme());
            $colorsRaw   = $this->settings->get('theme_' . $activeTheme . '_colors', '');
            if (!$colorsRaw) {
                return '';
            }
            $colors = json_decode($colorsRaw, true);
            if (!is_array($colors) || empty($colors)) {
                return '';
            }
            $cssVars = [];
            foreach ($colors as $varName => $value) {
                if (str_starts_with((string) $varName, '--') && ColorValueValidator::isValid((string) $value)) {
                    $cssVars[] = "            {$varName}: {$value};";
                }
            }
            if (empty($cssVars)) {
                return '';
            }
            return "<style>\n        :root, html.dark {\n" . implode("\n", $cssVars) . "\n        }\n    </style>\n";
        }, 1);

        // ── Register SEO Hook ────────────────────────────────────────
        $this->themes->addHook('head', function () use ($seoEnabled): string {
            if (!$seoEnabled) {
                return '';
            }

            $seo    = $this->seoState->getSeo();
            $entity = $this->seoState->getEntity();

            // Structured data extras: product rating summary + post author display name
            $vote       = null;
            $authorName = null;
            try {
                if (
                    $entity instanceof Product
                    && $entity->getId() !== null
                ) {
                    $summary   = $this->productVotes->getSummary($entity->getId());
                    $vote      = [
                        'avg'   => (float) ($summary['average'] ?? 0),
                        'count' => (int) ($summary['count'] ?? 0),
                    ];
                }
                if (
                    $entity instanceof Post
                    && $entity->getContentType() !== 'page'
                ) {
                    $authorName = $this->users->findById($entity->getAuthorId())?->getName();
                }
            } catch (\Throwable) {
                // Tables may not exist yet during installation — omit structured data extras
            }

            return $this->twig->getEnvironment()->render('partials/_seo_social.twig', [
                'seo'                 => $seo,
                'post'                => ($entity instanceof Post) ? $entity : null,
                'page'                => ($entity instanceof Post && $entity->getContentType() === 'page') ? $entity : null,
                'product'             => ($entity instanceof Product) ? $entity : null,
                'vote'                => $vote,
                'author_name'         => $authorName,
                'entity_translations' => $this->seoState->getTranslations(),
                'base_path'           => __app_base_path(),
            ]);
        }, 5);

        // ── Update notification badge ────────────────────────────────
        if ($this->session->has('user_id')) {
            $updateVersion = $this->updater->getAvailableVersion();
            if ($updateVersion !== '' && version_compare($updateVersion, $this->updater->getCurrentVersion(), '>')) {
                $this->setGlobal('update_available_version', $updateVersion);
            }
        }

        return $handler->handle($request);
    }

    /**
     * Returns [code => symbol] for currencies the storefront may display.
     * Applies allowed / blocked lists from settings (same logic as shipping countries).
     *
     * @return array<string, string>
     */
    private function buildAvailableCurrencies(string $default): array
    {
        $allowedRaw = $this->settings->get('allowed_currencies', '')  ?? '';
        $blockedRaw = $this->settings->get('blocked_currencies', '')  ?? '';

        $allowed = array_filter(array_map('strtoupper', array_map('trim', explode(',', $allowedRaw))));
        $blocked = array_filter(array_map('strtoupper', array_map('trim', explode(',', $blockedRaw))));

        $symbols = $this->currencySymbols();

        // Default currency is always available; additional currencies are opt-in.
        $pool = [
            $default => $symbols[$default] ?? $default,
        ];

        foreach ($allowed as $code) {
            if ($code === $default) {
                continue;
            }
            if (isset($symbols[$code])) {
                $pool[$code] = $symbols[$code];
            }
        }

        if (!empty($blocked)) {
            $pool = array_filter($pool, fn ($code)
                => !in_array($code, array_values($blocked), true), ARRAY_FILTER_USE_KEY);
        }

        // Always include the store default
        if (!isset($pool[$default])) {
            $pool[$default] = $symbols[$default] ?? $default;
        }

        ksort($pool);
        return $pool;
    }
}
