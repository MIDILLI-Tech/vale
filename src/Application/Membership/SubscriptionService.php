<?php

declare(strict_types=1);

namespace ValeApp\Application\Membership;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use ValeApp\Domain\Membership\Entity\MembershipPlan;
use ValeApp\Domain\Membership\Repository\PlanRepositoryInterface;
use ValeApp\Domain\Newsletter\Entity\Subscriber;
use ValeApp\Domain\Newsletter\Repository\SubscriberRepositoryInterface;
use ValeApp\Infrastructure\Payment\PayPalGateway;
use ValeApp\Infrastructure\Payment\StripeGateway;

final class SubscriptionService
{
    public function __construct(
        private readonly Connection $db,
        private readonly SubscriberRepositoryInterface $subscribers,
        private readonly PlanRepositoryInterface $plans,
        private readonly ?StripeGateway $stripe = null,
        private readonly ?PayPalGateway $paypal = null,
    ) {
    }

    public function createSubscription(
        int $userId,
        MembershipPlan $plan,
        string $gateway,
        string $gatewaySubscriptionId,
        string $status = 'active',
        ?\DateTimeImmutable $trialEndsAt = null,
    ): int {
        $now = new \DateTimeImmutable();

        $expiresAt = match (true) {
            $status === 'trialing' && $trialEndsAt   !== null     => $trialEndsAt,
            $status === 'active'   && $trialEndsAt   !== null     => $trialEndsAt,
            $status === 'active'   && $trialEndsAt === null       => $this->calculateExpiresAt($plan, $now),
            default                                               => null,
        };

        $this->db->insert(VL_DB_PREFIX . 'user_subscriptions', [
            'user_id'                 => $userId,
            'plan_id'                 => $plan->getId(),
            'gateway'                 => $gateway,
            'gateway_subscription_id' => $gatewaySubscriptionId,
            'status'                  => $status,
            'trial_ends_at'           => $trialEndsAt?->format('Y-m-d H:i:s'),
            'expires_at'              => $expiresAt?->format('Y-m-d H:i:s'),
            'created_at'              => $now->format('Y-m-d H:i:s'),
            'updated_at'              => $now->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->lastInsertId();
    }

    private function calculateExpiresAt(MembershipPlan $plan, \DateTimeImmutable $from): ?\DateTimeImmutable
    {
        return match ($plan->getInterval()) {
            'daily'    => $from->modify('+1 day'),
            'weekly'   => $from->modify('+1 week'),
            'monthly'  => $from->modify('+1 month'),
            'yearly'   => $from->modify('+1 year'),
            'one_time' => null,
            default    => null,
        };
    }

    public function assignPlanTag(Subscriber $subscriber, MembershipPlan $plan, bool $removeTrialTag = true): void
    {
        $tags    = $subscriber->getTags();
        $changed = false;

        $planTag = $plan->getTag();
        if ($planTag !== '' && !in_array($planTag, $tags, true)) {
            $tags[]  = $planTag;
            $changed = true;
        }

        if ($removeTrialTag) {
            $trialTag = $plan->getTrialTag();
            if ($trialTag !== null && $trialTag !== '') {
                $idx = array_search($trialTag, $tags, true);
                if ($idx !== false) {
                    array_splice($tags, $idx, 1);
                    $changed = true;
                }
            }
        }

        if ($changed) {
            $subscriber->setTags($tags);
            $this->subscribers->save($subscriber);
        }
    }

    public function assignTrialTag(Subscriber $subscriber, MembershipPlan $plan): void
    {
        $trialTag = $plan->getTrialTag();
        if ($trialTag === null || $trialTag === '') {
            return;
        }

        $tags = $subscriber->getTags();
        if (!in_array($trialTag, $tags, true)) {
            $tags[] = $trialTag;
            $subscriber->setTags($tags);
            $this->subscribers->save($subscriber);
        }
    }

    public function removePlanTag(Subscriber $subscriber, MembershipPlan $plan): void
    {
        $tags    = $subscriber->getTags();
        $changed = false;

        $planTag = $plan->getTag();
        if ($planTag !== '') {
            $idx = array_search($planTag, $tags, true);
            if ($idx !== false) {
                array_splice($tags, $idx, 1);
                $changed = true;
            }
        }

        $trialTag = $plan->getTrialTag();
        if ($trialTag !== null && $trialTag !== '') {
            $idx = array_search($trialTag, $tags, true);
            if ($idx !== false) {
                array_splice($tags, $idx, 1);
                $changed = true;
            }
        }

        if ($changed) {
            $subscriber->setTags($tags);
            $this->subscribers->save($subscriber);
        }
    }

    public function processExpiredTrials(): int
    {
        $prefix = VL_DB_PREFIX;
        $rows   = $this->db->fetchAllAssociative(
            "SELECT us.*, p.tag AS plan_tag, p.trial_tag AS plan_trial_tag
             FROM {$prefix}user_subscriptions us
             JOIN {$prefix}membership_plans p ON p.id = us.plan_id
             WHERE us.status = 'trialing'
               AND us.trial_ends_at IS NOT NULL
               AND us.trial_ends_at <= ?",
            [(new \DateTimeImmutable())->format('Y-m-d H:i:s')],
            [ParameterType::STRING]
        );

        $count = 0;
        foreach ($rows as $row) {
            $subscriptionId = (int) $row['id'];
            $userId         = (int) $row['user_id'];
            $planId         = (int) $row['plan_id'];

            $plan       = $this->plans->findById($planId);
            $subscriber = $this->subscribers->findByUserId($userId);

            if ($plan === null || $subscriber === null) {
                continue;
            }

            $payment = $this->db->fetchOne(
                "SELECT id FROM {$prefix}orders
                 WHERE subscription_id = ? AND order_type = 'subscription' AND status = 'completed'
                 LIMIT 1",
                [$row['gateway_subscription_id']],
                [ParameterType::STRING],
            );

            if ($payment !== false) {
                $this->db->update(
                    $prefix . 'user_subscriptions',
                    [
                        'status'     => 'active',
                        'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                    ],
                    ['id' => $subscriptionId],
                );
                $this->assignPlanTag($subscriber, $plan);
            } else {
                $this->db->update(
                    $prefix . 'user_subscriptions',
                    [
                        'status'     => 'expired',
                        'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                    ],
                    ['id' => $subscriptionId],
                );
                $this->removePlanTag($subscriber, $plan);
            }

            $count++;
        }

        return $count;
    }

    public function removePlanTagBySubscriptionId(string $gatewaySubscriptionId): void
    {
        $subRow = $this->findByGatewayId($gatewaySubscriptionId);
        if ($subRow === null) {
            return;
        }

        $planId = (int) ($subRow['plan_id'] ?? 0);
        $userId = (int) ($subRow['user_id'] ?? 0);
        if ($planId <= 0 || $userId <= 0) {
            return;
        }

        $plan       = $this->plans->findById($planId);
        $subscriber = $this->subscribers->findByUserId($userId);
        if ($plan === null || $subscriber === null) {
            return;
        }

        $this->removePlanTag($subscriber, $plan);
    }

    public function updateSubscriptionStatus(string $gatewaySubscriptionId, string $status): void
    {
        $this->db->update(
            VL_DB_PREFIX . 'user_subscriptions',
            [
                'status'     => $status,
                'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
            ['gateway_subscription_id' => $gatewaySubscriptionId],
        );
    }

    /**
     * Record a membership payment row. Idempotent: when a transaction
     * reference is provided (e.g. an invoice id), duplicate calls for the
     * same charge return the existing order id instead of inserting again.
     *
     * @param array<string, mixed> $customerData
     */
    public function recordPayment(int $userId, MembershipPlan $plan, string $gateway, string $gatewaySubscriptionId, string $amount, string $currency = 'USD', array $customerData = [], ?string $transactionReference = null): int
    {
        $now             = new \DateTimeImmutable();
        $transactionId   = $transactionReference ?? $gatewaySubscriptionId;

        $existing = $this->db->fetchOne(
            "SELECT id FROM " . VL_DB_PREFIX . "orders WHERE order_type = 'subscription' AND transaction_id = ? LIMIT 1",
            [$transactionId],
            [ParameterType::STRING],
        );
        if ($existing !== false) {
            return (int) $existing;
        }

        $billing = [
            'name'  => $customerData['name']  ?? '',
            'email' => $customerData['email'] ?? '',
        ];
        if (!empty($customerData['name'])) {
            $parts                 = explode(' ', $customerData['name'], 2);
            $billing['first_name'] = $parts[0] ?? '';
            $billing['last_name']  = $parts[1] ?? '';
        }

        $this->db->insert(VL_DB_PREFIX . 'orders', [
            'order_number'     => 'SUB-' . strtoupper(substr(md5(uniqid('', true)), 0, 8)),
            'user_id'          => $userId,
            'order_type'       => 'subscription',
            'plan_id'          => $plan->getId(),
            'plan_name'        => $plan->getName(),
            'subscription_id'  => $gatewaySubscriptionId,
            'status'           => 'completed',
            'currency'         => $currency,
            'total'            => (float) $amount,
            'payment_method'   => $gateway,
            'transaction_id'   => $transactionId,
            'paid_at'          => $now->format('Y-m-d H:i:s'),
            'billing'          => json_encode($billing),
            'created_at'       => $now->format('Y-m-d H:i:s'),
            'updated_at'       => $now->format('Y-m-d H:i:s'),
        ]);

        $orderId = (int) $this->db->lastInsertId();

        $this->db->insert(VL_DB_PREFIX . 'order_items', [
            'order_id' => $orderId,
            'name'     => $plan->getName(),
            'price'    => (float) $amount,
            'quantity' => 1,
            'subtotal' => (float) $amount,
            'total'    => (float) $amount,
        ]);

        return $orderId;
    }

    public function cancelSubscription(string $gatewaySubscriptionId): void
    {
        $now = new \DateTimeImmutable();
        $this->db->update(
            VL_DB_PREFIX . 'user_subscriptions',
            [
                'status'               => 'cancelling',
                'cancel_at_period_end' => 1,
                'cancelled_at'         => $now->format('Y-m-d H:i:s'),
                'updated_at'           => $now->format('Y-m-d H:i:s'),
            ],
            ['gateway_subscription_id' => $gatewaySubscriptionId],
        );

        $this->setOrderStatus($gatewaySubscriptionId, 'cancelled');
    }

    public function markCancelled(string $gatewaySubscriptionId): void
    {
        $now = new \DateTimeImmutable();
        $this->db->update(
            VL_DB_PREFIX . 'user_subscriptions',
            [
                'status'       => 'cancelled',
                'updated_at'   => $now->format('Y-m-d H:i:s'),
            ],
            ['gateway_subscription_id' => $gatewaySubscriptionId],
        );

        $this->setOrderStatus($gatewaySubscriptionId, 'cancelled');
    }

    public function reactivateSubscription(string $gatewaySubscriptionId): void
    {
        $now = new \DateTimeImmutable();
        $this->db->update(
            VL_DB_PREFIX . 'user_subscriptions',
            [
                'status'               => 'active',
                'cancel_at_period_end' => 0,
                'updated_at'           => $now->format('Y-m-d H:i:s'),
            ],
            ['gateway_subscription_id' => $gatewaySubscriptionId],
        );
    }

    public function findActiveByUserId(int $userId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'user_subscriptions' . ' WHERE user_id = ? AND status IN (?, ?, ?) ORDER BY id DESC LIMIT 1',
            [$userId, 'active', 'trialing', 'cancelling'],
            [ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
        );
        return $row !== false ? $row : null;
    }

    public function findPlanById(int $planId): ?MembershipPlan
    {
        return $this->plans->findById($planId);
    }

    public function cancelByUserId(int $userId): void
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'user_subscriptions' . ' WHERE user_id = ? AND status IN (?, ?, ?)',
            [$userId, 'active', 'trialing', 'cancelling'],
            [ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
        );

        foreach ($rows as $row) {
            $this->cancelSubscriptionRow($row);
        }
    }

    private function cancelSubscriptionRow(array $row): void
    {
        $subscriptionId = (int) $row['id'];
        $gatewayId      = (string) ($row['gateway_subscription_id'] ?? '');
        $gateway        = (string) ($row['gateway'] ?? '');
        $planId         = (int) ($row['plan_id'] ?? 0);
        $userId         = (int) ($row['user_id'] ?? 0);

        // Lifetime purchases have no gateway agreement to cancel.
        $isLifetime = str_starts_with($gatewayId, 'LIFETIME-');

        $immediateCancel = false;
        if ($gatewayId !== '' && !$isLifetime) {
            try {
                if ($gateway === 'stripe' && $this->stripe !== null) {
                    // Period-end cancellation: the customer keeps access until
                    // the subscription.deleted event arrives.
                    $this->stripe->cancelSubscription($gatewayId);
                } elseif ($gateway === 'paypal' && $this->paypal !== null) {
                    // Billing agreements terminate immediately at PayPal.
                    $this->paypal->cancelBillingAgreement($gatewayId);
                    $immediateCancel = true;
                }
            } catch (\Throwable $e) {
                error_log('Failed to cancel subscription at gateway: ' . $e->getMessage());
            }
        }

        $now     = new \DateTimeImmutable();
        $status  = ($immediateCancel || $isLifetime) ? 'cancelled' : 'cancelling';
        $this->db->update(
            VL_DB_PREFIX . 'user_subscriptions',
            [
                'status'               => $status,
                'cancel_at_period_end' => ($immediateCancel || $isLifetime) ? 0 : 1,
                'cancelled_at'         => $now->format('Y-m-d H:i:s'),
                'updated_at'           => $now->format('Y-m-d H:i:s'),
            ],
            ['id' => $subscriptionId],
        );

        if ($gatewayId !== '') {
            $this->setOrderStatus($gatewayId, 'cancelled');
        }

        // Immediate terminations revoke access right away: drop the plan tag.
        if (($immediateCancel || $isLifetime) && $planId > 0 && $userId > 0) {
            $plan       = $this->plans->findById($planId);
            $subscriber = $this->subscribers->findByUserId($userId);
            if ($plan !== null && $subscriber !== null) {
                $this->removePlanTag($subscriber, $plan);
            }
        }
    }

    private function setOrderStatus(string $gatewaySubscriptionId, string $status): void
    {
        $this->db->update(
            VL_DB_PREFIX . 'orders',
            [
                'status'     => $status,
                'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
            ['subscription_id' => $gatewaySubscriptionId, 'order_type' => 'subscription'],
        );
    }

    public function findByGatewayId(string $gatewaySubscriptionId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'user_subscriptions' . ' WHERE gateway_subscription_id = ?',
            [$gatewaySubscriptionId],
            [ParameterType::STRING],
        );
        return $row !== false ? $row : null;
    }

    public function terminateByUserId(int $userId): void
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'user_subscriptions' . ' WHERE user_id = ? AND status IN (?, ?, ?)',
            [$userId, 'active', 'trialing', 'cancelling'],
            [ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
        );

        foreach ($rows as $row) {
            $this->terminateSubscriptionRow($row);
        }
    }

    private function terminateSubscriptionRow(array $row): void
    {
        $subscriptionId = (int) $row['id'];
        $gatewayId      = (string) ($row['gateway_subscription_id'] ?? '');
        $gateway        = (string) ($row['gateway'] ?? '');
        $planId         = (int) ($row['plan_id'] ?? 0);
        $userId         = (int) ($row['user_id'] ?? 0);

        $isLifetime = str_starts_with($gatewayId, 'LIFETIME-');

        $immediateCancel = false;
        if ($gatewayId !== '' && !$isLifetime) {
            try {
                if ($gateway === 'stripe' && $this->stripe !== null) {
                    // Period-end: customer is billed through the current period,
                    // so access and tags must survive until it ends.
                    $this->stripe->cancelSubscription($gatewayId);
                } elseif ($gateway === 'paypal' && $this->paypal !== null) {
                    $this->paypal->cancelBillingAgreement($gatewayId);
                    $immediateCancel = true;
                }
            } catch (\Throwable $e) {
                error_log('Failed to cancel subscription at gateway: ' . $e->getMessage());
            }
        }

        $now    = new \DateTimeImmutable();
        $status = ($immediateCancel || $isLifetime || $gateway === 'paypal') ? 'cancelled' : 'cancelling';
        $this->db->update(
            VL_DB_PREFIX . 'user_subscriptions',
            [
                'status'       => $status,
                'cancelled_at' => $now->format('Y-m-d H:i:s'),
                'updated_at'   => $now->format('Y-m-d H:i:s'),
            ],
            ['id' => $subscriptionId],
        );

        if ($gatewayId !== '') {
            $this->setOrderStatus($gatewayId, 'cancelled');
        }

        if (($immediateCancel || $isLifetime) && $planId > 0 && $userId > 0) {
            $plan       = $this->plans->findById($planId);
            $subscriber = $this->subscribers->findByUserId($userId);
            if ($plan !== null && $subscriber !== null) {
                $this->removePlanTag($subscriber, $plan);
            }
        }
    }
}
