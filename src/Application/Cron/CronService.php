<?php

declare(strict_types=1);

namespace ValeApp\Application\Cron;

use ValeApp\Application\Membership\SubscriptionReconciliationService;
use ValeApp\Application\Membership\SubscriptionService;
use ValeApp\Application\Newsletter\AutomationService;
use ValeApp\Application\Newsletter\NewsletterService;
use ValeApp\Application\Plugin\PluginMigrator;
use ValeApp\Application\Plugin\PluginUpdateService;
use ValeApp\Application\Shop\AbandonedCartService;
use ValeApp\Application\Update\BackupService;
use ValeApp\Application\Update\UpdateNotificationService;
use ValeApp\Application\Update\UpdateService;
use ValeApp\Domain\Analytics\Repository\PageviewRepositoryInterface;
use ValeApp\Domain\Content\Entity\Post;
use ValeApp\Domain\Content\Repository\ContentTypeRepositoryInterface;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Content\Repository\RevisionRepositoryInterface;
use ValeApp\Domain\Newsletter\Repository\CampaignRepositoryInterface;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;
use ValeApp\Infrastructure\Content\SlugService;
use ValeApp\Infrastructure\ExchangeRate\ExchangeRateService;

/**
 * Executes all background jobs that are due to run.
 *
 * Called by:
 *   - CLI: php vale cron:run
 *   - HTTP endpoint: GET /cron/run?token={secret}
 *   - PseudoCronMiddleware (page-load mode)
 */
final class CronService
{
    private const LAST_RAN_KEY = 'cron_last_ran';

    /** @var array<string, bool>|null Cache for active custom content types */
    private ?array $activeCustomTypesCache = null;

    public function __construct(
        private readonly NewsletterService $newsletter,
        private readonly AutomationService $automations,
        private readonly CampaignRepositoryInterface $campaigns,
        private readonly PostRepositoryInterface $posts,
        private readonly ProductRepositoryInterface $products,
        private readonly ExchangeRateService $exchangeRate,
        private readonly SettingsRepositoryInterface $settings,
        private readonly UpdateService $updater,
        private readonly ?UpdateNotificationService $updateNotifier = null,
        private readonly ?BackupService $backupService = null,
        private readonly ?PageviewRepositoryInterface $pageviews = null,
        private readonly ?RevisionRepositoryInterface $revisions = null,
        private readonly ?SubscriptionReconciliationService $subscriptionReconciliation = null,
        private readonly ?SubscriptionService $subscriptionService = null,
        private readonly ?AbandonedCartService $abandonedCartService = null,
        private readonly ?SlugService $swapSlugs = null,
        private readonly string $rootDir = '',
        private readonly string $dataCacheDir = '',
        private readonly ?ContentTypeRepositoryInterface $contentTypes = null,
        private readonly ?PluginMigrator $pluginMigrator = null,
        private readonly ?PluginUpdateService $pluginUpdater = null,
    ) {
    }

