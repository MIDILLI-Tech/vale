<?php

declare(strict_types=1);

namespace ValeApp\Application\Shop;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use ValeApp\Application\Mail\MailService;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;

final class AbandonedCartService
{
    public function __construct(
        private readonly Connection $db,
        private readonly MailService $mailer,
        private readonly SettingsRepositoryInterface $settings,
    ) {
    }

    /**
     * Record or update the current user's cart as active.
     * Called on checkout page load for logged-in users.
     */
    public function touchCart(?int $userId, string $email, string $cartData, float $subtotal, string $currency, string $locale = 'en'): void
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return;
        }

        $existing = $this->db->fetchAssociative(
            "SELECT id, recovered_at FROM {$this->getTable()} WHERE LOWER(email) = ?",
            [$email],
            [ParameterType::STRING],
        );

        $now = date('Y-m-d H:i:s');
        if ($existing) {
            $update = [
                'cart_data'  => $cartData,
                'subtotal'   => $subtotal,
                'currency'   => $currency,
                'email'      => $email,
                'locale'     => $locale,
                'updated_at' => $now,
            ];

            if ($userId !== null) {
                $update['user_id'] = $userId;
            }

            // A previously recovered cart is being abandoned again: start a
            // fresh reminder cycle instead of staying permanently "recovered".
            if (!empty($existing['recovered_at'])) {
                $update['recovered_at']     = null;
                $update['reminder_count']   = 0;
                $update['last_reminded_at'] = null;
            }

            $this->db->update($this->getTable(), $update, ['id' => $existing['id']]);
        } else {
            $this->db->insert($this->getTable(), [
                'user_id'            => $userId,
                'email'              => $email,
                'locale'             => $locale,
                'cart_data'          => $cartData,
                'subtotal'           => $subtotal,
                'currency'           => $currency,
                'first_detected_at'  => $now,
                'reminder_count'     => 0,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
        }
    }

    /**
     * Mark a cart as recovered (order placed).
     */
    public function markRecovered(int $userId): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->update($this->getTable(), [
            'recovered_at' => $now,
            'updated_at'   => $now,
        ], [
            'user_id'      => $userId,
            'recovered_at' => null,
        ]);
    }

    /**
     * Mark a cart as recovered by email address (guest checkout).
     */
    public function markRecoveredByEmail(string $email): void
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $this->db->executeStatement(
            "UPDATE {$this->getTable()}
                SET recovered_at = ?, updated_at = ?
              WHERE LOWER(email) = ?
                AND recovered_at IS NULL",
            [$now, $now, $email],
        );
    }

    /**
     * Process due abandoned carts and send reminder emails.
     * Called from CronService::runDue().
     */
    public function processDue(): int
    {
        if ($this->settings->get('abandoned_cart_enabled', '0') !== '1') {
            return 0;
        }

        $delayHours    = max(1, (int) ($this->settings->get('abandoned_cart_delay_hours', '2') ?? '2'));
        $maxReminders  = max(1, (int) ($this->settings->get('abandoned_cart_max_reminders', '3') ?? '3'));
        $intervalHours = max(1, (int) ($this->settings->get('abandoned_cart_reminder_interval_hours', '24') ?? '24'));

        $delayCutoff    = (new \DateTimeImmutable("-{$delayHours} hours"))->format('Y-m-d H:i:s');
        $intervalCutoff = (new \DateTimeImmutable("-{$intervalHours} hours"))->format('Y-m-d H:i:s');

        $rows = $this->db->fetchAllAssociative(
            "SELECT * FROM {$this->getTable()}
             WHERE recovered_at IS NULL
               AND reminder_count < ?
               AND updated_at <= ?
               AND (last_reminded_at IS NULL OR last_reminded_at <= ?)
             ORDER BY updated_at ASC
             LIMIT 50",
            [$maxReminders, $delayCutoff, $intervalCutoff],
            [ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING],
        );

        $sent = 0;
        foreach ($rows as $row) {
            try {
                $this->sendReminder($row);
                $sent++;
            } catch (\Throwable $e) {
                error_log('[AbandonedCart] Failed to send reminder to user ' . $row['user_id'] . ': ' . $e->getMessage());
            }
        }

        return $sent;
    }

    private function sendReminder(array $row): void
    {
        $cartData = json_decode($row['cart_data'] ?? '{}', true) ?: [];

        $itemCount = 0;
        foreach ($cartData as $item) {
            $itemCount += (int) ($item['qty'] ?? 0);
        }

        $checkoutUrl = absolute_url('/checkout', [], ['lang' => $row['locale'] ?? 'en']);

        $context = [
            'email'        => $row['email'],
            'subtotal'     => (float) $row['subtotal'],
            'currency'     => $row['currency'] ?: $this->settings->get('currency', 'EUR'),
            'item_count'   => $itemCount,
            'checkout_url' => $checkoutUrl,
            'days_ago'     => (int) ceil((time() - strtotime($row['updated_at'])) / 86400),
        ];

        $this->mailer->sendTemplate(
            $row['email'],
            $this->mailer->getTranslator()->get('You left something in your cart!', [], $row['locale'] ?? 'en'),
            'mail/abandoned-cart',
            $context,
            locale: $row['locale'] ?? 'en',
            recipientType: 'customer',
        );

        $now = date('Y-m-d H:i:s');
        $this->db->update($this->getTable(), [
            'last_reminded_at'  => $now,
            'reminder_count'    => $row['reminder_count'] + 1,
            'updated_at'        => $now,
        ], ['id' => $row['id']]);
    }

    private function getReminderSubject(array $context): string
    {
        $name = $this->settings->get('site_name', '');
        return "{$name} - You left something in your cart!";
    }

    private function getTable(): string
    {
        return VL_DB_PREFIX . 'abandoned_carts';
    }
}
