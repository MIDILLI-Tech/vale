<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Admin;

use Doctrine\DBAL\Connection;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Slim\Views\Twig;
use Symfony\Component\Process\Process;
use ValeApp\App\Controllers\Admin\PaymentController;
use ValeApp\Application\Maintenance\ExportImportService;
use ValeApp\Application\Seo\AiCrawlerList;
use ValeApp\Application\Shop\ProductImporter;
use ValeApp\Application\System\SystemRequirementHelper;
use ValeApp\Application\Webhook\OutboundWebhookService;
use ValeApp\Domain\Comment\Repository\CommentRepositoryInterface;
use ValeApp\Domain\Content\Repository\CategoryRepositoryInterface;
use ValeApp\Domain\Content\Repository\ContentTypeRepositoryInterface;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Content\Repository\RevisionRepositoryInterface;
use ValeApp\Domain\Content\RoutingValidator;
use ValeApp\Domain\Content\TreeHelper;
use ValeApp\Domain\Form\Repository\FormRepositoryInterface;
use ValeApp\Domain\Media\Repository\MediaRepositoryInterface;
use ValeApp\Domain\Roles\Repository\RoleRepositoryInterface;
use ValeApp\Domain\Shared\Repository\WebhookRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\Shop\Entity\CountryTaxRate;
use ValeApp\Domain\Shop\Repository\CountryTaxRateRepositoryInterface;
use ValeApp\Infrastructure\Analytics\GeoIpResolver;
use ValeApp\Infrastructure\Cache\PageCache;
use ValeApp\Infrastructure\Content\CaptchaUrls;
use ValeApp\Infrastructure\Content\RedirectService;
use ValeApp\Infrastructure\Media\MediaScanService;
use ValeApp\Infrastructure\Media\MediaUploadService;
use ValeApp\Infrastructure\Payment\HttpTransport;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Security\Encrypter;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Theme\ThemeManager;
use ValeApp\Infrastructure\Translation\Translator;

