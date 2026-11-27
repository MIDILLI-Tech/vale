<?php

declare(strict_types=1);

namespace ValeApp\Domain\Shop\Repository;

interface ProductPriceHistoryRepositoryInterface
{
    public function record(int $productId, float $price, ?\DateTimeImmutable $at = null): void;

    public function lowestBetween(int $productId, ?int $variationId, \DateTimeImmutable $from, \DateTimeImmutable $to): ?float;

    public function deleteForProduct(int $productId): void;
}
