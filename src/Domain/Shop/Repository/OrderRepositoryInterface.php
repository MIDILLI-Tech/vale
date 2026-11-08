<?php

declare(strict_types=1);

namespace ValeApp\Domain\Shop\Repository;

use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Domain\Shop\Entity\Order;

interface OrderRepositoryInterface
{
    public function findById(int $id): ?Order;

    public function findByOrderNumber(string $orderNumber): ?Order;

    public function findByPaymentToken(string $token): ?Order;

    /** @return Order[] */
    public function findAll(Pagination $pagination, array $filters = []): array;

    public function count(array $filters = []): int;

    public function save(Order $order): Order;

    public function delete(int $id): void;

    /**
     * Atomically mark the order's inventory as processed.
     * Returns false when another process already claimed it.
     */
    public function claimInventoryProcessing(int $orderId): bool;

    /**
     * Atomically mark the order's inventory as restored (reversal done).
     * Returns false when another process already claimed it.
     */
    public function claimInventoryRestored(int $orderId): bool;

    /**
     * Atomically mark the order's coupon use as returned.
     * Returns false when the use was already returned (idempotent).
     */
    public function claimCouponReturn(int $orderId): bool;

    /**
     * Clear the "coupon use returned" marker — e.g. when a returned order
     * re-enters a paid state and consumes the use again.
     */
    public function resetCouponReturn(int $orderId): void;

    public function generateOrderNumber(): string;

    public function hasPurchasedProduct(string $email, int $productId): bool;

    /** Check if email has any paid orders (excludes a specific order ID if given). */
    public function hasPaidOrdersByEmail(string $email, ?int $excludeOrderId = null): bool;

    /**
     * @return Order[]
     */
    public function findByUserId(int $userId, int $limit = 20, int $offset = 0): array;

    public function countByUserId(int $userId, ?string $status = 'completed'): int;

    public function getTotalSpendByUserId(int $userId, ?string $status = 'completed'): float;

    public function getLastOrderDateByUserId(int $userId, ?string $status = 'completed'): ?\DateTimeImmutable;

    /**
     * Get aggregate report data for a date range.
     * @param string $from  Y-m-d
     * @param string $to    Y-m-d
     * @return array{summary: array, monthly: array, top_products: array, payment_methods: array, status_breakdown: array}
     */
    public function getReportData(string $from, string $to): array;
}
