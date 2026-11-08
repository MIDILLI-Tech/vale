<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Shop;

use ValeApp\Domain\Shop\Entity\Coupon;
use ValeApp\Domain\Shop\Repository\CouponRepositoryInterface;

final class InMemoryCouponRepository implements CouponRepositoryInterface
{
    /** @var array<string, Coupon> */
    public array $coupons = [];

    /** @var array<string, string[]> simulated orders: coupon code => buyer emails */
    public array $orderEmails = [];

    public function findById(int $id): ?Coupon
    {
        foreach ($this->coupons as $coupon) {
            if ($coupon->getId() === $id) {
                return $coupon;
            }
        }
        return null;
    }

    public function findByCode(string $code): ?Coupon
    {
        return $this->coupons[strtoupper(trim($code))] ?? null;
    }

    public function findAll(): array
    {
        return array_values($this->coupons);
    }

    public function save(Coupon $coupon): Coupon
    {
        if ($coupon->getId() === null) {
            $coupon->setId(count($this->coupons) + 1);
        }
        $this->coupons[$coupon->getCode()] = $coupon;
        return $coupon;
    }

    public function delete(int $id): void
    {
        foreach ($this->coupons as $code => $coupon) {
            if ($coupon->getId() === $id) {
                unset($this->coupons[$code]);
            }
        }
    }

    public function incrementUses(int $id): void
    {
        $coupon = $this->findById($id);
        if ($coupon !== null) {
            $coupon->setUsesCount($coupon->getUsesCount() + 1);
        }
    }

    public function incrementUsesIfAvailable(int $id): bool
    {
        $coupon = $this->findById($id);
        if ($coupon === null) {
            return false;
        }
        if ($coupon->getMaxUses() !== null && $coupon->getUsesCount() >= $coupon->getMaxUses()) {
            return false;
        }
        $coupon->setUsesCount($coupon->getUsesCount() + 1);
        return true;
    }

    public function decrementUses(int $id): void
    {
        $coupon = $this->findById($id);
        if ($coupon !== null && $coupon->getUsesCount() > 0) {
            $coupon->setUsesCount($coupon->getUsesCount() - 1);
        }
    }

    public function countUsesByEmail(string $code, string $email): int
    {
        return count($this->orderEmails[strtoupper(trim($code))] ?? []);
    }
}
