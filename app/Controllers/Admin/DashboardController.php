<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Admin;

use Doctrine\DBAL\Connection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use ValeApp\Application\Form\FormCaptchaService;
use ValeApp\Application\Plugin\PluginUpdateService;
use ValeApp\Domain\Content\Repository\ContentTypeRepositoryInterface;
use ValeApp\Infrastructure\Cache\FileCache;
use ValeApp\Infrastructure\Cache\PageCache;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Theme\ThemeManager;

/**
 * Admin dashboard controller.
 */
final class DashboardController
{
    public function __construct(
        private readonly Twig $twig,
        private readonly FileCache $cache,
        private readonly PageCache $pageCache,
        private readonly Connection $db,
        private readonly DbalSettingsRepository $settings,
        private readonly ThemeManager $themes,
        private readonly ContentTypeRepositoryInterface $contentTypes,
        private readonly FormCaptchaService $formCaptcha,
        private readonly PluginUpdateService $pluginUpdates,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // ── Quick stats ──────────────────────────────────────────────
        $postCount       = (int) $this->db->fetchOne('SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'posts' . ' WHERE content_type = ?', ['post']);
        $pageCount       = (int) $this->db->fetchOne('SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'posts' . ' WHERE content_type = ?', ['page']);
        $productCount    = (int) $this->db->fetchOne('SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'products');
        $orderCount      = (int) $this->db->fetchOne('SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'orders');
        $pendingOrders   = (int) $this->db->fetchOne("SELECT COUNT(*) FROM " . VL_DB_PREFIX . "orders WHERE status = 'pending'");
        $subscriberCount = (int) $this->db->fetchOne('SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'newsletter_subscribers');
        $commentCount    = (int) $this->db->fetchOne('SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'comments');
        $pendingComments = (int) $this->db->fetchOne("SELECT COUNT(*) FROM " . VL_DB_PREFIX . "comments WHERE status = 'pending'");
        $draftCount      = (int) $this->db->fetchOne("SELECT COUNT(*) FROM " . VL_DB_PREFIX . "posts WHERE status = 'draft'");
        $scheduledCount  = (int) $this->db->fetchOne("SELECT COUNT(*) FROM " . VL_DB_PREFIX . "posts WHERE status = 'scheduled'");

        // ── Environment ──────────────────────────────────────────────
        $appEnv   = env('APP_ENV', 'production');
        $appDebug = env('APP_DEBUG', false);

        // ── Quick-actions order ───────────────────────────────────────
        $savedQa = $this->settings->get('admin_quick_actions_order', '') ?? '';
        $qaOrder = $savedQa !== '' ? (json_decode($savedQa, true) ?? []) : [];

        $versionFile       = dirname(__DIR__, 3) . '/version.php';
        $valeVersion       = file_exists($versionFile) ? (require $versionFile) : '—';
        $isBaked           = is_file(dirname(__DIR__, 3) . '/storage/.baked');

        // ── System status checks ─────────────────────────────────────
        $status   = [];
        $__       = $this->twig->getEnvironment()->getFunction('__')->getCallable();

        // Maintenance mode (Settings -> General)
        if (($this->settings->get('maintenance_mode', '0') ?? '0') === '1') {
            $status[] = [
                'type'     => 'warning',
                'message'  => $__("Maintenance mode is enabled. Visitors see a maintenance page; only logged-in Admin and Super Admin users can access the public site."),
                'link'     => '/admin/settings#tab-general',
                'linkText' => __('General Settings'),
            ];
        }

        // Update maintenance flag (during auto-update)
        $maintenanceFlag = dirname(__DIR__, 3) . '/storage/framework/maintenance.php';
        if (is_file($maintenanceFlag)) {
            $flagData = @include $maintenanceFlag;
            if (is_array($flagData) && ($flagData['active'] ?? false) === true) {
                $status[] = [
                    'type'     => 'warning',
                    'message'  => __("The site is in update maintenance mode. Visitors are blocked; only logged-in Admin and Super Admin users can access the public site."),
                    'link'     => '/admin/update',
                    'linkText' => __('Updates & Backups'),
                ];
            }
        }

        // Cron
        $cronMode = $this->settings->get('cron_mode', 'none') ?? 'none';
        if ($cronMode === 'none') {
            $status[] = [
                'type'     => 'warning',
                'message'  => 'Cron is disabled. Scheduled tasks (automations, updates) will not run automatically.',
                'link'     => '/admin/settings#tab-maintenance',
                'linkText' => 'Maintenance → Scheduled Tasks',
            ];
        }

        if ($appDebug) {
            $status[] = [
                'type'    => 'warning',
                'message' => 'Debug mode is enabled. Cache is disabled and performance may be reduced while debugging is active.',
            ];
        }

        // Shop enabled but no payment configured
        $shopEnabled = ($this->settings->get('shop_enabled', '1') ?? '1') === '1';
        if ($shopEnabled) {
            $gateways   = ['stripe', 'paypal', 'mollie', 'twocheckout', 'checkoutcom', 'banktransfer'];
            $anyEnabled = false;
            foreach ($gateways as $gw) {
                if (($this->settings->get($gw . '_enabled', '0') ?? '0') === '1') {
                    $anyEnabled = true;
                    break;
                }
            }
            if (!$anyEnabled) {
                $status[] = [
                    'type'     => 'warning',
                    'message'  => 'Shop is enabled but no payment gateway is configured. Customers cannot complete purchases.',
                    'link'     => '/admin/settings#tab-payments',
                    'linkText' => 'Payment Settings',
                ];
            }
        }

        // Sandbox mode enabled on active payment gateways
        $sandboxGateways = [];
        $sandboxChecks   = [
            'paypal'      => ['name' => 'PayPal',      'sandbox' => 'paypal_sandbox',    'enabled' => 'paypal_enabled'],
            'mollie'      => ['name' => 'Mollie',      'sandbox' => 'mollie_sandbox',    'enabled' => 'mollie_enabled'],
            'checkoutcom' => ['name' => 'Checkout.com', 'sandbox' => 'checkoutcom_sandbox', 'enabled' => 'checkoutcom_enabled'],
        ];
        foreach ($sandboxChecks as $gw) {
            if (
                ($this->settings->get($gw['sandbox'], '0') ?? '0')    === '1'
                && ($this->settings->get($gw['enabled'], '0') ?? '0') === '1'
            ) {
                $sandboxGateways[] = $gw['name'];
            }
        }
        if (!empty($sandboxGateways)) {
            $status[] = [
                'type'     => 'warning',
                'message'  => 'Sandbox mode is active for: ' . implode(', ', $sandboxGateways) . '. Test transactions will not be processed as real payments.',
                'link'     => '/admin/settings?tab=ecommerce&subtab=payment',
                'linkText' => 'E-Commerce → Payment',
            ];
        }

        // Legal pages not configured
        $shopEnabled          = ($this->settings->get('shop_enabled', '1') ?? '1')          === '1';
        $subscriptionsEnabled = ($this->settings->get('subscriptions_enabled', '1') ?? '1') === '1'
            || ($this->settings->get('membership_enabled', '1') ?? '1')                     === '1';
        if ($shopEnabled || $subscriptionsEnabled) {
            $policyKeys = [
                'policy_page_returns'  => 'Returns / Cancellation Policy',
                'policy_page_privacy'  => 'Privacy Policy',
                'policy_page_terms'    => 'Terms & Conditions',
            ];
            $missingPolicies = [];
            foreach ($policyKeys as $key => $label) {
                $slug = trim($this->settings->get($key, '') ?? '');
                if ($slug === '') {
                    $missingPolicies[] = $label;
                }
            }
            if (!empty($missingPolicies)) {
                $status[] = [
                    'type'     => 'info',
                    'message'  => 'Legal pages not configured: ' . implode(', ', $missingPolicies) . '.',
                    'link'     => '/admin/settings?tab=ecommerce&subtab=legal',
                    'linkText' => 'E-Commerce → Legal',
                ];
            }
        }

        // Additional currencies configured but exchange rate provider is none
        $allowedCurrencies = trim($this->settings->get('allowed_currencies', '') ?? '');
        $fxProvider        = $this->settings->get('fx_provider', 'none') ?? 'none';
        if ($allowedCurrencies !== '' && $fxProvider === 'none') {
            $status[] = [
                'type'     => 'warning',
                'message'  => 'Additional currencies are enabled but the exchange rate provider is not set. Currency conversion will not work.',
                'link'     => '/admin/settings?tab=ecommerce&subtab=currency',
                'linkText' => 'Currency Settings',
            ];
        }

        // Mail driver is 'log'
        $mailDriver = $this->settings->get('mail_driver', 'log') ?? 'log';
        if ($mailDriver === 'log') {
            $status[] = [
                'type'     => 'warning',
                'message'  => 'Mail driver is set to "Log" mode. Emails are written to the log file but not actually sent to recipients.',
                'link'     => '/admin/settings#tab-mail',
                'linkText' => 'Mail Settings',
            ];
        }

        // Plugin updates available from the last manual or cron check
        $pluginUpdateCount = 0;
        foreach ($this->pluginUpdates->status() as $pluginUpdate) {
            if (!empty($pluginUpdate['available']) && empty($pluginUpdate['error'])) {
                $pluginUpdateCount++;
            }
        }

        if ($pluginUpdateCount > 0) {
            $status[] = [
                'type'     => 'info',
                'message'  => sprintf(
                    $__(':count plugin update(s) available.'),
                    $pluginUpdateCount,
                ),
                'link'     => '/admin/plugins',
                'linkText' => __('Review plugin updates'),
            ];
        }

        // Forms hidden because their captcha provider has no API keys
        $unavailableForms = $this->formCaptcha->findUnavailableForms();
        if (!empty($unavailableForms)) {
            $status[] = [
                'type'     => 'warning',
                'message'  => sprintf(
                    $__(':count form(s) are not displayed because their captcha provider is not configured: :forms.'),
                    (string) count($unavailableForms),
                    implode(', ', array_map(fn ($form) => $form->getName(), $unavailableForms)),
                ),
                'link'     => '/admin/settings?tab=privacy&subtab=captcha',
                'linkText' => __('Privacy Settings'),
            ];
        }

        // ── Dashboard news ───────────────────────────────────────────
        $ref            = require config_path('reference-data.php');
        $newsFeedUrl    = (string) ($ref['dashboard_news']['feed_url'] ?? '');
        $newsViewAllUrl = (string) ($ref['dashboard_news']['view_all_url'] ?? '');
        $newsDisabled   = ($this->settings->get('dashboard_news_disabled', '0') ?? '0') === '1';
        $newsEnabled    = !$newsDisabled && $newsFeedUrl !== '';
        $valeNews       = [];
        if ($newsEnabled) {
            $valeNews = $this->fetchValeNews($newsFeedUrl);
        }

        // ── Custom Content Types ────────────────────────────────────
        $cptEnabled = ($this->settings->get('custom_content_types_enabled', '1') ?? '1') === '1';
        $activeCpts = $cptEnabled ? $this->contentTypes->findActiveCustom() : [];

        // ── Menus Check ─────────────────────────────────────────────
        $definedMenus  = $this->themes->getDefinedMenus();
        $usedLocations = $this->db->fetchFirstColumn(
            'SELECT DISTINCT location FROM ' . VL_DB_PREFIX . 'menus' . ' WHERE location IS NOT NULL AND location != \'\''
        );
        $missingLocations = [];
        foreach ($usedLocations as $loc) {
            if (!isset($definedMenus[$loc])) {
                $missingLocations[] = $loc;
            }
        }
        if (!empty($missingLocations)) {
            $status[] = [
                'type'     => 'warning',
                'message'  => sprintf($__("Some menus are assigned to locations that do not exist in the current theme: %s."), implode(', ', $missingLocations)),
                'link'     => '/admin/menus',
                'linkText' => __('Manage Menus'),
            ];
        }

        // ── Needs attention (operational queue) ──────────────────────
        // Feature / permission gating happens in the template: the counts are
        // always computed so every role sees the same numbers, and the partial
        // decides which rows are relevant for the current user.
        $attention = [];
        if ($pendingComments > 0) {
            $attention[] = [
                'id'       => 'comments',
                'count'    => $pendingComments,
                'message'  => __('Comments awaiting moderation'),
                'link'     => '/admin/comments?status=pending',
                'linkText' => __('Review'),
            ];
        }
        if ($pendingOrders > 0) {
            $attention[] = [
                'id'       => 'orders',
                'count'    => $pendingOrders,
                'message'  => __('Pending orders'),
                'link'     => '/admin/orders?status=pending',
                'linkText' => __('Fulfill'),
            ];
        }
        if ($draftCount > 0) {
            $attention[] = [
                'id'       => 'drafts',
                'count'    => $draftCount,
                'message'  => __('Unpublished drafts'),
                'link'     => '/admin/posts?status=draft',
                'linkText' => __('Continue'),
            ];
        }
        if ($scheduledCount > 0) {
            $attention[] = [
                'id'       => 'scheduled',
                'count'    => $scheduledCount,
                'message'  => __('Scheduled posts'),
                'link'     => '/admin/posts?status=scheduled',
                'linkText' => __('Preview'),
            ];
        }

        return $this->twig->render($response, 'admin/dashboard.twig', [
            'title'                         => 'Dashboard',
            'php_version'                   => PHP_VERSION,
            'vale_version'                  => $valeVersion,
            'is_baked'                      => $isBaked,
            'cache_size'                    => $this->formatBytes($this->cache->getSize() + $this->pageCache->getSize()),
            'app_env'                       => $appEnv,
            'app_debug'                     => $appDebug,
            'post_count'                    => $postCount,
            'page_count'                    => $pageCount,
            'product_count'                 => $productCount,
            'order_count'                   => $orderCount,
            'pending_orders'                => $pendingOrders,
            'subscriber_count'              => $subscriberCount,
            'comment_count'                 => $commentCount,
            'pending_comments'              => $pendingComments,
            'draft_count'                   => $draftCount,
            'scheduled_count'               => $scheduledCount,
            'attention'                     => $attention,
            'qa_order'                      => $qaOrder,
            'status'                        => $status,
            'plugin_update_count'           => $pluginUpdateCount,
            'dashboard_news_disabled'       => $newsDisabled,
            'vale_news'                     => $valeNews,
            'vale_news_url'                 => $newsViewAllUrl,
            'active_cpts'                   => $activeCpts,
            'custom_content_types_enabled'  => $cptEnabled,
        ]);
    }

    public function news(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $newsDisabled = ($this->settings->get('dashboard_news_disabled', '0') ?? '0') === '1';
        $ref          = require config_path('reference-data.php');
        $newsUrl      = (string) ($ref['dashboard_news']['feed_url'] ?? '');
        $newsEnabled  = !$newsDisabled && $newsUrl !== '';
        $items        = [];
        if ($newsEnabled) {
            if ((($request->getQueryParams()['force'] ?? '') === '1')) {
                $this->cache->delete('dashboard_vale_news');
            }
            $items = $this->fetchValeNews($newsUrl);
        }

        $response->getBody()->write(json_encode(
            ['items' => $items, 'enabled' => $newsEnabled],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    private function fetchValeNews(string $url): array
    {
        $cacheKey = 'dashboard_vale_news';
        $cached   = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $items = [];
        $raw   = $this->httpGet($url);
        if ($raw !== null) {
            $xml = @simplexml_load_string($raw);
            if ($xml !== false && isset($xml->channel->item)) {
                foreach ($xml->channel->item as $item) {
                    if (count($items) >= 5) {
                        break;
                    }
                    $title = trim((string) ($item->title ?? ''));
                    $link  = trim((string) ($item->link ?? ''));
                    $desc  = trim(strip_tags((string) ($item->description ?? '')));
                    if ($desc === '') {
                        $contentNode = $item->children('http://purl.org/rss/1.0/modules/content/');
                        if (isset($contentNode->encoded)) {
                            $desc = trim(strip_tags((string) $contentNode->encoded));
                        }
                    }
                    $desc = trim(preg_replace('/\s+/u', ' ', $desc) ?? $desc);
                    if ($desc !== '' && mb_strlen($desc) > 160) {
                        $desc = mb_substr($desc, 0, 157) . '...';
                    }
                    $date = '';
                    $pub  = trim((string) ($item->pubDate ?? ''));
                    if ($pub !== '') {
                        $ts = strtotime($pub);
                        // Raw ISO date — formatted locale-aware in Twig (|local_date)
                        // and in JS (toLocaleDateString). Falls back to raw on parse failure.
                        $date = $ts ? gmdate('c', $ts) : $pub;
                    }
                    if ($title !== '' && $link !== '') {
                        $items[] = [
                            'title' => $title,
                            'link'  => $link,
                            'desc'  => $desc,
                            'date'  => $date,
                        ];
                    }
                }
            }
        }

        $this->cache->set($cacheKey, $items, 1800);
        return $items;
    }

    private function httpGet(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return null;
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_HTTPHEADER     => ['User-Agent: Vale CMS Dashboard'],
            ]);
            $res  = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($res === false || $code < 200 || $code >= 300) {
                return null;
            }
            return (string) $res;
        }

        // Fallback when ext-curl is unavailable: allow_url_fopen stream.
        if (!ini_get('allow_url_fopen')) {
            return null;
        }
        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'timeout'       => 8,
                'header'        => 'User-Agent: Vale CMS Dashboard',
                'ignore_errors' => true,
            ],
        ]);
        $stream = @fopen($url, 'r', false, $context);
        if ($stream === false) {
            return null;
        }
        $meta = stream_get_meta_data($stream);
        $res  = stream_get_contents($stream);
        fclose($stream);
        if ($res === false) {
            return null;
        }
        $statusLine = is_array($meta['wrapper_data'] ?? null) ? ($meta['wrapper_data'][0] ?? '') : '';
        if (!preg_match('#\s(\d{3})\s#', $statusLine, $m) || (int) $m[1] < 200 || (int) $m[1] >= 300) {
            return null;
        }
        return (string) $res;
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
}