final class SettingsController
{
    public function __construct(
        private readonly Translator $translator,
        private readonly Twig $twig,
        private readonly DbalSettingsRepository $settings,
        private readonly SessionManager $session,
        private readonly PostRepositoryInterface $posts,
        private readonly FormRepositoryInterface $forms,
        private readonly Connection $db,
        private readonly CategoryRepositoryInterface $categories,
        private readonly WebhookRepositoryInterface $webhooks,
        private readonly CountryTaxRateRepositoryInterface $countryTaxRates,
        private readonly GeoIpResolver $geoIpResolver,
        private readonly RevisionRepositoryInterface $revisions,
        private readonly CommentRepositoryInterface $comments,
        private readonly MediaRepositoryInterface $media,
        private readonly RoutingValidator $routingValidator,
        private readonly RedirectService $redirects,
        private readonly RoleRepositoryInterface $roles,
        private readonly ExportImportService $exportImportService,
        private readonly MediaScanService $mediaScanService,
        private readonly ProductImporter $productImporter,
        private readonly MediaUploadService $uploadService,
        private readonly ThemeManager $themeManager,
        private readonly ?PageCache $pageCache = null,
        private readonly ?Encrypter $encrypter = null,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        // NOTE: do NOT close the session here — Twig rendering still reads
        // session-backed globals. The lock is released after rendering.
        $referenceData   = require dirname(__DIR__, 3) . '/config/reference-data.php';
        $currencyCatalog = is_array($referenceData['currencies'] ?? null) ? $referenceData['currencies'] : [];
        $countryCodes    = is_array($referenceData['country_codes'] ?? null) ? $referenceData['country_codes'] : [];
        $signupUrls      = is_array($referenceData['signup_urls'] ?? null) ? $referenceData['signup_urls'] : [];
        $captchaUrls     = is_array($referenceData['captcha_urls'] ?? null) ? $referenceData['captcha_urls'] : [];
        $mailDrivers     = is_array($referenceData['mail_drivers'] ?? null) ? $referenceData['mail_drivers'] : [];
        $stripeMethods   = is_array($referenceData['stripe_payment_methods'] ?? null) ? $referenceData['stripe_payment_methods'] : [];

        $general       = $this->settings->getGroup('general');
        $content       = $this->settings->getGroup('content');
        $discussion    = $this->settings->getGroup('discussion');
        $seo           = $this->settings->getGroup('seo');
        $ecommerce     = $this->settings->getGroup('ecommerce');
        $privacy       = $this->settings->getGroup('privacy');
        $analytics     = $this->settings->getGroup('analytics');
        $registration  = $this->settings->getGroup('registration');
        $customScripts = $this->settings->getGroup('custom_scripts');

        $settings = array_merge($general, $content, $discussion, $seo, $ecommerce, $privacy, $analytics, $registration, $customScripts);

        $paymentConfig                    = PaymentController::buildConfig($this->settings);
        $paymentConfig['default_gateway'] = $this->settings->get('payment_active_gateway', 'stripe');

        // Which provider card to expand: set by PaymentController after
        // save/test/toggle/default (single-use — F5 collapses everything).
        $paymentOpenCard = $this->session->get('payment_open_card');
        $this->session->remove('payment_open_card');

        $captchaConfig = [
            'hcaptcha_site_key'    => $this->settings->get('hcaptcha_site_key', ''),
            'hcaptcha_secret_key'  => $this->settings->get('hcaptcha_secret_key', '') ? '••••••••' : '',
            'recaptcha_site_key'   => $this->settings->get('recaptcha_site_key', ''),
            'recaptcha_secret_key' => $this->settings->get('recaptcha_secret_key', '') ? '••••••••' : '',
            'recaptcha_threshold'  => $this->settings->get('recaptcha_threshold', '0.5'),
            'turnstile_site_key'   => $this->settings->get('turnstile_site_key', ''),
            'turnstile_secret_key' => $this->settings->get('turnstile_secret_key', '') ? '••••••••' : '',
        ];

        $commentCaptchaType = $this->settings->get('comment_captcha_type', 'math');
        $reviewCaptchaType  = $this->settings->get('review_captcha_type', $commentCaptchaType);

        $pagePickerSeeds = [
            'homepage'        => $this->pagePickerSeed((string) ($settings['homepage_page_id'] ?? ''), 'id'),
            'policy_returns'  => $this->pagePickerSeed((string) ($settings['policy_page_returns'] ?? ''), 'slug'),
            'policy_privacy'  => $this->pagePickerSeed((string) ($settings['policy_page_privacy'] ?? ''), 'slug'),
            'policy_terms'    => $this->pagePickerSeed((string) ($settings['policy_page_terms'] ?? ''), 'slug'),
        ];

        $checkoutForms = array_values(array_filter(
            $this->forms->findAll(),
            static fn ($form) => $form->isEnabled(),
        ));

        // Mail tab data
        $mailConfig   = [];
        $baseMailKeys = ['mail_driver', 'mail_from_address', 'mail_from_name', 'mail_smtp_host', 'mail_smtp_port', 'mail_smtp_user', 'mail_smtp_pass', 'mail_smtp_encryption', 'mail_resend_api_key', 'mail_unosend_api_key', 'mail_mailgun_api_key', 'mail_mailgun_domain', 'mail_sendgrid_api_key', 'mail_log_enabled', 'mail_batch_enabled', 'mail_batch_size'];

        // Dynamically build color key list from active theme's color_variables
        $activeThemeSlug = $this->settings->get('active_theme', 'default');
        $colorSchema     = $this->themeManager->getColorVariablesSchema($activeThemeSlug);
        $colorKeys       = [];
        foreach ($colorSchema as $varName => $spec) {
            if (!is_string($varName) || !str_starts_with($varName, '--')) {
                continue;
            }
            $colorKeys[] = 'mail_color_' . ltrim(str_replace('--theme-', '', $varName), '-');
        }

        foreach (array_merge($baseMailKeys, ['mail_use_theme_colors'], $colorKeys) as $key) {
            $mailConfig[$key] = $this->settings->get($key, '');
        }
        if ($mailConfig['mail_driver'] === '') {
            $mailConfig['mail_driver'] = 'log';
        }
        if ($mailConfig['mail_smtp_port'] === '') {
            $mailConfig['mail_smtp_port'] = '587';
        }
        if ($mailConfig['mail_smtp_encryption'] === '') {
            $mailConfig['mail_smtp_encryption'] = 'tls';
        }
        if ($mailConfig['mail_log_enabled'] === '') {
            $mailConfig['mail_log_enabled'] = '1';
        }
        if ($mailConfig['mail_batch_enabled'] === '') {
            $mailConfig['mail_batch_enabled'] = '1';
        }
        if ($mailConfig['mail_batch_size'] === '') {
            $mailConfig['mail_batch_size'] = '1000';
        }
        if ($mailConfig['mail_use_theme_colors'] === '') {
            $mailConfig['mail_use_theme_colors'] = '1';
        }
        $mailConfig['mail_active_theme'] = $activeThemeSlug;

        // Apply theme defaults for any color keys that are not saved
        foreach ($colorSchema as $varName => $spec) {
            if (!is_string($varName) || !str_starts_with($varName, '--')) {
                continue;
            }
            $settingKey                   = 'mail_color_' . ltrim(str_replace('--theme-', '', $varName), '-');
            if (!isset($mailConfig[$settingKey]) || $mailConfig[$settingKey] === '') {
                $mailConfig[$settingKey] = (string) ($spec['default'] ?? '#000000');
            }
        }

        // Email template list
        $mailTemplates = [];
        $viewsDir      = dirname(__DIR__, 3) . '/resources/views/mail';
        if (is_dir($viewsDir)) {
            foreach (glob($viewsDir . '/*.twig') as $path) {
                $name            = basename($path, '.twig');
                $default         = file_get_contents($path);
                $custom          = $this->settings->get('mail_template_' . $name, '');
                $mailTemplates[] = [
                    'name'    => $name,
                    'label'   => match ($name) {
                        'layout'                  => 'Layout (Base)',
                        'order-confirmation'      => 'Order Confirmation',
                        'order-status-update'     => 'Order Status Update',
                        'order-note'              => 'Order Note (Admin)',
                        'payment-received'        => 'Payment Received',
                        'password-reset'          => 'Password Reset',
                        'welcome'                 => 'Welcome',
                        'newsletter-confirmation' => 'Newsletter Confirmation',
                        default                   => str_replace(['-', '_'], ' ', ucfirst($name)),
                    },
                    'default' => $default,
                    'custom'  => ($custom !== '' && $custom !== null) ? $custom : null,
                ];
            }
            usort($mailTemplates, fn ($a, $b) => $a['name'] <=> $b['name']);
        }

        // API Tokens tab data
        $userId    = (int) $this->session->get('user_id');
        $apiTokens = $this->db->fetchAllAssociative(
            'SELECT id, name, abilities, last_used_at, expires_at, created_at FROM ' . VL_DB_PREFIX . 'api_tokens' . ' WHERE user_id = ? ORDER BY id DESC',
            [$userId],
        );
        $newToken = $this->session->flash('new_api_token');

        // Webhooks tab data
        $webhookList       = $this->webhooks->findAll();
        $webhookFormErrors = $this->session->get('webhook_form_errors') ?? [];
        $webhookFormOld    = $this->session->get('webhook_form_old')    ?? [];
        $this->session->remove('webhook_form_errors');
        $this->session->remove('webhook_form_old');
        // Find the webhook being edited (for modal re-open after validation error)
        $webhookEditId     = isset($webhookFormOld['_id']) ? (int) $webhookFormOld['_id'] : null;
        $webhookEdit       = $webhookEditId ? $this->webhooks->findById($webhookEditId) : null;

        // Routing prefix conflicts from a rejected settings save (shown inline, nothing was persisted)
        $routingPrefixErrors = $this->session->get('routing_prefix_errors') ?? [];
        $this->session->remove('routing_prefix_errors');

        // Cron / Maintenance tab data
        $cronConfig = [];
        foreach (['cron_mode', 'cron_interval', 'cron_last_ran', 'updates_enabled', 'update_auto_check', 'update_auto_apply'] as $key) {
            $cronConfig[$key] = $this->settings->get($key, '') ?? '';
        }
        if ($cronConfig['cron_mode'] === '') {
            $cronConfig['cron_mode'] = 'none';
        }
        if ($cronConfig['cron_interval'] === '') {
            $cronConfig['cron_interval'] = '5';
        }
        if ($cronConfig['updates_enabled'] === '') {
            $cronConfig['updates_enabled'] = 'true';
        }
        if ($cronConfig['update_auto_check'] === '') {
            $cronConfig['update_auto_check'] = 'true';
        }
        if ($cronConfig['update_auto_apply'] === '') {
            $cronConfig['update_auto_apply'] = 'false';
        }
        // Auto-generate cron_secret on first view if not set
        $cronSecret = $this->settings->get('cron_secret', '') ?? '';
        if ($cronSecret === '') {
            $cronSecret = bin2hex(random_bytes(16));
            $this->settings->set('cron_secret', $cronSecret, 'cron');
        }
        $cronConfig['cron_secret']             = $cronSecret;
        $cronConfig['cron_last_ran_formatted'] = '';
        if ($cronConfig['cron_last_ran'] !== '' && is_numeric($cronConfig['cron_last_ran'])) {
            $cronConfig['cron_last_ran_formatted'] = date('Y-m-d H:i:s', (int) $cronConfig['cron_last_ran']);
        }

        $countryTaxRates = $this->countryTaxRates->findAll();

        // System requirements check from config/reference-data.php
        $requirements    = $referenceData['requirements']          ?? [];
        $minPhp          = $requirements['min_php']                ?? '8.3.0';
        $requiredExts    = $requirements['required_extensions']    ?? [];
        $recommendedExts = $requirements['recommended_extensions'] ?? [];

        $missingRequiredPhp  = version_compare(PHP_VERSION, $minPhp, '<') ? $minPhp : null;
        $missingRequiredExts = [];
        foreach ($requiredExts as $ext => $desc) {
            if (!extension_loaded($ext)) {
                $missingRequiredExts[$ext] = $desc;
            }
        }
        $missingRecommendedExts = SystemRequirementHelper::missingRecommended(
            $recommendedExts,
            ($this->settings->get('shop_enabled', '1') ?? '1') === '1',
        );

        $rendered = $this->twig->render($response, 'admin/settings/index.twig', [
            'settings'                     => $settings,
            'seo_enabled'                  => ($this->settings->get('seo_enabled', '1') ?? '1') === '1',
            'missing_required_php'         => $missingRequiredPhp,
            'missing_required_exts'        => $missingRequiredExts,
            'missing_recommended_exts'     => $missingRecommendedExts,
            'zip_supported'                => class_exists(\ZipArchive::class),
            'roles'                        => $this->roles->findAll(),
            'paymentConfig'                => $paymentConfig,
            'payment_open_card'            => $paymentOpenCard,
            'checkoutForms'                => $checkoutForms,
            'captchaConfig'                => $captchaConfig,
            'comment_captcha_type'         => $commentCaptchaType,
            'review_captcha_type'          => $reviewCaptchaType,
            'pagePickerSeeds'              => $pagePickerSeeds,
            'mailConfig'                   => $mailConfig,
            'mailTemplates'                => $mailTemplates,
            'mail_drivers'                 => $mailDrivers,
            'mailSchema'                   => $colorSchema,
            'apiTokens'                    => $apiTokens,
            'newApiToken'                  => $newToken,
            'categories_all'               => TreeHelper::buildTree($this->categories->findAll()),
            'webhooks'                     => $webhookList,
            'webhookFormErrors'            => $webhookFormErrors,
            'webhookFormOld'               => $webhookFormOld,
            'routingPrefixErrors'          => $routingPrefixErrors,            'webhookEdit'                  => $webhookEdit,
            'available_events'             => OutboundWebhookService::AVAILABLE_EVENTS,
            'cronConfig'                   => $cronConfig,
            'currency_catalog'             => $currencyCatalog,
            'country_codes'                => $countryCodes,
            'signup_urls'                  => $signupUrls,
            'stripe_payment_methods'       => $stripeMethods,
            'captcha_urls'                 => $captchaUrls,
            'ai_crawlers'                  => AiCrawlerList::all(),
            'countryTaxRates'              => $countryTaxRates,
            'import_export_urls'           => is_array($referenceData['import_export_urls'] ?? null) ? $referenceData['import_export_urls'] : [],
            'analytics_default_excludes'   => is_array($referenceData['analytics_excluded_prefixes'] ?? null) ? $referenceData['analytics_excluded_prefixes'] : [],
            'sso_providers'                => is_array($referenceData['sso_providers'] ?? null) ? $referenceData['sso_providers'] : [],
            'geo_config'                   => is_array($referenceData['geo'] ?? null) ? $referenceData['geo'] : [],
            'geo_db_status'                => $this->getGeoDbStatus(),
            // Heavy maintenance counters are LAZY (null = not counted yet). They are
            // served by maintenanceStats() via AJAX when the user clicks "Count",
            // instead of running on every page open (they could time out on large
            // production databases and leave the PHP worker hanging with the
            // session file locked — all pages appeared frozen until restart).
            'revision_count'               => null,
            'revision_prunable'            => null,
            'spam_comment_count'           => null,
            'trash_comment_count'          => null,
            'orphaned_meta_count'          => null,
            'orphaned_media_file_count'    => null,
            'orphaned_media_unused_count'  => null,
            'pageview_retention_days'      => $this->settings->get('analytics_retention_days', '365') ?? '365',
            'redirect_count'               => null,
            'maintenance_stats_url'        => $this->maintenanceStatsUrl(),
            'php_upload_max_mb'            => (string) round(MediaUploadService::parsePhpLimit(ini_get('upload_max_filesize')) / 1024 / 1024),
            'php_max_uploads'              => ini_get('max_file_uploads'),
            // Raw stored values for the media limits form (0 = "use server/default").
            'media_limits'                 => [
                'file_size_mb'     => (string) $this->settings->get('media_max_file_size', '0'),
                'file_count'       => (string) $this->settings->get('media_max_file_count', '0'),
                'batch_size_mb'    => (string) $this->settings->get('media_max_batch_size', '0'),
                'batch_default_mb' => (string) round(MediaUploadService::parsePhpLimit(ini_get('post_max_size')) / 1048576),
            ],
            'data_cache_count'             => null,
            'data_cache_size'              => null,
            'page_cache_count'             => null,
            'page_cache_size'              => null,
            'views_cache_count'            => null,
            'views_cache_size'             => null,
            'custom_carrier_renames'       => $this->session->get('custom_carrier_renames', []),
        ]);

        // Twig globals above read session state — release the lock now so other
        // tabs/AJAX from the same admin are not blocked behind this response.
        $this->releaseSessionLock();

        return $rendered;
    }

