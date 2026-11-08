<?php

declare(strict_types=1);

namespace ValeApp\Domain\Shop\Repository;

use ValeApp\Domain\Shop\Entity\OrderLog;

interface OrderLogRepositoryInterface
{
    /** @return OrderLog[] */
    public function findByOrderId(int $orderId): array;

    public function add(
        int $orderId,
        string $event,
        ?string $note = null,
        string $type = 'system',
        ?int $userId = null,
        ?string $addedBy = null,
    ): void;

    public function delete(int $id): void;
}
