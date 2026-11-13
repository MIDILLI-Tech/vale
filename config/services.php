<?php

/**
 * services.php — Global DI container configuration.
 */

declare(strict_types=1);
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\DriverManager;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Twig\Markup;
use Twig\TwigFilter;
use Twig\TwigFunction;
use ValeApp\App\Controllers\Admin\AppearanceController;
use ValeApp\App\Controllers\Admin\ContentTypeController;
use ValeApp\App\Controllers\Admin\DashboardController;
use ValeApp\App\Controllers\Admin\ExchangeRateController;
use ValeApp\App\Controllers\Admin\MailController;
use ValeApp\App\Controllers\Admin\MembershipController;
use ValeApp\App\Controllers\Admin\NewsletterController;
use ValeApp\App\Controllers\Admin\OrderController;
use ValeApp\App\Controllers\Admin\PaymentController;
use ValeApp\App\Controllers\Admin\PluginController;
use ValeApp\App\Controllers\Admin\ProductCategoryController;
use ValeApp\App\Controllers\Admin\ProductController;
use ValeApp\App\Controllers\Admin\SettingsController;
use ValeApp\App\Controllers\Admin\ThemeController;
use ValeApp\App\Controllers\Admin\UpdateController;
use ValeApp\App\Controllers\Admin\UserController;
use ValeApp\App\Controllers\Api\HealthController;
use ValeApp\App\Controllers\Api\OrderApiController;
use ValeApp\App\Controllers\CronController;
use ValeApp\App\Controllers\Front\AccountController;
use ValeApp\App\Controllers\Front\CheckoutController;
use ValeApp\App\Controllers\Front\CurrencyController;
use ValeApp\App\Controllers\Front\FormFrontController;
use ValeApp\App\Controllers\Front\MembershipCheckoutController;
use ValeApp\App\Controllers\Front\PluginAssetController;
use ValeApp\App\Controllers\Front\WebhookController;
use ValeApp\App\Middleware\AdminCacheBustMiddleware;
use ValeApp\App\Middleware\AdminMiddleware;
use ValeApp\App\Middleware\ApiAbilityMiddleware;
use ValeApp\App\Middleware\ApiAuthMiddleware;
use ValeApp\App\Middleware\AuthMiddleware;
use ValeApp\App\Middleware\CartExpireMiddleware;
use ValeApp\App\Middleware\CsrfMiddleware;
use ValeApp\App\Middleware\FlashMiddleware;
use ValeApp\App\Middleware\LocaleMiddleware;
use ValeApp\App\Middleware\MaintenanceMiddleware;
use ValeApp\App\Middleware\PageCacheMiddleware;
use ValeApp\App\Middleware\PageviewMiddleware;
use ValeApp\App\Middleware\ProbeBlockMiddleware;
use ValeApp\App\Middleware\ProbeRateLimitMiddleware;
use ValeApp\App\Middleware\PseudoCronMiddleware;
use ValeApp\App\Middleware\RateLimitMiddleware;
use ValeApp\App\Middleware\RedirectMiddleware;
use ValeApp\Application\Auth\LoginUseCase;
use ValeApp\Application\Content\BlogFeedService;
use ValeApp\Application\Content\SaveCategoryUseCase;
use ValeApp\Application\Content\SavePostUseCase;
use ValeApp\Application\Content\SaveTagUseCase;
use ValeApp\Application\Cron\CronService;
use ValeApp\Application\Form\FormAccessService;
use ValeApp\Application\Form\FormCaptchaService;
use ValeApp\Application\Mail\MailService;
use ValeApp\Application\Maintenance\ExportImportService;
use ValeApp\Application\Membership\SubscriptionReconciliationService;
use ValeApp\Application\Membership\SubscriptionService;
use ValeApp\Application\Newsletter\AutomationService;
use ValeApp\Application\Newsletter\NewsletterService;
use ValeApp\Application\Plugin\PluginAssetResolver;
use ValeApp\Application\Plugin\PluginLoader;
use ValeApp\Application\Plugin\PluginManager;
use ValeApp\Application\Plugin\PluginMigrator;
use ValeApp\Application\Plugin\PluginSecurityScanner;
use ValeApp\Application\Plugin\PluginUpdateService;
use ValeApp\Application\Seo\AiRobotsTxtUseCase;
use ValeApp\Application\Seo\GenerateSitemapUseCase;
use ValeApp\Application\Seo\RobotsTxtUseCase;
use ValeApp\Application\Seo\SeoState;
use ValeApp\Application\Shop\AbandonedCartService;
use ValeApp\Application\Shop\BankTransferDetailsService;
use ValeApp\Application\Shop\ChangeOrderStatusUseCase;
use ValeApp\Application\Shop\CouponValidator;
use ValeApp\Application\Shop\DeliveryEstimateService;
use ValeApp\Application\Shop\IdealoFeedService;
use ValeApp\Application\Shop\OrderStatusEffects;
use ValeApp\Application\Shop\ProductFeedService;
use ValeApp\Application\Shop\ProductImporter;
use ValeApp\Application\Shop\ShopifyFeedService;
use ValeApp\Application\Shop\YandexMarketFeedService;
use ValeApp\Application\Update\BackupService;
use ValeApp\Application\Update\MaintenanceMode;
use ValeApp\Application\Update\PreUpdateChecker;
use ValeApp\Application\Update\SignatureVerifier;
use ValeApp\Application\Update\UpdateNotificationService;
use ValeApp\Application\Update\UpdateService;
use ValeApp\Application\Webhook\OutboundWebhookService;
use ValeApp\Domain\Analytics\Repository\PageviewRepositoryInterface;
use ValeApp\Domain\Comment\Repository\CommentRepositoryInterface;
use ValeApp\Domain\Content\Entity\Post;
use ValeApp\Domain\Content\Repository\CategoryRepositoryInterface;
use ValeApp\Domain\Content\Repository\ContentTypeRepositoryInterface;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Content\Repository\RevisionRepositoryInterface;
use ValeApp\Domain\Content\Repository\TagRepositoryInterface;
use ValeApp\Domain\Content\RoutingValidator;
use ValeApp\Domain\Form\Repository\FormRepositoryInterface;
use ValeApp\Domain\Mail\Port\MailInterface;
use ValeApp\Domain\Media\Repository\MediaRepositoryInterface;
use ValeApp\Domain\Membership\Repository\PlanRepositoryInterface;
use ValeApp\Domain\Menu\Repository\MenuRepositoryInterface;
use ValeApp\Domain\Newsletter\Repository\AutomationRepositoryInterface;
use ValeApp\Domain\Newsletter\Repository\CampaignRepositoryInterface;
use ValeApp\Domain\Newsletter\Repository\SubscriberRepositoryInterface;
use ValeApp\Domain\Roles\Repository\RoleRepositoryInterface;
use ValeApp\Domain\Seo\Repository\SeoMetaRepositoryInterface;
use ValeApp\Domain\Shared\Event\EventDispatcherInterface;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Domain\Shared\Repository\WebhookRepositoryInterface;
use ValeApp\Domain\Shop\Entity\Coupon;
use ValeApp\Domain\Shop\Entity\Product;
use ValeApp\Domain\Shop\Repository\CountryTaxRateRepositoryInterface;
use ValeApp\Domain\Shop\Repository\CouponRepositoryInterface;
use ValeApp\Domain\Shop\Repository\DownloadRepositoryInterface;
use ValeApp\Domain\Shop\Repository\OrderLogRepositoryInterface;
use ValeApp\Domain\Shop\Repository\OrderRepositoryInterface;
use ValeApp\Domain\Shop\Repository\ProductCategoryRepositoryInterface;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;
use ValeApp\Domain\Shop\Repository\ProductVoteRepositoryInterface;
use ValeApp\Domain\User\Repository\UserRepositoryInterface;
use ValeApp\Infrastructure\Admin\IconHelper;
use ValeApp\Infrastructure\Admin\NavOrderService;
use ValeApp\Infrastructure\Analytics\GeoIpResolver;
use ValeApp\Infrastructure\Analytics\UserAgentParser;
use ValeApp\Infrastructure\Auth\PasswordHasher;
use ValeApp\Infrastructure\Cache\AdminSearchCache;
use ValeApp\Infrastructure\Cache\FileCache;
use ValeApp\Infrastructure\Cache\PageCache;
use ValeApp\Infrastructure\Cache\PageCacheInvalidator;
use ValeApp\Infrastructure\Content\ContentMarkerNormalizer;
use ValeApp\Infrastructure\Content\ContentSummary;
use ValeApp\Infrastructure\Content\ReadingTimeService;
use ValeApp\Infrastructure\Content\RedirectService;
use ValeApp\Infrastructure\Content\ShortcodeProcessor;
use ValeApp\Infrastructure\Content\SlugService;
use ValeApp\Infrastructure\Event\EventDispatcher;
use ValeApp\Infrastructure\ExchangeRate\ExchangeRateService;
use ValeApp\Infrastructure\Http\SafeRemoteFetcher;
use ValeApp\Infrastructure\Mail\FileLoggingMailDecorator;
use ValeApp\Infrastructure\Mail\LogMailAdapter;
use ValeApp\Infrastructure\Mail\MailgunAdapter;
use ValeApp\Infrastructure\Mail\PhpMailAdapter;
use ValeApp\Infrastructure\Mail\PHPMailerAdapter;
use ValeApp\Infrastructure\Mail\ResendAdapter;
use ValeApp\Infrastructure\Mail\SendGridAdapter;
use ValeApp\Infrastructure\Mail\UnosendAdapter;
use ValeApp\Infrastructure\Media\ImageProcessor;
use ValeApp\Infrastructure\Media\MediaScanService;
use ValeApp\Infrastructure\Media\MediaUploadService;
use ValeApp\Infrastructure\Payment\CheckoutComGateway;
use ValeApp\Infrastructure\Payment\MollieGateway;
use ValeApp\Infrastructure\Payment\PayPalGateway;
use ValeApp\Infrastructure\Payment\StripeGateway;
use ValeApp\Infrastructure\Payment\TwoCheckoutGateway;
use ValeApp\Infrastructure\Pdf\DompdfGenerator;
use ValeApp\Infrastructure\Pdf\PdfGeneratorInterface;
use ValeApp\Infrastructure\Persistence\DbalAutomationRepository;
use ValeApp\Infrastructure\Persistence\DbalCampaignRepository;
use ValeApp\Infrastructure\Persistence\DbalCategoryRepository;
use ValeApp\Infrastructure\Persistence\DbalCommentRepository;
use ValeApp\Infrastructure\Persistence\DbalContentTypeRepository;
use ValeApp\Infrastructure\Persistence\DbalCountryTaxRateRepository;
use ValeApp\Infrastructure\Persistence\DbalCouponRepository;
use ValeApp\Infrastructure\Persistence\DbalDownloadRepository;
use ValeApp\Infrastructure\Persistence\DbalFormRepository;
use ValeApp\Infrastructure\Persistence\DbalMediaRepository;
use ValeApp\Infrastructure\Persistence\DbalMembershipPlanRepository;
use ValeApp\Infrastructure\Persistence\DbalMenuRepository;
use ValeApp\Infrastructure\Persistence\DbalOrderLogRepository;
use ValeApp\Infrastructure\Persistence\DbalOrderRepository;
use ValeApp\Infrastructure\Persistence\DbalPageviewRepository;
use ValeApp\Infrastructure\Persistence\DbalPostRepository;
use ValeApp\Infrastructure\Persistence\DbalProductCategoryRepository;
use ValeApp\Infrastructure\Persistence\DbalProductRepository;
use ValeApp\Infrastructure\Persistence\DbalProductVoteRepository;
use ValeApp\Infrastructure\Persistence\DbalRevisionRepository;
use ValeApp\Infrastructure\Persistence\DbalRoleRepository;
use ValeApp\Infrastructure\Persistence\DbalSeoMetaRepository;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Persistence\DbalSubscriberRepository;
use ValeApp\Infrastructure\Persistence\DbalTagRepository;
use ValeApp\Infrastructure\Persistence\DbalUserRepository;
use ValeApp\Infrastructure\Persistence\DbalWebhookRepository;
use ValeApp\Infrastructure\Security\CsrfManager;
use ValeApp\Infrastructure\Security\Encrypter;
use ValeApp\Infrastructure\Security\IpAnonymizer;
use ValeApp\Infrastructure\Security\RateLimiter;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Theme\ThemeManager;
use ValeApp\Infrastructure\Translation\Translator;