    public function update(Request $request, Response $response): Response
    {
        $body   = (array) $request->getParsedBody();
        $group  = $body['_group']  ?? 'general';
        $subtab = $body['_subtab'] ?? '';
        unset($body['_csrf_token'], $body['_METHOD'], $body['_group'], $body['_subtab']);

        // Route prefix validation (atomic: simultaneous edits validate against each other)
        $routingKeys = [
            'blog', 'shop', 'category', 'tag', 'checkout', 'cart', 'membership', 'account', 'page',
            'login', 'register', 'forgot_password', 'reset_password', 'verify_email', 'logout',
        ];
        $prefixValidationOverlay = [];
        $prefixErrors            = [];

        foreach ($routingKeys as $type) {
            $key = 'routing_' . $type . '_prefix';
            if (isset($body[$key])) {
                $newValue = trim((string) $body[$key]);
                if ($key === 'routing_page_prefix' && $newValue === '/') {
                    $newValue = '';
                }
                $body[$key]                       = $newValue;
                $prefixValidationOverlay[$key]    = $newValue;
            }
        }

        // Localized prefixes (e.g. routing_blog_prefix_tr) are posted alongside the base keys.
        foreach ($body as $bodyKey => $bodyValue) {
            if (!is_string($bodyKey) || !is_string($bodyValue)) {
                continue;
            }
            if (!str_starts_with($bodyKey, 'routing_') || !str_ends_with($bodyKey, '_prefix')) {
                continue;
            }
            if (array_key_exists($bodyKey, $prefixValidationOverlay)) {
                continue;
            }
            $body[$bodyKey]                    = trim($bodyValue);
            $prefixValidationOverlay[$bodyKey] = $body[$bodyKey];
        }

        foreach ($prefixValidationOverlay as $key => $newValue) {
            if ($newValue === '') {
                continue;
            }
            $error = $this->routingValidator->getConflictError($newValue, $key, null, $prefixValidationOverlay);
            if ($error !== null) {
                $prefixErrors[$key] = $error;
            }
        }

        if ($prefixErrors !== []) {
            $this->session->set('flash_error', $this->translator->get('Routing prefixes were not saved due to conflicts.'));
            $this->session->set('routing_prefix_errors', $prefixErrors);
            $redirectUrl = '/settings?tab=' . urlencode($this->settingsTab($group));
            if ($subtab !== '') {
                $redirectUrl .= '&subtab=' . urlencode($subtab);
            }
            return $response->withHeader('Location', admin_url($redirectUrl))->withStatus(302);
        }

        // Enabling multi-language content strips a leading locale segment
        // (e.g. '/en') from every URL before routing — refuse to switch on
        // while existing prefixes, content types or pages start with one.
        $multiBefore = ($this->settings->get('multi_language_content', '0') ?? '0') === '1';
        $multiAfter  = array_key_exists('multi_language_content', $body)
            ? ((string) $body['multi_language_content'] === '1')
            : $multiBefore;

        if (!$multiBefore && $multiAfter) {
            $localeConflicts = $this->routingValidator->findLocaleConflictsOnEnable(
                $prefixValidationOverlay + ['multi_language_content' => '1'],
            );
            if ($localeConflicts !== []) {
                $this->session->set(
                    'flash_error',
                    $this->translator->get('Multi-language content was not enabled: ') . implode(' ', $localeConflicts),
                );
                $redirectUrl = '/settings?tab=' . urlencode($this->settingsTab($group));
                if ($subtab !== '') {
                    $redirectUrl .= '&subtab=' . urlencode($subtab);
                }
                return $response->withHeader('Location', admin_url($redirectUrl))->withStatus(302);
            }
        }

        if (!empty($body['create_redirects'])) {
            foreach ($routingKeys as $type) {
                $key      = 'routing_' . $type . '_prefix';
                $oldValue = $this->settings->get($key);
                $newValue = $body[$key] ?? '';
                if ($oldValue !== null && $oldValue !== $newValue) {
                    // Page prefix root -> non-root: create per-page redirects instead of a blanket prefix
                    // (prefix redirect from / would match all URLs and break the site)
                    if ($key === 'routing_page_prefix' && ($oldValue === '' || $oldValue === '/') && $newValue !== '' && $newValue !== '/') {
                        $newPrefix = '/' . ltrim($newValue, '/');
                        $allPages  = $this->posts->findAll(new Pagination(1, 99999), ['content_type' => 'page']);
                        foreach ($allPages as $page) {
                            $pagePath = $this->posts->buildPagePath($page);
                            $this->redirects->recordRedirect('/' . $pagePath, $newPrefix . '/' . $pagePath);
                        }
                    } else {
                        $this->redirects->recordPrefixRedirect($oldValue, $newValue);
                    }
                }
            }
        }

        // Don't overwrite secret keys submitted as masked placeholders
        $maskedSecretKeys = [
            'hcaptcha_secret_key', 'recaptcha_secret_key', 'turnstile_secret_key',
            'steam_api_key',
        ];
        $refData = require dirname(__DIR__, 3) . '/config/reference-data.php';
        foreach (($refData['sso_providers'] ?? []) as $ssoKey => $_) {
            $maskedSecretKeys[] = 'sso_' . $ssoKey . '_client_secret';
        }
        foreach ($maskedSecretKeys as $secretKey) {
            if (isset($body[$secretKey]) && $body[$secretKey] === '••••••••') {
                unset($body[$secretKey]);
            }
        }

        // Cron group: auto-generate secret if regenerate requested or missing
        if ($group === 'cron') {
            if (!empty($body['_regenerate_cron_secret']) || ($this->settings->get('cron_secret', '') ?? '') === '') {
                $body['cron_secret'] = bin2hex(random_bytes(16));
            }
            unset($body['_regenerate_cron_secret']);
        }

        if ($group === 'ecommerce' && $subtab === 'checkout') {
            $displayKeys = [
                'checkout_display_first_name',
                'checkout_display_last_name',
                'checkout_display_email',
                'checkout_display_phone',
                'checkout_display_country',
                'checkout_display_address',
                'checkout_display_city',
                'checkout_display_state',
                'checkout_display_postcode',
                'checkout_display_note',
            ];
            foreach ($displayKeys as $key) {
                $body[$key] = isset($body[$key]) ? '1' : '0';
            }

            $requiredKeys = [
                'checkout_required_first_name',
                'checkout_required_last_name',
                'checkout_required_email',
                'checkout_required_phone',
                'checkout_required_country',
                'checkout_required_address',
                'checkout_required_city',
                'checkout_required_state',
                'checkout_required_postcode',
                'checkout_required_note',
            ];
            foreach ($requiredKeys as $key) {
                $body[$key] = isset($body[$key]) ? '1' : '0';
            }

            $pairs = [
                'first_name', 'last_name', 'email', 'phone', 'country',
                'address', 'city', 'state', 'postcode', 'note',
            ];
            foreach ($pairs as $field) {
                if (($body['checkout_display_' . $field] ?? '0') !== '1') {
                    $body['checkout_required_' . $field] = '0';
                }
            }

            $displayMode = (string) ($body['tax_display_mode'] ?? 'incl_and_breakdown');
            if (!in_array($displayMode, ['incl_only', 'incl_and_breakdown'], true)) {
                $displayMode = 'incl_and_breakdown';
            }
            $body['tax_display_mode'] = $displayMode;

            $body['buy_now_enabled'] = isset($body['buy_now_enabled'])
                && $body['buy_now_enabled'] === '1' ? '1' : '0';

            // Abandoned cart settings
            $body['abandoned_cart_enabled'] = isset($body['abandoned_cart_enabled']) ? '1' : '0';
            $delay                          = (int) ($body['abandoned_cart_delay_hours'] ?? 2);
            if ($delay < 1) {
                $delay = 1;
            }
            $body['abandoned_cart_delay_hours'] = (string) $delay;
            $max                                = (int) ($body['abandoned_cart_max_reminders'] ?? 3);
            if ($max < 1) {
                $max = 1;
            }
            $body['abandoned_cart_max_reminders'] = (string) $max;
            $interval                             = (int) ($body['abandoned_cart_reminder_interval_hours'] ?? 24);
            if ($interval < 1) {
                $interval = 24;
            }
            $body['abandoned_cart_reminder_interval_hours'] = (string) $interval;
        }

        if ($group === 'ecommerce' && $subtab === 'shop') {
            $referenceData     = require dirname(__DIR__, 3) . '/config/reference-data.php';
            $knownCountryCodes = is_array($referenceData['country_codes'] ?? null) ? $referenceData['country_codes'] : [];
            $shopCountry       = strtoupper(trim((string) ($body['shop_country'] ?? '')));
            if (!in_array($shopCountry, $knownCountryCodes, true)) {
                $shopCountry = strtoupper(trim((string) ($this->settings->get('shop_country', 'US') ?? 'US')));
                if (!in_array($shopCountry, $knownCountryCodes, true)) {
                    $shopCountry = 'US';
                }
            }
            $body['shop_country'] = $shopCountry;

            $regime = (string) ($body['tax_regime'] ?? 'normal_vat');
            if (in_array($regime, ['kleinunternehmer', 'vat_exempt'], true)) {
                $regime = 'no_tax';
            }
            if (!in_array($regime, ['normal_vat', 'no_tax'], true)) {
                $regime = 'normal_vat';
            }
            $body['tax_regime'] = $regime;

            $rateRaw                  = (string) ($body['tax_default_rate'] ?? '19');
            $rate                     = max(0.0, (float) str_replace(',', '.', $rateRaw));
            $body['tax_default_rate'] = number_format($rate, 2, '.', '');

            $body['tax_zero_reason_default'] = trim((string) ($body['tax_zero_reason_default'] ?? ''));

            // Backward compatibility with existing keys used in older builds.
            $body['tax_enabled'] = $regime === 'normal_vat' ? '1' : '0';
            $body['tax_rate']    = (string) ((int) round($rate));

            // Save per-country tax rates
            $this->saveCountryTaxRates($body);

            // Save custom tracking carriers
            $this->saveCustomCarriers($body);

            // Remove array fields so they don't reach setMany()
            unset($body['ctr_country_code'], $body['ctr_regime'], $body['ctr_rate'], $body['ctr_zero_reason'], $body['ctr_fallback']);

            // Normalize checkout country allow/block lists
            $body['checkout_allowed_countries'] = $this->normalizeCountryList((string) ($body['checkout_allowed_countries'] ?? ''), $knownCountryCodes);
            $body['checkout_blocked_countries'] = $this->normalizeCountryList((string) ($body['checkout_blocked_countries'] ?? ''), $knownCountryCodes);
        }

        if ($group === 'ecommerce' && ($subtab === 'currency' || array_key_exists('currency', $body))) {
            $knownCurrencies = $this->knownCurrencies();
            $default         = strtoupper(trim((string) ($body['currency'] ?? 'USD')));
            if (!in_array($default, $knownCurrencies, true)) {
                $default = 'USD';
            }

            $additional = $this->parseCurrencyList((string) ($body['allowed_currencies'] ?? ''), $default, $knownCurrencies);
            $blocked    = $this->parseCurrencyList((string) ($body['blocked_currencies'] ?? ''), $default, $knownCurrencies);

            $body['currency']           = $default;
            $body['allowed_currencies'] = implode(',', $additional);
            $body['blocked_currencies'] = implode(',', $blocked);
        }

        // When admin language is changed, also update default content locale
        if (isset($body['locale'])) {
            $body['default_locale'] = $body['locale'];
        }

        // Validate timezone against real IANA identifiers; apply immediately for this request
        if (isset($body['timezone'])) {
            $tz = trim((string) $body['timezone']);
            if (!in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
                $tz = 'UTC';
            }
            $body['timezone'] = $tz;
            date_default_timezone_set($tz);
        }

        $this->settings->setMany($body, $group);

        // Flush page cache and clear template/data caches so setting changes take effect immediately
        if ($this->pageCache !== null) {
            $this->pageCache->flush();
        }
        $this->clearCacheDir('storage/cache/data');
        $this->clearCacheDir('storage/cache/views');

        $this->session->set('flash_success', $this->translator->get('Settings saved.'));
        $redirectTab = $this->settingsTab($group);
        $redirectUrl = '/settings?tab=' . urlencode($redirectTab);
        if ($subtab !== '') {
            $redirectUrl .= '&subtab=' . urlencode((string) $subtab);
        }
        return $response->withHeader('Location', admin_url($redirectUrl))->withStatus(302);
    }

