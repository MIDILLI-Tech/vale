<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use ValeApp\Domain\Shop\Repository\ProductPriceHistoryRepositoryInterface;

final class DbalProductPriceHistoryRepository implements ProductPriceHistoryRepositoryInterface
{
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    public function record(int $productId, float $price, ?\DateTimeImmutable $at = null): void
    {
        $this->db->insert(VL_DB_PREFIX . 'product_price_history', [
            'product_id'  => $productId,
            'price'       => $price,
            'recorded_at' => ($at ?? new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], [
            'product_id' => ParameterType::INTEGER,
        ]);
    }

    public function lowestBetween(int $productId, ?int $variationId, \DateTimeImmutable $from, \DateTimeImmutable $to): ?float
    {
        $sql    = 'SELECT MIN(price) FROM ' . VL_DB_PREFIX . 'product_price_history'
            . ' WHERE product_id = ? AND recorded_at >= ? AND recorded_at <= ?';
        $params = [$productId, $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')];
        $types  = [ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING];

        if ($variationId === null) {
            $sql .= ' AND variation_id IS NULL';
        } else {
            $sql      .= ' AND variation_id = ?';
            $params[] = $variationId;
            $types[]  = ParameterType::INTEGER;
        }

        $value = $this->db->fetchOne($sql, $params, $types);

        return $value === false || $value === null ? null : (float) $value;
    }

    public function deleteForProduct(int $productId): void
    {
        $this->db->delete(VL_DB_PREFIX . 'product_price_history', ['product_id' => $productId]);
    }
}