use function DI\autowire;
use function DI\create;
use function DI\factory;
use function DI\get;

return [

    // ── Configuration arrays ─────────────────────────────────────────
    'config.app'      => factory(fn () => require dirname(__DIR__) . '/config/app.php'),
    'config.database' => factory(fn () => require dirname(__DIR__) . '/config/database.php'),
    'config.cache'    => factory(fn () => require dirname(__DIR__) . '/config/cache.php'),
    'config.auth'     => factory(fn () => require dirname(__DIR__) . '/config/auth.php'),
    'config.shop'     => factory(fn () => require dirname(__DIR__) . '/config/shop.php'),
    'config.media'    => factory(fn () => require dirname(__DIR__) . '/config/media.php'),

    SeoState::class => create(SeoState::class),

    RoutingValidator::class => factory(function ($c) {
        return new RoutingValidator(
            $c->get(SettingsRepositoryInterface::class),
            $c->get(ContentTypeRepositoryInterface::class),
            $c->get(PostRepositoryInterface::class),
            // Prefixes must not collide with any locale that has a UI file —
            // not only the currently active content languages, because
            // multi-language can be switched on later.
            $c->get(Translator::class)->getUiLocales(),
        );
    }),

    // ── SEO ──────────────────────────────────────────────────────────
    AiRobotsTxtUseCase::class => autowire(),
    RobotsTxtUseCase::class   => factory(function ($c) {
        $appCfg = $c->get('config.app');

        return new RobotsTxtUseCase(
            $c->get(SettingsRepositoryInterface::class),
            rtrim((string) env('APP_URL', $appCfg['url']), '/'),
        );
    }),
    GenerateSitemapUseCase::class => factory(function ($c) {
        $appCfg = $c->get('config.app');

        return new GenerateSitemapUseCase(
            $c->get(SeoMetaRepositoryInterface::class),
            $c->get(SettingsRepositoryInterface::class),
            rtrim((string) env('APP_URL', $appCfg['url']), '/'),
        );
    }),

    LoggerInterface::class => create(NullLogger::class),

    // ── Database ─────────────────────────────────────────────────────
    DbalConnection::class => factory(function ($c) {
        $cfg       = $c->get('config.database');
        $driverMap = [
            'sqlite'     => 'pdo_sqlite',
            'pgsql'      => 'pdo_pgsql',
            'postgres'   => 'pdo_pgsql',
            'postgresql' => 'pdo_pgsql',
        ];
        $params = [
            'driver'   => $driverMap[$cfg['driver']] ?? 'pdo_mysql',
            'user'     => $cfg['user'],
            'password' => $cfg['pass'],
        ];

        if ($cfg['driver'] === 'sqlite') {
            $path = $cfg['name'];
            if ($path !== ':memory:' && !str_starts_with($path, '/') && !preg_match('/^[a-zA-Z]:/', $path)) {
                $path = base_path(ltrim($path, '/\\'));
            }
            $params['path'] = $path;
        } else {
            $params['host']    = $cfg['host'];
            $params['port']    = $cfg['port'];
            $params['dbname']  = $cfg['name'];
            $params['charset'] = $cfg['charset'];
        }

        return DriverManager::getConnection($params);
    }),

    // ── Encryption (libsodium) ──────────────────────────────────────
    Encrypter::class => factory(function ($c) {
        $appKey = (string) env('APP_KEY', '');
        return new Encrypter($appKey);
    }),

    // ── Session ──────────────────────────────────────────────────────
    SessionManager::class => factory(function ($c) {
        $cfg = $c->get('config.auth');
        return new SessionManager($cfg['session']);
    }),

    // ── Login (remember-me config) ───────────────────────────────────
    LoginUseCase::class => factory(function ($c) {
        $cfg = $c->get('config.auth');
        return new LoginUseCase(
            $c->get(UserRepositoryInterface::class),
            $c->get(PasswordHasher::class),
            $c->get(SessionManager::class),
            $c->get(RoleRepositoryInterface::class),
            $c->get(RateLimiter::class),
            $c->get(DbalSettingsRepository::class),
            $cfg['remember_me'] ?? [],
        );
    }),

    // ── Cache (PSR-16) ───────────────────────────────────────────────
    CacheInterface::class => factory(function ($c) {
        $cfg = $c->get('config.cache');
        return new FileCache($cfg['path'], $cfg['ttl']);
    }),

    FileCache::class => get(CacheInterface::class),

    // ── Admin Search Cache ──────────────────────────────────
    AdminSearchCache::class => factory(function ($c) {
        return new AdminSearchCache($c->get(CacheInterface::class));
    }),

    // ── Page Cache ───────────────────────────────────────────────────
    PageCache::class => factory(function ($c) {
        $cfg = $c->get('config.cache');
        return new PageCache(
            $cfg['page']['path'],
            $cfg['page']['ttl'],
            $cfg['page']['exclude'],
            $cfg['page']['enabled'],
        );
    }),

    // ── Rate Limiting ────────────────────────────────────────────────
    RateLimiter::class => factory(function ($c) {
        $cfg = $c->get('config.auth');
        return new RateLimiter($cfg['rate_limit']['storage_path']);
    }),

    'rate_limit.login' => factory(function ($c) {
        $cfg = $c->get('config.auth');
        return new RateLimitMiddleware(
            $c->get(RateLimiter::class),
            'login',
            $cfg['rate_limit']['login'],
        );
    }),

    'rate_limit.form' => factory(function ($c) {
        $cfg = $c->get('config.auth');
        return new RateLimitMiddleware(
            $c->get(RateLimiter::class),
            'form',
            $cfg['rate_limit']['form'],
        );
    }),

    'rate_limit.api' => factory(function ($c) {
        $cfg = $c->get('config.auth');
        return new RateLimitMiddleware(
            $c->get(RateLimiter::class),
            'api',
            $cfg['rate_limit']['api'],
        );
    }),

    // ── Probe / scanner throttling (outermost — see bootstrap/app.php) ──
    ProbeBlockMiddleware::class => create(ProbeBlockMiddleware::class),

    ProbeRateLimitMiddleware::class => factory(function ($c) {
        $cfg = $c->get('config.auth');
        return new ProbeRateLimitMiddleware(
            $c->get(RateLimiter::class),
            $cfg['rate_limit']['probe'],
            $cfg['rate_limit']['probe_window'],
        );
    }),

    // ── Events / Hooks ───────────────────────────────────────────────
    EventDispatcherInterface::class => create(EventDispatcher::class),

    // ── Plugins (user-owned code in content/) ────────────────────────
    PluginSecurityScanner::class => create(PluginSecurityScanner::class),

    PluginManager::class => factory(function ($c) {
        $cfg         = $c->get('config.app');
        $versionFile = $cfg['paths']['root'] . '/version.php';
        $version     = is_file($versionFile) ? (string) (require $versionFile) : '0.0.0';

        return new PluginManager(
            $c->get(SettingsRepositoryInterface::class),
            $cfg['paths']['root'] . '/content/plugins',
            $version,
            filter_var(env('VL_DISABLE_PLUGINS', ''), FILTER_VALIDATE_BOOLEAN),
            $c->get(PluginSecurityScanner::class),
        );
    }),

    PluginLoader::class => factory(function ($c) {
        $cfg = $c->get('config.app');

        return new PluginLoader(
            $c->get(PluginManager::class),
            $cfg['paths']['root'] . '/content/mu-plugins',
            $c->get(App::class),
            $c,
            $c->get(Twig::class),
        );
    }),

    // ── Translation (i18n) ───────────────────────────────────────────
    Translator::class => factory(function ($c) {
        $cfg           = $c->get('config.app');
        $settings      = $c->get(DbalSettingsRepository::class);
        $defaultLocale = $settings->get('default_locale', $cfg['locale']);
        return new Translator(
            $cfg['paths']['resources'] . '/lang',
            $cfg['locale'],
            $cfg['locales'],
            $defaultLocale,
        );
    }),

    // ── CSRF ─────────────────────────────────────────────────────────
    CsrfManager::class => factory(function ($c) {
        $cfg     = $c->get('config.auth');
        $session = $c->get(SessionManager::class);
        return new CsrfManager($session, $cfg['csrf']);
    }),

    // ── Theme Manager ─────────────────────────────────────────────────
    ThemeManager::class => factory(function ($c) {
        $cfg      = $c->get('config.app');
        $settings = $c->get(DbalSettingsRepository::class);
        $session  = $c->get(SessionManager::class);

        try {
            $activeTheme = $session->get('preview_theme') ?? $settings->get('active_theme') ?? 'default';
        } catch (\Throwable) {
            $activeTheme = 'default';
        }
        return new ThemeManager($cfg['paths']['themes'], (string) $activeTheme);
    }),

    // ── Twig ─────────────────────────────────────────────────────────
    Twig::class => factory(function ($c) {
        $cfg          = $c->get('config.app');
        $cacheCfg     = $c->get('config.cache');
        $themeManager = $c->get(ThemeManager::class);

        $paths = $themeManager->buildTemplatePaths($cfg['paths']['resources'] . '/views');

        $twig = Twig::create($paths, [
            'cache'       => $cfg['env'] !== 'dev' && !$cfg['debug'] ? $cacheCfg['views'] : false,
            'auto_reload' => $cfg['env'] === 'dev' || $cfg['debug'],
        ]);

        $translator = $c->get(Translator::class);
        $csrf       = $c->get(CsrfManager::class);

        $couponRepo = $c->get(CouponRepositoryInterface::class);
        $allCoupons = [];
        try {
            $allCoupons = array_values(array_map(
                fn (Coupon $coupon) => [
                    'id'         => (int) $coupon->getId(),
                    'code'       => $coupon->getCode(),
                    'type'       => $coupon->getType(),
                    'amount'     => (float) $coupon->getAmount(),
                    'enabled'    => $coupon->isEnabled(),
                    'expires_at' => $coupon->getExpiresAt()?->format('Y-m-d'),
                ],
                array_values(array_filter($couponRepo->findAll(), fn ($coupon) => $coupon->isEnabled())),
            ));
        } catch (\Throwable $e) {
            // Table doesn't exist yet during initial installation/migration
        }

        $settings = $c->get(DbalSettingsRepository::class);
        $env      = $twig->getEnvironment();
        $env->addGlobal('_vl_coupons', json_encode($allCoupons));
        $env->addGlobal('site_name', $settings->get('site_name', $cfg['name']));
        $env->addGlobal('site_tagline', $settings->get('site_tagline', ''));
        $env->addGlobal('font_family', $settings->get('font_family', 'inter'));
        $env->addGlobal('site_logo', $settings->get('site_logo', ''));
        $env->addGlobal('default_locale', $settings->get('default_locale', 'en'));
        $env->addGlobal('current_locale', $translator->getLocale());
        $env->addGlobal('locale', $translator->getLocale());

        $env->addGlobal('cookie_warning_enabled', $settings->get('cookie_warning_enabled', '0'));
        $env->addGlobal('cookie_warning_text', $settings->get('cookie_warning_text', ''));
        $env->addGlobal('shop_enabled', $settings->get('shop_enabled', '1'));
        $env->addGlobal('membership_enabled', $settings->get('membership_enabled', '1'));
        $env->addGlobal('subscriptions_enabled', $settings->get('subscriptions_enabled', '1'));
        $env->addGlobal('user_language_choice', $settings->get('user_language_choice', '1'));
        $env->addGlobal('allow_comment_replies', $settings->get('allow_comment_replies', '1'));
        $env->addGlobal('comment_max_depth', (int) $settings->get('comment_max_depth', '2'));

        $env->addGlobal(
            'comments_login_required',
            $settings->get('comments_login_required', '0')
        );
        $env->addGlobal(
            'reviews_login_required',
            $settings->get('reviews_login_required', '0')
        );
        $env->addGlobal('comment_system', $settings->get('comment_system', 'local'));
        $env->addGlobal('disqus_shortname', $settings->get('disqus_shortname', ''));
        $env->addGlobal('giscus_repo', $settings->get('giscus_repo', ''));
        $env->addGlobal('giscus_repo_id', $settings->get('giscus_repo_id', ''));
        $env->addGlobal('giscus_category', $settings->get('giscus_category', ''));
        $env->addGlobal('giscus_category_id', $settings->get('giscus_category_id', ''));
        $env->addGlobal('giscus_mapping', $settings->get('giscus_mapping', 'pathname'));
        // Empty means "let the active theme decide" (core → light, dark themes → dark).
        $env->addGlobal('giscus_theme', $settings->get('giscus_theme', ''));
        $env->addGlobal('facebook_app_id', $settings->get('facebook_app_id', ''));
        $env->addGlobal('multi_language_content', $settings->get('multi_language_content', '0'));
        $env->addGlobal('content_languages', $settings->get('content_languages', ''));

        $env->addGlobal('advertisement_enabled', $settings->get('advertisement_enabled', '0'));
        $env->addGlobal('advertisement_code', $settings->get('advertisement_code', ''));
        $env->addGlobal('advertisement_placement', $settings->get('advertisement_placement', 'manual'));

        // Captcha provider readiness for the block editor (booleans only —
        // secret keys are never exposed to the browser).
        $env->addGlobal('vl_captcha_configured', [
            'hcaptcha'  => trim((string) $settings->get('hcaptcha_site_key', ''))  !== ''
                && trim((string) $settings->get('hcaptcha_secret_key', ''))        !== '',
            'recaptcha' => trim((string) $settings->get('recaptcha_site_key', '')) !== ''
                && trim((string) $settings->get('recaptcha_secret_key', ''))       !== '',
            'turnstile' => trim((string) $settings->get('turnstile_site_key', '')) !== ''
                && trim((string) $settings->get('turnstile_secret_key', ''))       !== '',
        ]);

        $env->addGlobal('routing_account_prefix', '/' . ltrim($settings->get('routing_account_prefix', '/account'), '/'));
        $env->addGlobal('routing_blog_prefix', '/' . ltrim($settings->get('routing_blog_prefix', '/blog'), '/'));
        $env->addGlobal('routing_shop_prefix', '/' . ltrim($settings->get('routing_shop_prefix', '/shop'), '/'));
        $env->addGlobal('routing_cart_prefix', '/' . ltrim($settings->get('routing_cart_prefix', '/cart'), '/'));
        $env->addGlobal('routing_checkout_prefix', '/' . ltrim($settings->get('routing_checkout_prefix', '/checkout'), '/'));
        $env->addGlobal('routing_membership_prefix', '/' . ltrim($settings->get('routing_membership_prefix', '/membership') ?? '/membership', '/'));
        $env->addGlobal('routing_category_prefix', '/' . ltrim($settings->get('routing_category_prefix', '/category'), '/'));
        $env->addGlobal('routing_tag_prefix', '/' . ltrim($settings->get('routing_tag_prefix', '/tag'), '/'));
        $env->addGlobal('routing_page_prefix', $settings->get('routing_page_prefix', '') ? ('/' . ltrim($settings->get('routing_page_prefix', ''), '/')) : '');

        $env->addGlobal('routing_login_prefix', '/' . ltrim($settings->get('routing_login_prefix', '/login'), '/'));
        $env->addGlobal('routing_register_prefix', '/' . ltrim($settings->get('routing_register_prefix', '/register'), '/'));
        $env->addGlobal('routing_forgot_password_prefix', '/' . ltrim($settings->get('routing_forgot_password_prefix', '/forgot-password'), '/'));
        $env->addGlobal('routing_reset_password_prefix', '/' . ltrim($settings->get('routing_reset_password_prefix', '/reset-password'), '/'));
        $env->addGlobal('routing_verify_email_prefix', '/' . ltrim($settings->get('routing_verify_email_prefix', '/verify-email'), '/'));
        $env->addGlobal('routing_logout_prefix', '/' . ltrim($settings->get('routing_logout_prefix', '/logout'), '/'));

        // site_url: prefer APP_URL (installer-set) over config
        $appUrl = rtrim((string) env('APP_URL', $cfg['url']), '/');
        $env->addGlobal('site_url', $appUrl);
        $env->addGlobal('show_author_on_posts', $settings->get('show_author_on_posts', '1'));
        $env->addGlobal('show_modification_date_on_posts', $settings->get('show_modification_date_on_posts', '0'));
        $env->addGlobal('show_timestamp_on_posts', $settings->get('show_timestamp_on_posts', '0'));
        $env->addGlobal('show_visitor_timezone', $settings->get('show_visitor_timezone', '1'));
        $env->addGlobal('show_reading_time_on_posts', $settings->get('show_reading_time_on_posts', '0'));
        $env->addGlobal('reading_time_wpm', $settings->get('reading_time_wpm', '200'));
        $env->addGlobal('show_read_count_on_posts', $settings->get('show_read_count_on_posts', '0'));

        // base_path: extracted from APP_URL for use in templates
        $parsed   = parse_url($appUrl);
        $basePath = isset($parsed['path']) ? rtrim($parsed['path'], '/') : '';
        $env->addGlobal('base_path', $basePath);

        // assets_use_cdn: controls whether to load assets from CDN or local build
        $assetsUseCdn = (bool) env('ASSETS_USE_CDN', false);
        $env->addGlobal('assets_use_cdn', $assetsUseCdn);

        // client_search_fetch_limit: max records preloaded for client-side search (admin JS)
        $env->addGlobal('client_search_fetch_limit', client_side_search_fetch_limit());

        $matchCategoryColor = static function (string $category): string {
            $lower = strtolower($category);
            if (str_contains($lower, 'added')) {
                return 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400';
            }
            if (str_contains($lower, 'fixed')) {
                return 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400';
            }
            if (str_contains($lower, 'security')) {
                return 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400';
            }
            if (str_contains($lower, 'performance')) {
                return 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400';
            }
            if (str_contains($lower, 'changed')) {
                return 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300';
            }
            if (str_contains($lower, 'documentation')) {
                return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400';
            }
            if (str_contains($lower, 'testing')) {
                return 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-400';
            }
            if (str_contains($lower, 'deprecated')) {
                return 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400';
            }
            if (str_contains($lower, 'removed')) {
                return 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400';
            }
            return 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300';
        };

        $env->addFilter(new TwigFilter('changelog', function (?string $text) use ($matchCategoryColor): string {
            if ($text === null || $text === '') {
                return '';
            }

            $lines      = explode("\n", $text);
            $html       = [];
            $inList     = false;
            $inCategory = false;

            $closeList = static function () use (&$html, &$inList, &$inCategory): void {
                if ($inList) {
                    $html[] = '</ul>';
                    $inList = false;
                }
                if ($inCategory) {
                    $html[]     = '</div>';
                    $inCategory = false;
                }
            };

            foreach ($lines as $line) {
                $trimmed = trim($line);

                if ($trimmed === '') {
                    $closeList();
                    continue;
                }

                if (preg_match('/^## \[(.+?)\](?:\s+-\s+(.+))?$/', $trimmed, $m)) {
                    $closeList();
                    $version = htmlspecialchars($m[1], ENT_QUOTES | ENT_HTML5);
                    $date    = isset($m[2]) ? htmlspecialchars($m[2], ENT_QUOTES | ENT_HTML5) : '';
                    $html[]  = '<div class="mb-4 pb-3 border-b border-gray-200 dark:border-gray-700 last:border-0">';
                    $html[]  = '<h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">v' . $version . '</h3>';
                    if ($date !== '') {
                        $html[] = '<span class="text-xs text-gray-400 ml-2">' . $date . '</span>';
                    }
                    $html[] = '</div>';
                } elseif (preg_match('/^### (.+)$/', $trimmed, $m)) {
                    $closeList();
                    $category   = htmlspecialchars($m[1], ENT_QUOTES | ENT_HTML5);
                    $badgeColor = $matchCategoryColor($m[1]);
                    $html[]     = '<div class="mb-3">';
                    $html[]     = '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ' . $badgeColor . '">' . $category . '</span>';
                    $html[]     = '<ul class="mt-1.5 ml-1 space-y-1 text-sm text-gray-700 dark:text-gray-300">';
                    $inList     = true;
                    $inCategory = true;
                } elseif (preg_match('/^- (.+)$/', $trimmed, $m)) {
                    if (!$inList) {
                        $html[] = '<ul class="ml-1 space-y-1 text-sm text-gray-700 dark:text-gray-300">';
                        $inList = true;
                    }
                    $item   = htmlspecialchars($m[1], ENT_QUOTES | ENT_HTML5);
                    $html[] = '<li class="flex items-start gap-2"><span class="text-gray-400 mt-1 shrink-0">•</span><span>' . $item . '</span></li>';
                } elseif (!$inList) {
                    $html[] = '<p class="text-sm text-gray-600 dark:text-gray-400">' . htmlspecialchars($trimmed, ENT_QUOTES | ENT_HTML5) . '</p>';
                }
            }

            $closeList();

            return implode("\n", $html);
        }, ['is_safe' => ['html']]));

        $env->addGlobal('footer_copyright', $settings->get('footer_copyright', ''));
        $env->addGlobal('supported_locales', $translator->getAvailableLocales());
        $env->addGlobal('all_locales', $translator->getAllAvailableLocales());
        $referenceData = require dirname(__DIR__) . '/config/reference-data.php';
        $env->addGlobal('supported_timezones', is_array($referenceData['timezones'] ?? null) ? $referenceData['timezones'] : []);
        $env->addGlobal('languages', is_array($referenceData['languages'] ?? null) ? $referenceData['languages'] : []);
        $env->addGlobal('active_theme', $themeManager->getActiveTheme());
        $env->addGlobal('theme_meta', $themeManager->getThemeMeta());

        // Third-party service lists (cron, webhook test, exchange rate, etc.) — config/reference-data.php
        $servicesList  = is_array($referenceData['services'] ?? null) ? $referenceData['services'] : [];
        $env->addGlobal('services_list', $servicesList);
        $env->addGlobal('captcha_urls', is_array($referenceData['captcha_urls'] ?? null) ? $referenceData['captcha_urls'] : []);
        $env->addGlobal('media_embeds', is_array($referenceData['media_embeds'] ?? null) ? $referenceData['media_embeds'] : []);
        $env->addGlobal('cron_mode', $settings->get('cron_mode', 'none'));

        // Icons
        $env->addFunction(new TwigFunction('resolveIcon', [IconHelper::class, 'resolveIcon']));

        // SEO globals
        $engineVisibility = $settings->get('search_engine_visibility', '1');
        $env->addGlobal('seo_search_engine_visibility', $engineVisibility);
        $env->addGlobal('seo_title_separator', $settings->get('seo_title_separator', ' — '));
        $env->addGlobal('seo_home_description', $settings->get('seo_home_description', ''));
        $env->addGlobal('seo_noindex_archives', $settings->get('seo_noindex_archives', '0'));
        $env->addGlobal('google_search_console_code', $settings->get('meta_google_verification', ''));
        $env->addGlobal('bing_webmaster_code', $settings->get('meta_bing_verification', ''));
        $env->addGlobal('yandex_verification_code', $settings->get('meta_yandex_verification', ''));



        // Twig functions: __('key'), csrf_field(), theme_hook()
        $env->addFunction(new TwigFunction('__', [$translator, 'get']));
        $env->addFunction(new TwigFunction('current_locale', [$translator, 'getLocale']));

        // local_date filter: locale-aware date formatting using IntlDateFormatter.
        // Usage: {{ post.publishedAt|local_date }}            -> "26 Haziran 2026" (tr) / "26 June 2026" (en) / "26 juin 2026" (fr)
        //        {{ post.publishedAt|local_date('medium') }}   -> "26 Haz 2026" (tr)
        //        {{ post.publishedAt|local_date('short') }}    -> "26.06.2026" (tr)
        //        {{ post.publishedAt|local_date('full') }}     -> "Cuma, 26 Haziran 2026" (tr)
        //        {{ post.publishedAt|local_date('long', true) }} -> "26 Haziran 2026 14:30"
        // Falls back to PHP date() when intl extension is unavailable.
        $env->addFilter(new TwigFilter(
            'local_date',
            function ($value, string $style = 'long', bool $withTime = false) use ($translator): string {
                if ($value === null || $value === '') {
                    return '';
                }
                if (is_string($value)) {
                    try {
                        $value = new \DateTimeImmutable($value);
                    } catch (\Throwable) {
                        return (string) $value;
                    }
                }
                $locale = strtolower($translator->getLocale());
                // No locale allowlist: getLocale() only returns supported locales and
                // IntlDateFormatter handles any of them (a new lang/*.json just works).
                if (extension_loaded('intl')) {
                    $intlStyleMap = [
                        'full'   => \IntlDateFormatter::FULL,
                        'long'   => \IntlDateFormatter::LONG,
                        'medium' => \IntlDateFormatter::MEDIUM,
                        'short'  => \IntlDateFormatter::SHORT,
                    ];
                    $dateType = $intlStyleMap[$style] ?? \IntlDateFormatter::LONG;
                    $timeType = $withTime ? \IntlDateFormatter::SHORT : \IntlDateFormatter::NONE;
                    // ICU rejects numeric-offset zone names ('+00:00') and 'Z' —
                    // normalize them to UTC (same instant, valid identifier).
                    $tzName = $value->getTimezone()->getName();
                    if ($tzName === false || preg_match('/^[+-]\d{2}:?\d{2}$/', $tzName) === 1 || $tzName === 'Z' || $tzName === 'z') {
                        $tzName = 'UTC';
                    }
                    $fmt = null;
                    try {
                        $fmt = new \IntlDateFormatter(
                            $locale,
                            $dateType,
                            $timeType,
                            $tzName,
                            \IntlDateFormatter::GREGORIAN,
                        );
                    } catch (\Throwable) {
                        $fmt = null;
                    }
                    $out = false;
                    if ($fmt !== null) {
                        $out = $fmt->format($value);
                    }
                    if ($out !== false) {
                        return $out;
                    }
                }
                $phpFormatMap = [
                    'full'   => 'l, F j, Y',
                    'long'   => 'F j, Y',
                    'medium' => 'M j, Y',
                    'short'  => 'Y-m-d',
                ];
                $fmtStr = $phpFormatMap[$style] ?? 'F j, Y';
                if ($withTime) {
                    $fmtStr .= ' H:i';
                }
                return $value->format($fmtStr);
            }
        ));

        // iso_date filter: machine-readable ISO-8601 with UTC offset for <time datetime="...">.
        // Usage: {{ post.publishedAt|iso_date }} -> "2026-09-28T18:30:10+02:00"
        // Naive values (plain DB strings) are interpreted in the PHP default timezone,
        // which the bootstrap sets from the site `timezone` setting — so the offset is
        // always the site offset. Pair with data-vtz for visitor-timezone JS conversion.
        $env->addFilter(new TwigFilter(
            'iso_date',
            static function ($value): string {
                if ($value === null || $value === '') {
                    return '';
                }
                if (is_string($value)) {
                    try {
                        $value = new \DateTimeImmutable($value);
                    } catch (\Throwable) {
                        return (string) $value;
                    }
                }
                if (!$value instanceof \DateTimeInterface) {
                    return (string) $value;
                }
                return $value->format('c');
            }
        ));

        // |price filter: locale-aware currency formatting.
        // Usage: {{ product.price|price }}       -> uses current_currency + translator locale
        //        {{ product.price|price('USD') }} -> explicit currency code
        //        {{ (product.price * fx_rate)|price(current_currency) }} -> converted amount + explicit currency
        // Falls back to symbol + number_format (dot decimal) when intl extension is unavailable.
        $env->addFilter(new TwigFilter(
            'price',
            function ($amount, ?string $currency = null) use ($env, $translator): string {
                $amount = (float) $amount;
                if ($currency === null) {
                    $globals  = $env->getGlobals();
                    $currency = $globals['current_currency'] ?? $globals['default_currency'] ?? 'USD';
                }
                static $symbols = null;
                if ($symbols === null) {
                    $refData = require __DIR__ . '/reference-data.php';
                    $symbols = [];
                    foreach (($refData['currencies'] ?? []) as $c) {
                        $code = strtoupper((string) ($c['code'] ?? ''));
                        $sym  = (string) ($c['symbol'] ?? '');
                        if ($code !== '' && $sym !== '') {
                            $symbols[$code] = $sym;
                        }
                    }
                }
                $symbol = $symbols[$currency] ?? $currency . ' ';
                if (extension_loaded('intl')) {
                    $locale = strtolower($translator->getLocale() ?? 'en');
                    $fmt    = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
                    $fmt->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, 2);
                    $fmt->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 2);
                    $result = $fmt->formatCurrency($amount, $currency);
                    if ($result !== false) {
                        return $result;
                    }
                }
                return $symbol . number_format($amount, 2);
            }
        ));

        // |num filter: locale-aware number formatting for non-currency values (e.g., tax rates)
        // Usage: {{ value|num(2) }}
        // Falls back to number_format (dot decimal) when intl extension is unavailable.
        $env->addFilter(new TwigFilter(
            'num',
            function ($value, int $decimals = 0) use ($translator): string {
                $value = (float) $value;
                if (extension_loaded('intl')) {
                    $locale = strtolower($translator->getLocale() ?? 'en');
                    $fmt    = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
                    $fmt->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
                    $fmt->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $decimals);
                    $result = $fmt->format($value);
                    if ($result !== false) {
                        return $result;
                    }
                }
                return number_format($value, $decimals);
            }
        ));

        $env->addFunction(new TwigFunction('csrf_field', function () use ($csrf) {
            $token = $csrf->getToken();
            $html  = '<input type="hidden" name="_csrf_token" value="'
                . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
            return new Markup($html, 'UTF-8');
        }, ['is_safe' => ['html']]));
        $env->addFunction(new TwigFunction('csrf_token', function () use ($csrf): string {
            return $csrf->getToken();
        }));
        $env->addFunction(new TwigFunction('theme_hook', function (string $hook) use ($themeManager): string {
            return $themeManager->doHook($hook);
        }, ['is_safe' => ['html']]));

        // read_time filter: calculates estimated reading time.
        // Usage: {{ post.body|read_time }}
        $readingTimeService = $c->get(ReadingTimeService::class);
        $env->addFilter(new TwigFilter('read_time', [$readingTimeService, 'calculate']));

        // compact_count filter: human-friendly view counters.
        // Usage: {{ post.viewCount|compact_count }}
        $env->addFilter(new TwigFilter('compact_count', static function (mixed $count): string {
            $count = max(0, (int) $count);
            if ($count < 1000) {
                return (string) $count;
            }
            foreach ([[1_000_000_000, 'B'], [1_000_000, 'M'], [1_000, 'K']] as [$size, $suffix]) {
                if ($count >= $size) {
                    $value = $count / $size;
                    return ($value >= 100 ? number_format($value, 0, '.', '') : number_format($value, 1, '.', '')) . $suffix;
                }
            }
            return (string) $count;
        }));

        // more_teaser filter: splits a body on the <!--more--> marker.
        // Returns [teaserHtml, customLabel|null]. Usage: {% set t = post.body|more_teaser %}
        $env->addFilter(new TwigFilter('more_teaser', static function (?string $body): array {
            return ContentMarkerNormalizer::splitOnMore($body);
        }));

        // post_teaser filter: one summary for every card surface — manual
        // excerpt, then the content before the <!--more--> break, then an auto
        // summary of the first N words (Settings → Content → Blog).
        $env->addFilter(new TwigFilter('post_teaser', static function (Post $post, ?int $words = null) use ($settings): array {
            $limit = $words ?? (int) ($settings->get('listing_teaser_words', '55') ?? '55');

            return ContentSummary::blockTeaser($post->getBody(), $post->getExcerpt(), $limit);
        }));

        // meta_teaser filter: plain-text description for meta/og/twitter tags.
        // The meta description wins over the manual excerpt; the auto summary
        // is only used while the listing summary length is enabled.
        $env->addFilter(new TwigFilter('meta_teaser', static function (mixed $entity, int $maxChars = 160) use ($settings): string {
            $limit = (int) ($settings->get('listing_teaser_words', '55') ?? '55');

            if ($entity instanceof Post) {
                $manual = $entity->getMetaDescription() ?: $entity->getExcerpt();

                return $manual !== null && $manual !== ''
                    ? ContentSummary::plainTeaser($entity->getBody(), $manual, $maxChars)
                    : ($limit > 0 ? ContentSummary::plainTeaser($entity->getBody(), null, $maxChars) : '');
            }

            if ($entity instanceof Product) {
                $manual = $entity->getMetaDescription() ?: $entity->getShortDescription();

                return $manual !== null && $manual !== ''
                    ? ContentSummary::plainTeaser($entity->getDescription(), $manual, $maxChars)
                    : ($limit > 0 ? ContentSummary::plainTeaser($entity->getDescription(), null, $maxChars) : '');
            }

            return '';
        }));

        // Shortcode filter: {{ post.body|shortcodes(post) }}
        $shortcodes = $c->get(ShortcodeProcessor::class);
        $env->addFilter(new TwigFilter('shortcodes', function (?string $content, $post = null) use ($shortcodes, $settings): string {
            $processed = $shortcodes->process($content ?? '', $post instanceof Post ? $post : null);

            if ($settings->get('media_lazy_loading_enabled', '1') === '1') {
                $processed = preg_replace_callback('/<img\s+([^>]+)>/i', function ($matches) {
                    $attributes = $matches[1];
                    if (str_contains($attributes, 'loading=')) {
                        return $matches[0];
                    }
                    return '<img ' . $attributes . ' loading="lazy">';
                }, $processed);
            }

            return $processed;
        }, ['is_safe' => ['html']]));

        // url() function: resolves named routes via Slim's RouteParser,
        // or prepends base_path to plain paths (when path starts with /)
        $env->addFunction(new TwigFunction('url', function (string $path, array $data = [], array $queryParams = []) use ($c): string {
            if (
                str_starts_with($path, 'http://')  ||
                str_starts_with($path, 'https://') ||
                str_starts_with($path, '//')
            ) {
                return $path;
            }

            $resolvedPath = $path;
            if (!str_starts_with($path, '/')) {
                try {
                    $app          = $c->get(App::class);
                    $routeParser  = $app->getRouteCollector()->getRouteParser();
                    $resolvedPath = $routeParser->urlFor($path, $data, $queryParams);
                    $queryParams  = []; // Slim already included them
                } catch (\Throwable) {
                    $resolvedPath = '/' . $path;
                }
            }

            return site_url($resolvedPath, [], $queryParams);
        }));

        // locale_name() — language name in the current UI locale (admin or visitor)
        $env->addFunction(new TwigFunction('locale_name', function (string $code) use ($translator): string {
            if (extension_loaded('intl')) {
                $displayIn = $translator->getLocale();
                $name      = \Locale::getDisplayLanguage($code, $displayIn);
                if ($name !== false && $name !== '') {
                    return $name;
                }
            }

            return strtoupper($code);
        }));

        // country_name() — country name in the current UI locale (admin)
        $env->addFunction(new TwigFunction('country_name', function (string $code) use ($translator): string {
            if (extension_loaded('intl') && $code !== '') {
                $name = \Locale::getDisplayRegion('-' . $code, $translator->getLocale());
                if ($name !== false && $name !== '') {
                    return $name;
                }
            }

            return $code;
        }));

        // language_name() — language name in the current UI locale (admin)
        $env->addFunction(new TwigFunction('language_name', function (string $code) use ($translator): string {
            if (extension_loaded('intl') && $code !== '') {
                $name = \Locale::getDisplayLanguage($code, $translator->getLocale());
                if ($name !== false && $name !== '') {
                    return ucfirst($name) . ' (' . strtoupper($code) . ')';
                }
            }

            return strtoupper($code);
        }));

        // site_url() function: full site URL with locale-aware prefixing
        $env->addFunction(new TwigFunction('site_url', function (string $path = '', array $data = [], array $queryParams = []): string {
            return site_url($path, $data, $queryParams);
        }));

        // absolute_url() function: guaranteed absolute URL (hreflang, feeds)
        $env->addFunction(new TwigFunction('absolute_url', function (string $path = '', array $data = [], array $queryParams = []): string {
            return absolute_url($path, $data, $queryParams);
        }));

        // get_media_url() function: resolves media ID to full URL
        $mediaRepo = $c->get(MediaRepositoryInterface::class);
        $env->addFunction(new TwigFunction('get_media_url', function ($id) use ($mediaRepo, $appUrl): ?string {
            if (!$id) {
                return null;
            }
            $media = $mediaRepo->findById((int) $id);
            // Prefer the WebP rendition when it exists; fall back to the original.
            $url = $media ? ($media->getThumbnailUrl('webp') ?? $media->getUrl()) : null;
            return $url ? rtrim($appUrl, '/') . $url : null;
        }));

        // asset() function: returns URL for local build assets, with cache-busting version
        $env->addFunction(new TwigFunction('asset', function (string $path) use ($basePath): string {
            if (
                str_starts_with($path, 'http://')  ||
                str_starts_with($path, 'https://') ||
                str_starts_with($path, '//')
            ) {
                return $path;
            }
            $file    = dirname(__DIR__) . '/public/' . ltrim($path, '/');
            $version = file_exists($file) ? (string) filemtime($file) : '';
            $url     = $basePath . '/' . ltrim($path, '/');
            return $version !== '' ? $url . '?v=' . $version : $url;
        }));

        // env() function: expose PHP env() to Twig
        $env->addFunction(new TwigFunction('env', function (string $key, mixed $default = null): mixed {
            return env($key, $default);
        }));

        // vale_version() function: reads version.php
        $env->addFunction(new TwigFunction('vale_version', function (): string {
            $file = dirname(__DIR__) . '/version.php';
            return file_exists($file) ? (require $file) : '—';
        }));

        // format_bytes() function: bytes to human-readable format
        $env->addFunction(new TwigFunction('format_bytes', function (int $bytes): string {
            if ($bytes === 0) {
                return '0 B';
            }
            $units = ['B', 'KB', 'MB', 'GB'];
            $i     = (int) floor(log($bytes, 1024));
            return round($bytes / (1024 ** $i), 2) . ' ' . $units[$i];
        }));

        return $twig;
    }),

    // ── Middleware ────────────────────────────────────────────────────
    ApiAbilityMiddleware::class => factory(function () {
        return new ApiAbilityMiddleware(
            AppFactory::determineResponseFactory(),
        );
    }),

    CsrfMiddleware::class => factory(function ($c) {
        return new CsrfMiddleware($c->get(CsrfManager::class));
    }),

    LocaleMiddleware::class => factory(function ($c) {
        return new LocaleMiddleware(
            $c->get(Translator::class),
            $c->get(DbalSettingsRepository::class),
            $c->get(SessionManager::class),
            $c->get(Twig::class),
            $c->get(MenuRepositoryInterface::class),
            $c->get(ThemeManager::class),
            $c->get(UserRepositoryInterface::class),
            $c->get(PostRepositoryInterface::class),
            $c->get(CategoryRepositoryInterface::class),
            $c->get(ProductRepositoryInterface::class),
            $c->get(ContentTypeRepositoryInterface::class),
        );
    }),

    RedirectMiddleware::class => factory(function ($c) {
        return new RedirectMiddleware(
            $c->get(RedirectService::class),
            $c->get(Translator::class),
            $c->get(DbalSettingsRepository::class)
        );
    }),

    ExportImportService::class => factory(function ($c) {
        return new ExportImportService(
            $c->get(DbalConnection::class),
            $c->get(DbalSettingsRepository::class)
        );
    }),

    SavePostUseCase::class => factory(function ($c) {
        return new SavePostUseCase(
            $c->get(PostRepositoryInterface::class),
            $c->get(SlugService::class),
            $c->get(RevisionRepositoryInterface::class),
            $c->get(DbalSettingsRepository::class),
            $c->get(RedirectService::class),
            $c->get(ContentTypeRepositoryInterface::class),
            $c->get(RoutingValidator::class)
        );
    }),

    SaveCategoryUseCase::class => factory(function ($c) {
        return new SaveCategoryUseCase(
            $c->get(CategoryRepositoryInterface::class),
            $c->get(SlugService::class),
            $c->get(RedirectService::class),
            $c->get(DbalSettingsRepository::class),
            $c->get(ContentTypeRepositoryInterface::class),
        );
    }),

    SaveTagUseCase::class => factory(function ($c) {
        return new SaveTagUseCase(
            $c->get(TagRepositoryInterface::class),
            $c->get(SlugService::class),
            $c->get(RedirectService::class),
            $c->get(DbalSettingsRepository::class),
            $c->get(ContentTypeRepositoryInterface::class),
        );
    }),

    ProductController::class         => autowire(),
    ProductCategoryController::class => autowire(),

    PageCacheMiddleware::class => factory(function ($c) {
        return new PageCacheMiddleware(
            $c->get(PageCache::class),
            $c->get(DbalSettingsRepository::class),
        );
    }),

    MaintenanceMiddleware::class => factory(function ($c) {
        return new MaintenanceMiddleware(
            $c->get(DbalSettingsRepository::class),
            $c->get(Twig::class),
            dirname(__DIR__),
        );
    }),

    AdminCacheBustMiddleware::class => factory(function ($c) {
        return new AdminCacheBustMiddleware($c->get(PageCache::class));
    }),

    FlashMiddleware::class => factory(function ($c) {
        return new FlashMiddleware(
            $c->get(SessionManager::class),
            $c->get(Twig::class),
            $c->get(DbalSettingsRepository::class),
            $c->get(DbalConnection::class),
            $c->get(ExchangeRateService::class),
            $c->get(NavOrderService::class),
            $c->get(UpdateService::class),
            $c->get(ContentTypeRepositoryInterface::class),
            $c->get(ThemeManager::class),
            $c->get(SeoState::class),
            $c->get(MediaRepositoryInterface::class),
            $c->get(ProductVoteRepositoryInterface::class),
            $c->get(UserRepositoryInterface::class),
            $c->get(CouponRepositoryInterface::class),
            $c->get(EventDispatcherInterface::class),
            $c->get(PluginManager::class),
        );
    }),

    // ── Auth & Users ─────────────────────────────────────────────────
    PasswordHasher::class => factory(function ($c) {
        $cfg = $c->get('config.auth');
        return new PasswordHasher($cfg['password']['algo'], $cfg['password']['cost']);
    }),

    UserRepositoryInterface::class => factory(function ($c) {
        return new DbalUserRepository($c->get(DbalConnection::class), $c->get(Encrypter::class));
    }),

    AuthMiddleware::class => factory(function ($c) {
        return new AuthMiddleware($c->get(SessionManager::class));
    }),

    AdminMiddleware::class => factory(function ($c) {
        return new AdminMiddleware($c->get(SessionManager::class));
    }),

    // ── M3: Content & CMS ────────────────────────────────────────────
    BlogFeedService::class => factory(function ($c) {
        $appCfg  = $c->get('config.app');
        $siteUrl = rtrim((string) env('APP_URL', $appCfg['url']), '/');
        return new BlogFeedService(
            $c->get(PostRepositoryInterface::class),
            $c->get(MediaRepositoryInterface::class),
            $siteUrl,
            $appCfg['name'] ?? 'Blog',
            $c->get(LoggerInterface::class),
            $c->get(DbalSettingsRepository::class),
            $c->get(ShortcodeProcessor::class),
        );
    }),

    SlugService::class => factory(function ($c) {
        return new SlugService($c->get(DbalConnection::class));
    }),

    ReadingTimeService::class => factory(function ($c) {
        return new ReadingTimeService(
            $c->get(Translator::class),
            $c->get(DbalSettingsRepository::class)
        );
    }),

    RedirectService::class => factory(function ($c) {
        return new RedirectService(
            $c->get(DbalConnection::class),
            $c->get(DbalSettingsRepository::class)
        );
    }),

    FormAccessService::class => factory(function ($c) {
        return new FormAccessService(
            $c->get(SessionManager::class),
            $c->get(SubscriberRepositoryInterface::class),
            $c->get(DbalSettingsRepository::class),
        );
    }),

    FormCaptchaService::class => factory(function ($c) {
        return new FormCaptchaService(
            $c->get(FormRepositoryInterface::class),
            $c->get(DbalSettingsRepository::class),
        );
    }),

    ShortcodeProcessor::class => factory(function ($c) {
        $appUrl   = rtrim((string) env('APP_URL', $c->get('config.app')['url']), '/');
        $parsed   = parse_url($appUrl);
        $basePath = isset($parsed['path']) ? rtrim($parsed['path'], '/') : '';
        return new ShortcodeProcessor(
            $c->get(FormRepositoryInterface::class),
            $c->get(CsrfManager::class),
            $c->get(SessionManager::class),
            $c->get(PostRepositoryInterface::class),
            $c->get(MediaRepositoryInterface::class),
            $c->get(ProductRepositoryInterface::class),
            $c->get(DbalSettingsRepository::class),
            $c->get(FormAccessService::class),
            $c->get(FormCaptchaService::class),
            $basePath,
            $c->get(ContentTypeRepositoryInterface::class),
        );
    }),

    PostRepositoryInterface::class => factory(function ($c) {
        return new DbalPostRepository($c->get(DbalConnection::class));
    }),

    CategoryRepositoryInterface::class => factory(function ($c) {
        return new DbalCategoryRepository($c->get(DbalConnection::class));
    }),

    ContentTypeRepositoryInterface::class => factory(function ($c) {
        return new DbalContentTypeRepository($c->get(DbalConnection::class));
    }),

    TagRepositoryInterface::class => factory(function ($c) {
        return new DbalTagRepository($c->get(DbalConnection::class));
    }),

    RevisionRepositoryInterface::class => factory(function ($c) {
        return new DbalRevisionRepository($c->get(DbalConnection::class));
    }),

    MediaRepositoryInterface::class => factory(function ($c) {
        return new DbalMediaRepository($c->get(DbalConnection::class));
    }),

    MenuRepositoryInterface::class => factory(function ($c) {
        return new DbalMenuRepository($c->get(DbalConnection::class));
    }),

    DbalSettingsRepository::class => factory(function ($c) {
        return new DbalSettingsRepository($c->get(DbalConnection::class), $c->get(Encrypter::class));
    }),

    SettingsRepositoryInterface::class => get(DbalSettingsRepository::class),

    ImageProcessor::class => create(ImageProcessor::class),

    // Redirect-hardened HTTP client shared by the download proxy and the
    // remote media importer (validates PrivateNetworkGuard on every hop).
    SafeRemoteFetcher::class => factory(static function () {
        return new SafeRemoteFetcher();
    }),

    MediaUploadService::class => factory(function ($c) {
        $cfg      = $c->get('config.media');
        $settings = $c->get(DbalSettingsRepository::class);

        $cfg['enable_webp']            = $settings->get('media_webp_enabled', '0')           === '1';
        $cfg['use_year_month_folders'] = $settings->get('media_use_year_month_folders', '1') === '1';
        $cfg['use_original_filename']  = $settings->get('media_use_original_filename', '0')  === '1';

        // Both size settings are stored in megabytes (as labeled in the admin
        // form); the service config expects bytes. 0 keeps the config default.
        $fileSizeMb           = (int) $settings->get('media_max_file_size', '0');
        $cfg['max_file_size'] = $fileSizeMb > 0 ? $fileSizeMb * 1024 * 1024 : 0;

        // Admin batch setting is stored in MB; 0 = follow the server
        // (post_max_size), which matches the service's own default.
        $batchMb = (int) $settings->get('media_max_batch_size', '0');
        if ($batchMb > 0) {
            $cfg['max_total_size'] = $batchMb * 1024 * 1024;
        }

        $cfg['max_file_count'] = (int) $settings->get('media_max_file_count', '0');

        return new MediaUploadService(
            $c->get(MediaRepositoryInterface::class),
            $c->get(ImageProcessor::class),
            $cfg,
            $c->get(CacheInterface::class),
        );
    }),

    MediaScanService::class => factory(function ($c) {
        $cfg      = $c->get('config.media');
        $settings = $c->get(DbalSettingsRepository::class);

        $cfg['enable_webp'] = $settings->get('media_webp_enabled', '0') === '1';

        return new MediaScanService(
            $c->get(DbalConnection::class),
            $c->get(ImageProcessor::class),
            $cfg,
        );
    }),

    // ── M4: SEO ──────────────────────────────────────────────────────
    SeoMetaRepositoryInterface::class => factory(function ($c) {
        return new DbalSeoMetaRepository($c->get(DbalConnection::class));
    }),
    // ── M5: Mail ─────────────────────────────────────────
    MailInterface::class => factory(function ($c) {
        $settings = $c->get(DbalSettingsRepository::class);
        $driver   = $settings->get('mail_driver', 'log');

        $appCfg  = $c->get('config.app');
        $logDir  = $appCfg['paths']['storage'] . '/logs';

        // Mail provider API base URLs come from config/reference-data.php (single source of truth).
        $referenceData = require __DIR__ . '/reference-data.php';
        $mailDrivers   = is_array($referenceData['mail_drivers'] ?? null) ? $referenceData['mail_drivers'] : [];
        $apiUrl        = static fn (string $slug, string $fallback): string => (string) ($mailDrivers[$slug]['api_url'] ?? $fallback);

        $inner   = match ($driver) {
            'smtp'     => new PHPMailerAdapter($settings),
            'phpmail'  => new PhpMailAdapter($settings),
            'resend'   => new ResendAdapter($settings, $apiUrl('resend', 'https://api.resend.com')),
            'unosend'  => new UnosendAdapter($settings, $apiUrl('unosend', 'https://api.unosend.co')),
            'mailgun'  => new MailgunAdapter($settings, $apiUrl('mailgun', 'https://api.mailgun.net/v3')),
            'sendgrid' => new SendGridAdapter($settings, $apiUrl('sendgrid', 'https://api.sendgrid.com/v3')),
            default    => new LogMailAdapter($logDir),
        };

        // File log: log driver her zaman, diğerlerinde ayara bağlı
        if ($driver !== 'log' && $settings->get('mail_log_enabled', '1') === '1') {
            return new FileLoggingMailDecorator($inner, $logDir);
        }

        return $inner;
    }),

    MailService::class => factory(function ($c) {
        return new MailService(
            $c->get(MailInterface::class),
            $c->get(Twig::class),
            $c->get(DbalSettingsRepository::class),
            $c->get(Translator::class),
            $c->get(ThemeManager::class),
        );
    }),

    // ── Plugins: admin screen + public asset serving ─────────────
    PluginMigrator::class => factory(function ($c) {
        return new PluginMigrator(
            $c->get(PluginManager::class),
            $c->get(Connection::class),
            $c->get(DbalSettingsRepository::class),
        );
    }),

    PluginUpdateService::class => factory(function ($c) {
        $root = $c->get('config.app')['paths']['root'];

        return new PluginUpdateService(
            $c->get(PluginManager::class),
            $c->get(PluginMigrator::class),
            $c->get(SettingsRepositoryInterface::class),
            $c->get(SafeRemoteFetcher::class),
            $root . '/storage/cache/plugin-updates',
            $root . '/storage/backups/plugins',
            $c->get(PluginSecurityScanner::class),
        );
    }),

    PluginAssetResolver::class => factory(function ($c) {
        return new PluginAssetResolver();
    }),

    PluginAssetController::class => factory(function ($c) {
        return new PluginAssetController(
            $c->get(PluginManager::class),
            $c->get(PluginAssetResolver::class),
        );
    }),

    PluginController::class => factory(function ($c) {
        return new PluginController(
            $c->get(Twig::class),
            $c->get(PluginManager::class),
            $c->get(PluginMigrator::class),
            $c->get(PluginUpdateService::class),
            $c->get(PageCache::class),
            $c->get(SessionManager::class),
            $c->get(Translator::class),
            $c->get(SettingsRepositoryInterface::class),
            $c->get(PluginSecurityScanner::class),
        );
    }),

    AppearanceController::class => factory(function ($c) {
        return new AppearanceController(
            $c->get(Twig::class),
            $c->get(DbalSettingsRepository::class),
            $c->get(SessionManager::class),
            $c->get(Translator::class),
        );
    }),

    // ── M6: Comments ─────────────────────────────────────────────
    CommentRepositoryInterface::class => factory(function ($c) {
        return new DbalCommentRepository($c->get(DbalConnection::class));
    }),

    // ── M6: Forms ────────────────────────────────────────────────
    FormRepositoryInterface::class => factory(function ($c) {
        return new DbalFormRepository($c->get(DbalConnection::class));
    }),

    // ── M7: Newsletter ────────────────────────────────────────────
    SubscriberRepositoryInterface::class => factory(function ($c) {
        return new DbalSubscriberRepository($c->get(DbalConnection::class));
    }),

    CampaignRepositoryInterface::class => factory(function ($c) {
        return new DbalCampaignRepository($c->get(DbalConnection::class));
    }),

    NewsletterService::class => factory(function ($c) {
        $cfg = $c->get('config.app');
        return new NewsletterService(
            $c->get(MailInterface::class),
            $c->get(CampaignRepositoryInterface::class),
            $c->get(SubscriberRepositoryInterface::class),
            $c->get(CouponRepositoryInterface::class),
            $c->get(PostRepositoryInterface::class),
            $c->get(CategoryRepositoryInterface::class),
            $c->get(TagRepositoryInterface::class),
            $c->get(MediaRepositoryInterface::class),
            $cfg['url'],
        );
    }),

    AutomationRepositoryInterface::class => factory(function ($c) {
        return new DbalAutomationRepository($c->get(DbalConnection::class));
    }),

    AutomationService::class => factory(function ($c) {
        $cfg = $c->get('config.app');
        return new AutomationService(
            $c->get(AutomationRepositoryInterface::class),
            $c->get(SubscriberRepositoryInterface::class),
            $c->get(MailInterface::class),
            $c->get(OrderRepositoryInterface::class),
            $cfg['url'],
        );
    }),

    // ── M8: Shop ──────────────────────────────────────────────────
    ProductRepositoryInterface::class => factory(function ($c) {
        return new DbalProductRepository($c->get(DbalConnection::class));
    }),

    DeliveryEstimateService::class => factory(function ($c) {
        return new DeliveryEstimateService(
            $c->get(SettingsRepositoryInterface::class),
        );
    }),

    ProductFeedService::class => factory(function ($c) {
        $cfg     = $c->get('config.shop');
        $appCfg  = $c->get('config.app');
        $siteUrl = rtrim((string) env('APP_URL', $appCfg['url']), '/');
        return new ProductFeedService(
            $c->get(ProductRepositoryInterface::class),
            $c->get(MediaRepositoryInterface::class),
            $siteUrl,
            $cfg['currency'],
            $c->get(LoggerInterface::class),
            $c->get(DbalSettingsRepository::class),
        );
    }),

    IdealoFeedService::class => factory(function ($c) {
        $cfg     = $c->get('config.shop');
        $appCfg  = $c->get('config.app');
        $siteUrl = rtrim((string) env('APP_URL', $appCfg['url']), '/');
        return new IdealoFeedService(
            $c->get(ProductRepositoryInterface::class),
            $c->get(MediaRepositoryInterface::class),
            $siteUrl,
            $cfg['currency'],
            $c->get(LoggerInterface::class),
            $c->get(DbalSettingsRepository::class),
        );
    }),

    ShopifyFeedService::class => factory(function ($c) {
        $cfg     = $c->get('config.shop');
        $appCfg  = $c->get('config.app');
        $siteUrl = rtrim((string) env('APP_URL', $appCfg['url']), '/');
        return new ShopifyFeedService(
            $c->get(ProductRepositoryInterface::class),
            $c->get(MediaRepositoryInterface::class),
            $siteUrl,
            $cfg['name'] ?? 'My Shop',
            $c->get(LoggerInterface::class),
            $c->get(DbalSettingsRepository::class),
        );
    }),

    YandexMarketFeedService::class => factory(function ($c) {
        $cfg     = $c->get('config.shop');
        $appCfg  = $c->get('config.app');
        $siteUrl = rtrim((string) env('APP_URL', $appCfg['url']), '/');
        return new YandexMarketFeedService(
            $c->get(ProductRepositoryInterface::class),
            $c->get(MediaRepositoryInterface::class),
            $siteUrl,
            $cfg['name'] ?? 'My Shop',
            $cfg['currency'],
            $c->get(LoggerInterface::class),
            $c->get(DbalSettingsRepository::class),
        );
    }),

    ProductCategoryRepositoryInterface::class => factory(function ($c) {
        return new DbalProductCategoryRepository($c->get(DbalConnection::class));
    }),

    PlanRepositoryInterface::class => factory(function ($c) {
        return new DbalMembershipPlanRepository($c->get(DbalConnection::class));
    }),

    MembershipController::class => autowire(),
    NewsletterController::class => autowire()
        ->constructorParameter('subscriptionService', get(SubscriptionService::class))
        ->constructorParameter('users', get(UserRepositoryInterface::class)),

    SubscriptionService::class => factory(function ($c) {
        return new SubscriptionService(
            $c->get(Connection::class),
            $c->get(SubscriberRepositoryInterface::class),
            $c->get(PlanRepositoryInterface::class),
            $c->get(StripeGateway::class),
            $c->get(PayPalGateway::class),
        );
    }),

    MembershipCheckoutController::class => factory(function ($c) {
        return new MembershipCheckoutController(
            $c->get(Twig::class),
            $c->get(PlanRepositoryInterface::class),
            $c->get(SessionManager::class),
            $c->get(DbalSettingsRepository::class),
            $c->get(Connection::class),
            $c->get(SubscriptionService::class),
            $c->get(SubscriberRepositoryInterface::class),
            $c->get(StripeGateway::class),
            $c->get(PayPalGateway::class),
            $c->get(MailService::class),
            $c->get(UserRepositoryInterface::class),
            $c->get(ExchangeRateService::class),
            $c->get(OrderLogRepositoryInterface::class),
            $c->get(CouponRepositoryInterface::class),
            $c->get(CouponValidator::class),
            $c->get(OrderRepositoryInterface::class),
        );
    }),

    CountryTaxRateRepositoryInterface::class => factory(function ($c) {
        return new DbalCountryTaxRateRepository($c->get(DbalConnection::class));
    }),

    ProductVoteRepositoryInterface::class => factory(function ($c) {
        return new DbalProductVoteRepository($c->get(DbalConnection::class));
    }),

    OrderRepositoryInterface::class => factory(function ($c) {
        return new DbalOrderRepository(
            $c->get(DbalConnection::class),
            env('ENCRYPT_PII', false) ? $c->get(Encrypter::class) : null,
        );
    }),

    CouponRepositoryInterface::class => factory(function ($c) {
        return new DbalCouponRepository($c->get(DbalConnection::class));
    }),

    OrderLogRepositoryInterface::class => factory(function ($c) {
        return new DbalOrderLogRepository($c->get(DbalConnection::class));
    }),

    DownloadRepositoryInterface::class => factory(function ($c) {
        return new DbalDownloadRepository($c->get(DbalConnection::class));
    }),

    // ── Webhooks ───────────────────────────────────────────────────────
    WebhookRepositoryInterface::class => factory(function ($c) {
        return new DbalWebhookRepository($c->get(DbalConnection::class));
    }),
    // OutboundWebhookService is autowired by PHP-DI (constructor uses WebhookRepositoryInterface)

    // OrderController & FormFrontController are autowired by PHP-DI using type hints
    OrderController::class => autowire()
        ->constructorParameter('mail', get(MailService::class))
        ->constructorParameter('downloadRepo', get(DownloadRepositoryInterface::class))
        ->constructorParameter('pdfGenerator', get(PdfGeneratorInterface::class))
        ->constructorParameter('paypalGateway', get(PayPalGateway::class))
        ->constructorParameter('stripeGateway', get(StripeGateway::class))
        ->constructorParameter('mollieGateway', get(MollieGateway::class))
        ->constructorParameter('twoCheckoutGateway', get(TwoCheckoutGateway::class))
        ->constructorParameter('checkoutComGateway', get(CheckoutComGateway::class))
        ->constructorParameter('subscriptionService', get(SubscriptionService::class))
        ->constructorParameter('bankTransferDetails', get(BankTransferDetailsService::class)),

    // Order status / tracking orchestration shared by admin panel and REST API
    OrderStatusEffects::class => autowire()
        ->constructorParameter('subscribers', get(SubscriberRepositoryInterface::class))
        ->constructorParameter('downloadRepo', get(DownloadRepositoryInterface::class))
        ->constructorParameter('automationService', get(AutomationService::class)),
    ChangeOrderStatusUseCase::class => autowire()
        ->constructorParameter('mail', get(MailService::class))
        ->constructorParameter('stripeGateway', get(StripeGateway::class))
        ->constructorParameter('paypalGateway', get(PayPalGateway::class))
        ->constructorParameter('subscriptionService', get(SubscriptionService::class)),
    // UpdateOrderTrackingUseCase, OrderTrackingNotifier, OrderShippingEstimator
    // and ShippingCarrierCatalog are autowired by PHP-DI (all constructor args
    // are bound classes: OrderLog/Order/Product repositories, MailService,
    // Translator, ShippingCarrierCatalog, OrderShippingEstimator).

    UserController::class => autowire()
        ->constructorParameter('stripeGateway', get(StripeGateway::class))
        ->constructorParameter('paypalGateway', get(PayPalGateway::class)),
    FormFrontController::class => autowire()
        ->constructorParameter('automationService', get(AutomationService::class)),
    // PaymentController needs the Stripe gateway (live method list) and the
    // PSR-16 cache that stores it; both are nullable constructor params, so
    // PHP-DI would otherwise fall back to the defaults.
    PaymentController::class => autowire()
        ->constructorParameter('stripeGateway', get(StripeGateway::class))
        ->constructorParameter('cache', get(CacheInterface::class)),

    // ── M9: REST API ──────────────────────────────────────────────────
    ApiAuthMiddleware::class => factory(function ($c) {
        return new ApiAuthMiddleware(
            $c->get(DbalConnection::class),
            AppFactory::determineResponseFactory(),
        );
    }),

    // ── M10: Roles & Permissions ──────────────────────────────────────
    RoleRepositoryInterface::class => factory(function ($c) {
        return new DbalRoleRepository($c->get(DbalConnection::class));
    }),

    // ── Payments ──────────────────────────────────────────────────────
    StripeGateway::class => factory(function ($c) {
        /** @var DbalSettingsRepository $settings */
        $settings    = $c->get(DbalSettingsRepository::class);
        $methodTypes = array_values(array_filter(array_map(
            static fn (string $type): string => strtolower(trim($type)),
            explode(',', (string) ($settings->get('stripe_payment_method_types', '') ?? ''))
        ), static fn (string $type): bool => preg_match('/^[a-z0-9_]+$/', $type) === 1));
        return new StripeGateway(
            $settings->get('stripe_secret_key', '')     ?? '',
            $settings->get('stripe_webhook_secret', '') ?? '',
            array_values(array_unique($methodTypes)),
        );
    }),

    PayPalGateway::class => factory(function ($c) {
        /** @var DbalSettingsRepository $settings */
        $settings = $c->get(DbalSettingsRepository::class);
        $appUrl   = rtrim((string) env('APP_URL', 'http://localhost:8089'), '/');
        return new PayPalGateway(
            $settings->get('paypal_client_id', '')     ?? '',
            $settings->get('paypal_client_secret', '') ?? '',
            (bool) ($settings->get('paypal_sandbox', '1') ?? '1'),
            $appUrl . '/checkout/success',
            $appUrl . '/checkout/cancel',
            $settings->get('paypal_webhook_id', '') ?? '',
        );
    }),

    MollieGateway::class => factory(function ($c) {
        $settings = $c->get(DbalSettingsRepository::class);
        $appUrl   = rtrim((string) env('APP_URL', 'http://localhost:8089'), '/');
        return new MollieGateway(
            $settings->get('mollie_api_key', '') ?? '',
            $appUrl . '/checkout/success',
            $appUrl . '/webhooks/mollie',
            $settings->get('mollie_sandbox', '1') === '1',
        );
    }),

    TwoCheckoutGateway::class => factory(function ($c) {
        $settings = $c->get(DbalSettingsRepository::class);
        $appUrl   = rtrim((string) env('APP_URL', 'http://localhost:8089'), '/');
        return new TwoCheckoutGateway(
            $settings->get('twocheckout_merchant_code', '') ?? '',
            $settings->get('twocheckout_api_secret', '')    ?? '',
            $settings->get('twocheckout_secret_key', '')    ?? '',
            $appUrl . '/checkout/success',
            $appUrl . '/checkout/cancel',
            $appUrl . '/webhooks/twocheckout',
        );
    }),

    CheckoutComGateway::class => factory(function ($c) {
        $settings = $c->get(DbalSettingsRepository::class);
        $appUrl   = rtrim((string) env('APP_URL', 'http://localhost:8089'), '/');
        return new CheckoutComGateway(
            $settings->get('checkoutcom_secret_key', '')     ?? '',
            $settings->get('checkoutcom_webhook_secret', '') ?? '',
            (bool) ($settings->get('checkoutcom_sandbox', '1') ?? '1'),
            $appUrl . '/checkout/success',
            $appUrl . '/checkout/cancel',
            $appUrl . '/webhooks/checkoutcom',
            $settings->get('checkoutcom_base_url', '')              ?? '',
            $settings->get('checkoutcom_processing_channel_id', '') ?? '',
        );
    }),

    CheckoutController::class => autowire()
        ->constructorParameter('gateways', [
            get(StripeGateway::class),
            get(PayPalGateway::class),
            get(MollieGateway::class),
            get(TwoCheckoutGateway::class),
            get(CheckoutComGateway::class),
        ])
        ->constructorParameter('mail', get(MailService::class))
        ->constructorParameter('orderLogs', get(OrderLogRepositoryInterface::class))
        ->constructorParameter('exchangeRates', get(ExchangeRateService::class))
        ->constructorParameter('subscriberRepo', get(SubscriberRepositoryInterface::class))
        ->constructorParameter('automationService', get(AutomationService::class))
        ->constructorParameter('downloadRepo', get(DownloadRepositoryInterface::class))
        ->constructorParameter('translator', get(Translator::class))
        ->constructorParameter('db', get(Connection::class))
        ->constructorParameter('abandonedCart', get(AbandonedCartService::class))
        ->constructorParameter('bankTransferDetails', get(BankTransferDetailsService::class))
        ->constructorParameter('couponValidator', get(CouponValidator::class))
        ->constructorParameter('deliveryEstimate', get(DeliveryEstimateService::class))
        ->constructorParameter('encrypter', env('ENCRYPT_PII', false) ? get(Encrypter::class) : null),

    WebhookController::class => autowire()
        ->constructorParameter('gateways', [
            get(StripeGateway::class),
            get(PayPalGateway::class),
            get(MollieGateway::class),
            get(TwoCheckoutGateway::class),
            get(CheckoutComGateway::class),
        ])
        ->constructorParameter('orderLogs', get(OrderLogRepositoryInterface::class))
        ->constructorParameter('subscribers', get(SubscriberRepositoryInterface::class))
        ->constructorParameter('automationService', get(AutomationService::class))
        ->constructorParameter('subscriptionService', get(SubscriptionService::class))
        ->constructorParameter('plans', get(PlanRepositoryInterface::class))
        ->constructorParameter('coupons', get(CouponRepositoryInterface::class))
        ->constructorParameter('db', get(Connection::class)),

    ExchangeRateService::class => factory(function ($c) {
        return new ExchangeRateService(
            $c->get(DbalSettingsRepository::class),
        );
    }),

    AbandonedCartService::class => factory(function ($c) {
        return new AbandonedCartService(
            $c->get(Connection::class),
            $c->get(MailService::class),
            $c->get(DbalSettingsRepository::class),
        );
    }),

    CouponValidator::class => factory(function ($c) {
        return new CouponValidator(
            $c->get(CouponRepositoryInterface::class),
        );
    }),

    BankTransferDetailsService::class => factory(function ($c) {
        return new BankTransferDetailsService(
            $c->get(SettingsRepositoryInterface::class),
        );
    }),

    CronService::class => factory(function ($c) {
        return new CronService(
            $c->get(NewsletterService::class),
            $c->get(AutomationService::class),
            $c->get(CampaignRepositoryInterface::class),
            $c->get(PostRepositoryInterface::class),
            $c->get(ProductRepositoryInterface::class),
            $c->get(ExchangeRateService::class),
            $c->get(DbalSettingsRepository::class),
            $c->get(UpdateService::class),
            $c->get(UpdateNotificationService::class),
            $c->get(BackupService::class),
            $c->get(PageviewRepositoryInterface::class),
            $c->get(RevisionRepositoryInterface::class),
            $c->get(SubscriptionReconciliationService::class),
            $c->get(SubscriptionService::class),
            $c->get(AbandonedCartService::class),
            $c->get(SlugService::class),
            dirname(__DIR__),
            dirname(__DIR__) . '/storage/cache/data',
            $c->get(ContentTypeRepositoryInterface::class),
            $c->get(PluginMigrator::class),
            $c->get(PluginUpdateService::class),
            $c->get(EventDispatcherInterface::class),
            $c->get(PageCacheInvalidator::class),
        );
    }),

    SubscriptionReconciliationService::class => factory(function ($c) {
        return new SubscriptionReconciliationService(
            $c->get(Connection::class),
            $c->get(SubscriptionService::class),
            $c->get(SubscriberRepositoryInterface::class),
            $c->get(PlanRepositoryInterface::class),
            $c->get(DbalSettingsRepository::class),
            $c->get(PayPalGateway::class),
            $c->get(StripeGateway::class),
        );
    }),

    CronController::class => autowire(),

    HealthController::class => factory(function ($c) {
        return new HealthController(
            $c->get(DbalConnection::class),
            $c->get(RateLimiter::class),
            dirname(__DIR__),
            (int) env('HEALTH_DEEP_LIMIT', 30),
            (int) env('HEALTH_DEEP_WINDOW', 60),
        );
    }),

    OrderApiController::class => autowire(),

    PseudoCronMiddleware::class => factory(function ($c) {
        return new PseudoCronMiddleware(
            $c->get(CronService::class),
            dirname(__DIR__),
        );
    }),

    CartExpireMiddleware::class => autowire(),

    ExchangeRateController::class => autowire(),
    DashboardController::class    => autowire(),
    ContentTypeController::class  => autowire(),
    AccountController::class      => autowire()
        ->constructorParameter('orders', get(OrderRepositoryInterface::class))
        ->constructorParameter('orderLogs', get(OrderLogRepositoryInterface::class))
        ->constructorParameter('mailer', get(MailService::class))
        ->constructorParameter('subscribers', get(SubscriberRepositoryInterface::class))
        ->constructorParameter('stripe', get(StripeGateway::class))
        ->constructorParameter('paypal', get(PayPalGateway::class))
        ->constructorParameter('encrypter', env('ENCRYPT_PII', false) ? get(Encrypter::class) : null),
    CurrencyController::class => autowire(),
    ThemeController::class    => autowire(),
    MailController::class     => autowire(),
    SettingsController::class => autowire()
        ->constructorParameter('pageCache', get(PageCache::class))
        ->constructorParameter('encrypter', env('ENCRYPT_PII', false) ? get(Encrypter::class) : null),

    // ── Update ───────────────────────────────────────────────────────
    SignatureVerifier::class => factory(function () {
        $requiredRaw      = strtolower(trim((string) env('UPDATE_REQUIRE_SIGNATURE', 'true')));
        $requireSignature = !in_array($requiredRaw, ['0', 'false', 'off', 'no'], true);

        return new SignatureVerifier(
            (string) env('UPDATE_PUBLIC_KEY_ED25519', ''),
            (string) env('UPDATE_PUBLIC_KEY_GPG', ''),
            $requireSignature,
        );
    }),

    PreUpdateChecker::class => factory(function () {
        return new PreUpdateChecker();
    }),

    MaintenanceMode::class => factory(function () {
        return new MaintenanceMode(dirname(__DIR__));
    }),

    BackupService::class => factory(function ($c) {
        return new BackupService(
            dirname(__DIR__),
            $c->get(Connection::class),
            runMigrations: static function (): string {
                $app    = new \Phinx\Console\PhinxApplication();
                $app->setAutoExit(false);
                $input  = new \Symfony\Component\Console\Input\ArrayInput([
                    'command'         => 'migrate',
                    '--configuration' => dirname(__DIR__) . '/phinx.php',
                    '--environment'   => 'default',
                ]);
                $output = new \Symfony\Component\Console\Output\BufferedOutput();
                $app->run($input, $output);

                return $output->fetch();
            },
        );
    }),

    UpdateController::class => autowire()
        ->constructorParameter('backupService', get(BackupService::class))
        ->constructorParameter('settings', get(DbalSettingsRepository::class)),

    UpdateService::class => factory(function ($c) {
        return new UpdateService(
            $c->get(DbalSettingsRepository::class),
            dirname(__DIR__),
            (string) env('UPDATE_MANIFEST_URL', ''),
            $c->get(SignatureVerifier::class),
            $c->get(PreUpdateChecker::class),
            $c->get(BackupService::class),
            $c->get(MaintenanceMode::class),
        );
    }),

    UpdateNotificationService::class => factory(function ($c) {
        $cfg = $c->get('config.app');

        return new UpdateNotificationService(
            $c->get(DbalSettingsRepository::class),
            $c->get(MailInterface::class),
            (string) ($cfg['url'] ?? ''),
        );
    }),

    PdfGeneratorInterface::class => factory(function () {
        if (class_exists(\Dompdf\Dompdf::class)) {
            return new DompdfGenerator();
        }
        return new class () implements PdfGeneratorInterface {
            public function generate(string $html): string
            {
                return '';
            }
            public function isAvailable(): bool
            {
                return false;
            }
        };
    }),

    // ── M11: Analytics ──────────────────────────────────────────────
    PageviewRepositoryInterface::class => factory(function ($c) {
        return new DbalPageviewRepository($c->get(DbalConnection::class));
    }),

    IpAnonymizer::class => factory(function () {
        return new IpAnonymizer();
    }),

    UserAgentParser::class => factory(function () {
        return new UserAgentParser();
    }),

    GeoIpResolver::class => factory(function () {
        return new GeoIpResolver(
            dirname(__DIR__) . '/storage/geo',
        );
    }),

    PageviewMiddleware::class => factory(function ($c) {
        return new PageviewMiddleware(
            $c->get(PageviewRepositoryInterface::class),
            $c->get(DbalSettingsRepository::class),
            $c->get(GeoIpResolver::class),
            $c->get(UserAgentParser::class),
            $c->get(IpAnonymizer::class),
            $c->get(DbalConnection::class),
            $c->get(CacheInterface::class),
        );
    }),

    // AnalyticsController is autowired by PHP-DI (constructor uses Twig,
    // PageviewRepositoryInterface, DbalSettingsRepository, Connection)

    ProductImporter::class => factory(function ($c) {
        return new ProductImporter(
            $c->get(DbalConnection::class),
            $c->get(SlugService::class),
            $c->get(ImageProcessor::class),
            $c->get('config.media'),
        );
    }),
];
