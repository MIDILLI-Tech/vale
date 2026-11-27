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
     * An open declaration for the same order and customer email means a
     * repeat submission: the customer already has a dated confirmation.
     *
     * @param array<string, mixed> $input
     */
    public function findDuplicate(array $input): ?WithdrawalRequest
    {
        $orderNumber = strtoupper(trim((string) ($input['order_number'] ?? '')));
        $email       = mb_strtolower(trim((string) ($input['email'] ?? '')));

        if ($orderNumber === '' || $email === '') {
            return null;
        }

        return $this->requests->findOpenByOrderAndEmail($orderNumber, $email);
    }

    /** @param array<string, mixed> $input */
    public function resolveOrder(array $input): ?Order
    {
        $orderNumber = strtoupper(trim((string) ($input['order_number'] ?? '')));
        if ($orderNumber === '') {
            return null;
        }

        return $this->orders->findByOrderNumber($orderNumber)
            ?? $this->orders->findByOrderNumber(trim((string) ($input['order_number'] ?? '')));
    }

    /**
     * The regular period starts when the goods arrive; without a delivery date
     * the payment/creation date is the best available estimate.
     *
     * Nothing here is a hard answer unless the delivery was confirmed by an
     * operator: a guessed date must never make a declaration "late" or
     * "expired", so the customer keeps the benefit of the doubt.
     *
     * @return array{
     *     deadline: ?\DateTimeImmutable,
     *     late: bool,
     *     expired: bool,
     *     estimated: bool,
     *     provisional: bool,
     *     confirmed: bool,
     * }
     */
    public function deadline(?Order $order, int $days): array
    {
        $days = max(1, $days);
        $none = [
            'deadline'    => null,
            'late'        => false,
            'expired'     => false,
            'estimated'   => false,
            'provisional' => false,
            'confirmed'   => false,
        ];

        if ($order === null) {
            return $none;
        }

        $delivered = $order->getDeliveredAt();
        $start     = $delivered ?? $order->getPaidAt() ?? $order->getCreatedAt();
        if ($start === null) {
            return $none;
        }

        $confirmed = $order->hasConfirmedDelivery();
        $deadline  = $start->modify('+' . $days . ' days');
        $now       = new \DateTimeImmutable();

        return [
            'deadline'    => $deadline,
            'late'        => $confirmed && $now > $deadline,
            // CRD Article 10: a trader that never informed the consumer gets a
            // 12-month extension on top of the regular period.
            'expired'     => $confirmed && $now > $deadline->modify('+12 months'),
            'estimated'   => $delivered === null,
            'provisional' => $delivered !== null && !$confirmed,
            'confirmed'   => $confirmed,
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    public function submit(
        array $input,
        string $ipAddress,
        string $locale,
        ?\DateTimeImmutable $deadline = null,
        bool $estimated = false,
    ): WithdrawalRequest {
        $orderNumber = strtoupper(trim((string) ($input['order_number'] ?? '')));
        $email       = mb_strtolower(trim((string) ($input['email'] ?? '')));

        $order = $this->resolveOrder($input);

        $billing      = $order?->getBilling() ?? [];
        $billingEmail = mb_strtolower(trim((string) ($billing['email'] ?? '')));
        $linked       = $order !== null && $billingEmail !== '' && $billingEmail === $email;

        $request = new WithdrawalRequest($orderNumber, $email, $this->generateReference());
        $request->setLocale($locale);
        $request->setIpAddress($ipAddress);
        if ($linked) {
            $request->setDeadlineAt($deadline);
            $request->setDeadlineEstimated($estimated);
        }
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