    /**
      * Run all jobs that are due and return a summary.
      *
      * @param bool $forceRates Force exchange rate refresh regardless of cache interval
      * @param bool $forceUpdate Force update check regardless of auto-check interval
      *
      * @return array{campaigns_sent: int, automations_sent: int, posts_published: int, products_published: int, posts_expired: int, products_expired: int, posts_go_public: int, products_go_public: int, swaps_processed: int, rates_updated: bool, update_checked: bool, update_available: bool, update_version: string, plugin_updates_checked: int, plugin_updates_available: int, subscriptions_reconciled: int, trials_expired: int, abandoned_carts_sent: int}
      */
    public function runDue(bool $forceRates = false, bool $forceUpdate = false): array
    {
        $result = [
            'campaigns_sent'           => 0,
            'automations_sent'         => 0,
            'posts_published'          => 0,
            'products_published'       => 0,
            'posts_expired'            => 0,
            'products_expired'         => 0,
            'posts_go_public'          => 0,
            'products_go_public'       => 0,
            'swaps_processed'          => 0,
            'rates_updated'            => false,
            'update_checked'           => false,
            'update_available'         => false,
            'update_version'           => '',
            'plugin_updates_checked'   => 0,
            'plugin_updates_available' => 0,
            'subscriptions_reconciled' => 0,
            'trials_expired'           => 0,
            'abandoned_carts_sent'     => 0,
        ];

        $newsletterEnabled = $this->settings->bool('newsletter_enabled', true);

        // ── Scheduled newsletter campaigns ────────────────────────────
        if ($newsletterEnabled) {
            foreach ($this->campaigns->findDueForSending() as $campaign) {
                try {
                    $this->newsletter->sendCampaign($campaign);
                    $result['campaigns_sent']++;
                } catch (\Throwable) {
                    // Continue with other jobs if one fails
                }
            }
        }

        // ── Automation email sequences ─────────────────────────────────
        if ($newsletterEnabled) {
            try {
                $result['automations_sent'] = $this->automations->processDue(50);
            } catch (\Throwable) {
                // Non-critical
            }
        }

        // ── Scheduled posts / pages / custom types ──────────────────────
        // Gated per content type (post → blog_enabled, page → pages_enabled,
        // CPT → active flag). Everything used to be gated on blog_enabled
        // only, so scheduled pages never published while the blog was off.
        foreach ($this->posts->findDueForPublishing() as $post) {
            if (!$this->contentTypeEnabled($post->getContentType())) {
                // Stays scheduled until its content type is enabled again.
                continue;
            }
            try {
                if ($this->publishWithSwap($post)) {
                    $result['swaps_processed']++;
                }
                $result['posts_published']++;
            } catch (\Throwable) {
                // Continue with other jobs if one fails
            }
        }

        // ── Go-public for unlisted items with a reached publish date ──
        // Same gating as scheduled publishing; swap handover applies too.
        foreach ($this->posts->findDueForGoPublic() as $post) {
            if (!$this->contentTypeEnabled($post->getContentType())) {
                // Stays unlisted until its content type is enabled again.
                continue;
            }
            try {
                if ($this->publishWithSwap($post)) {
                    $result['swaps_processed']++;
                }
                $result['posts_go_public']++;
            } catch (\Throwable) {
                // Continue with other jobs if one fails
            }
        }

        // ── Expired posts/pages (plain expiry or swap-back) ─────────
        // Per-type gating happens inside processDueExpiries().
        try {
            $expiry                        = $this->processDueExpiries();
            $result['posts_expired']       = $expiry['expired'];
            $result['swaps_processed']    += $expiry['swapped'];
        } catch (\Throwable) {
            // Non-critical
        }

        // ── Scheduled products ────────────────────────────────────────
        if ($this->settings->bool('shop_enabled', true)) {
            foreach ($this->products->findDueForPublishing() as $product) {
                try {
                    $product->publish();
                    $this->products->save($product);
                    $result['products_published']++;
                } catch (\Throwable) {
                    // Continue with other jobs if one fails
                }
            }

            // ── Expired products ───────────────────────────────────────
            $result['products_expired'] = $this->processDueProductExpirations();

            // ── Go-public for unlisted products ──────────────────────────
            foreach ($this->products->findDueForGoPublic() as $product) {
                try {
                    $product->publish();
                    $this->products->save($product);
                    $result['products_go_public']++;
                } catch (\Throwable) {
                    // Continue with other jobs if one fails
                }
            }
        }

        // ── Exchange rate refresh ─────────────────────────────────────
        // Uses fx_provider + fx_cache_hours from the E-Commerce settings.
        // If a provider is configured there, cron respects that interval automatically.
        // Only runs when shop is enabled (rates are used for product pricing).
        if ($this->isExchangeRateRefreshDue($forceRates)) {
            try {
                $this->exchangeRate->fetchAndCache();
                $result['rates_updated'] = true;
            } catch (\Throwable) {
                // Non-critical
            }
        }

        // ── Auto update-check (every 24 h) ────────────────────────────
        // Master switch: updates_enabled=false disables all automatic checks
        // and auto-apply. Manual checks (admin UI / CLI) remain available.
        $updatesEnabled = ($this->settings->get('updates_enabled', 'true') ?? 'true') !== 'false';
        $autoCheck      = $this->settings->get('update_auto_check', 'true') ?? 'true';
        if ($updatesEnabled && ($forceUpdate || ($autoCheck !== 'false' && $this->updater->isDueForAutoCheck()))) {
            try {
                $manifest                   = $this->updater->check();
                $result['update_checked']   = true;
                $result['update_available'] = $this->updater->isUpdateAvailable();
                $result['update_version']   = $this->updater->getAvailableVersion() ?? '';

                if ($manifest !== null && $result['update_available'] && $this->updateNotifier !== null) {
                    try {
                        $this->updateNotifier->notifyUpdateAvailable($manifest, $this->updater->getCurrentVersion());
                    } catch (\Throwable) {
                        // Notification should not block cron jobs
                    }
                }
            } catch (\Throwable) {
                // Non-critical — log nothing to avoid noise
            }
        }

        // ── Plugin update checks (every 24 h) ─────────────────────────
        // Plugin updates are never applied automatically: cron only refreshes
        // the cached status so the dashboard and plugin screen can warn.
        if ($this->pluginUpdater !== null
            && $updatesEnabled
            && ($forceUpdate || ($autoCheck !== 'false' && $this->pluginUpdater->isDueForCheck()))
        ) {
            try {
                $pluginResults                    = $this->pluginUpdater->checkAll();
                $result['plugin_updates_checked'] = count($pluginResults);

                foreach ($pluginResults as $pluginStatus) {
                    if (!empty($pluginStatus['available']) && empty($pluginStatus['error'])) {
                        $result['plugin_updates_available']++;
                    }
                }
            } catch (\Throwable $e) {
                error_log('[Cron] Plugin update check failed: ' . $e->getMessage());
            }
        }

        // ── Auto-apply update (if enabled) ───────────────────────────
        // Auto-apply requires: auto_apply setting = true, update available, and
        // either --force-update flag OR auto_check is enabled
        $autoApply   = $this->settings->get('update_auto_apply', 'false') ?? 'false';
        $shouldApply = $autoApply === 'true' && $this->updater->isUpdateAvailable();
        $canApply    = $updatesEnabled       && ($forceUpdate || ($autoCheck !== 'false'));
        if ($shouldApply && $canApply) {
            $lockFile = ($this->rootDir !== '' ? $this->rootDir : (string) getcwd())
                . '/storage/framework/update.lock';
            $lock = @fopen($lockFile, 'c');
            if ($lock !== false && flock($lock, LOCK_EX | LOCK_NB)) {
                try {
                    $manifest = $this->updater->getCachedManifest();
                    if ($manifest !== null) {
                        $zipPath = $this->updater->download(
                            $manifest['download_url'] ?? '',
                            $manifest['sha256']       ?? ''
                        );
                        $updateResult = $this->updater->apply($zipPath, $manifest);
                        $this->settings->set('update_last_auto_applied', (string) time(), 'cron');
                        $this->settings->set(
                            'update_last_auto_applied_version',
                            $manifest['version'] ?? '',
                            'cron'
                        );

                        if ($this->updateNotifier !== null) {
                            try {
                                $this->updateNotifier->notifyAutoApplyResult($manifest, true, '', $updateResult);
                            } catch (\Throwable) {
                                // Notification should not block cron jobs
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    if ($this->updateNotifier !== null) {
                        try {
                            $this->updateNotifier->notifyAutoApplyResult($manifest ?? [], false, $e->getMessage());
                        } catch (\Throwable) {
                            // Notification should not block cron jobs
                        }
                    }
                    // Non-critical — rollback attempted inside UpdateService
                } finally {
                    flock($lock, LOCK_UN);
                    fclose($lock);
                }
            }
        }

        // ── Subscription reconciliation (daily) ──────────────────────
        if ($this->subscriptionReconciliation !== null && $this->settings->bool('subscriptions_enabled', true)) {
            try {
                $reconResult                             = $this->subscriptionReconciliation->reconcile();
                $result['subscriptions_reconciled']      = $reconResult['corrected'] ?? 0;
                if ($reconResult['corrected'] > 0) {
                    error_log(
                        '[Cron] Subscription reconciliation: '
                        . $reconResult['checked'] . ' checked, '
                        . $reconResult['corrected'] . ' corrected.'
                    );
                }
            } catch (\Throwable) {
                // Non-critical
            }
        }

        // ── Trial expiration sweep ───────────────────────────────────
        if ($this->subscriptionService !== null && $this->settings->bool('subscriptions_enabled', true)) {
            try {
                $trialsExpired            = $this->subscriptionService->processExpiredTrials();
                $result['trials_expired'] = $trialsExpired;
                if ($trialsExpired > 0) {
                    error_log('[Cron] Trial expiration: ' . $trialsExpired . ' trials processed.');
                }
            } catch (\Throwable) {
                // Non-critical
            }
        }

        // ── Abandoned cart recovery ───────────────────────────────────
        if ($this->abandonedCartService !== null && $this->settings->bool('shop_enabled', true)) {
            try {
                $result['abandoned_carts_sent'] = $this->abandonedCartService->processDue();
            } catch (\Throwable) {
                // Non-critical
            }
        }

        // ── Analytics: purge old pageviews ────────────────────────────
        if ($this->pageviews !== null && $this->settings->bool('analytics_enabled', true)) {
            try {
                $retentionDays = max(1, (int) ($this->settings->get('analytics_retention_days', '365') ?? 365));
                $before        = new \DateTimeImmutable("-{$retentionDays} days");
                $this->pageviews->deleteOlderThan($before);
            } catch (\Throwable) {
                // Non-critical
            }
        }

        // ── Plugin migrations ─────────────────────────────────────────
        // Plugin files can gain migrations after a deploy; running them here
        // keeps per-request boots free of schema changes.
        if ($this->pluginMigrator !== null) {
            try {
                $this->pluginMigrator->migrateEnabled();
            } catch (\Throwable $e) {
                error_log('[Cron] Plugin migrations failed: ' . $e->getMessage());
            }
        }

        // ── Update last ran timestamp ─────────────────────────────────
        $this->settings->set(self::LAST_RAN_KEY, (string) time(), 'cron');

        return $result;
    }

    /**
     * Publish a due record. When it names a live swap partner, the partner
     * vacates the live slug instead of a plain publish (campaign go-live).
     *
     * @return bool true when a slug handover was performed
     */
    private function publishWithSwap(Post $post): bool
    {
        $partner = $this->swapPartner($post);
        if ($partner !== null && $partner->isPublished()) {
            $this->swapWithHomepageFollow($partner, $post);
            return true;
        }

        $post->publish();
        $this->posts->save($post);
        return false;
    }

    /**
     * @return array{expired: int, swapped: int}
     */
    private function processDueExpiries(): array
    {
        $expired = 0;
        $swapped = 0;

        foreach ($this->posts->findDueForExpiry() as $post) {
            if (!$this->contentTypeEnabled($post->getContentType())) {
                continue;
            }
            try {
                $partner = $this->swapPartner($post);
                if ($partner !== null) {
                    $this->swapWithHomepageFollow($post, $partner);
                    $swapped++;
                } else {
                    $post->expire();
                    $this->posts->save($post);
                }
                $expired++;
            } catch (\Throwable) {
                // Continue with other jobs if one fails
            }
        }

        return ['expired' => $expired, 'swapped' => $swapped];
    }

    /**
     * Perform a slug handover and keep the homepage setting pointed at the
     * live record: when the vacating record is the configured homepage,
     * the setting follows to the record taking over its URL.
     */
    private function swapWithHomepageFollow(Post $expiring, Post $partner): void
    {
        $this->posts->performSwap($expiring, $partner, $this->buildArchiveSlug($expiring));

        try {
            $homepageId = (int) ($this->settings->get('homepage_page_id', '0') ?? '0');
            if ($homepageId > 0 && $expiring->getId() !== null && $homepageId === $expiring->getId()) {
                $this->settings->set('homepage_page_id', (string) $partner->getId(), 'general');
            }
        } catch (\Throwable) {
            // Non-critical: swap already committed
        }
    }

    /**
     * Resolve a usable swap partner: must exist, not be trashed or expired,
     * and share the content type + language scope (slug uniqueness scope).
     */
    private function swapPartner(Post $post): ?Post
    {
        if (!$post->hasSwap()) {
            return null;
        }

        try {
            $partner = $this->posts->findById($post->getSwapPostId());
        } catch (\Throwable) {
            return null;
        }

        if ($partner                                 === null
            || $partner->getStatus()                 === 'trash'
            || $partner->isExpiredStatus()
            || $partner->getContentType() !== $post->getContentType()
            || $partner->getLanguage()    !== $post->getLanguage()
        ) {
            return null;
        }

        return $partner;
    }

    private function buildArchiveSlug(Post $post): Slug
    {
        $base = (string) $post->getSlug() . '-expired-' . (new \DateTimeImmutable())->format('YmdHi');

        if ($this->swapSlugs === null) {
            return new Slug($base);
        }

        return $this->swapSlugs->unique(
            $base,
            VL_DB_PREFIX . 'posts',
            'slug',
            $post->getId(),
            'content_type = ? AND language = ?',
            [$post->getContentType(), $post->getLanguage()],
        );
    }

    /**
     * Expire products whose expires_at has passed. The date is kept on
     * purpose: cart/checkout reject date-expired items, so clearing it
     * would silently make them purchasable again.
     */
    private function processDueProductExpirations(): int
    {
        $expired = 0;

        foreach ($this->products->findDueForExpiry() as $product) {
            try {
                $product->setStatus('expired');
                $this->products->save($product);
                $expired++;
            } catch (\Throwable) {
                // Continue with other jobs if one fails
            }
        }

        return $expired;
    }

    /**
     * Whether scheduled publishing/expiry is allowed for this content type.
     * post → blog_enabled, page → pages_enabled, CPT → active flag.
     */
    private function contentTypeEnabled(string $type): bool
    {
        return match ($type) {
            'post'  => $this->settings->bool('blog_enabled', true),
            'page'  => $this->settings->bool('pages_enabled', true),
            default => $this->customTypeActive($type),
        };
    }

    /**
     * A custom (non-system) type only publishes while its active flag is on.
     * Fails open when the repository is unavailable so scheduling keeps working.
     * Results are cached per instance to avoid N+1 queries.
     */
    private function customTypeActive(string $slug): bool
    {
        if ($this->contentTypes === null) {
            return true;
        }

        if ($this->activeCustomTypesCache === null) {
            $this->activeCustomTypesCache = [];
            try {
                foreach ($this->contentTypes->findActiveCustom() as $type) {
                    $this->activeCustomTypesCache[(string) $type->getSlug()] = true;
                }
            } catch (\Throwable) {
                return true;
            }
        }

        return $this->activeCustomTypesCache[$slug] ?? false;
    }

    /**
     * Preview which jobs are due without executing anything.
     *
     * @param bool $forceRates Force exchange rate refresh regardless of cache interval
     * @param bool $forceUpdate Force update check regardless of auto-check interval
     *
     * @return array{newsletter_enabled: bool, blog_enabled: bool, shop_enabled: bool, campaigns_due: int, automations_due: int, posts_due: int, products_due: int, posts_expiring: int, products_expiring: int, posts_go_public: int, products_go_public: int, rates_configured: bool, rates_due: bool, update_check_due: bool}
     */
    public function previewDue(bool $forceRates = false, bool $forceUpdate = false): array
    {
        $newsletterEnabled = $this->settings->bool('newsletter_enabled', true);
        $blogEnabled       = $this->settings->bool('blog_enabled', true);
        $shopEnabled       = $this->settings->bool('shop_enabled', true);

        $campaignsDue   = 0;
        $automationsDue = 0;
        if ($newsletterEnabled) {
            $campaignsDue   = count($this->campaigns->findDueForSending());
            $automationsDue = $this->automations->countDueEnrollments();
        }

        // Counts match runDue(): posts whose content type is enabled.
        $postsDue = 0;
        foreach ($this->posts->findDueForPublishing() as $post) {
            if ($this->contentTypeEnabled($post->getContentType())) {
                $postsDue++;
            }
        }

        $postsExpiring = 0;
        foreach ($this->posts->findDueForExpiry() as $post) {
            if ($this->contentTypeEnabled($post->getContentType())) {
                $postsExpiring++;
            }
        }

        $postsGoPublic = 0;
        foreach ($this->posts->findDueForGoPublic() as $post) {
            if ($this->contentTypeEnabled($post->getContentType())) {
                $postsGoPublic++;
            }
        }

        $productsDue      = 0;
        $productsExpiring = 0;
        $productsGoPublic = 0;
        if ($shopEnabled) {
            $productsDue      = count($this->products->findDueForPublishing());
            $productsExpiring = count($this->products->findDueForExpiry());
            $productsGoPublic = count($this->products->findDueForGoPublic());
        }

        $provider        = $this->settings->get('fx_provider', 'none') ?? 'none';
        $ratesConfigured = $shopEnabled && $provider !== 'none';

        return [
            'newsletter_enabled' => $newsletterEnabled,
            'blog_enabled'       => $blogEnabled,
            'shop_enabled'       => $shopEnabled,
            'campaigns_due'      => $campaignsDue,
            'automations_due'    => $automationsDue,
            'posts_due'          => $postsDue,
            'products_due'       => $productsDue,
            'products_expiring'  => $productsExpiring,
            'products_go_public' => $productsGoPublic,
            'posts_expiring'     => $postsExpiring,
            'posts_go_public'    => $postsGoPublic,
            'rates_configured'   => $ratesConfigured,
            'rates_due'          => $this->isExchangeRateRefreshDue($forceRates),
            'update_check_due'   => $this->isUpdateCheckDue($forceUpdate),
        ];
    }

    /**
     * Number of files a cleanDataCache() call would remove.
     */
    public function countDataCacheFiles(): int
    {
        if ($this->dataCacheDir === '' || !is_dir($this->dataCacheDir)) {
            return 0;
        }

        $count = 0;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dataCacheDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            if ($file->isFile()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Number of revisions pruneRevisions($keepCount) would delete.
     */
    public function countPrunableRevisions(int $keepCount = 10, ?string $contentType = null): int
    {
        if ($this->revisions === null) {
            return 0;
        }

        return $this->revisions->countPrunable(max(1, $keepCount), $contentType);
    }

    private function isExchangeRateRefreshDue(bool $forceRates): bool
    {
        $shopEnabled = $this->settings->bool('shop_enabled', true);
        $provider    = $this->settings->get('fx_provider', 'none') ?? 'none';
        if (!$shopEnabled || $provider === 'none') {
            return false;
        }

        if ($forceRates) {
            return true;
        }

        $intervalHours = max(1, (int) ($this->settings->get('fx_cache_hours', '24') ?? 24));
        $lastRan       = $this->settings->get('fx_rates_updated_at', '') ?? '';
        if ($lastRan === '') {
            return false;
        }

        $updatedAt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $lastRan);
        if ($updatedAt === false) {
            return false;
        }

        return new \DateTimeImmutable() > $updatedAt->modify("+{$intervalHours} hours");
    }

    private function isUpdateCheckDue(bool $forceUpdate): bool
    {
        $updatesEnabled = ($this->settings->get('updates_enabled', 'true') ?? 'true') !== 'false';
        if (!$updatesEnabled) {
            return false;
        }

        $autoCheck = $this->settings->get('update_auto_check', 'true') ?? 'true';

        return $forceUpdate || ($autoCheck !== 'false' && $this->updater->isDueForAutoCheck());
    }

    /**
     * Clear the data cache directory. Returns number of files removed.
     */
    public function cleanDataCache(): int
    {
        if ($this->dataCacheDir === '' || !is_dir($this->dataCacheDir)) {
            return 0;
        }

        $cleared = 0;
        $files   = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dataCacheDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            if ($file->isFile()) {
                @unlink($file->getPathname());
                $cleared++;
            }
        }

        return $cleared;
    }

    /**
     * Prune old revisions, keeping only the latest $keepCount per item.
     * Null $contentType prunes all content types.
     * Returns the total number of revisions deleted.
     */
    public function pruneRevisions(int $keepCount = 10, ?string $contentType = null): int
    {
        if ($this->revisions === null) {
            return 0;
        }

        return $this->revisions->pruneAll(max(1, $keepCount), $contentType);
    }

    /**
     * Check whether cron is due to run based on mode and interval.
     * Used by PseudoCronMiddleware to avoid unnecessary DB writes.
     */
    public function isDue(): bool
    {
        $mode = $this->settings->get('cron_mode', 'none') ?? 'none';
        if ($mode !== 'page_load') {
            return false;
        }

        $intervalMinutes = max(1, (int) ($this->settings->get('cron_interval', '5') ?? 5));
        $lastRan         = (int) ($this->settings->get(self::LAST_RAN_KEY, '0') ?? 0);

        return (time() - $lastRan) >= ($intervalMinutes * 60);
    }
}
