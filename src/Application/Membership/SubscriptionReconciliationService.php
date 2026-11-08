<?php

declare(strict_types=1);

namespace ValeApp\Application\Membership;

use Doctrine\DBAL\Connection;
use ValeApp\Domain\Membership\Repository\PlanRepositoryInterface;
use ValeApp\Domain\Newsletter\Repository\SubscriberRepositoryInterface;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Infrastructure\Payment\PayPalGateway;
use ValeApp\Infrastructure\Payment\StripeGateway;

final class SubscriptionReconciliationService
{
    private const SETTING_LAST_RAN = 'subscription_reconciliation_last_ran';
    private const INTERVAL_HOURS   = 24;

    public function __construct(
        private readonly Connection $db,
        private readonly SubscriptionService $subscriptionService,
        private readonly SubscriberRepositoryInterface $subscribers,
        private readonly PlanRepositoryInterface $plans,
        private readonly SettingsRepositoryInterface $settings,
        private readonly ?PayPalGateway $paypal = null,
        private readonly ?StripeGateway $stripe = null,
    ) {
    }

    /**
     * Check all active/trialing subscriptions against their payment gateway.
     * If the gateway reports a cancelled/expired status, update local DB and remove tags.
     *
     * @param bool $force Skip the 24-hour interval check
     * @return array{checked:int,corrected:int}
     */
    public function reconcile(bool $force = false): array
    {
        $result = ['checked' => 0, 'corrected' => 0];

        if (!$force && !$this->isDue()) {
            return $result;
        }

        $prefix = VL_DB_PREFIX;
        $rows   = $this->db->fetchAllAssociative(
            "SELECT us.*, o.billing
             FROM {$prefix}user_subscriptions us
             LEFT JOIN {$prefix}orders o ON o.subscription_id = us.gateway_subscription_id AND o.order_type = 'subscription'
             WHERE us.status IN ('active', 'trialing', 'cancelling')
             AND us.gateway IN ('stripe', 'paypal')
             AND us.gateway_subscription_id NOT LIKE 'LIFETIME-%'
             ORDER BY us.id ASC",
        );

        $failures = 0;

        foreach ($rows as $row) {
            $gateway      = $row['gateway']                 ?? '';
            $gatewaySubId = $row['gateway_subscription_id'] ?? '';

            if ($gatewaySubId === '') {
                continue;
            }

            // One flaky gateway response must not abort the whole sweep.
            try {
                $gatewayStatus = $this->fetchGatewayStatus($gateway, $gatewaySubId);
            } catch (\Throwable $e) {
                error_log('[SubscriptionReconcile] ' . $gateway . ' lookup failed for ' . $gatewaySubId . ': ' . $e->getMessage());
                $failures++;
                continue;
            }

            if ($gatewayStatus === null) {
                continue;
            }

            $result['checked']++;

            if ($this->isTerminalStatus($gatewayStatus)) {
                $this->subscriptionService->updateSubscriptionStatus($gatewaySubId, 'cancelled');
                $this->subscriptionService->removePlanTagBySubscriptionId($gatewaySubId);
                $result['corrected']++;
            }
        }

        // Only stamp the daily interval when the sweep actually completed —
        // otherwise transient outages would block retries for a full day.
        if ($failures === 0 || $failures < count($rows)) {
            $this->settings->set(self::SETTING_LAST_RAN, (string) time(), 'cron');
        }

        return $result;
    }

    private function fetchGatewayStatus(string $gateway, string $gatewaySubId): ?string
    {
        if ($gateway === 'stripe' && $this->stripe !== null) {
            $info = $this->stripe->getSubscriptionStatus($gatewaySubId);
            return $info['status'] ?? null;
        }

        if ($gateway === 'paypal' && $this->paypal !== null) {
            $info = $this->paypal->getBillingAgreementStatus($gatewaySubId);
            if ($info === null) {
                return null;
            }
            return match (strtolower($info['state'] ?? '')) {
                'active'     => 'active',
                'cancelled'  => 'canceled',
                'suspended'  => 'past_due',
                'expired'    => 'canceled',
                'reactivate' => 'active',
                default      => $info['state'] ?? null,
            };
        }

        return null;
    }

    private function isTerminalStatus(string $status): bool
    {
        // 'unpaid'/'past_due' are recoverable dunning states — cancelling on
        // first sight would revoke access while the gateway retries payment.
        return in_array($status, ['canceled', 'cancelled', 'incomplete_expired', 'expired'], true);
    }

    private function isDue(): bool
    {
        $lastRan = $this->settings->get(self::SETTING_LAST_RAN, '');

        if ($lastRan === '' || $lastRan === null || $lastRan === false) {
            return true;
        }

        return ((int) $lastRan + (self::INTERVAL_HOURS * 3600)) < time();
    }
}
