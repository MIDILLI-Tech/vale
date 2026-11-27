<?php

declare(strict_types=1);

namespace ValeApp\Application\Shop;

use ValeApp\Domain\Shop\Entity\Order;
use ValeApp\Domain\Shop\Entity\WithdrawalRequest;
use ValeApp\Domain\Shop\Repository\OrderRepositoryInterface;
use ValeApp\Domain\Shop\Repository\WithdrawalRequestRepositoryInterface;

final class WithdrawalService
{
    public function __construct(
        private readonly WithdrawalRequestRepositoryInterface $requests,
        private readonly OrderRepositoryInterface $orders,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return list<string>
     */
    public function validate(array $input): array
    {
        $errors      = [];
        $orderNumber = trim((string) ($input['order_number'] ?? ''));
        $email       = trim((string) ($input['email'] ?? ''));

        if ($orderNumber === '' || mb_strlen($orderNumber) > 64) {
            $errors[] = __('Enter the order number from your order confirmation.');
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = __('Enter a valid email address.');
        }
        if (empty($input['confirm'])) {
            $errors[] = __('Please confirm that you want to withdraw from the contract.');
        }
        if (mb_strlen(trim((string) ($input['items_note'] ?? ''))) > 2000) {
            $errors[] = __('The item description is too long.');
        }
        if (mb_strlen(trim((string) ($input['reason'] ?? ''))) > 2000) {
            $errors[] = __('The reason is too long.');
        }
        if (mb_strlen(trim((string) ($input['name'] ?? ''))) > 191) {
            $errors[] = __('The name is too long.');
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function submit(array $input, string $ipAddress, string $locale): WithdrawalRequest
    {
        $orderNumber = strtoupper(trim((string) ($input['order_number'] ?? '')));
        $email       = mb_strtolower(trim((string) ($input['email'] ?? '')));

        $order = $this->orders->findByOrderNumber($orderNumber);
        if ($order === null) {
            $order = $this->orders->findByOrderNumber(trim((string) ($input['order_number'] ?? '')));
        }

        $billing      = $order?->getBilling() ?? [];
        $billingEmail = mb_strtolower(trim((string) ($billing['email'] ?? '')));
        $linked       = $order !== null && $billingEmail !== '' && $billingEmail === $email;

        $request = new WithdrawalRequest($orderNumber, $email, $this->generateReference());
        $request->setLocale($locale);
        $request->setIpAddress($ipAddress);
        $request->setReason((string) ($input['reason'] ?? ''));
        $request->setItemsNote((string) ($input['items_note'] ?? ''));

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' && $linked) {
            $name = trim((string) (($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? '')));
        }
        $request->setCustomerName($name);

        if ($linked) {
            $request->setOrderId($order?->getId());
            $request->setItems($this->snapshotItems($order));
        }

        return $this->requests->save($request);
    }

    private function generateReference(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $reference = 'W-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            if ($this->requests->findByReference($reference) === null) {
                return $reference;
            }
        }

        return 'W-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(6)));
    }

    /** @return list<array<string, mixed>> */
    private function snapshotItems(Order $order): array
    {
        $items = [];

        foreach ($order->getItems() as $item) {
            $items[] = [
                'product_id'   => $item['product_id'] ?? null,
                'product_name' => (string) ($item['product_name'] ?? $item['name'] ?? ''),
                'variation'    => $item['variation_name'] ?? $item['variation'] ?? null,
                'quantity'     => (int) ($item['quantity'] ?? 1),
                'price'        => $item['price'] ?? null,
            ];
        }

        return $items;
    }
}