    private function settingsTab(string $group): string
    {
        $tabMap = [
            'cron'           => 'maintenance',
            'custom_scripts' => 'advanced',
        ];

        return $tabMap[$group] ?? $group;
    }

    /**
     * Parses a comma-separated currency list and returns unique supported codes.
     * The default store currency is excluded because it is always implicitly allowed.
     *
     * @return string[]
     */
    private function parseCurrencyList(string $raw, string $default, array $knownCurrencies): array
    {
        $parts  = array_map('trim', explode(',', $raw));
        $seen   = [];
        $result = [];

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $code = strtoupper($part);
            if ($code === $default) {
                continue;
            }
            if (!in_array($code, $knownCurrencies, true)) {
                continue;
            }
            if (isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;
            $result[]    = $code;
        }

        return $result;
    }

    /** @return string[] */
    private function knownCurrencies(): array
    {
        $referenceData = require dirname(__DIR__, 3) . '/config/reference-data.php';
        $currencies    = is_array($referenceData['currencies'] ?? null) ? $referenceData['currencies'] : [];

        $codes = [];
        foreach ($currencies as $currency) {
            $code = strtoupper(trim((string) ($currency['code'] ?? '')));
            if ($code !== '') {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    /** @return string Comma-separated uppercase country codes, validated against known list. */
    private function normalizeCountryList(string $raw, array $knownCountryCodes): string
    {
        $parts    = array_map('strtoupper', array_map('trim', explode(',', $raw)));
        $filtered = array_values(array_filter($parts, static fn (string $code): bool => $code !== '' && in_array($code, $knownCountryCodes, true)));
        return implode(',', array_unique($filtered));
    }

    private function saveCountryTaxRates(array $body): void
    {
        $countries = (array) ($body['ctr_country_code'] ?? []);
        $regimes   = (array) ($body['ctr_regime'] ?? []);
        $rates     = (array) ($body['ctr_rate'] ?? []);
        $reasons   = (array) ($body['ctr_zero_reason'] ?? []);
        $fallbacks = (array) ($body['ctr_fallback'] ?? []);

        $savedIds = [];

        foreach ($countries as $i => $countryCode) {
            $countryCode = strtoupper(trim((string) $countryCode));
            if ($countryCode === '') {
                continue;
            }

            $regime = (string) ($regimes[$i] ?? 'normal_vat');
            if (!in_array($regime, ['normal_vat', 'no_tax', 'use_global'], true)) {
                $regime = 'normal_vat';
            }

            $rate = null;
            if (!empty($rates[$i]) && $rates[$i] !== '') {
                $rate = max(0.0, (float) str_replace(',', '.', (string) $rates[$i]));
            }

            $zeroReason = trim((string) ($reasons[$i] ?? ''));
            $isFallback = !empty($fallbacks[$i]);

            $existing = $this->countryTaxRates->findByCountry($countryCode);

            if ($existing !== null) {
                $existing->setRegime($regime);
                $existing->setRate($rate);
                $existing->setZeroReason($zeroReason ?: null);
                $existing->setIsFallback($isFallback);
                $this->countryTaxRates->save($existing);
                $savedIds[] = $existing->getId();
            } else {
                $entity = new CountryTaxRate($countryCode);
                $entity->setRegime($regime);
                $entity->setRate($rate);
                $entity->setZeroReason($zeroReason ?: null);
                $entity->setIsFallback($isFallback);
                $this->countryTaxRates->save($entity);
                $savedIds[] = $entity->getId();
            }
        }

        // Remove rows that were deleted via the UI
        $this->countryTaxRates->deleteExcept($savedIds);
    }

    private function saveCustomCarriers(array $body): void
    {
        $names = (array) ($body['custom_carrier_name'] ?? []);
        $urls  = (array) ($body['custom_carrier_url'] ?? []);

        $seenSlugs = [];
        $carriers  = [];
        $renames   = [];

        foreach ($names as $i => $rawName) {
            $name = trim((string) $rawName);
            $url  = trim((string) ($urls[$i] ?? ''));

            if ($name === '' || $url === '') {
                continue;
            }

            $baseSlug = strtolower(trim(preg_replace('/[^a-z0-9]+/', '-', $name), '-'));
            $slug     = $baseSlug;
            $counter  = 2;
            while (isset($seenSlugs[$slug])) {
                $slug = $baseSlug . '-' . $counter;
                $counter++;
            }
            $seenSlugs[$slug] = true;

            if ($slug !== $baseSlug) {
                $renames[$name] = $slug;
            }

            $carriers[] = [
                'name'         => $name,
                'url'          => $url,
                'value'        => $slug,
                'label'        => $name,
                'url_template' => $url,
            ];
        }

        $this->settings->set('custom_shipping_carriers', json_encode($carriers));

        if (!empty($renames)) {
            $this->session->set('custom_carrier_renames', $renames);
        } elseif ($this->session->has('custom_carrier_renames')) {
            $this->session->remove('custom_carrier_renames');
        }

        unset($body['custom_carrier_name'], $body['custom_carrier_url']);
    }

    /** @return array<string, array{exists: bool, size: ?int, size_formatted: ?string}> */
    private function getGeoDbStatus(): array
    {
        $modes  = ['ip2location_country', 'ip2location_city', 'maxmind_city', 'dbip_city'];
        $status = [];

        foreach ($modes as $mode) {
            $path      = $this->geoIpResolver->getDatabasePath($mode);
            $exists    = $path !== null && file_exists($path);
            $size      = null;
            $formatted = null;

            if ($exists && $path !== null) {
                $bytes = @filesize($path);
                if ($bytes !== false) {
                    $size      = $bytes;
                    $formatted = $this->formatBytes($bytes);
                }
            }

            $status[$mode] = [
                'exists'         => $exists,
                'size'           => $size,
                'size_formatted' => $formatted,
            ];
        }

        return $status;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB'];
        $i     = (int) floor(log($bytes, 1024));
        return round($bytes / (1024 ** $i), 2) . ' ' . $units[$i];
    }

    /**
     * Lazy maintenance counters for the settings page.
     *
     * The settings page opens instantly (no heavy counts) and the Maintenance
     * tab fetches these via AJAX when the user clicks "Count". Each requested
     * key is computed independently so one slow counter cannot block the rest.
     * ?only=revision,spam,trash,orphaned-meta,orphaned-media-files,orphaned-media-unused,cache,redirects
     */
    public function maintenanceStats(Request $request, Response $response): Response
    {
        $this->releaseSessionLock();

        $params = $request->getQueryParams();
        $only   = isset($params['only']) ? strtolower((string) $params['only']) : 'all';
        $wanted = $only === '' || $only === 'all'
            ? null
            : array_filter(array_map('trim', explode(',', $only)));

        $want = static function (string $key) use ($wanted): bool {
            return $wanted === null || in_array($key, $wanted, true);
        };

        $stats = [];

        if ($want('revision')) {
            try {
                $stats['revision_count']    = $this->revisions->countAll();
                $stats['revision_prunable'] = $this->revisions->countPrunable(10);
            } catch (\Throwable $e) {
                $stats['revision_count']    = null;
                $stats['revision_prunable'] = null;
                $stats['revision_error']    = $e->getMessage();
            }
        }

        if ($want('spam') || $want('trash') || $want('comments')) {
            try {
                $stats['spam_comment_count']  = $this->comments->countByStatus('spam');
                $stats['trash_comment_count'] = $this->comments->countByStatus('trash');
            } catch (\Throwable $e) {
                $stats['spam_comment_count']  = null;
                $stats['trash_comment_count'] = null;
                $stats['comments_error']      = $e->getMessage();
            }
        }

        if ($want('orphaned-meta')) {
            try {
                $stats['orphaned_meta_count'] = $this->countOrphanedMeta();
            } catch (\Throwable $e) {
                $stats['orphaned_meta_count'] = null;
                $stats['orphaned_meta_error'] = $e->getMessage();
            }
        }

        if ($want('orphaned-media-files')) {
            try {
                $stats['orphaned_media_file_count'] = count($this->media->findMissingFiles());
            } catch (\Throwable $e) {
                $stats['orphaned_media_file_count']  = null;
                $stats['orphaned_media_files_error'] = $e->getMessage();
            }
        }

        if ($want('orphaned-media-unused')) {
            try {
                $stats['orphaned_media_unused_count'] = count($this->media->findUnused());
            } catch (\Throwable $e) {
                $stats['orphaned_media_unused_count'] = null;
                $stats['orphaned_media_unused_error'] = $e->getMessage();
            }
        }

        if ($want('cache')) {
            try {
                $stats['page_cache_count']  = $this->countFiles('storage/cache/pages');
                $stats['page_cache_size']   = $this->dirSize('storage/cache/pages');
                $stats['data_cache_count']  = $this->countFiles('storage/cache/data');
                $stats['data_cache_size']   = $this->dirSize('storage/cache/data');
                $stats['views_cache_count'] = $this->countFiles('storage/cache/views');
                $stats['views_cache_size']  = $this->dirSize('storage/cache/views');
            } catch (\Throwable $e) {
                $stats['cache_error'] = $e->getMessage();
            }
        }

        if ($want('redirects')) {
            try {
                $stats['redirect_count'] = ($this->settings->get('seo_enabled', '1') ?? '1') === '1'
                    ? $this->redirects->countAll()
                    : 0;
            } catch (\Throwable $e) {
                $stats['redirect_count']  = null;
                $stats['redirects_error'] = $e->getMessage();
            }
        }

        $response->getBody()->write((string) json_encode(['ok' => true, 'stats' => $stats]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Relative URL of the lazy maintenance-stats endpoint, preserving any
     * subdirectory base path (e.g. /vale) the app is installed under.
     * Relative is enough — the settings page fetches it same-origin.
     */
    private function maintenanceStatsUrl(): string
    {
        return admin_url('/maintenance/stats');
    }

    /**
     * Close the native PHP session for writing so concurrent requests from the
     * same admin (other tabs, AJAX) are not blocked behind this long response.
     */
    private function releaseSessionLock(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    public function clearAllCache(Request $request, Response $response): Response
    {
        $cleared  = $this->clearCacheDir('storage/cache/pages');
        $cleared += $this->clearCacheDir('storage/cache/data');
        $cleared += $this->clearCacheDir('storage/cache/views');

        $this->session->set('flash_success', $this->translator->get('Cache cleared (:count files).', [':count' => (string) $cleared]));
        return $response->withHeader('Location', admin_url('/'))->withStatus(302);
    }

    public function warmCache(Request $request, Response $response): Response
    {
        $process = new Process(['php', base_path('vale'), 'cache:warm']);
        $process->setWorkingDirectory(base_path());
        $process->setTimeout(120);

        // Pass current environment variables to the sub-process
        $process->setEnv($_ENV + $_SERVER);

        $process->run();

        $outputText = trim($process->getOutput());
        $errorText  = trim($process->getErrorOutput());

        if ($process->isSuccessful()) {
            $this->session->set('flash_success', $this->translator->get('Cache warmed successfully.') . ($outputText ? ' ' . $outputText : ''));
        } else {
            $this->session->set('flash_error', $this->translator->get('Cache warm failed.') . ($errorText ? ' ' . $errorText : ''));
        }

        return $response->withHeader('Location', admin_url('/settings?tab=maintenance&subtab=caching'))->withStatus(302);
    }

    public function rebuildContainerCache(Request $request, Response $response): Response
    {
        $clear = new Process(['php', base_path('vale'), 'cache:clear', 'container']);
        $clear->setWorkingDirectory(base_path());
        $clear->setTimeout(120);
        $clear->setEnv($_ENV + $_SERVER);
        $clear->run();

        if (!$clear->isSuccessful()) {
            $error = trim($clear->getErrorOutput()) ?: trim($clear->getOutput());
            $this->session->set('flash_error', $this->translator->get('Compiled container clear failed.') . ($error ? ' ' . $error : ''));
            return $response->withHeader('Location', admin_url('/settings?tab=maintenance&subtab=caching'))->withStatus(302);
        }

        $warm = new Process(['php', base_path('vale'), 'cache:warm', 'container']);
        $warm->setWorkingDirectory(base_path());
        $warm->setTimeout(120);
        $warm->setEnv($_ENV + $_SERVER);
        $warm->run();

        if ($warm->isSuccessful()) {
            $this->session->set('flash_success', $this->translator->get('Compiled container cleared and rebuilt successfully.'));
        } else {
            $error = trim($warm->getErrorOutput()) ?: trim($warm->getOutput());
            $this->session->set('flash_error', $this->translator->get('Compiled container rebuild failed.') . ($error ? ' ' . $error : ''));
        }

        return $response->withHeader('Location', admin_url('/settings?tab=maintenance&subtab=caching'))->withStatus(302);
    }

    public function clearPageCache(Request $request, Response $response): Response
    {
        $cleared = $this->clearCacheDir('storage/cache/pages');

        $this->session->set('flash_success', $this->translator->get('Page cache cleared (:count files).', [':count' => (string) $cleared]));
        return $response->withHeader('Location', admin_url('/settings?tab=maintenance&subtab=caching'))->withStatus(302);
    }

    public function clearDataCache(Request $request, Response $response): Response
    {
        $cleared  = $this->clearCacheDir('storage/cache/data');
        $cleared += $this->clearCacheDir('storage/cache/views');

        $this->session->set('flash_success', $this->translator->get('Data & template cache cleared (:count files).', [':count' => (string) $cleared]));
        return $response->withHeader('Location', admin_url('/settings?tab=maintenance&subtab=caching'))->withStatus(302);
    }

    public function rescanMedia(Request $request, Response $response): Response
    {
        $body     = (array) $request->getParsedBody();
        $organize = empty($body['do_not_organize']);

        $prefix = VL_DB_PREFIX;
        $admin  = $this->db->fetchAssociative(
            "SELECT u.id FROM {$prefix}users u
             INNER JOIN {$prefix}user_roles ur ON ur.user_id = u.id
             INNER JOIN {$prefix}roles r ON r.id = ur.role_id
             WHERE r.slug IN ('super_admin', 'admin')
             ORDER BY u.id ASC LIMIT 1"
        );
        $userId = $admin ? (int) $admin['id'] : null;

        if ($userId === null) {
            return $this->jsonResponse($response, ['ok' => false, 'message' => $this->translator->get('No admin user found for media scan.')]);
        }

        $output = new class () implements \Symfony\Component\Console\Output\OutputInterface {
            public function write($messages, bool $newline = false, int $options = 0): void
            {
            }
            public function writeln($messages, int $options = 0): void
            {
            }
            public function setVerbosity(int $level): void
            {
            }
            public function getVerbosity(): int
            {
                return self::VERBOSITY_NORMAL;
            }
            public function setDecorated(bool $decorated): void
            {
            }
            public function isDecorated(): bool
            {
                return false;
            }
            public function setFormatter(\Symfony\Component\Console\Formatter\OutputFormatterInterface $formatter): void
            {
            }
            public function getFormatter(): \Symfony\Component\Console\Formatter\OutputFormatterInterface
            {
                throw new \LogicException('Not implemented');
            }
            public function isQuiet(): bool
            {
                return false;
            }
            public function isVerbose(): bool
            {
                return false;
            }
            public function isVeryVerbose(): bool
            {
                return false;
            }
            public function isDebug(): bool
            {
                return false;
            }
        };

        try {
            $result  = $this->mediaScanService->scan($userId, $output, $organize);
            $message = sprintf(
                '%d scanned, %d added, %d updated',
                $result['scanned'],
                $result['added'],
                $result['updated'] ?? 0
            );
            if (!empty($result['errors'])) {
                $message .= ', ' . count($result['errors']) . ' errors';
                foreach (array_slice($result['errors'], 0, 5) as $err) {
                    $message .= "\n" . $err['file'] . ': ' . $err['error'];
                }
            }
            return $this->jsonResponse($response, ['ok' => true, 'message' => $message]);
        } catch (\Throwable $e) {
            return $this->jsonResponse($response, ['ok' => false, 'message' => $e->getMessage()]);
        }
    }

    public function pruneRevisions(Request $request, Response $response): Response
    {
        $body           = (array) $request->getParsedBody();
        $keepCount      = max(1, (int) ($body['keep_count'] ?? 10));
        $deleted        = $this->revisions->pruneAll($keepCount);

        $this->session->set('flash_success', $this->translator->get('Revision pruning complete. :count revision(s) removed.', [':count' => (string) $deleted]));
        return $response->withHeader('Location', admin_url('/settings?tab=maintenance&subtab=cleanup'))->withStatus(302);
    }

    public function dbOptimize(Request $request, Response $response): Response
    {
        try {
            $body       = (array) $request->getParsedBody();
            $operations = (array) ($body['operations'] ?? ['all']);
            $results    = [];

            $runAll        = in_array('all', $operations, true);
            $platformClass = strtolower($this->db->getDatabasePlatform()::class);
            $isSqlite      = str_contains($platformClass, 'sqlite') || str_contains($platformClass, 'postgres') || str_contains($platformClass, 'pgsql');

            // Revisions
            if ($runAll || in_array('revisions', $operations, true)) {
                try {
                    $keepCount       = max(1, (int) ($body['keep_count'] ?? 10));
                    $deleted         = $this->revisions->pruneAll($keepCount);
                    $results[]       = "{$deleted} revision(s) pruned";
                } catch (\Throwable) {
                }
            }

            // Spam comments
            if ($runAll || in_array('spam', $operations, true)) {
                try {
                    $count     = $this->comments->countByStatus('spam');
                    $deleted   = $this->comments->deleteByStatus('spam');
                    $results[] = "{$deleted} spam comment(s) deleted (was {$count})";
                } catch (\Throwable) {
                }
            }

            // Trashed comments
            if ($runAll || in_array('trash', $operations, true)) {
                try {
                    $count     = $this->comments->countByStatus('trash');
                    $deleted   = $this->comments->deleteByStatus('trash');
                    $results[] = "{$deleted} trashed comment(s) deleted (was {$count})";
                } catch (\Throwable) {
                }
            }

            // Orphaned post_meta
            if ($runAll || in_array('orphaned-meta', $operations, true)) {
                try {
                    $prefix  = VL_DB_PREFIX;
                    $deleted = (int) $this->db->executeStatement(
                        "DELETE FROM {$prefix}post_meta WHERE post_id NOT IN (SELECT id FROM {$prefix}posts)"
                    );
                    $results[] = "{$deleted} orphaned meta record(s) cleaned";
                } catch (\Throwable) {
                }
            }

            // Orphaned media files (missing on disk)
            // Media records are only ever deleted when their checkbox is
            // explicitly selected — never via the implicit "all" default.
            // Records still referenced by content are always kept: the file can
            // simply be restored, deleting the record would break live pages.
            if (in_array('orphaned-media-files', $operations, true)) {
                try {
                    $missingFiles  = $this->media->findMissingFiles();
                    $referencedIds = $this->media->findReferencedIds();
                    $deletedCount  = 0;
                    $keptCount     = 0;

                    foreach ($missingFiles as $missing) {
                        $id = (int) $missing['id'];
                        if (isset($referencedIds[$id])) {
                            $keptCount++;
                            continue;
                        }
                        $m = $this->media->findById($id);
                        if ($m !== null) {
                            // Remove leftover variant files before dropping the record.
                            $this->uploadService->deleteFile($m);
                        }
                        $this->media->delete($id);
                        $deletedCount++;
                    }

                    $results[] = "{$deletedCount} orphaned media record(s) deleted"
                        . ($keptCount > 0 ? ", {$keptCount} kept (still referenced by content — restore the files)" : '');
                } catch (\Throwable) {
                }
            }

            // Unused media (not referenced in content)
            // Explicit-only, same reasoning as orphaned-media-files above.
            if (in_array('orphaned-media-unused', $operations, true)) {
                try {
                    $unusedMedia = $this->media->findUnused();
                    $count       = count($unusedMedia);
                    if ($count > 0) {
                        foreach ($unusedMedia as $item) {
                            $m = $this->media->findById((int) $item['id']);
                            if ($m !== null) {
                                // Removes the original plus every variant file.
                                $this->uploadService->deleteFile($m);
                            }
                            $this->media->delete((int) $item['id']);
                        }
                        $results[] = "{$count} unused media record(s) and file(s) deleted";
                    } else {
                        $results[] = "0 unused media records";
                    }
                } catch (\Throwable) {
                }
            }

            // Old pageviews
            if ($runAll || in_array('pageviews', $operations, true)) {
                try {
                    $days    = max(1, (int) ($body['pageview_days'] ?? 120));
                    $prefix  = VL_DB_PREFIX;
                    $deleted = (int) $this->db->executeStatement(
                        "DELETE FROM {$prefix}pageviews WHERE created_at < ?",
                        [date('Y-m-d H:i:s', time() - ($days * 86400))]
                    );
                    $results[] = "{$deleted} old pageview(s) purged";
                } catch (\Throwable) {
                }
            }

            // Optimize tables
            if ($runAll || in_array('optimize-tables', $operations, true)) {
                try {
                    $platformClass = strtolower($this->db->getDatabasePlatform()::class);
                    if (str_contains($platformClass, 'sqlite') || str_contains($platformClass, 'postgres') || str_contains($platformClass, 'pgsql')) {
                        $this->db->executeStatement("VACUUM");
                        $results[] = "Database optimized (VACUUM)";
                    } else {
                        $prefix    = VL_DB_PREFIX;
                        $optimized = 0;
                        try {
                            $allTables = $this->db->createSchemaManager()->listTableNames();
                            foreach ($allTables as $table) {
                                if (str_starts_with((string) $table, $prefix)) {
                                    try {
                                        $quoted = $this->db->quoteIdentifier($table);
                                        $stmt   = $this->db->executeQuery("OPTIMIZE TABLE {$quoted}");
                                        $stmt->free();
                                        $optimized++;
                                    } catch (\Throwable) {
                                    }
                                }
                            }
                        } catch (\Throwable) {
                        }
                        $results[] = "{$optimized} table(s) optimized";
                    }
                } catch (\Throwable) {
                }
            }

            $summary = implode('; ', $results);
            $this->session->set('flash_success', $this->translator->get('DB Optimize complete: ' . $summary));
        } catch (\Throwable $e) {
            $this->session->set('flash_error', $this->translator->get('DB Optimize error: ' . $e->getMessage()));
        }

        return $response->withHeader('Location', admin_url('/settings?tab=maintenance&subtab=cleanup'))->withStatus(302);
    }

    public function clearRedirects(Request $request, Response $response): Response
    {
        if (($this->settings->get('seo_enabled', '1') ?? '1') !== '1') {
            return $response->withStatus(403);
        }

        $deleted = $this->redirects->clearAll();
        $this->session->set(
            'flash_success',
            $this->translator->get('Redirect table cleared. :count redirect(s) removed.', [':count' => (string) $deleted]),
        );

        return $response->withHeader('Location', admin_url('/settings?tab=maintenance&subtab=cleanup'))->withStatus(302);
    }

    public function optimizeAll(Request $request, Response $response): Response
    {
        try {
            $cleared  = $this->clearCacheDir('storage/cache/pages');
            $cleared += $this->clearCacheDir('storage/cache/data');
            $cleared += $this->clearCacheDir('storage/cache/views');

            if ($this->pageCache !== null) {
                $this->pageCache->flush();
            }

            // DB optimize (runs all operations)
            $pruned = 0;
            $spam   = 0;
            $trash  = 0;
            try {
                $pruned = $this->revisions->pruneAll(10);
            } catch (\Throwable) {
            }
            try {
                $spam   = $this->comments->deleteByStatus('spam');
            } catch (\Throwable) {
            }
            try {
                $trash  = $this->comments->deleteByStatus('trash');
            } catch (\Throwable) {
            }

            $prefix        = VL_DB_PREFIX;
            $platformClass = strtolower($this->db->getDatabasePlatform()::class);
            $isSqliteOrPg  = str_contains($platformClass, 'sqlite') || str_contains($platformClass, 'postgres') || str_contains($platformClass, 'pgsql');

            $orphaned  = 0;
            $pageviews = 0;
            try {
                $orphaned = (int) $this->db->executeStatement(
                    "DELETE FROM {$prefix}post_meta WHERE post_id NOT IN (SELECT id FROM {$prefix}posts)"
                );
            } catch (\Throwable) {
            }
            try {
                $pageviews = (int) $this->db->executeStatement(
                    "DELETE FROM {$prefix}pageviews WHERE created_at < ?",
                    [date('Y-m-d H:i:s', time() - 31536000)]
                );
            } catch (\Throwable) {
            }

            if ($isSqliteOrPg) {
                try {
                    $this->db->executeStatement("VACUUM");
                } catch (\Throwable) {
                }
                $optimized = 'Database';
            } else {
                $optimizedCount = 0;
                try {
                    $allTables = $this->db->createSchemaManager()->listTableNames();
                    foreach ($allTables as $table) {
                        if (str_starts_with((string) $table, $prefix)) {
                            try {
                                $quoted = $this->db->quoteIdentifier($table);
                                $stmt   = $this->db->executeQuery("OPTIMIZE TABLE {$quoted}");
                                $stmt->free();
                                $optimizedCount++;
                            } catch (\Throwable) {
                            }
                        }
                    }
                } catch (\Throwable) {
                }
                $optimized = $optimizedCount . ' tables';
            }

            $msg = "Cache cleared ({$cleared} files), {$pruned} revisions pruned, {$spam} spam + {$trash} trash comments deleted, {$orphaned} orphaned meta records cleaned, pageviews purged, {$optimized} optimized.";
            $this->session->set('flash_success', $this->translator->get($msg));
        } catch (\Throwable $e) {
            $this->session->set('flash_error', $this->translator->get('Optimization error: ' . $e->getMessage()));
        }

        return $response->withHeader('Location', admin_url('/'))->withStatus(302);
    }

    private function countOrphanedMeta(): int
    {
        // LEFT JOIN ... IS NULL scales better than NOT IN (subquery) on large tables.
        $prefix = VL_DB_PREFIX;
        return (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM {$prefix}post_meta m LEFT JOIN {$prefix}posts p ON p.id = m.post_id WHERE p.id IS NULL"
        );
    }

    private function clearCacheDir(string $relativePath): int
    {
        $cleared = 0;
        $dir     = base_path($relativePath);
        if (!is_dir($dir)) {
            return 0;
        }
        try {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($files as $file) {
                if ($file->isFile()) {
                    @unlink($file->getPathname());
                    $cleared++;
                }
            }
        } catch (\Throwable $e) {
            error_log('[Cache Clear] Error clearing ' . $relativePath . ': ' . $e->getMessage());
        }
        return $cleared;
    }

    private function countFiles(string $relativePath): int
    {
        $dir = base_path($relativePath);
        if (!is_dir($dir)) {
            return 0;
        }
        $count = 0;
        try {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($files as $file) {
                if ($file->isFile()) {
                    $count++;
                }
            }
        } catch (\Throwable) {
        }
        return $count;
    }

    private function dirSize(string $relativePath): int
    {
        $dir = base_path($relativePath);
        if (!is_dir($dir)) {
            return 0;
        }
        $size  = 0;
        try {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($files as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                }
            }
        } catch (\Throwable) {
        }
        return $size;
    }

    public function verifyCaptcha(Request $request, Response $response): Response
    {
        $body  = (array) $request->getParsedBody();
        $type  = $body['type']  ?? '';
        $token = $body['token'] ?? '';

        if ($type === 'math') {
            $ans = (int) ($body['math_answer'] ?? 0);
            if ($ans === 5) {
                return $this->jsonResponse($response, ['success' => true, 'message' => 'Math verification (2+3=5) successful!']);
            }
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Incorrect math answer.']);
        }

        $secret = '';
        $url    = '';

        if ($type === 'hcaptcha') {
            $secret = $this->settings->get('hcaptcha_secret_key', '');
            $url    = CaptchaUrls::fromFile()->verifyUrl('hcaptcha');
        } elseif ($type === 'recaptcha') {
            $secret = $this->settings->get('recaptcha_secret_key', '');
            $url    = CaptchaUrls::fromFile()->verifyUrl('recaptcha');
        } elseif ($type === 'turnstile') {
            $secret = $this->settings->get('turnstile_secret_key', '');
            $url    = CaptchaUrls::fromFile()->verifyUrl('turnstile');
        }

        if ($secret === '') {
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Provider not configured (missing secret key).']);
        }

        // Config-only test (empty token): validate the secret key via Google's API
        if ($token === '') {
            $resp = HttpTransport::request(
                'POST',
                $url,
                ['Content-Type: application/x-www-form-urlencoded'],
                http_build_query(['secret' => $secret, 'response' => 'dummy']),
                5
            );
            if (!$resp['ok']) {
                return $this->jsonResponse($response, ['success' => false, 'message' => 'Failed to connect to ' . $type . '. ' . $resp['error']]);
            }
            $data  = json_decode($resp['body'], true);
            $error = $data['error-codes'][0] ?? '';
            if ($error === 'missing-input-response' || $error === 'invalid-input-response') {
                return $this->jsonResponse($response, ['success' => true, 'message' => 'Keys are valid and reachable.']);
            }
            $messages = [
                'invalid-input-secret'  => 'Invalid secret key. Check your ' . $type . ' admin console.',
                'missing-input-secret'  => 'Secret key not sent to provider.',
                'invalid-keys'          => 'Invalid site key or secret key.',
                'bad-request'           => 'Malformed request to provider.',
            ];
            return $this->jsonResponse($response, ['success' => false, 'message' => $messages[$error] ?? 'Config error: ' . $error]);
        }

        $resp = HttpTransport::request(
            'POST',
            $url,
            ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query(['secret' => $secret, 'response' => $token]),
            5
        );

        if (!$resp['ok']) {
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Failed to connect to provider. ' . $resp['error']]);
        }

        $data = json_decode($resp['body'], true);
        $ok   = ($data['success'] ?? false) === true;

        if ($ok && $type === 'recaptcha') {
            $score = (float) ($data['score'] ?? 0);
            return $this->jsonResponse($response, [
                'success' => true,
                'message' => sprintf('reCAPTCHA verified! Score: %0.1f', $score),
            ]);
        }

        return $this->jsonResponse($response, [
            'success' => $ok,
            'message' => $ok ? 'Verification successful!' : ($data['error-codes'][0] ?? 'Verification failed.'),
        ]);
    }

    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    public function downloadGeoDb(Request $request, Response $response): Response
    {
        $body       = (array) ($request->getParsedBody() ?? []);
        $queryMode  = $request->getQueryParams()['mode'] ?? null;
        $savedMode  = $this->settings->get('analytics_geolocation', 'none');
        $mode       = (string) ($body['mode'] ?? $queryMode ?? $savedMode ?? 'none');
        $validModes = ['ip2location_country', 'ip2location_city', 'maxmind_city', 'dbip_city'];
        if (!in_array($mode, $validModes, true)) {
            $payload = json_encode(['ok' => false, 'message' => 'No valid geolocation mode selected.']);
            $response->getBody()->write($payload);
            return $response->withHeader('Content-Type', 'application/json');
        }

        $referenceData = require dirname(__DIR__, 3) . '/config/reference-data.php';
        $geoConfig     = is_array($referenceData['geo'] ?? null) ? $referenceData['geo'] : [];
        $modeConfig    = match ($mode) {
            'ip2location_country' => $geoConfig['ip2location']['country'] ?? [],
            'ip2location_city'    => $geoConfig['ip2location']['city']    ?? [],
            'maxmind_city'        => $geoConfig['maxmind']['city']        ?? [],
            'dbip_city'           => $geoConfig['dbip']['city']           ?? [],
            default               => [],
        };

        if (in_array($mode, ['ip2location_city', 'maxmind_city'], true)) {
            $downloadPage = $modeConfig['download_page'] ?? '';
            $providerName = match ($mode) {
                'maxmind_city' => 'MaxMind GeoLite2 City',
                default        => 'IP2Location LITE DB3',
            };
            $msg = $providerName . ' requires manual download.';
            if ($downloadPage !== '') {
                $msg .= ' Download from: ' . $downloadPage;
            }
            $payload = json_encode(['ok' => false, 'message' => $msg]);
            $response->getBody()->write($payload);
            return $response->withHeader('Content-Type', 'application/json');
        }

        $geoDir = dirname(__DIR__, 3) . '/storage/geo';
        if (!is_dir($geoDir)) {
            $created = @mkdir($geoDir, 0755, true);
            if (!$created) {
                $payload = json_encode(['ok' => false, 'message' => 'Cannot create storage/geo/ directory. Check filesystem permissions.']);
                $response->getBody()->write($payload);
                return $response->withHeader('Content-Type', 'application/json');
            }
        }

        if (!is_writable($geoDir)) {
            $payload = json_encode(['ok' => false, 'message' => 'storage/geo/ directory is not writable. Check permissions.']);
            $response->getBody()->write($payload);
            return $response->withHeader('Content-Type', 'application/json');
        }

        $ok = $this->geoIpResolver->downloadDatabase($mode, $modeConfig);
        if ($ok) {
            $size          = $this->geoIpResolver->getDatabaseSize($mode);
            $sizeFormatted = $size !== null ? $this->formatBytes($size) : null;
            $payload       = json_encode(['ok' => true, 'size_formatted' => $sizeFormatted]);
        } else {
            $providerName = $mode === 'dbip_city' ? 'DB-IP' : 'IP2Location';
            $msg          = "Download failed. The {$providerName} server may be unreachable, "
                . 'or the storage/geo/ directory is not writable.';
            $payload      = json_encode(['ok' => false, 'message' => $msg]);
        }

        $response->getBody()->write($payload);
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function checkCloudflare(Request $request, Response $response): Response
    {
        $this->releaseSessionLock();

        $appUrl       = (string) env('APP_URL', '');
        $detectedVia  = null;
        $isCloudflare = false;

        // 1. First check: Ping APP_URL if valid and not purely localhost
        $parsedUrl   = parse_url($appUrl);
        $host        = strtolower($parsedUrl['host'] ?? '');
        $isLocalhost = in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.local');

        if ($appUrl !== '' && !$isLocalhost) {
            $pingUrl = rtrim($appUrl, '/') . '/favicon.ico';
            $pingRes = HttpTransport::request(
                'HEAD',
                $pingUrl,
                ['User-Agent: ValeCMS-CFCheck/1.0'],
                null,
                5
            );

            if ($pingRes['ok']) {
                $rawHeaders = (string) ($pingRes['headers'] ?? '');
                if (
                    stripos($rawHeaders, 'server: cloudflare')  !== false
                    || stripos($rawHeaders, 'cf-ray:')          !== false
                    || stripos($rawHeaders, 'cf-cache-status:') !== false
                ) {
                    $isCloudflare = true;
                    $detectedVia  = 'site_ping';
                }
            }
        }

        // 2. Fallback check: Current request headers from admin user
        if (!$isCloudflare) {
            $server = $request->getServerParams();
            $cfRay  = $request->getHeaderLine('CF-RAY')
                ?: ($server['HTTP_CF_RAY'] ?? '');
            $cfConnectingIp = $request->getHeaderLine('CF-Connecting-IP')
                ?: ($server['HTTP_CF_CONNECTING_IP'] ?? '');
            $cfCountry = $request->getHeaderLine('CF-IPCountry')
                ?: ($server['HTTP_CF_IPCOUNTRY'] ?? '');

            if ($cfRay !== '' || $cfConnectingIp !== '' || $cfCountry !== '') {
                $isCloudflare = true;
                $detectedVia  = 'request_header';
            }
        }

        $trustedProxies = (string) env('TRUSTED_PROXIES', '');
        $remoteAddr     = $request->getServerParams()['REMOTE_ADDR'] ?? '';
        $isTrusted      = $trustedProxies === ''
            || vl_is_trusted_proxy($remoteAddr, $trustedProxies);

        $data = [
            'ok'             => true,
            'is_cloudflare'  => $isCloudflare,
            'detected_via'   => $detectedVia,
            'is_localhost'   => $isLocalhost,
            'proxy_trusted'  => $isTrusted,
        ];

        $response->getBody()->write((string) json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function exportSiteData(Request $request, Response $response): Response
    {
        $body   = (array) $request->getParsedBody();
        $types  = $body['types']  ?? [];
        $format = $body['format'] ?? 'json';

        if (empty($types)) {
            $this->session->set('flash_error', $this->translator->get('Please select at least one data type to export.'));
            return $response->withHeader('Location', admin_url('/settings?tab=maintenance&subtab=data'))->withStatus(302);
        }

        $data         = $this->exportImportService->export($types, [
            'include_secrets' => !empty($body['include_secrets']),
        ]);
        $jsonFilename = 'vale-export-' . date('Y-m-d-His') . '.json';
        $payload      = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if ($format === 'zip' && class_exists(\ZipArchive::class)) {
            $zipPath     = $this->exportImportService->createZip($payload, $jsonFilename);
            $zipFilename = str_replace('.json', '.zip', $jsonFilename);
            $stream      = fopen($zipPath, 'rb');
            $psrStream   = (new StreamFactory())->createStreamFromResource($stream);

            return $response
                ->withHeader('Content-Type', 'application/zip')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $zipFilename . '"')
                ->withBody($psrStream);
        }

        $response->getBody()->write($payload);
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $jsonFilename . '"')
            ->withStatus(200);
    }

    public function importSiteData(Request $request, Response $response): Response
    {
        $files = $request->getUploadedFiles();
        /** @var UploadedFile|null $file */
        $file = $files['import_file'] ?? null;

        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            $this->session->set('flash_error', $this->translator->get('Please select a valid file to import.'));
            return $response->withHeader('Location', admin_url('/settings?tab=maintenance&subtab=data'))->withStatus(302);
        }

        // Set time limit to 0 for large imports (if allowed by PHP configuration)
        @set_time_limit(0);

        $filename = $file->getClientFilename();
        $isZip    = str_ends_with(strtolower($filename), '.zip');
        $content  = '';

        if ($isZip) {
            if (!class_exists(\ZipArchive::class)) {
                $this->session->set('flash_error', $this->translator->get('ZIP extension is not enabled on this server.'));
                return $response->withHeader('Location', admin_url('/settings?tab=maintenance&subtab=data'))->withStatus(302);
            }

            $zip      = new \ZipArchive();
            $tempPath = $file->getFilePath();
            if ($zip->open($tempPath) === true) {
                // Find first .json file in the zip
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entryName = $zip->getNameIndex($i);
                    if (str_ends_with(strtolower($entryName), '.json')) {
                        $content = $zip->getFromIndex($i);
                        break;
                    }
                }
                $zip->close();
            }

            if (empty($content)) {
                $this->session->set('flash_error', $this->translator->get('No JSON file found inside the ZIP archive.'));
                return $response->withHeader('Location', admin_url('/settings?tab=maintenance&subtab=data'))->withStatus(302);
            }
        } else {
            $content = (string)$file->getStream();
        }

        $data = json_decode($content, true);

        if (!$data) {
            $this->session->set('flash_error', $this->translator->get('Invalid JSON data.'));
            return $response->withHeader('Location', admin_url('/settings?tab=maintenance&subtab=data'))->withStatus(302);
        }

        $body     = (array) $request->getParsedBody();
        $dryRun   = !empty($body['dry_run']);
        $results  = $this->exportImportService->import($data, $dryRun, [
            'include_secrets' => !empty($body['include_secrets']),
        ]);

        if ($results['success']) {
            $msg = $this->translator->get('Import completed successfully.');
            if ($dryRun) {
                $msg .= ' ' . $this->translator->get('(DRY RUN - no changes saved)');
            }
            $details = [];
            foreach ($results['imported'] as $type => $count) {
                $skipped   = $results['skipped'][$type] ?? 0;
                $details[] = "{$type}: {$count} " . $this->translator->get('imported') . ", {$skipped} " . $this->translator->get('skipped');
            }
            if (!empty($details)) {
                $msg .= ' ' . implode('; ', $details);
            }
            if (!empty($results['warnings'])) {
                $msg .= ' ' . $this->translator->get('Warnings: ') . implode(' | ', $results['warnings']);
            }
            $msg .= ' ' . $this->translator->get('Note: media files are not included in the export. Copy the uploads directory, then rescan media.');
            $this->session->set('flash_success', $msg);
        } else {
            $this->session->set('flash_error', $this->translator->get('Import failed: ') . ($results['errors']['global'] ?? 'Unknown error'));
        }

        return $response->withHeader('Location', admin_url('/settings?tab=maintenance&subtab=data'))->withStatus(302);
    }

    public function exportData(Request $request, Response $response): Response
    {
        $body  = (array) $request->getParsedBody();
        $email = trim($body['email'] ?? '');

        if ($email === '') {
            $this->session->set('flash_error', 'Email is required.');
            return $response->withHeader('Location', admin_url('/settings?tab=privacy&subtab=gdpr'))->withStatus(302);
        }

        $data = [
            'email'        => $email,
            'exported_at'  => date('Y-m-d H:i:s'),
            'profile'      => null,
            'addresses'    => [],
            'orders'       => [],
            'subscription' => null,
            'comments'     => [],
            'newsletter'   => null,
        ];

        // Check if user exists
        $userRow = $this->db->fetchAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'users WHERE email = ?',
            [$email]
        );

        if ($userRow) {
            $userId          = (int) $userRow['id'];
            $data['profile'] = [
                'name'       => $userRow['name'],
                'email'      => $userRow['email'],
                'locale'     => $userRow['locale'],
                'created_at' => $userRow['created_at'],
            ];

            $data['addresses'] = [
                'billing'  => $this->decryptAddressFields(json_decode((string) $userRow['billing_address'], true) ?? [], self::USER_ADDRESS_ENCRYPTED_FIELDS),
                'shipping' => $this->decryptAddressFields(json_decode((string) $userRow['shipping_address'], true) ?? [], self::USER_ADDRESS_ENCRYPTED_FIELDS),
            ];

            // Orders
            $orderRows = $this->db->fetchAllAssociative(
                'SELECT * FROM ' . VL_DB_PREFIX . 'orders WHERE user_id = ? OR LOWER(billing) LIKE LOWER(?)',
                [$userId, '%"email":"' . $email . '"%']
            );
            foreach ($orderRows as $or) {
                // Filter out internal/sensitive keys
                unset($or['user_id'], $or['payment_token'], $or['transaction_id'], $or['subscription_id'], $or['payment_intent']);
                $or['billing']          = $this->decryptAddressFields(json_decode((string) $or['billing'], true) ?? [], self::ORDER_ADDRESS_ENCRYPTED_FIELDS);
                $or['shipping_address'] = $this->decryptAddressFields(json_decode((string) $or['shipping_address'], true) ?? [], self::ORDER_ADDRESS_ENCRYPTED_FIELDS);
                $data['orders'][]       = $or;
            }

            // Subscriptions
            $sub = $this->db->fetchAssociative(
                'SELECT us.*, mp.name AS plan_name FROM ' . VL_DB_PREFIX . 'user_subscriptions us LEFT JOIN ' . VL_DB_PREFIX . 'membership_plans mp ON mp.id = us.plan_id WHERE us.user_id = ? AND us.status = ?',
                [$userId, 'active']
            );
            if ($sub) {
                unset($sub['user_id'], $sub['gateway_subscription_id'], $sub['subscriber_id']);
                $data['subscription'] = $sub;
            }
        } else {
            // Non-registered user, search by email in orders and newsletter
            $orderRows = $this->db->fetchAllAssociative(
                'SELECT * FROM ' . VL_DB_PREFIX . 'orders WHERE LOWER(billing) LIKE LOWER(?)',
                ['%"email":"' . $email . '"%']
            );
            foreach ($orderRows as $or) {
                $or['billing']          = $this->decryptAddressFields(json_decode((string) $or['billing'], true) ?? [], self::ORDER_ADDRESS_ENCRYPTED_FIELDS);
                $or['shipping_address'] = $this->decryptAddressFields(json_decode((string) $or['shipping_address'], true) ?? [], self::ORDER_ADDRESS_ENCRYPTED_FIELDS);
                $data['orders'][]       = $or;
            }
        }

        // Comments
        $commentRows = $this->db->fetchAllAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'comments WHERE author_email = ?' . ($userRow ? ' OR user_id = ?' : ''),
            $userRow ? [$email, (int) $userRow['id']] : [$email]
        );
        $data['comments'] = $commentRows;

        // Newsletter
        $subscriber = $this->db->fetchAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'newsletter_subscribers WHERE email = ?',
            [$email]
        );
        if ($subscriber) {
            unset($subscriber['user_id'], $subscriber['token']);
            $subscriber['tags'] = json_decode((string) ($subscriber['tags'] ?? '[]'), true);
            $data['newsletter'] = $subscriber;
        }

        $filename = 'export-' . str_replace(['@', '.'], '-', $email) . '-' . date('Y-m-d') . '.json';
        $payload  = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $response->getBody()->write($payload);
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withStatus(200);
    }

    public function importProducts(Request $request, Response $response): Response
    {
        $files = $request->getUploadedFiles();
        /** @var UploadedFile|null $file */
        $file     = $files['import_file'] ?? null;
        $platform = (string) ($request->getParsedBody()['platform'] ?? '');

        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            $this->session->set('flash_error', $this->translator->get('Please select a valid CSV/TXT file to import.'));
            return $response->withHeader('Location', admin_url('/settings?tab=ecommerce&subtab=import'))->withStatus(302);
        }

        @set_time_limit(0);

        $tempPath       = $file->getFilePath();
        $importMedia    = !empty($request->getParsedBody()['import_media']);
        $updateExisting = !empty($request->getParsedBody()['update_existing']);

        $res = ['success' => false, 'errors' => ['Unsupported platform']];

        if ($platform === 'shopify') {
            $res = $this->productImporter->importShopify($tempPath, $importMedia);
        } elseif ($platform === 'etsy') {
            $res = $this->productImporter->importEtsy($tempPath, $importMedia);
        } elseif ($platform === 'ebay') {
            $res = $this->productImporter->importEbay($tempPath, $importMedia);
        } elseif ($platform === 'amazon') {
            $res = $this->productImporter->importAmazon($tempPath, $importMedia);
        } elseif ($platform === 'products') {
            $res = $this->productImporter->importProducts($tempPath, $importMedia, false, $updateExisting);
        }

        if ($res['success']) {
            $msg = sprintf(
                $this->translator->get('Import completed successfully! Imported: %d products, %d variants. Skipped: %d'),
                $res['imported'],
                $res['variants'] ?? 0,
                $res['skipped']  ?? 0
            );
            $this->session->set('flash_success', $msg);
        } else {
            $this->session->set('flash_error', $this->translator->get('Import failed: ') . implode(', ', $res['errors']));
        }

        return $response->withHeader('Location', admin_url('/settings?tab=ecommerce&subtab=import'))->withStatus(302);
    }

    /** @return array{value: string, label: string}|null */
    private function pagePickerSeed(string $value, string $mode): ?array
    {
        if ($value === '') {
            return null;
        }

        $post = $mode === 'slug'
            ? $this->posts->findBySlug(new Slug($value), 'page')
            : $this->posts->findById((int) $value);

        if ($post === null || $post->getContentType() !== 'page' || !$post->isPublished()) {
            return null;
        }

        return [
            'value' => $mode === 'slug' ? (string) $post->getSlug() : (string) $post->getId(),
            'label' => $post->getTitle(),
        ];
    }

    /**
     * Free-text PII stored on the user billing/shipping JSON (address_1 schema).
     */
    private const USER_ADDRESS_ENCRYPTED_FIELDS = ['first_name', 'last_name', 'phone', 'company', 'address_1', 'address_2'];

    /**
     * Free-text PII stored on the order billing/shipping JSON (address or
     * legacy address_1 schema).
     */
    private const ORDER_ADDRESS_ENCRYPTED_FIELDS = ['first_name', 'last_name', 'phone', 'company', 'address', 'address_2', 'address_1'];

    /**
     * @param array<string,mixed>         $decoded
     * @param list<non-empty-string>      $fields
     * @return array<string,mixed>
     */
    private function decryptAddressFields(array $decoded, array $fields): array
    {
        if ($this->encrypter === null || $decoded === []) {
            return $decoded;
        }
        foreach ($fields as $field) {
            if (isset($decoded[$field]) && $decoded[$field] !== '') {
                $decoded[$field] = $this->encrypter->decrypt((string) $decoded[$field]);
            }
        }
        return $decoded;
    }
}
