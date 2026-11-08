<?php

declare(strict_types=1);

namespace ValeApp\Application\Shop;

use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Domain\Shop\Entity\Order;

/**
 * Builds the customer-facing bank transfer instruction data for an order,
 * replacing {order_number} / {order_id} / {order_total} placeholders.
 */
final class BankTransferDetailsService
{
    /** Checkout stores "banktransfer"; legacy admin-created rows may store "bank_transfer". */
    private const METHOD_ALIASES = ['banktransfer', 'bank_transfer'];

    public function __construct(
        private readonly SettingsRepositoryInterface $settings,
    ) {
    }

    public static function isBankTransferMethod(?string $method): bool
    {
        return in_array($method, self::METHOD_ALIASES, true);
    }

    /**
     * Template-ready details. Keys match the names already used by the
     * checkout success page and mail templates.
     *
     * @return array<string, mixed>
     */
    public function forOrder(Order $order): array
    {
        $description = (string) ($this->settings->get('banktransfer_description', '') ?? '');
        $description = str_replace(
            ['{order_number}', '{order_id}', '{order_total}'],
            [$order->getOrderNumber(), (string) $order->getId(), number_format($order->getTotal(), 2)],
            $description,
        );

        return [
            'banktransfer_label'    => (string) ($this->settings->get('banktransfer_label', 'Bank Transfer / EFT') ?? ''),
            'banktransfer_bank'     => trim((string) ($this->settings->get('banktransfer_bank_name', '') ?? '')),
            'banktransfer_iban'     => trim((string) ($this->settings->get('banktransfer_iban', '') ?? '')),
            'banktransfer_holder'   => trim((string) ($this->settings->get('banktransfer_holder_name', '') ?? '')),
            'banktransfer_swift'    => trim((string) ($this->settings->get('banktransfer_swift', '') ?? '')),
            'banktransfer_routing'  => trim((string) ($this->settings->get('banktransfer_routing', '') ?? '')),
            'banktransfer_branch'   => trim((string) ($this->settings->get('banktransfer_branch', '') ?? '')),
            'banktransfer_desc'     => $description,
            'banktransfer_max_days' => max(1, (int) ($this->settings->get('banktransfer_max_days', '14') ?? '14')),
        ];
    }

    /** @param array<string, mixed> $details */
    public static function isConfigured(array $details): bool
    {
        return isset($details['banktransfer_iban']) && trim((string) $details['banktransfer_iban']) !== '';
    }
}
