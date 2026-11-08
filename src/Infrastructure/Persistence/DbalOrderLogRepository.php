<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use ValeApp\Domain\Shop\Entity\OrderLog;
use ValeApp\Domain\Shop\Repository\OrderLogRepositoryInterface;

final class DbalOrderLogRepository implements OrderLogRepositoryInterface
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return OrderLog[] */
    public function findByOrderId(int $orderId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'order_logs' . ' WHERE order_id = ? ORDER BY id ASC',
            [$orderId],
            [ParameterType::INTEGER],
        );
        return array_map(fn (array $row) => OrderLog::fromRow($row), $rows);
    }

    public function add(
        int $orderId,
        string $event,
        ?string $note = null,
        string $type = 'system',
        ?int $userId = null,
        ?string $addedBy = null,
    ): void {
        $this->db->insert(VL_DB_PREFIX . 'order_logs', [
            'order_id'   => $orderId,
            'event'      => $event,
            'note'       => $note,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'type'       => $type,
            'user_id'    => $userId,
            'added_by'   => $addedBy,
        ]);
    }

    public function delete(int $id): void
    {
        $this->db->delete(VL_DB_PREFIX . 'order_logs', ['id' => $id]);
    }
}
