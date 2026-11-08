<?php

declare(strict_types=1);

namespace ValeApp\Domain\Shop\Repository;

use ValeApp\Domain\Shop\Entity\Coupon;

interface CouponRepositoryInterface
{
    public function findById(int $id): ?Coupon;
    public function findByCode(string $code): ?Coupon;

    /** @return Coupon[] */
    public function findAll(): array;

    public function save(Coupon $coupon): Coupon;
    public function delete(int $id): void;
    public function incrementUses(int $id): void;

    /** Atomically increments uses only when max_uses allows it. Returns false when the limit is reached. */
    public function incrementUsesIfAvailable(int $id): bool;

    public function decrementUses(int $id): void;

    public function countUsesByEmail(string $code, string $email): int;
}
